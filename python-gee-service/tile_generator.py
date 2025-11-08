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

logger = logging.getLogger(__name__)

class MapTileGenerator:
    """Generate XYZ map tiles from erosion rasters"""
    
    def __init__(self, storage_path='/var/www/rusle-icarda/storage/rusle-tiles'):
        self.storage_path = Path(storage_path)
        self.tile_size = 256
        
    def generate_tiles(self, geotiff_path, area_type, area_id, year, zoom_levels=None, geometry_json=None):
        """
        Generate XYZ map tiles from GeoTIFF or sampled data
        
        Args:
            geotiff_path: Path to GeoTIFF or .npy file
            area_type: 'region' or 'district'
            area_id: ID of the area
            year: Year
            zoom_levels: List of zoom levels to generate (default: [6, 7, 8, 9, 10, 11, 12])
            geometry_json: Optional GeoJSON geometry for boundary masking
            
        Returns:
            tiles_base_path: Path to tiles directory
        """
        try:
            logger.info(f"Generating map tiles for {area_type} {area_id}, year {year}")
            
            if zoom_levels is None:
                zoom_levels = [6, 7, 8, 9, 10, 11, 12]
            
            tiles_dir = self.storage_path / 'tiles' / f'{area_type}_{area_id}' / str(year)
            tiles_dir.mkdir(parents=True, exist_ok=True)
            
            geotiff_path = Path(geotiff_path)
            
            # Check if we have sampled data (.npy) or GeoTIFF
            npy_path = geotiff_path.with_suffix('.npy')
            
            if npy_path.exists():
                logger.info("Using sampled raster data")
                data, bounds, metadata = self._load_sampled_data(npy_path)
                projected_geometry = self._project_geometry_to_webmercator(geometry_json) if geometry_json else None
            else:
                logger.info("Using GeoTIFF data")
                try:
                    import rasterio
                    data, bounds = self._load_raster_data_webmercator(geotiff_path)
                    projected_geometry = self._project_geometry_to_webmercator(geometry_json) if geometry_json else None
                except ImportError:
                    logger.warning("rasterio not available, trying fallback method")
                    # Fallback: create simple placeholder tiles
                    return self._generate_placeholder_tiles(tiles_dir, None, zoom_levels)
            
            # Apply colormap
            logger.info("Applying erosion colormap...")
            colored_data = self.apply_erosion_colormap(data)
            
            # Generate tiles for each zoom level
            total_tiles = 0
            for zoom in zoom_levels:
                logger.info(f"Generating tiles for zoom level {zoom}...")
                tiles_generated = self._generate_tiles_for_zoom(
                    colored_data, bounds, tiles_dir, zoom, projected_geometry
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
        data = saved_data['data']
        bounds = saved_data['bounds']
        west, south = self._lonlat_to_webmercator(bounds[0], bounds[1])
        east, north = self._lonlat_to_webmercator(bounds[2], bounds[3])
        return data, [west, south, east, north], saved_data

    def _load_raster_data_webmercator(self, geotiff_path):
        """Load raster data and reproject to Web Mercator (EPSG:3857)."""
        import rasterio
        from rasterio.transform import array_bounds
        from rasterio.warp import calculate_default_transform, reproject, Resampling

        with rasterio.open(geotiff_path) as src:
            src_crs = src.crs or 'EPSG:4326'
            dst_crs = 'EPSG:3857'

            if rasterio.crs.CRS.from_user_input(src_crs) != rasterio.crs.CRS.from_user_input(dst_crs):
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
                data = src.read(1).astype(np.float32)
                transform = src.transform
                height, width = data.shape

        bounds = array_bounds(data.shape[0], data.shape[1], transform)
        return data, [bounds[0], bounds[1], bounds[2], bounds[3]]

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
    
    def _generate_tiles_for_zoom(self, colored_data, bounds, tiles_dir, zoom, geometry_json=None):
        """Generate all tiles for a specific zoom level"""
        tile_count = 0

        lon_min, lat_min = self._webmercator_to_lonlat(bounds[0], bounds[1])
        lon_max, lat_max = self._webmercator_to_lonlat(bounds[2], bounds[3])
        if lon_min > lon_max:
            lon_min, lon_max = lon_max, lon_min
        if lat_min > lat_max:
            lat_min, lat_max = lat_max, lat_min
        
        # Get all tiles that intersect the bounds at this zoom level
        tiles = list(mercantile.tiles(
            lon_min, lat_min,  # west, south
            lon_max, lat_max,  # east, north
            zooms=zoom
        ))
        
        data_height, data_width = colored_data.shape[:2]
        
        for tile in tiles:
            # Calculate tile bounds in lat/lon
            tile_bounds = mercantile.xy_bounds(tile)
            
            # Extract and render tile
            tile_image = self._render_tile(
                colored_data,
                bounds,
                tile_bounds,
                data_width,
                data_height,
                geometry_json  # Pass geometry for boundary masking
            )
            
            # Save tile
            tile_path = tiles_dir / str(tile.z) / str(tile.x)
            tile_path.mkdir(parents=True, exist_ok=True)
            
            output_file = tile_path / f'{tile.y}.png'
            tile_image.save(output_file, 'PNG', optimize=True)
            
            tile_count += 1
        
        return tile_count
    
    def _render_tile(self, colored_data, data_bounds, tile_bounds, data_width, data_height, geometry_json=None):
        """
        Render a single tile from the colored data with optional boundary masking
        
        Args:
            colored_data: RGBA numpy array
            data_bounds: [west, south, east, north] of data
            tile_bounds: Bounds object from mercantile
            data_width, data_height: Dimensions of source data
            geometry_json: Optional GeoJSON geometry for boundary masking
        """
        # Create blank tile
        tile = Image.new('RGBA', (self.tile_size, self.tile_size), (0, 0, 0, 0))
        
        # Calculate which part of the data maps to this tile
        west, south, east, north = data_bounds
        
        # Convert tile bounds to data coordinates
        data_west = (tile_bounds.left - west) / (east - west) * data_width
        data_east = (tile_bounds.right - west) / (east - west) * data_width
        data_north = (north - tile_bounds.top) / (north - south) * data_height
        data_south = (north - tile_bounds.bottom) / (north - south) * data_height
        
        # Clamp to data bounds
        data_west = max(0, min(data_width - 1, data_west))
        data_east = max(0, min(data_width - 1, data_east))
        data_north = max(0, min(data_height - 1, data_north))
        data_south = max(0, min(data_height - 1, data_south))
        
        # Check if tile intersects data
        if data_west >= data_east or data_north >= data_south:
            return tile
        
        # Extract relevant portion of data
        y1, y2 = int(data_north), int(data_south) + 1
        x1, x2 = int(data_west), int(data_east) + 1
        
        if y2 > y1 and x2 > x1:
            data_slice = colored_data[y1:y2, x1:x2]
            
            # Convert to PIL Image
            if data_slice.size > 0:
                img = Image.fromarray(data_slice, mode='RGBA')
                # Resize to tile size
                img = img.resize((self.tile_size, self.tile_size), Image.LANCZOS)
                
                # Apply boundary mask if geometry is provided
                if geometry_json:
                    mask = self._create_geometry_mask(geometry_json, tile_bounds, self.tile_size)
                    if mask:
                        # Apply mask: pixels outside geometry become transparent
                        img = Image.composite(img, Image.new('RGBA', img.size, (0, 0, 0, 0)), mask)
                
                return img
        
        return tile
    
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
            from shapely.ops import transform
            
            # Create tile polygon
            tile_polygon = box(
                tile_bounds.left, tile_bounds.bottom,
                tile_bounds.right, tile_bounds.top
            )
            
            # Convert GeoJSON to Shapely geometry
            geom = shape(geometry_json)
            
            # Check if tile intersects geometry
            if not tile_polygon.intersects(geom):
                # Tile is completely outside geometry
                return Image.new('L', (tile_size, tile_size), 0)  # All black (transparent)
            
            # Create mask by rasterizing geometry onto tile
            mask = Image.new('L', (tile_size, tile_size), 0)  # Start with all transparent
            draw = ImageDraw.Draw(mask)
            
            # Transform geometry coordinates to pixel coordinates
            # Tile bounds: [west, south, east, north]
            # Pixel coordinates: [0, 0] to [tile_size, tile_size]
            lon_range = tile_bounds.right - tile_bounds.left
            lat_range = tile_bounds.top - tile_bounds.bottom
            
            def transform_coords(lon, lat):
                """Transform lon/lat to pixel coordinates"""
                x = int(((lon - tile_bounds.left) / lon_range) * tile_size)
                y = int(((tile_bounds.top - lat) / lat_range) * tile_size)
                return x, y
            
            # Extract coordinates from geometry and draw polygon
            if geom.geom_type == 'Polygon':
                coords = geom.exterior.coords
                pixels = [transform_coords(lon, lat) for lon, lat in coords]
                if len(pixels) > 2:
                    draw.polygon(pixels, fill=255)  # White = inside geometry
                    
                    # Fill holes (interiors)
                    for interior in geom.interiors:
                        hole_coords = interior.coords
                        hole_pixels = [transform_coords(lon, lat) for lon, lat in hole_coords]
                        if len(hole_pixels) > 2:
                            draw.polygon(hole_pixels, fill=0)  # Black = outside (hole)
            
            elif geom.geom_type == 'MultiPolygon':
                for polygon in geom.geoms:
                    coords = polygon.exterior.coords
                    pixels = [transform_coords(lon, lat) for lon, lat in coords]
                    if len(pixels) > 2:
                        draw.polygon(pixels, fill=255)
                        
                        # Fill holes
                        for interior in polygon.interiors:
                            hole_coords = interior.coords
                            hole_pixels = [transform_coords(lon, lat) for lon, lat in hole_coords]
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
        
        # Low erosion (green)
        mask = (data > 0) & (data < 50)
        colored[mask] = [76, 175, 80, 200]  # Green
        
        # Medium erosion (yellow)
        mask = (data >= 50) & (data < 100)
        colored[mask] = [255, 235, 59, 200]  # Yellow
        
        # High erosion (orange)
        mask = (data >= 100) & (data < 150)
        colored[mask] = [255, 152, 0, 200]  # Orange
        
        # Very high erosion (red)
        mask = data >= 150
        colored[mask] = [244, 67, 54, 220]  # Red
        
        # No data / transparent
        colored[data <= 0] = [0, 0, 0, 0]
        
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





