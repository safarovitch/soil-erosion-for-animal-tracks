"""
GeoTIFF Raster Generator for RUSLE Erosion Data
Exports RUSLE computation results to GeoTIFF format
"""
import ee
import requests
import numpy as np
import logging
from pathlib import Path
from gee_service import gee_service
from rusle_calculator import RUSLECalculator

logger = logging.getLogger(__name__)

class ErosionRasterGenerator:
    """Generate GeoTIFF rasters from RUSLE computations"""
    
    def __init__(self, storage_path='/var/www/rusle-icarda/storage/rusle-tiles'):
        self.storage_path = Path(storage_path)
        self.storage_path.mkdir(parents=True, exist_ok=True)
        self.rusle_calc = RUSLECalculator()
        
    def generate_geotiff(self, area_type, area_id, year, geometry_json, bbox=None):
        """
        Generate GeoTIFF raster for erosion data
        
        Args:
            area_type: 'region' or 'district'
            area_id: ID of the area
            year: Year to compute
            geometry_json: GeoJSON geometry dict
            bbox: Optional bbox [minLon, minLat, maxLon, maxLat]
            
        Returns:
            tuple: (geotiff_path, statistics, metadata)
        """
        try:
            logger.info(f"Generating GeoTIFF for {area_type} {area_id}, year {year}")
            
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
            
            rusle_result = self.rusle_calc.compute_rusle(
                year, 
                simplified_geom, 
                scale=scale,
                compute_stats=True
            )
            
            # Clip to original geometry for accurate boundaries
            soil_loss_image = rusle_result['image'].clip(original_geom)
            statistics = rusle_result['statistics']
            
            # Define output path
            output_dir = self.storage_path / 'geotiff' / f'{area_type}_{area_id}' / str(year)
            output_dir.mkdir(parents=True, exist_ok=True)
            output_path = output_dir / 'erosion.tif'
            
            # Calculate area to determine export method
            area_km2 = complexity['area_km2']
            
            logger.info(f"Area: {area_km2:.2f} km²")
            
            if area_km2 < 2000:
                # Small/medium area - use getThumbURL for direct download
                logger.info("Using direct download method (getThumbURL)")
                self._download_small_area(
                    soil_loss_image, 
                    original_geom,  # Use original for accurate boundaries
                    output_path,
                    scale
                )
            else:
                # Large area - use simpler method with sampling
                logger.info("Using sampling method for large area")
                self._generate_from_samples(
                    soil_loss_image,
                    original_geom,  # Use original for accurate boundaries
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
                'area_km2': area_km2,
                'complexity': complexity['complexity_level'],
                'scale': scale,
                'simplify_tolerance': tolerance,
                'bbox': bbox,
                'original_geometry': geometry_json  # Store original geometry for tile masking
            }
            
            return str(output_path), statistics, metadata
            
        except Exception as e:
            logger.error(f"Failed to generate GeoTIFF: {str(e)}", exc_info=True)
            raise
    
    def _download_small_area(self, image, geometry, output_path, scale):
        """Download GeoTIFF for small/medium areas using getThumbURL"""
        try:
            # Get bounds
            bounds = geometry.bounds().getInfo()
            coords = bounds['coordinates'][0]
            lons = [c[0] for c in coords]
            lats = [c[1] for c in coords]
            
            bbox = [min(lons), min(lats), max(lons), max(lats)]
            
            # Calculate dimensions based on scale
            width_deg = bbox[2] - bbox[0]
            height_deg = bbox[3] - bbox[1]
            width_px = int((width_deg * 111000) / scale)  # ~111km per degree
            height_px = int((height_deg * 111000) / scale)
            
            # Limit dimensions to prevent timeout
            max_dim = 4096
            if width_px > max_dim or height_px > max_dim:
                scale_factor = max(width_px, height_px) / max_dim
                width_px = int(width_px / scale_factor)
                height_px = int(height_px / scale_factor)
            
            dimensions = f'{width_px}x{height_px}'
            
            logger.info(f"Requesting GeoTIFF: {dimensions} pixels")
            
            # Request GeoTIFF from GEE
            url = image.getThumbURL({
                'min': 0,
                'max': 200,
                'dimensions': dimensions,
                'region': geometry,
                'format': 'GEO_TIFF'
            })
            
            # Download
            logger.info("Downloading GeoTIFF from GEE...")
            response = requests.get(url, timeout=300)
            response.raise_for_status()
            
            # Save to file
            with open(output_path, 'wb') as f:
                f.write(response.content)
            
            logger.info(f"✓ Downloaded {len(response.content) / 1024 / 1024:.2f} MB")
            
        except Exception as e:
            logger.error(f"Download failed: {str(e)}")
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





