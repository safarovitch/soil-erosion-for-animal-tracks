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
    
    LONG_TERM_R_START_YEAR = 1994
    LONG_TERM_R_END_YEAR = 2024  # Exclusive upper bound for filterDate
    FLOW_ACC_GRID_SIZE = 300  # meters, matches HydroSHEDS 30 arc-second (~927m) res resampled to 300m exports
    
    def __init__(self):
        pass
    
    @staticmethod
    def _clip_image(image, geometry):
        if geometry:
            return image.clip(geometry)
        return image
    
    @staticmethod
    def _filter_collection(collection, geometry):
        if geometry:
            return collection.filterBounds(geometry)
        return collection
    
    def _load_modis_landcover(self, year, geometry):
        """Load MODIS land cover image for supplied year, clipped to geometry"""
        start_date = f'{year}-01-01'
        end_date = f'{year}-12-31'
        collection = ee.ImageCollection('MODIS/061/MCD12Q1') \
            .filterDate(start_date, end_date)
        collection = self._filter_collection(collection, geometry)
        
        # Fallback to the provided GEE code default (2023) if collection is empty
        land_cover_image = ee.Image(ee.Algorithms.If(
            collection.size().gt(0),
            collection.first(),
            ee.Image('MODIS/061/MCD12Q1/2023_01_01')
        ))
        
        land_cover_image = land_cover_image.select('LC_Type1')
        return self._clip_image(land_cover_image, geometry)
    
    def compute_r_factor(self, year, geometry=None, use_long_term=True):
        """
        Compute R-Factor (Rainfall Erosivity)
        Using CHIRPS precipitation data
        """
        try:
            if use_long_term:
                start_year = self.LONG_TERM_R_START_YEAR
                end_year = self.LONG_TERM_R_END_YEAR
                start_date = f'{start_year}-01-01'
                end_date = f'{end_year}-01-01'
                years = max(1, end_year - start_year)
            else:
                start_date = f'{year}-01-01'
                end_date = f'{year + 1}-01-01'
                years = 1
            
            # Load CHIRPS precipitation data
            chirps = ee.ImageCollection('UCSB-CHG/CHIRPS/DAILY') \
                .filterDate(start_date, end_date) \
                .select('precipitation')
            chirps = self._filter_collection(chirps, geometry)
            
            # Calculate annual precipitation
            total_precip = chirps.sum().toFloat()
            mean_precip = total_precip.divide(years)
            
            # Linear R-factor approximation from GEE workflow
            # R = 0.562 * P_annual - 8.12 (P in mm)
            r_factor = mean_precip.multiply(0.562).subtract(8.12)
            r_factor = r_factor.max(0)
            r_factor = self._clip_image(r_factor, geometry)
            
            return r_factor.rename('R_factor')
            
        except Exception as e:
            logger.error(f"Failed to compute R-factor: {str(e)}")
            raise
    
    def compute_k_factor(self, geometry=None, structure_code=2, permeability_code=3):
        """
        Compute K-Factor (Soil Erodibility)
        Using OpenLandMap soil fraction data
        """
        try:
            clay = ee.Image("OpenLandMap/SOL/SOL_CLAY-WFRACTION_USDA-3A1A1A_M/v02").select('b0')
            sand = ee.Image("OpenLandMap/SOL/SOL_SAND-WFRACTION_USDA-3A1A1A_M/v02").select('b0')
            organic_carbon = ee.Image("OpenLandMap/SOL/SOL_ORGANIC-CARBON_USDA-6A1C_M/v02").select('b0')
            
            clay = self._clip_image(clay, geometry)
            sand = self._clip_image(sand, geometry)
            organic_carbon = self._clip_image(organic_carbon, geometry)
            
            # Convert SOC (%) to organic matter (%)
            organic_matter = organic_carbon.multiply(0.01724)
            
            # Derive silt as residual
            silt = ee.Image.constant(100).subtract(sand).subtract(clay)
            
            # Compute M parameter
            M = silt.add(sand.multiply(ee.Image.constant(100).subtract(clay)))
            
            base_k = ee.Image(27.66) \
                .multiply(M.pow(1.14)) \
                .multiply(1e-8) \
                .multiply(ee.Image(12).subtract(organic_matter))
            
            k_factor = base_k \
                .add(ee.Image(0.0043).multiply(ee.Image(structure_code).subtract(2))) \
                .add(ee.Image(0.0033).multiply(ee.Image(permeability_code).subtract(3)))
            
            k_factor = k_factor.max(0)
            k_factor = self._clip_image(k_factor, geometry)
            
            return k_factor.rename('K_factor')
            
        except Exception as e:
            logger.error(f"Failed to compute K-factor: {str(e)}")
            raise
    
    def compute_ls_factor(self, geometry=None, grid_size=None):
        """
        Compute LS-Factor (Slope Length and Steepness)
        Using SRTM DEM and HydroSHEDS flow accumulation
        """
        try:
            grid_size = grid_size or self.FLOW_ACC_GRID_SIZE
            
            dem = ee.Image('USGS/SRTMGL1_003').select('elevation')
            dem = self._clip_image(dem, geometry)
            
            slope_deg = ee.Terrain.slope(dem)
            slope_rad = slope_deg.multiply(math.pi / 180.0)
            slope_rad = slope_rad.where(slope_rad.eq(0), 0.0001)
            sin_slope = slope_rad.sin()
            
            flow_acc = ee.Image("WWF/HydroSHEDS/30ACC")
            flow_acc = self._clip_image(flow_acc, geometry)
            flow_acc = flow_acc.where(flow_acc.eq(0), 1)
            
            ls_factor = flow_acc.multiply(grid_size) \
                .divide(22.13) \
                .pow(0.4) \
                .multiply(sin_slope.divide(0.0896).pow(1.3))
            
            ls_factor = ls_factor.max(0)
            ls_factor = self._clip_image(ls_factor, geometry)
            
            return ls_factor.rename('LS_factor')
            
        except Exception as e:
            logger.error(f"Failed to compute LS-factor: {str(e)}")
            raise
    
    def compute_c_factor(self, year, geometry=None):
        """
        Compute C-Factor (Cover Management)
        Using MODIS land cover mapping
        """
        try:
            land_cover = self._load_modis_landcover(year, geometry)
            
            # Remap MODIS IGBP classes to C-factor values (based on provided GEE script)
            class_ids = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17]
            c_values = [0.05, 0.05, 0.05, 0.05, 0.05, 0.1, 0.1, 0.05, 0.1, 0.1, 0.0, 0.15, 0.01, 0.15, 0.0, 0.4, 0.0]
            
            c_factor = land_cover.remap(class_ids, c_values, 0).rename('C_factor')
            c_factor = self._clip_image(c_factor, geometry)
            
            return c_factor.rename('C_factor')
            
        except Exception as e:
            logger.error(f"Failed to compute C-factor: {str(e)}")
            raise
    
    def compute_p_factor(self, year, geometry=None):
        """
        Compute P-Factor (Conservation Practice)
        Using MODIS land cover with slope-dependent coefficients for cropland
        """
        try:
            land_cover = self._load_modis_landcover(year, geometry)
            
            dem = ee.Image('USGS/SRTMGL1_003').select('elevation')
            dem = self._clip_image(dem, geometry)
            slope_deg = ee.Terrain.slope(dem)
            
            p_factor = ee.Image.constant(1.0)
            p_factor = self._clip_image(p_factor, geometry)
            
            cropland = land_cover.eq(12)
            
            p_factor = p_factor.where(cropland.And(slope_deg.lte(5)), 0.10)
            p_factor = p_factor.where(cropland.And(slope_deg.gt(5)).And(slope_deg.lte(10)), 0.12)
            p_factor = p_factor.where(cropland.And(slope_deg.gt(10)).And(slope_deg.lte(20)), 0.14)
            p_factor = p_factor.where(cropland.And(slope_deg.gt(20)).And(slope_deg.lte(30)), 0.19)
            p_factor = p_factor.where(cropland.And(slope_deg.gt(30)).And(slope_deg.lte(50)), 0.25)
            p_factor = p_factor.where(cropland.And(slope_deg.gt(50)).And(slope_deg.lte(100)), 0.33)
            p_factor = p_factor.where(cropland.And(slope_deg.gt(100)), 0.33)
            
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
            r_factor = self.compute_r_factor(year, geometry)
            k_factor = self.compute_k_factor(geometry)
            ls_factor = self.compute_ls_factor(geometry)
            c_factor = self.compute_c_factor(year, geometry)
            p_factor = self.compute_p_factor(year, geometry)
            
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

