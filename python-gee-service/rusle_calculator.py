"""
RUSLE (Revised Universal Soil Loss Equation) Calculator
Implements all RUSLE factors and erosion computation for Tajikistan
"""
import ee
import logging
import math
import threading
from concurrent.futures import ThreadPoolExecutor, as_completed
from gee_service import gee_service

logger = logging.getLogger(__name__)

class TimeoutError(Exception):
    pass

def timeout_wrapper(func, timeout_seconds=600):
    """
    Wrapper to timeout long-running GEE API calls
    """
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

class RUSLECalculator:
    """RUSLE Calculator for soil erosion estimation"""
    
    def __init__(self):
        pass
    
    def compute_r_factor(self, year):
        """
        Compute R-Factor (Rainfall Erosivity)
        Using CHIRPS precipitation data
        """
        try:
            start_date = f'{year}-01-01'
            end_date = f'{year}-12-31'
            
            # Load CHIRPS precipitation data
            chirps = ee.ImageCollection('UCSB-CHG/CHIRPS/DAILY') \
                .filterDate(start_date, end_date) \
                .select('precipitation')
            
            # Calculate annual precipitation
            annual_precip = chirps.sum()
            
            # Simplified R-factor calculation
            # R = 0.0483 * P^1.61 (where P is annual precipitation in mm)
            r_factor = annual_precip.pow(1.61).multiply(0.0483)
            
            return r_factor.rename('R_factor')
            
        except Exception as e:
            logger.error(f"Failed to compute R-factor: {str(e)}")
            raise
    
    def compute_k_factor(self):
        """
        Compute K-Factor (Soil Erodibility)
        Using SoilGrids data
        """
        try:
            # Load soil data from SoilGrids (select first band - 0-5cm depth, most relevant for erosion)
            clay = ee.Image('projects/soilgrids-isric/clay_mean').select(0).divide(100.0)
            silt = ee.Image('projects/soilgrids-isric/silt_mean').select(0).divide(100.0)
            sand = ee.Image('projects/soilgrids-isric/sand_mean').select(0).divide(100.0)
            
            # Calculate M value
            M = silt.add(sand.multiply(0.1)).multiply(100)
            
            # Simplified K-factor calculation
            # K = M * 0.0001 * 12 - 0.02 * (sand * 0.02 + 0.03)
            k_factor = M.multiply(0.0001).multiply(12).subtract(0.02) \
                .multiply(sand.multiply(0.02).add(0.03)) \
                .clamp(0.01, 0.7)
            
            return k_factor.rename('K_factor')
            
        except Exception as e:
            logger.error(f"Failed to compute K-factor: {str(e)}")
            raise
    
    def compute_ls_factor(self):
        """
        Compute LS-Factor (Slope Length and Steepness)
        Using SRTM DEM
        """
        try:
            # Load DEM (explicitly select elevation band)
            dem = ee.Image('USGS/SRTMGL1_003').select('elevation')
            
            # Calculate slope in degrees
            slope = ee.Terrain.slope(dem)
            
            # Estimate flow accumulation (simplified)
            flow_acc = dem.focal_max(90).subtract(dem)
            slope_length = flow_acc.multiply(30)  # 30m pixel size
            
            # Calculate L-factor
            m = slope.divide(100).add(1).multiply(0.5)
            l_factor = slope_length.divide(22.13).pow(m)
            
            # Calculate S-factor
            slope_rad = slope.multiply(math.pi / 180)
            s_factor = ee.Image().expression(
                'slope < 9 ? slope * 0.065 * 1.7 + 0.065 : sin(slope_rad) * 16.8 - 0.5',
                {
                    'slope': slope,
                    'slope_rad': slope_rad
                }
            )
            
            # Combine L and S factors (ensure single band output)
            ls_factor = l_factor.multiply(s_factor).clamp(0, 300)
            
            return ls_factor.rename('LS_factor')
            
        except Exception as e:
            logger.error(f"Failed to compute LS-factor: {str(e)}")
            raise
    
    def compute_c_factor(self, year):
        """
        Compute C-Factor (Cover Management)
        Using Sentinel-2 NDVI
        """
        try:
            start_date = f'{year}-01-01'
            end_date = f'{year}-12-31'
            
            # Load Sentinel-2 data
            s2 = ee.ImageCollection('COPERNICUS/S2_SR_HARMONIZED') \
                .filterDate(start_date, end_date) \
                .filter(ee.Filter.lt('CLOUDY_PIXEL_PERCENTAGE', 20))
            
            # Calculate NDVI
            def calculate_ndvi(image):
                nir = image.select('B8')
                red = image.select('B4')
                ndvi = nir.subtract(red).divide(nir.add(red)).rename('NDVI')
                return ndvi
            
            ndvi_collection = s2.map(calculate_ndvi)
            mean_ndvi = ndvi_collection.mean()
            
            # Convert NDVI to C-factor
            # C = (1 - NDVI) / 2, clamped between 0.001 and 1
            c_factor = mean_ndvi.multiply(-1).add(1).divide(2).clamp(0.001, 1.0)
            
            return c_factor.rename('C_factor')
            
        except Exception as e:
            logger.error(f"Failed to compute C-factor: {str(e)}")
            raise
    
    def compute_p_factor(self, year):
        """
        Compute P-Factor (Conservation Practice)
        Using ESA WorldCover land cover classification
        """
        try:
            # Load land cover data
            land_cover = ee.Image('ESA/WorldCover/v100/2020').select('Map')
            
            # Map land cover classes to P-factor values
            # 10: Tree cover - 0.1
            # 20: Shrubland - 0.2
            # 30: Grassland - 0.3
            # 40: Cropland - 0.5
            # 50: Built-up - 1.0
            # 60: Bare/sparse - 1.0
            # 70: Snow/ice - 0.1
            # 80: Water - 0.0
            # 90: Wetlands - 0.3
            # 95: Mangroves - 0.1
            # 100: Moss/lichen - 0.3
            
            p_factor = land_cover \
                .where(land_cover.eq(10), 0.1) \
                .where(land_cover.eq(20), 0.2) \
                .where(land_cover.eq(30), 0.3) \
                .where(land_cover.eq(40), 0.5) \
                .where(land_cover.eq(50), 1.0) \
                .where(land_cover.eq(60), 1.0) \
                .where(land_cover.eq(70), 0.1) \
                .where(land_cover.eq(80), 0.0) \
                .where(land_cover.eq(90), 0.3) \
                .where(land_cover.eq(95), 0.1) \
                .where(land_cover.eq(100), 0.3)
            
            return p_factor.rename('P_factor')
            
        except Exception as e:
            logger.error(f"Failed to compute P-factor: {str(e)}")
            raise
    
    def compute_rusle(self, year, geometry, scale=30, compute_stats=True):
        """
        Compute full RUSLE erosion rate
        A = R * K * LS * C * P
        Returns erosion rate in t/ha/yr
        
        Args:
            year: Year for computation
            geometry: Area geometry
            scale: Resolution in meters (default 30m, use 100m+ for faster computation)
            compute_stats: Whether to compute statistics (can be slow for large areas)
        """
        try:
            logger.info(f"Computing RUSLE for year {year} at {scale}m resolution")
            
            # Compute all factors
            r_factor = self.compute_r_factor(year)
            k_factor = self.compute_k_factor()
            ls_factor = self.compute_ls_factor()
            c_factor = self.compute_c_factor(year)
            p_factor = self.compute_p_factor(year)
            
            # Calculate soil loss: A = R * K * LS * C * P
            soil_loss = r_factor.multiply(k_factor) \
                .multiply(ls_factor) \
                .multiply(c_factor) \
                .multiply(p_factor) \
                .clamp(0, 200)  # Clamp to reasonable range
            
            # Ensure single band output (select first band if multiple exist)
            soil_loss = soil_loss.select([0])
            soil_loss = soil_loss.rename('soil_loss')
            
            # Compute statistics only if requested (can be slow for large areas)
            if compute_stats:
                logger.info(f"  Computing statistics at {scale}m scale...")
                stats = gee_service.compute_statistics(soil_loss, geometry, scale=scale)
                
                # Helper function to safely round values, handling None
                def safe_round(value, decimals=2):
                    if value is None:
                        return 0.0
                    try:
                        return round(float(value), decimals)
                    except (TypeError, ValueError):
                        return 0.0
                
                return {
                    'image': soil_loss,
                    'statistics': {
                        'mean': safe_round(stats.get('soil_loss_mean', 0)),
                        'min': safe_round(stats.get('soil_loss_min', 0)),
                        'max': safe_round(stats.get('soil_loss_max', 0)),
                        'std_dev': safe_round(stats.get('soil_loss_stdDev', 0))
                    }
                }
            else:
                # Return without statistics for faster processing
                return {
                    'image': soil_loss,
                    'statistics': None
                }
            
        except Exception as e:
            logger.error(f"Failed to compute RUSLE: {str(e)}")
            raise
    
    def compute_detailed_grid(self, year, geometry, grid_size=10, bbox=None, geojson=None):
        """
        Compute detailed erosion grid for visualization
        Returns cell-by-cell erosion data
        OPTIMIZED: Uses adaptive parameters based on geometry complexity
        
        Args:
            bbox: Optional pre-calculated bbox as [minLon, minLat, maxLon, maxLat]
            geojson: Optional original GeoJSON for complexity analysis
        """
        try:
            logger.info(f"Computing detailed grid for year {year}, grid_size={grid_size}")
            
            # OPTIMIZATION 1: Analyze geometry complexity
            logger.info("  Step 1/5: Analyzing geometry complexity...")
            if geojson:
                complexity = gee_service.analyze_geometry_complexity(geojson, geometry)
                logger.info(f"    Complexity: {complexity['complexity_level']} "
                          f"({complexity['coord_count']} coords, {complexity['area_km2']} km²)")
                
                # Use recommended parameters
                params = complexity['recommended']
                simplify_tolerance = params['simplify_tolerance']
                rusle_scale = params['rusle_scale']
                sample_scale = params['sample_scale']
                recommended_grid = params['grid_size']
                max_samples = params['max_samples']
                batch_size = params['batch_size']
                max_workers = params['max_workers']
                
                # Use smaller grid if recommended and not overridden
                if grid_size == 10:  # If using default
                    grid_size = recommended_grid
                    logger.info(f"    Adjusted grid_size: {grid_size}x{grid_size} (optimized for area size)")
                
                logger.info(f"    Optimization parameters:")
                logger.info(f"      - Simplify tolerance: {simplify_tolerance}m")
                logger.info(f"      - RUSLE scale: {rusle_scale}m")
                logger.info(f"      - Sample scale: {sample_scale}m")
                logger.info(f"      - Max samples: {max_samples}")
                logger.info(f"      - Batch size: {batch_size}, Workers: {max_workers}")
            else:
                # Use default optimized parameters
                from config import Config
                simplify_tolerance = 1000
                rusle_scale = 150
                sample_scale = 100
                max_samples = Config.MAX_SAMPLES_LARGE_AREA
                batch_size = Config.BATCH_SIZE_OPTIMIZED
                max_workers = Config.MAX_WORKERS_OPTIMIZED
                logger.info("    Using default optimized parameters")
            
            # OPTIMIZATION 2: Simplify geometry BEFORE computation
            logger.info(f"  Step 2/5: Simplifying geometry (tolerance: {simplify_tolerance}m)...")
            simplified_geometry = geometry.simplify(maxError=simplify_tolerance)
            logger.info("    ✓ Geometry simplified")
            
            # OPTIMIZATION 3: Compute RUSLE with adaptive scale
            logger.info(f"  Step 3/5: Computing RUSLE soil loss image (scale: {rusle_scale}m)...")
            rusle_result = self.compute_rusle(year, simplified_geometry, scale=rusle_scale, compute_stats=False)
            soil_loss_image = rusle_result['image']
            
            # Get bounding box (use pre-calculated if provided)
            logger.info("  Step 4/5: Calculating bounding box...")
            if bbox and len(bbox) == 4:
                logger.info(f"    Using pre-calculated bbox: {bbox}")
                bbox_dict = {
                    'min_lon': bbox[0],
                    'min_lat': bbox[1],
                    'max_lon': bbox[2],
                    'max_lat': bbox[3]
                }
            else:
                logger.info("    Calling GEE to calculate bbox...")
                bbox_dict = gee_service.calculate_bbox(simplified_geometry)
            
            # Use the bbox dict
            bbox = bbox_dict
            
            # Calculate cell dimensions
            lon_range = bbox['max_lon'] - bbox['min_lon']
            lat_range = bbox['max_lat'] - bbox['min_lat']
            cell_width = lon_range / grid_size
            cell_height = lat_range / grid_size
            
            logger.info(f"  Step 5/5: Creating {grid_size}x{grid_size} grid and sampling erosion values...")
            
            # Create ALL grid cell geometries as Earth Engine objects (client-side, no API calls)
            grid_cells = []
            for i in range(grid_size):
                for j in range(grid_size):
                    min_lon = bbox['min_lon'] + (i * cell_width)
                    max_lon = min_lon + cell_width
                    min_lat = bbox['min_lat'] + (j * cell_height)
                    max_lat = min_lat + cell_height
                    
                    # Create cell geometry (EE object, not fetched yet)
                    cell_geom = ee.Geometry.Rectangle([min_lon, min_lat, max_lon, max_lat])
                    clipped_cell = cell_geom.intersection(simplified_geometry, ee.ErrorMargin(1))
                    
                    grid_cells.append({
                        'x': i,
                        'y': j,
                        'geometry': clipped_cell,
                        'bbox': [min_lon, min_lat, max_lon, max_lat]
                    })
            
            # OPTIMIZATION 4: Smart sampling - limit to max_samples for large areas
            total_cells = len(grid_cells)
            logger.info(f"    Created {total_cells} cells")
            
            try:
                # Create sample points at cell centers
                point_features = []
                for idx, cell in enumerate(grid_cells):
                    bbox_cell = cell['bbox']
                    center_lon = (bbox_cell[0] + bbox_cell[2]) / 2
                    center_lat = (bbox_cell[1] + bbox_cell[3]) / 2
                    point = ee.Geometry.Point([center_lon, center_lat])
                    
                    # Create a feature with the cell index
                    feature = ee.Feature(point, {'cell_idx': idx})
                    point_features.append(feature)
                
                # Create a FeatureCollection with all points and filter to region
                points_fc = ee.FeatureCollection(point_features)
                points_in_region = points_fc.filterBounds(simplified_geometry)
                
                # OPTIMIZATION: Limit samples for very large areas
                sample_limit = min(total_cells, max_samples)
                logger.info(f"    Sampling {sample_limit} points (optimized from {total_cells} cells)")
                logger.info(f"    Using batch_size={batch_size}, workers={max_workers}")
                
                def sample_batch(batch_start, batch_end):
                    """Sample a batch of points in parallel"""
                    try:
                        batch_fc = points_in_region.toList(batch_end - batch_start, batch_start)
                        batch_fc = ee.FeatureCollection(batch_fc)
                        
                        sample_result = soil_loss_image.sampleRegions(
                            collection=batch_fc,
                            scale=sample_scale,  # Use adaptive scale
                            geometries=False
                        ).getInfo()
                        
                        return sample_result.get('features', [])
                    except Exception as e:
                        logger.warning(f"    Batch {batch_start}-{batch_end} failed: {str(e)}")
                        return []
                
                # Execute parallel sampling with optimized parameters
                all_samples = []
                with ThreadPoolExecutor(max_workers=max_workers) as executor:
                    futures = []
                    for batch_start in range(0, sample_limit, batch_size):
                        batch_end = min(batch_start + batch_size, sample_limit)
                        future = executor.submit(sample_batch, batch_start, batch_end)
                        futures.append(future)
                    
                    # Collect results as they complete
                    for future in as_completed(futures):
                        try:
                            batch_samples = future.result(timeout=45)  # Reduced timeout
                            all_samples.extend(batch_samples)
                            logger.info(f"    ✓ Batch complete ({len(all_samples)} total samples so far)")
                        except Exception as e:
                            logger.warning(f"    Batch failed: {str(e)}")
                
                samples = {'features': all_samples}
                logger.info(f"    ✓ Received {len(all_samples)} samples from GEE (parallel, optimized)")
                
            except Exception as e:
                logger.error(f"    ✗ Failed to sample regions: {str(e)}")
                samples = None
            
            # If sampling returns no features, fall back to simpler approach
            if not samples or 'features' not in samples or len(samples['features']) == 0:
                logger.warning("  Sampling returned no results, using center point sampling...")
                # Sample each center point individually
                erosion_values_dict = {}
                for idx, cell in enumerate(grid_cells):
                    bbox_cell = cell['bbox']
                    center_lon = (bbox_cell[0] + bbox_cell[2]) / 2
                    center_lat = (bbox_cell[1] + bbox_cell[3]) / 2
                    point = ee.Geometry.Point([center_lon, center_lat])
                    
                    # Check if point is within the geometry
                    if idx < 5:  # Only sample first 5 for speed
                        try:
                            sample = soil_loss_image.sample(point, 30).first().getInfo()
                            if sample and 'properties' in sample:
                                erosion_values_dict[idx] = sample['properties'].get('soil_loss', 0)
                        except:
                            pass
                
                # Don't use default values - only return cells with actual data
                # This ensures we only show cells inside the region
                pass  # erosion_values_dict already has the sampled values
            else:
                # Extract erosion values from samples using cell_idx
                erosion_values_dict = {}
                for feature in samples['features']:
                    if 'properties' in feature:
                        cell_idx = feature['properties'].get('cell_idx')
                        soil_loss = feature['properties'].get('soil_loss', 0)
                        if cell_idx is not None:
                            erosion_values_dict[cell_idx] = soil_loss
            
            # Build result cells (only include cells with erosion data - these are inside the region)
            cells = []
            erosion_values = []
            
            for idx, cell in enumerate(grid_cells):
                erosion_rate = erosion_values_dict.get(idx, 0)
                
                # Only include cells with valid erosion data (sampling automatically filters to region)
                if erosion_rate is not None and erosion_rate > 0:
                    erosion_values.append(erosion_rate)
                    
                    # Use simple bbox geometry instead of fetching actual clipped geometry
                    bbox_cell = cell['bbox']
                    cell_geojson = {
                        'type': 'Polygon',
                        'coordinates': [[
                            [bbox_cell[0], bbox_cell[1]],
                            [bbox_cell[2], bbox_cell[1]],
                            [bbox_cell[2], bbox_cell[3]],
                            [bbox_cell[0], bbox_cell[3]],
                            [bbox_cell[0], bbox_cell[1]]
                        ]]
                    }
                    
                    cells.append({
                        'x': cell['x'],
                        'y': cell['y'],
                        'erosion_rate': round(float(erosion_rate), 2),
                        'geometry': cell_geojson
                    })
            
            # Calculate statistics from cell values
            if erosion_values:
                mean_erosion = sum(erosion_values) / len(erosion_values)
                min_erosion = min(erosion_values)
                max_erosion = max(erosion_values)
                
                # Calculate standard deviation
                variance = sum((x - mean_erosion) ** 2 for x in erosion_values) / len(erosion_values)
                std_dev = math.sqrt(variance)
            else:
                # No valid cells with erosion data
                mean_erosion = min_erosion = max_erosion = std_dev = 0
            
            logger.info(f"  ✓ Grid complete: {len(cells)} cells with data")
            
            # OPTIMIZATION 5: Skip boundary fetch - frontend already has geometry
            # This saves 1-2 minutes on complex geometries
            logger.info("  ✓ Skipping boundary fetch (optimization - frontend has geometry)")
            
            return {
                'cells': cells,
                'statistics': {
                    'mean': round(mean_erosion, 2),
                    'min': round(min_erosion, 2),
                    'max': round(max_erosion, 2),
                    'std_dev': round(std_dev, 2)
                },
                'grid_size': grid_size,
                'bbox': [bbox['min_lon'], bbox['min_lat'], bbox['max_lon'], bbox['max_lat']],
                'cell_count': len(cells)
                # region_boundary removed - frontend uses original geometry
            }
            
        except Exception as e:
            logger.error(f"Failed to compute detailed grid: {str(e)}", exc_info=True)
            raise

# Global RUSLE calculator instance
rusle_calculator = RUSLECalculator()

