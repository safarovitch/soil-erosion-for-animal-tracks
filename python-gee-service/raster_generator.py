"""
GeoTIFF Raster Generator for RUSLE Erosion Data
Exports RUSLE computation results to GeoTIFF format
"""
import ee
import numpy as np
import logging
import math
import tempfile
import time
from pathlib import Path

import requests
import rasterio
from requests.exceptions import ReadTimeout, RequestException
from rasterio.merge import merge
from gee_service import gee_service
from rusle_calculator import RUSLECalculator

logger = logging.getLogger(__name__)

class ErosionRasterGenerator:
    """Generate GeoTIFF rasters from RUSLE computations"""
    
    def __init__(self, storage_path='/var/www/rusle-icarda/storage/rusle-tiles'):
        self.storage_path = Path(storage_path)
        self.storage_path.mkdir(parents=True, exist_ok=True)
        self.rusle_calc = RUSLECalculator()
        
    def generate_geotiff(self, area_type, area_id, year, geometry_json, bbox=None, end_year=None):
        """
        Generate GeoTIFF raster for erosion data
        
        Args:
            area_type: 'region' or 'district'
            area_id: ID of the area
            year: Year to compute (start year if range)
            end_year: Optional inclusive end year (defaults to same as year)
            geometry_json: GeoJSON geometry dict
            bbox: Optional bbox [minLon, minLat, maxLon, maxLat]
            
        Returns:
            tuple: (geotiff_path, statistics, metadata)
        """
        try:
            start_year = year
            end_year = end_year if end_year is not None else year
            period_label = str(start_year) if end_year == start_year else f"{start_year}-{end_year}"
            
            logger.info(f"Generating GeoTIFF for {area_type} {area_id}, period {period_label}")
            
            # Convert GeoJSON to EE Geometry
            geometry = gee_service.geometry_from_geojson(geometry_json)
            
            # Analyze complexity for optimization
            complexity = gee_service.analyze_geometry_complexity(geometry_json, geometry)
            logger.info(f"Geometry complexity: {complexity['complexity_level']}")
            
            # Simplify geometry for faster processing (but keep original for boundary clipping)
            tolerance = complexity['recommended']['simplify_tolerance']
            # Reduce tolerance for better boundary accuracy (max 500m instead of 2000m)
            tolerance = min(tolerance, 500)  # Cap at 500m for better boundary accuracy
            simplified_geom = geometry.simplify(maxError=tolerance)
            
            # Keep original geometry for accurate boundary clipping
            original_geom = geometry  # No simplification for boundaries
            
            # Compute RUSLE with adaptive scale
            scale = complexity['recommended']['rusle_scale']
            logger.info(f"Computing RUSLE at {scale}m resolution...")
            logger.info(f"Using simplified geometry (tolerance: {tolerance}m) for computation")
            logger.info(f"Using original geometry for boundary clipping")
            
            if end_year != start_year:
                logger.info(f"Using decade-average rainfall ({start_year}-{end_year}) for R-factor")
                r_factor_image = self.rusle_calc.compute_r_factor_range(
                    start_year,
                    end_year,
                    simplified_geom
                )
                rusle_result = self.rusle_calc.compute_rusle(
                    start_year,
                    simplified_geom,
                    scale=scale,
                    compute_stats=True,
                    r_factor_image=r_factor_image
                )
                rainfall_stats = self.rusle_calc.compute_rainfall_statistics(
                    start_year,
                    end_year,
                    original_geom,
                    scale=max(5000, scale)
                )
            else:
                rusle_result = self.rusle_calc.compute_rusle(
                    year, 
                    simplified_geom, 
                    scale=scale,
                    compute_stats=True
                )
                rainfall_stats = self.rusle_calc.compute_rainfall_statistics(
                    start_year,
                    end_year,
                    original_geom,
                    scale=max(5000, scale)
                )
            
            # Clip to original geometry for accurate boundaries
            soil_loss_image = rusle_result['image'].clip(original_geom)
            statistics = rusle_result['statistics']
            
            # Define output path
            output_dir = self.storage_path / 'geotiff' / f'{area_type}_{area_id}' / period_label
            output_dir.mkdir(parents=True, exist_ok=True)
            output_path = output_dir / 'erosion.tif'
            
            tile_count = 0
            tiling_error = None
            try:
                tile_count = self._download_tiled_image(
                    soil_loss_image,
                    original_geom,
                    output_path,
                    scale=scale
                )
                logger.info(f"✓ Downloaded GeoTIFF via {tile_count} tiles")
            except Exception as tiling_exc:
                logger.error(f"Tiled download failed: {tiling_exc}", exc_info=True)
                tiling_error = str(tiling_exc)
                logger.info("Falling back to sampling-based raster generation...")
                self._generate_from_samples(
                    soil_loss_image,
                    original_geom,
                    output_path,
                    bbox,
                    scale
                )
            
            # Verify the file was created
            if not output_path.exists():
                raise Exception("GeoTIFF file was not created")
            
            logger.info(f"✓ GeoTIFF saved to: {output_path}")
            
            # Prepare metadata
            metadata = {
                'period': {
                    'start_year': start_year,
                    'end_year': end_year,
                    'label': period_label
                },
                'area_km2': complexity['area_km2'],
                'complexity': complexity['complexity_level'],
                'scale': scale,
                'simplify_tolerance': tolerance,
                'bbox': bbox,
                'original_geometry': geometry_json,  # Store original geometry for tile masking
                'tile_count': tile_count,
                'tiling_error': tiling_error,
                'rainfall_statistics': rainfall_stats,
                'erosion_class_breakdown': self.rusle_calc.compute_erosion_class_breakdown(
                    soil_loss_image,
                    original_geom,
                    scale=max(100, scale)
                )
            }
            
            return str(output_path), statistics, metadata
            
        except Exception as e:
            logger.error(f"Failed to generate GeoTIFF: {str(e)}", exc_info=True)
            raise
    
    def _generate_from_samples(self, image, geometry, output_path, bbox, scale):
        """
        Generate raster from sampled points for very large areas
        This is a fallback method when direct download would timeout
        """
        try:
            # For very large areas, we'll create a lower-resolution raster
            # by sampling at strategic points
            
            logger.info("Generating raster from samples...")
            
            # Use a coarser grid for very large areas
            grid_size = 50  # 50x50 grid
            
            # Get bounds
            if bbox and len(bbox) == 4:
                min_lon, min_lat, max_lon, max_lat = bbox
            else:
                bounds = geometry.bounds().getInfo()
                coords = bounds['coordinates'][0]
                lons = [c[0] for c in coords]
                lats = [c[1] for c in coords]
                min_lon, min_lat = min(lons), min(lats)
                max_lon, max_lat = max(lons), max(lats)
            
            # Create sampling grid
            lon_step = (max_lon - min_lon) / grid_size
            lat_step = (max_lat - min_lat) / grid_size
            
            # Sample points
            points = []
            for i in range(grid_size):
                for j in range(grid_size):
                    lon = min_lon + (i + 0.5) * lon_step
                    lat = min_lat + (j + 0.5) * lat_step
                    points.append(ee.Feature(ee.Geometry.Point([lon, lat]), {'idx': i * grid_size + j}))
            
            points_fc = ee.FeatureCollection(points).filterBounds(geometry)
            
            # Sample the image
            logger.info(f"Sampling {grid_size}x{grid_size} grid...")
            samples = image.sampleRegions(
                collection=points_fc,
                scale=scale * 2,  # Use coarser scale
                geometries=False
            ).getInfo()
            
            # Create numpy array
            data = np.zeros((grid_size, grid_size), dtype=np.float32)
            
            for feature in samples['features']:
                props = feature['properties']
                idx = props.get('idx', 0)
                value = props.get('soil_loss', 0)
                
                i = idx // grid_size
                j = idx % grid_size
                if i < grid_size and j < grid_size:
                    data[j, i] = value
            
            # Save as numpy array for backup
            import pickle
            with open(output_path.with_suffix('.npy'), 'wb') as f:
                pickle.dump({
                    'data': data,
                    'bounds': [min_lon, min_lat, max_lon, max_lat],
                    'grid_size': grid_size
                }, f)
            
            # Create proper GeoTIFF using rasterio
            logger.info("Converting to GeoTIFF...")
            self._numpy_to_geotiff(
                data, 
                [min_lon, min_lat, max_lon, max_lat],
                output_path
            )
            
            logger.info(f"✓ Sampled raster generated: {grid_size}x{grid_size}")
            
        except Exception as e:
            logger.error(f"Sampling failed: {str(e)}")
            raise
    
    def _download_tiled_image(self, image, geometry, output_path, scale, max_pixels=1024):
        """
        Download an image by splitting the region into manageable tiles and mosaicking.
        """
        bounds = geometry.bounds().getInfo()
        coords = bounds['coordinates'][0]
        lons = [coord[0] for coord in coords]
        lats = [coord[1] for coord in coords]

        if not lons or not lats:
            raise ValueError("Geometry bounds returned no coordinates.")

        min_lon = min(lons)
        max_lon = max(lons)
        min_lat = min(lats)
        max_lat = max(lats)

        width_deg = max_lon - min_lon
        height_deg = max_lat - min_lat
        if width_deg <= 0 or height_deg <= 0:
            raise ValueError("Geometry bounds are invalid or empty.")

        meters_per_degree = 111000  # Approximate conversion at Tajikistan latitude

        tile_width_deg = max_pixels * scale / meters_per_degree
        tile_height_deg = max_pixels * scale / meters_per_degree
        tile_width_deg = max(tile_width_deg, width_deg)
        tile_height_deg = max(tile_height_deg, height_deg)

        cols = max(1, math.ceil(width_deg / tile_width_deg))
        rows = max(1, math.ceil(height_deg / tile_height_deg))
        tile_width_deg = width_deg / cols
        tile_height_deg = height_deg / rows

        logger.info(f"Tiling geometry into {cols} x {rows} grid (scale {scale}m, max {max_pixels}px)")

        with tempfile.TemporaryDirectory() as tmpdir:
            tile_paths = []

            for row in range(rows):
                for col in range(cols):
                    tile_min_lon = min_lon + col * tile_width_deg
                    tile_max_lon = min(tile_min_lon + tile_width_deg, max_lon)
                    tile_min_lat = min_lat + row * tile_height_deg
                    tile_max_lat = min(tile_min_lat + tile_height_deg, max_lat)

                    tile_rect = ee.Geometry.Rectangle([tile_min_lon, tile_min_lat, tile_max_lon, tile_max_lat])
                    tile_geom = geometry.intersection(tile_rect, ee.ErrorMargin(1))

                    try:
                        area = tile_geom.area(maxError=1).getInfo()
                    except Exception:
                        area = 0

                    if area <= 0:
                        continue

                    tile_width_px = max(1, int(((tile_max_lon - tile_min_lon) * meters_per_degree) / scale))
                    tile_height_px = max(1, int(((tile_max_lat - tile_min_lat) * meters_per_degree) / scale))
                    tile_width_px = min(tile_width_px, max_pixels)
                    tile_height_px = min(tile_height_px, max_pixels)

                    if tile_width_px < 10 or tile_height_px < 10:
                        continue

                    url = image.getThumbURL({
                        'min': 0,
                        'max': 200,
                        'dimensions': f'{tile_width_px}x{tile_height_px}',
                        'region': tile_geom,
                        'format': 'GEO_TIFF'
                    })

                    timeout = min(600, 300 + (tile_width_px * tile_height_px / 10000))
                    tile_path = Path(tmpdir) / f"tile_{row}_{col}.tif"

                    logger.info(f"Downloading tile ({row+1}/{rows}, {col+1}/{cols}) "
                                f"{tile_width_px}x{tile_height_px}px timeout={int(timeout)}s")

                    max_retries = 3
                    for attempt in range(1, max_retries + 1):
                        try:
                            response = requests.get(url, timeout=int(timeout))
                            response.raise_for_status()

                            with open(tile_path, 'wb') as f:
                                f.write(response.content)

                            tile_paths.append(tile_path)
                            break
                        except ReadTimeout:
                            if attempt == max_retries:
                                logger.error(
                                    f"Read timeout downloading tile ({row+1}/{rows}, {col+1}/{cols}). "
                                    f"Reached {max_retries} retries; giving up."
                                )
                                raise

                            next_attempt = attempt + 1
                            backoff = min(120, 10 * attempt)
                            logger.warning(
                                f"Timeout downloading tile ({row+1}/{rows}, {col+1}/{cols}), "
                                f"retrying in {backoff}s (attempt {next_attempt}/{max_retries}, timeout={int(timeout)}s)."
                            )
                            time.sleep(backoff)
                        except RequestException as exc:
                            if attempt == max_retries:
                                logger.error(
                                    f"Request failed downloading tile ({row+1}/{rows}, {col+1}/{cols}). "
                                    f"Reached {max_retries} retries; giving up."
                                )
                                raise

                            next_attempt = attempt + 1
                            backoff = min(120, 5 * attempt)
                            logger.warning(
                                f"Error downloading tile ({row+1}/{rows}, {col+1}/{cols}): {exc}. "
                                f"Retrying in {backoff}s (attempt {next_attempt}/{max_retries})."
                            )
                            time.sleep(backoff)

            if not tile_paths:
                raise RuntimeError("Tiled download produced no tiles.")

            datasets = [rasterio.open(str(path)) for path in tile_paths]
            try:
                mosaic, out_transform = merge(datasets)
                out_meta = datasets[0].meta.copy()
                out_meta.update({
                    "driver": "GTiff",
                    "height": mosaic.shape[1],
                    "width": mosaic.shape[2],
                    "transform": out_transform,
                    "compress": "lzw"
                })

                with rasterio.open(output_path, 'w', **out_meta) as dest:
                    dest.write(mosaic)
            finally:
                for ds in datasets:
                    ds.close()

            return len(tile_paths)

    def _numpy_to_geotiff(self, data, bounds, output_path):
        """
        Convert numpy array to proper GeoTIFF using rasterio
        
        Args:
            data: numpy array (height, width)
            bounds: [min_lon, min_lat, max_lon, max_lat]
            output_path: Path object for output file
        """
        try:
            import rasterio
            from rasterio.transform import from_bounds
            
            height, width = data.shape
            min_lon, min_lat, max_lon, max_lat = bounds
            
            # Create affine transform
            transform = from_bounds(min_lon, min_lat, max_lon, max_lat, width, height)
            
            # Write GeoTIFF
            with rasterio.open(
                output_path,
                'w',
                driver='GTiff',
                height=height,
                width=width,
                count=1,
                dtype=data.dtype,
                crs='EPSG:4326',
                transform=transform,
                compress='lzw'
            ) as dst:
                dst.write(data, 1)
            
            logger.info(f"✓ GeoTIFF written: {output_path}")
            
        except ImportError:
            logger.warning("rasterio not available, using GDAL directly")
            self._numpy_to_geotiff_gdal(data, bounds, output_path)
        except Exception as e:
            logger.error(f"Failed to create GeoTIFF: {str(e)}")
            raise
    
    def _numpy_to_geotiff_gdal(self, data, bounds, output_path):
        """
        Fallback: Convert numpy array to GeoTIFF using GDAL directly
        """
        try:
            from osgeo import gdal, osr
            
            height, width = data.shape
            min_lon, min_lat, max_lon, max_lat = bounds
            
            # Create the output raster
            driver = gdal.GetDriverByName('GTiff')
            dst_ds = driver.Create(
                str(output_path),
                width,
                height,
                1,
                gdal.GDT_Float32,
                options=['COMPRESS=LZW']
            )
            
            # Set geotransform [x_min, pixel_width, 0, y_max, 0, -pixel_height]
            pixel_width = (max_lon - min_lon) / width
            pixel_height = (max_lat - min_lat) / height
            geotransform = [min_lon, pixel_width, 0, max_lat, 0, -pixel_height]
            dst_ds.SetGeoTransform(geotransform)
            
            # Set projection to WGS84
            srs = osr.SpatialReference()
            srs.ImportFromEPSG(4326)
            dst_ds.SetProjection(srs.ExportToWkt())
            
            # Write data
            dst_ds.GetRasterBand(1).WriteArray(data)
            dst_ds.FlushCache()
            dst_ds = None
            
            logger.info(f"✓ GeoTIFF written with GDAL: {output_path}")
            
        except Exception as e:
            logger.error(f"Failed to create GeoTIFF with GDAL: {str(e)}")
            raise





