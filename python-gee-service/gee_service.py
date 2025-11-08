"""
Google Earth Engine Service
Handles EE initialization, authentication, and core operations
"""
import ee
import logging
import threading
from pathlib import Path

from config import Config

logger = logging.getLogger(__name__)

class TimeoutError(Exception):
    """Timeout exception for GEE operations"""
    pass

def timeout_wrapper(func, timeout_seconds=None):
    """
    Wrapper to timeout long-running GEE API calls
    """
    if timeout_seconds is None:
        timeout_seconds = Config.GEE_API_TIMEOUT
    
    result = [None]
    exception = [None]
    
    def target():
        try:
            result[0] = func()
        except Exception as e:
            exception[0] = e
    
    thread = threading.Thread(target=target)
    thread.daemon = True
    thread.start()
    thread.join(timeout_seconds)
    
    if thread.is_alive():
        logger.error(f"Operation timed out after {timeout_seconds} seconds")
        raise TimeoutError(f"Operation timed out after {timeout_seconds} seconds")
    
    if exception[0]:
        raise exception[0]
    
    return result[0]

class GEEService:
    """Google Earth Engine Service"""
    
    def __init__(self):
        self.initialized = False
        self.project_id = None
        
    def initialize(self):
        """Initialize Earth Engine with service account credentials"""
        try:
            logger.info("=== Starting Earth Engine Initialization ===")
            
            # Validate configuration
            logger.info("Step 1: Validating configuration...")
            Config.validate()
            logger.info(f"  ✓ Project ID: {Config.GEE_PROJECT_ID}")
            logger.info(f"  ✓ Service Account: {Config.GEE_SERVICE_ACCOUNT_EMAIL}")
            logger.info(f"  ✓ Private Key Path: {Config.GEE_PRIVATE_KEY_PATH}")
            
            # Load service account credentials
            logger.info("Step 2: Loading service account credentials...")
            credentials = ee.ServiceAccountCredentials(
                email=Config.GEE_SERVICE_ACCOUNT_EMAIL,
                key_file=Config.GEE_PRIVATE_KEY_PATH
            )
            logger.info("  ✓ Credentials loaded successfully")
            
            # Initialize Earth Engine
            logger.info("Step 3: Initializing Earth Engine client library...")
            ee.Initialize(
                credentials=credentials,
                project=Config.GEE_PROJECT_ID
            )
            logger.info("  ✓ Earth Engine client library initialized")
            
            # Test that EE actually works by performing a simple operation
            logger.info("Step 4: Testing Earth Engine with sample operation...")
            test_image = ee.Image('USGS/SRTMGL1_003')
            test_info = test_image.getInfo()
            logger.info(f"  ✓ Test successful - DEM has {len(test_info.get('bands', []))} band(s)")
            
            self.initialized = True
            self.project_id = Config.GEE_PROJECT_ID
            
            logger.info("=== ✓ Earth Engine Initialized Successfully ===")
            return True
            
        except FileNotFoundError as e:
            logger.error(f"✗ Private key file not found: {str(e)}", exc_info=True)
            logger.error(f"  Check that {Config.GEE_PRIVATE_KEY_PATH} exists and is readable")
            raise
        except Exception as e:
            error_msg = str(e)
            logger.error(f"✗ Failed to initialize Earth Engine: {error_msg}", exc_info=True)
            
            # Provide helpful troubleshooting tips based on error type
            if "PERMISSION_DENIED" in error_msg or "permission" in error_msg.lower():
                logger.error("  Troubleshooting: Service account lacks required permissions")
                logger.error("  Required roles:")
                logger.error("    - roles/earthengine.writer (for computations)")
                logger.error("    - roles/serviceusage.serviceUsageConsumer (for API access)")
                logger.error(f"  Visit: https://console.cloud.google.com/iam-admin/iam/project?project={Config.GEE_PROJECT_ID}")
            elif "not enabled" in error_msg.lower() or "API" in error_msg:
                logger.error("  Troubleshooting: Earth Engine API may not be enabled")
                logger.error(f"  Visit: https://console.cloud.google.com/apis/library/earthengine.googleapis.com?project={Config.GEE_PROJECT_ID}")
            elif "credentials" in error_msg.lower() or "authentication" in error_msg.lower():
                logger.error("  Troubleshooting: Invalid service account credentials")
                logger.error("    - Verify the private key JSON file is valid")
                logger.error("    - Ensure service account email matches the key file")
            
            raise
    
    def is_initialized(self):
        """Check if Earth Engine is initialized"""
        return self.initialized
    
    def get_health_status(self):
        """Get health status of GEE service"""
        try:
            if not self.initialized:
                return {
                    'status': 'not_initialized',
                    'message': 'Earth Engine not initialized'
                }
            
            # Test with a simple EE operation
            test_image = ee.Image(1)
            test_image.getInfo()
            
            return {
                'status': 'healthy',
                'message': 'Earth Engine is operational',
                'project_id': self.project_id
            }
        except Exception as e:
            return {
                'status': 'error',
                'message': str(e)
            }
    
    def geometry_from_geojson(self, geojson):
        """Convert GeoJSON to Earth Engine Geometry"""
        try:
            return ee.Geometry(geojson)
        except Exception as e:
            logger.error(f"Failed to convert GeoJSON to EE Geometry: {str(e)}")
            raise ValueError(f"Invalid GeoJSON geometry: {str(e)}")
    
    def calculate_bbox(self, geometry):
        """Calculate bounding box from geometry"""
        try:
            bounds = geometry.bounds().getInfo()
            coords = bounds['coordinates'][0]
            
            # Extract min/max coordinates
            lons = [c[0] for c in coords]
            lats = [c[1] for c in coords]
            
            return {
                'min_lon': min(lons),
                'min_lat': min(lats),
                'max_lon': max(lons),
                'max_lat': max(lats)
            }
        except Exception as e:
            logger.error(f"Failed to calculate bounding box: {str(e)}")
            raise
    
    def compute_statistics(self, image, geometry, scale=30, timeout_seconds=None):
        """Compute statistics for an image over a geometry"""
        try:
            # Use config timeout if not specified
            if timeout_seconds is None:
                timeout_seconds = Config.GEE_API_TIMEOUT
            
            # Build the reduceRegion operation
            reducer = ee.Reducer.mean().combine(
                reducer2=ee.Reducer.min(), sharedInputs=True
            ).combine(
                reducer2=ee.Reducer.max(), sharedInputs=True
            ).combine(
                reducer2=ee.Reducer.stdDev(), sharedInputs=True
            )
            
            reduced = image.reduceRegion(
                reducer=reducer,
                geometry=geometry,
                scale=scale,
                maxPixels=1e9,
                bestEffort=True
            )
            
            # Wrap getInfo() call with timeout
            def get_stats():
                return reduced.getInfo()
            
            stats = timeout_wrapper(get_stats, timeout_seconds=timeout_seconds)
            
            return stats
        except Exception as e:
            logger.error(f"Failed to compute statistics: {str(e)}")
            raise
    
    def calculate_area_km2(self, geometry):
        """Calculate area in square kilometers"""
        try:
            # Use Earth Engine's area calculation (accurate geodesic)
            area_m2 = geometry.area(maxError=100).getInfo()
            area_km2 = area_m2 / 1000000  # Convert m² to km²
            return area_km2
        except Exception as e:
            logger.warning(f"Failed to calculate area via GEE: {str(e)}")
            # Fallback to approximate calculation
            return self._approximate_area_km2(geometry)
    
    def _approximate_area_km2(self, geometry):
        """Approximate area calculation (fallback)"""
        try:
            bounds = geometry.bounds().getInfo()
            coords = bounds['coordinates'][0]
            lons = [c[0] for c in coords]
            lats = [c[1] for c in coords]
            
            # Simple bounding box area (rough approximation)
            width_deg = max(lons) - min(lons)
            height_deg = max(lats) - min(lats)
            
            # At ~38°N (Tajikistan), 1° ≈ 85km longitude, 111km latitude
            width_km = width_deg * 85
            height_km = height_deg * 111
            
            return width_km * height_km
        except:
            return 1000  # Default to 1000 km² if all fails
    
    def count_coordinates(self, geojson):
        """Count total coordinates in a GeoJSON geometry"""
        total = 0
        
        try:
            if geojson['type'] == 'Polygon':
                for ring in geojson.get('coordinates', []):
                    total += len(ring)
            elif geojson['type'] == 'MultiPolygon':
                for polygon in geojson.get('coordinates', []):
                    for ring in polygon:
                        total += len(ring)
            else:
                # Point, LineString, etc.
                coords = geojson.get('coordinates', [])
                if isinstance(coords, list):
                    total = len(coords)
        except Exception as e:
            logger.warning(f"Failed to count coordinates: {str(e)}")
            total = 0
        
        return total
    
    def sample_image_to_grid(self, image, geometry, grid_size=50, scale=5000):
        """
        Sample an image to create a grid of pixel values for visualization
        
        Args:
            image: Earth Engine Image to sample
            geometry: Earth Engine geometry defining the region
            grid_size: Number of grid cells (grid_size x grid_size)
            scale: Scale in meters for sampling
            
        Returns:
            dict: Grid data with cells containing pixel values
        """
        try:
            # Get bounding box
            bbox = self.calculate_bbox(geometry)
            min_lon = bbox['min_lon']
            min_lat = bbox['min_lat']
            max_lon = bbox['max_lon']
            max_lat = bbox['max_lat']
            
            # Calculate cell dimensions
            cell_width = (max_lon - min_lon) / grid_size
            cell_height = (max_lat - min_lat) / grid_size
            
            # Sample image at grid points
            logger.info(f"Sampling {grid_size}x{grid_size} grid...")
            cells = []
            sample_points = []
            
            # Create sample points at cell centers
            for i in range(grid_size):
                for j in range(grid_size):
                    x = min_lon + (i + 0.5) * cell_width
                    y = min_lat + (j + 0.5) * cell_height
                    
                    point = ee.Geometry.Point([x, y])
                    sample_points.append(point)
                    
                    # Store cell metadata
                    cells.append({
                        'x': i,
                        'y': j,
                        'point_index': len(sample_points) - 1,
                        'bbox': [
                            min_lon + i * cell_width,
                            min_lat + j * cell_height,
                            min_lon + (i + 1) * cell_width,
                            min_lat + (j + 1) * cell_height
                        ]
                    })
            
            # Sample all points in batch
            logger.info(f"Sampling {len(sample_points)} points from image...")
            sample_collection = ee.FeatureCollection([
                ee.Feature(point, {}) for point in sample_points
            ])
            
            sampled = image.reduceRegions(
                collection=sample_collection,
                reducer=ee.Reducer.first(),
                scale=scale
            ).getInfo()
            
            # Extract values and assign to cells
            logger.info("Processing sampled values...")
            band_name = image.bandNames().getInfo()[0]
            
            for i, cell in enumerate(cells):
                feature = sampled['features'][i]
                value = feature['properties'].get(band_name, None)
                cell['value'] = float(value) if value is not None else None
                
                # Add geometry for frontend
                x1, y1, x2, y2 = cell['bbox']
                cell['geometry'] = {
                    'type': 'Polygon',
                    'coordinates': [[
                        [x1, y1],
                        [x2, y1],
                        [x2, y2],
                        [x1, y2],
                        [x1, y1]
                    ]]
                }
            
            # Calculate statistics
            values = [cell['value'] for cell in cells if cell['value'] is not None]
            if values:
                import numpy as np
                stats = {
                    'mean': float(np.mean(values)),
                    'min': float(np.min(values)),
                    'max': float(np.max(values)),
                    'std_dev': float(np.std(values))
                }
            else:
                stats = {'mean': 0, 'min': 0, 'max': 0, 'std_dev': 0}
            
            logger.info(f"Sampled {len(values)} valid cells")
            
            return {
                'cells': cells,
                'statistics': stats,
                'grid_size': grid_size,
                'bbox': [min_lon, min_lat, max_lon, max_lat]
            }
            
        except Exception as e:
            logger.error(f"Failed to sample image to grid: {str(e)}", exc_info=True)
            raise
    
    def analyze_geometry_complexity(self, geojson, geometry):
        """
        Analyze geometry complexity to determine optimal processing parameters
        Returns dict with complexity metrics and recommended settings
        """
        coord_count = self.count_coordinates(geojson)
        
        # Try to calculate area (may be slow for complex geometries)
        try:
            area_km2 = self.calculate_area_km2(geometry)
        except:
            area_km2 = 1000  # Default assumption
        
        # Determine complexity level
        is_large_area = area_km2 > Config.LARGE_AREA_THRESHOLD_KM2
        is_complex = coord_count > Config.COMPLEX_GEOMETRY_THRESHOLD
        
        # Recommend processing parameters based on complexity
        if is_large_area and is_complex:
            # Very complex: Tajikistan-sized MultiPolygon
            recommended = {
                'simplify_tolerance': 2000,  # 2km
                'rusle_scale': 300,          # 300m
                'sample_scale': 200,         # 200m
                'grid_size': 5,              # 5x5 grid
                'max_samples': 25,           # Sample only 25 points
                'batch_size': 50,
                'max_workers': 8
            }
        elif is_complex:
            # Complex but smaller
            recommended = {
                'simplify_tolerance': 1000,  # 1km
                'rusle_scale': 200,          # 200m
                'sample_scale': 150,         # 150m
                'grid_size': 7,              # 7x7 grid
                'max_samples': 49,
                'batch_size': 50,
                'max_workers': 6
            }
        elif is_large_area:
            # Large but simple
            recommended = {
                'simplify_tolerance': 1000,  # 1km
                'rusle_scale': 200,          # 200m
                'sample_scale': 150,         # 150m
                'grid_size': 7,              # 7x7 grid
                'max_samples': 50,
                'batch_size': 50,
                'max_workers': 6
            }
        else:
            # Small and simple: use high quality
            recommended = {
                'simplify_tolerance': 500,   # 500m
                'rusle_scale': 100,          # 100m
                'sample_scale': 100,         # 100m
                'grid_size': 10,             # 10x10 grid
                'max_samples': 100,
                'batch_size': 30,
                'max_workers': 4
            }
        
        return {
            'coord_count': coord_count,
            'area_km2': round(area_km2, 2),
            'is_large_area': is_large_area,
            'is_complex': is_complex,
            'complexity_level': 'very_high' if (is_large_area and is_complex) else 
                              'high' if is_complex else 
                              'medium' if is_large_area else 'low',
            'recommended': recommended
        }

# Global GEE service instance
gee_service = GEEService()

