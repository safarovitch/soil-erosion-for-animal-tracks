"""
Map Tile Generator for Erosion Visualization
Generates PNG map tiles in XYZ format from GeoTIFF rasters
"""
from PIL import Image, ImageDraw
import numpy as np
import mercantile
import logging
from pathlib import Path
import math
from affine import Affine

logger = logging.getLogger(__name__)

class MapTileGenerator:
    """Generate XYZ map tiles from erosion rasters"""
    
    def __init__(self, storage_path='/var/www/rusle-icarda/storage/rusle-tiles'):
        self.storage_path = Path(storage_path)
        self.tile_size = 256
        
    def generate_tiles(self, geotiff_path, area_type, area_id, year, zoom_levels=None, geometry_json=None, end_year=None):
        """
        Generate XYZ map tiles from GeoTIFF or sampled data
        
        Args:
            geotiff_path: Path to GeoTIFF or .npy file
            area_type: 'region' or 'district'
            area_id: ID of the area
            year: Year (start year if range)
            end_year: Optional inclusive end year
            zoom_levels: List of zoom levels to generate (default: [6, 7, 8, 9, 10])
            geometry_json: Optional GeoJSON geometry for boundary masking
            
        Returns:
            tiles_base_path: Path to tiles directory
        """
        try:
            end_year = end_year if end_year is not None else year
            period_label = str(year) if end_year == year else f"{year}-{end_year}"
            
            logger.info(f"Generating map tiles for {area_type} {area_id}, period {period_label}")
            
            if zoom_levels is None:
                zoom_levels = [6, 7, 8, 9, 10]
            
            tiles_dir = self.storage_path / 'tiles' / f'{area_type}_{area_id}' / period_label
            tiles_dir.mkdir(parents=True, exist_ok=True)
            
            geotiff_path = Path(geotiff_path)
            
            # Check if we have sampled data (.npy) or GeoTIFF
            npy_path = geotiff_path.with_suffix('.npy')
            
            if npy_path.exists():
                logger.info("Using sampled raster data")
                data, transform, bounds, metadata = self._load_sampled_data(npy_path)
                projected_geometry = self._project_geometry_to_webmercator(geometry_json) if geometry_json else None
            else:
                logger.info("Using GeoTIFF data")
                try:
                    import rasterio
                    data, transform, bounds = self._load_raster_data_webmercator(geotiff_path)
                    projected_geometry = self._project_geometry_to_webmercator(geometry_json) if geometry_json else None
                except ImportError:
                    logger.warning("rasterio not available, trying fallback method")
                    # Fallback: create simple placeholder tiles
                    return self._generate_placeholder_tiles(tiles_dir, None, zoom_levels)
            
            # Generate tiles for each zoom level
            total_tiles = 0
            for zoom in zoom_levels:
                logger.info(f"Generating tiles for zoom level {zoom}...")
                tiles_generated = self._generate_tiles_for_zoom(
                    data, transform, bounds, tiles_dir, zoom, projected_geometry
                )
                total_tiles += tiles_generated
                logger.info(f"  ✓ Generated {tiles_generated} tiles at zoom {zoom}")
            
            logger.info(f"✓ Total tiles generated: {total_tiles}")
            
            return str(tiles_dir)
            
        except Exception as e:
            logger.error(f"Failed to generate tiles: {str(e)}", exc_info=True)
            raise
    
    def _load_sampled_data(self, npy_path):
        """Load sampled raster data from pickle file"""
        import pickle
        with open(npy_path, 'rb') as f:
            saved_data = pickle.load(f)
        from rasterio.transform import from_bounds

        data = saved_data['data']
        bounds = saved_data['bounds']
        west, south = self._lonlat_to_webmercator(bounds[0], bounds[1])
        east, north = self._lonlat_to_webmercator(bounds[2], bounds[3])

        height, width = data.shape
        transform = from_bounds(west, south, east, north, width, height)

        return data, transform, [west, south, east, north], saved_data

    def _load_raster_data_webmercator(self, geotiff_path):
        """
        Load raster data and reproject to Web Mercator (EPSG:3857).
        
        Note: For optimal performance, ensure GEE exports use EPSG:3857:
        Export.image.toDrive({
          image: RUSLE,
          scale: 500,
          crs: 'EPSG:3857',  // CRITICAL - ensures raster is in Web Mercator
          region: geometry,
          maxPixels: 1e13
        });
        """
        import rasterio
        from rasterio.transform import array_bounds
        from rasterio.warp import calculate_default_transform, reproject, Resampling

        with rasterio.open(geotiff_path) as src:
            src_crs = src.crs or 'EPSG:4326'
            dst_crs = 'EPSG:3857'

            # Validate CRS and ensure Web Mercator
            src_crs_obj = rasterio.crs.CRS.from_user_input(src_crs)
            dst_crs_obj = rasterio.crs.CRS.from_user_input(dst_crs)
            
            if src_crs_obj != dst_crs_obj:
                logger.warning(
                    f"GeoTIFF is not in Web Mercator (EPSG:3857). "
                    f"Current CRS: {src_crs}. Reprojecting to {dst_crs}. "
                    f"For better performance, export from GEE with crs: 'EPSG:3857'."
                )
                logger.info(f"Reprojecting GeoTIFF from {src_crs} to {dst_crs}")
                transform, width, height = calculate_default_transform(
                    src_crs,
                    dst_crs,
                    src.width,
                    src.height,
                    *src.bounds
                )
                data = np.zeros((height, width), dtype=np.float32)
                reproject(
                    source=rasterio.band(src, 1),
                    destination=data,
                    src_transform=src.transform,
                    src_crs=src_crs,
                    dst_transform=transform,
                    dst_crs=dst_crs,
                    resampling=Resampling.bilinear,
                    dst_nodata=0,
                )
            else:
                # Already in Web Mercator (EPSG:3857) - optimal case
                logger.debug(f"GeoTIFF is already in Web Mercator (EPSG:3857) - no reprojection needed")
                data = src.read(1).astype(np.float32)
                transform = src.transform

        if not isinstance(transform, Affine):
            transform = Affine(*transform)

        if transform.e > 0:
            logger.warning(
                "Raster transform has positive Y scale; flipping data vertically to maintain north-up orientation."
            )
            data = np.flipud(data)
            transform = transform * Affine.translation(0, data.shape[0]) * Affine.scale(1, -1)

        bounds = array_bounds(data.shape[0], data.shape[1], transform)
        # array_bounds returns (minx, miny, maxx, maxy) in Web Mercator meters
        # For Web Mercator: minx=west, maxx=east, miny=south, maxy=north
        # Return as [west, south, east, north]
        # Note: In Web Mercator, Y increases northward, so miny < maxy for northern hemisphere
        west, south, east, north = bounds[0], bounds[1], bounds[2], bounds[3]
        
        # Verify bounds are in correct order
        if west >= east or south >= north:
            logger.error(f"Invalid bounds from rasterio: west={west}, east={east}, south={south}, north={north}")
            # Try to fix by swapping if needed
            if west > east:
                west, east = east, west
            if south > north:
                south, north = north, south
        
        return data, transform, [west, south, east, north]

    def _project_geometry_to_webmercator(self, geometry_json):
        """Project GeoJSON geometry coordinates to Web Mercator."""
        if not geometry_json:
            return None

        def project_coords(coords):
            projected = []
            for lon, lat in coords:
                x, y = self._lonlat_to_webmercator(lon, lat)
                projected.append([x, y])
            return projected

        geom_type = geometry_json.get('type')
        if geom_type == 'Polygon':
            return {
                'type': 'Polygon',
                'coordinates': [project_coords(ring) for ring in geometry_json.get('coordinates', [])]
            }
        if geom_type == 'MultiPolygon':
            return {
                'type': 'MultiPolygon',
                'coordinates': [
                    [project_coords(ring) for ring in polygon]
                    for polygon in geometry_json.get('coordinates', [])
                ]
            }
        return geometry_json

    def _lonlat_to_webmercator(self, lon, lat):
        """Convert longitude/latitude in degrees to Web Mercator meters."""
        origin_shift = 20037508.342789244
        x = lon * origin_shift / 180.0
        lat = max(min(lat, 89.9999), -89.9999)
        y = math.log(math.tan((90 + lat) * math.pi / 360.0)) * origin_shift / math.pi
        return x, y

    def _webmercator_to_lonlat(self, x, y):
        """Convert Web Mercator meters to longitude/latitude in degrees."""
        origin_shift = 20037508.342789244
        lon = (x / origin_shift) * 180.0
        lat = (y / origin_shift) * 180.0
        lat = 180 / math.pi * (2 * math.atan(math.exp(lat * math.pi / 180.0)) - math.pi / 2)
        return lon, lat
    
    def _generate_tiles_for_zoom(self, data, transform, bounds, tiles_dir, zoom, geometry_json=None):
        """Generate all tiles for a specific zoom level"""
        west, south, east, north = bounds  # Web Mercator meters
        
        logger.debug(
            "Tile generation bounds (Web Mercator): west=%.2f, east=%.2f, south=%.2f, north=%.2f",
            west, east, south, north
        )
        
        tile_count = 0
        for tile in self._iter_tiles_covering_bounds(bounds, zoom):
            tile_bounds = mercantile.xy_bounds(tile)  # Web Mercator meters

            tile_image = self._render_tile(
                data,
                bounds,
                tile_bounds,
                transform,
                geometry_json
            )

            if tile_image is None:
                continue

            tile_path = tiles_dir / str(zoom) / str(tile.x)
            tile_path.mkdir(parents=True, exist_ok=True)
            tile_image.save(tile_path / f'{tile.y}.png', 'PNG', optimize=True)
            tile_count += 1
        
        return tile_count
    
    def _render_tile(self, data, data_bounds, tile_bounds, transform, geometry_json=None):
        """
        Render a single tile from the scalar data with optional boundary masking
        
        Args:
            data: 2D numpy array of erosion values
            data_bounds: [west, south, east, north] of data (Web Mercator)
            tile_bounds: Bounds object from mercantile
            transform: Affine transform for the source raster (Web Mercator)
            geometry_json: Optional GeoJSON geometry for boundary masking (Web Mercator)
        """
        from rasterio.transform import from_bounds
        from rasterio.warp import reproject, Resampling

        west, south, east, north = data_bounds
        if west >= east or south >= north:
            logger.warning(f"Invalid data bounds: west={west}, east={east}, south={south}, north={north}")
            return None

        # Quick overlap check
        if (
            tile_bounds.right <= west or
            tile_bounds.left >= east or
            tile_bounds.top <= south or
            tile_bounds.bottom >= north
        ):
            return None

        if data.ndim != 2:
            logger.warning(f"Unexpected data shape for scalar raster: {data.shape}")
            return None

        src_data = np.expand_dims(data.astype(np.float32, copy=False), axis=0)  # (1, height, width)
        dst_data = np.full((1, self.tile_size, self.tile_size), np.nan, dtype=np.float32)

        dst_transform = from_bounds(
            tile_bounds.left,
            tile_bounds.bottom,
            tile_bounds.right,
            tile_bounds.top,
            self.tile_size,
            self.tile_size
        )

        try:
            reproject(
                source=src_data,
                destination=dst_data,
                src_transform=transform,
                src_crs='EPSG:3857',
                dst_transform=dst_transform,
                dst_crs='EPSG:3857',
                resampling=Resampling.bilinear,
                dst_nodata=np.nan,
                num_threads=1
            )
        except Exception as exc:
            logger.warning(f"Failed to reproject tile (x={tile_bounds.left}, y={tile_bounds.top}): {exc}")
            return None

        tile_scalar = dst_data[0]

        if not np.isfinite(tile_scalar).any():
            return None

        with np.errstate(invalid='ignore'):
            tile_scalar[tile_scalar < 0] = 0

        if not np.any(tile_scalar > 0):
            return None

        colored_tile = self.apply_erosion_colormap(tile_scalar)

        # Skip tiles with no visible pixels
        if not np.any(colored_tile[:, :, 3]):
            return None

        img = Image.fromarray(colored_tile, 'RGBA')

        if geometry_json:
            mask = self._create_geometry_mask(geometry_json, tile_bounds, self.tile_size)
            if mask:
                img = Image.composite(img, Image.new('RGBA', (self.tile_size, self.tile_size), (0, 0, 0, 0)), mask)

        return img
    
    def _iter_tiles_covering_bounds(self, bounds, zoom):
        """Yield mercantile tiles covering the provided Web Mercator bounds."""
        west, south, east, north = bounds
        lon_west, lat_south = self._webmercator_to_lonlat(west, south)
        lon_east, lat_north = self._webmercator_to_lonlat(east, north)

        lon_min = min(lon_west, lon_east)
        lon_max = max(lon_west, lon_east)
        lat_min = min(lat_south, lat_north)
        lat_max = max(lat_south, lat_north)

        padding = 1e-9  # Ensure coverage when bounds align exactly on tile edges
        lon_min -= padding
        lat_min -= padding
        lon_max += padding
        lat_max += padding

        return mercantile.tiles(lon_min, lat_min, lon_max, lat_max, [zoom], truncate=True)
    
    def _create_geometry_mask(self, geometry_json, tile_bounds, tile_size):
        """
        Create a mask for the tile based on geometry boundaries
        
        Args:
            geometry_json: GeoJSON geometry
            tile_bounds: Bounds object from mercantile
            tile_size: Size of tile in pixels
            
        Returns:
            PIL Image mask (L mode, white=inside, black=outside)
        """
        try:
            from shapely.geometry import shape, box
            
            tile_polygon = box(
                tile_bounds.left, tile_bounds.bottom,
                tile_bounds.right, tile_bounds.top
            )
            
            geom = shape(geometry_json)
            
            if not tile_polygon.intersects(geom):
                return Image.new('L', (tile_size, tile_size), 0)
            
            mask = Image.new('L', (tile_size, tile_size), 0)
            draw = ImageDraw.Draw(mask)
            
            x_range = tile_bounds.right - tile_bounds.left
            y_range = tile_bounds.top - tile_bounds.bottom
            
            def to_pixel(x, y):
                px = int(((x - tile_bounds.left) / x_range) * tile_size)
                py = int(((tile_bounds.top - y) / y_range) * tile_size)
                return px, py

            if geom.geom_type == 'Polygon':
                coords = geom.exterior.coords
                pixels = [to_pixel(x, y) for x, y in coords]
                if len(pixels) > 2:
                    draw.polygon(pixels, fill=255)
                    for interior in geom.interiors:
                        hole_pixels = [to_pixel(x, y) for x, y in interior.coords]
                        if len(hole_pixels) > 2:
                            draw.polygon(hole_pixels, fill=0)
            elif geom.geom_type == 'MultiPolygon':
                for polygon in geom.geoms:
                    pixels = [to_pixel(x, y) for x, y in polygon.exterior.coords]
                    if len(pixels) > 2:
                        draw.polygon(pixels, fill=255)
                        for interior in polygon.interiors:
                            hole_pixels = [to_pixel(x, y) for x, y in interior.coords]
                            if len(hole_pixels) > 2:
                                draw.polygon(hole_pixels, fill=0)
            
            return mask
            
        except ImportError:
            logger.warning("shapely not available, skipping boundary masking")
            return None
        except Exception as e:
            logger.warning(f"Failed to create geometry mask: {str(e)}")
            return None
    
    def apply_erosion_colormap(self, data):
        """
        Apply color scheme to erosion data:
        - Green: 0-50 t/ha/yr (low)
        - Yellow: 50-100 t/ha/yr (medium)
        - Orange: 100-150 t/ha/yr (high)
        - Red: 150+ t/ha/yr (very high)
        
        Args:
            data: 2D numpy array of erosion values
            
        Returns:
            RGBA numpy array
        """
        colored = np.zeros((data.shape[0], data.shape[1], 4), dtype=np.uint8)
        
        # Create mask for nodata values (nodata=0 or NaN)
        # GeoTIFF with nodata=0 should have these pixels masked
        nodata_mask = (data <= 0) | np.isnan(data)
        
        # Low erosion (green)
        mask = (data > 0) & (data < 50) & ~nodata_mask
        colored[mask] = [76, 175, 80, 200]  # Green
        
        # Medium erosion (yellow)
        mask = (data >= 50) & (data < 100) & ~nodata_mask
        colored[mask] = [255, 235, 59, 200]  # Yellow
        
        # High erosion (orange)
        mask = (data >= 100) & (data < 150) & ~nodata_mask
        colored[mask] = [255, 152, 0, 200]  # Orange
        
        # Very high erosion (red)
        mask = (data >= 150) & ~nodata_mask
        colored[mask] = [244, 67, 54, 220]  # Red
        
        # No data / transparent - ensure nodata pixels are transparent
        colored[nodata_mask] = [0, 0, 0, 0]
        
        return colored
    
    def _generate_placeholder_tiles(self, tiles_dir, bounds, zoom_levels):
        """Generate simple placeholder tiles when rasterio is not available"""
        logger.warning("Generating placeholder tiles (rasterio not available)")
        
        for zoom in zoom_levels:
            # Just create a few placeholder tiles
            tile_path = tiles_dir / str(zoom) / '0'
            tile_path.mkdir(parents=True, exist_ok=True)
            
            # Create a simple colored tile
            img = Image.new('RGBA', (self.tile_size, self.tile_size), (255, 200, 0, 180))
            img.save(tile_path / '0.png', 'PNG')
        
        return str(tiles_dir)





