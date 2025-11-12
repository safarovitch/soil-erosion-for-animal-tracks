"""
Flask Application for Google Earth Engine Service
Exposes REST API endpoints for RUSLE computation
"""
from flask import Flask, request, jsonify
from flask_cors import CORS
import logging
import ee
from config import Config
from gee_service import gee_service
from rusle_calculator import rusle_calculator
from rainfall_calculator import rainfall_calculator

# Import tasks for precomputation
try:
    from tasks import generate_erosion_map_task
    CELERY_AVAILABLE = True
except ImportError:
    CELERY_AVAILABLE = False
    logging.warning("Celery tasks not available")

# Configure logging
logging.basicConfig(
    level=getattr(logging, Config.LOG_LEVEL.upper()),
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Create Flask app
app = Flask(__name__)
CORS(app)  # Enable CORS for PHP requests

# Initialize GEE on startup
try:
    gee_service.initialize()
    logger.info("Google Earth Engine initialized successfully")
except Exception as e:
    logger.error(f"Failed to initialize Google Earth Engine: {str(e)}")

@app.route('/api/health', methods=['GET'])
def health_check():
    """Health check endpoint"""
    try:
        gee_status = gee_service.get_health_status()
        return jsonify({
            'status': 'ok',
            'service': 'python-gee-service',
            'gee': gee_status
        }), 200
    except Exception as e:
        return jsonify({
            'status': 'error',
            'message': str(e)
        }), 500

@app.route('/api/rusle/compute', methods=['POST'])
def compute_rusle():
    """
    Compute RUSLE erosion for an area
    Input: {area_geometry: GeoJSON, start_year: int, end_year: int}
    Output: {statistics: {...}, success: bool}
    """
    try:
        data = request.get_json()
        
        # Validate input
        if not data or 'area_geometry' not in data:
            return jsonify({
                'success': False,
                'error': 'Missing required parameter: area_geometry'
            }), 400
        
        area_geometry = data['area_geometry']
        start_year = data.get('start_year', data.get('year'))
        end_year = data.get('end_year', start_year)
        
        if start_year is None:
            return jsonify({
                'success': False,
                'error': 'Missing required parameter: start_year'
            }), 400
        
        start_year = int(start_year)
        end_year = int(end_year) if end_year is not None else start_year
        
        # Validate year range
        if start_year < Config.RUSLE_START_YEAR or end_year > Config.RUSLE_END_YEAR:
            return jsonify({
                'success': False,
                'error': f'Years must be between {Config.RUSLE_START_YEAR} and {Config.RUSLE_END_YEAR}'
            }), 400
        
        if end_year < start_year:
            return jsonify({
                'success': False,
                'error': 'end_year must be greater than or equal to start_year'
            }), 400
        
        # Convert GeoJSON to EE Geometry
        geometry = gee_service.geometry_from_geojson(area_geometry)
        
        # Compute RUSLE for the requested period
        if end_year != start_year:
            r_factor = rusle_calculator.compute_r_factor_range(start_year, end_year, geometry)
            result = rusle_calculator.compute_rusle(
                start_year,
                geometry,
                r_factor_image=r_factor
            )
        else:
            result = rusle_calculator.compute_rusle(start_year, geometry)
        
        rainfall_stats = rusle_calculator.compute_rainfall_statistics(start_year, end_year, geometry)
        erosion_classes = rusle_calculator.compute_erosion_class_breakdown(
            result['image'],
            geometry,
            scale=100
        )
        
        period_label = str(start_year) if start_year == end_year else f"{start_year}-{end_year}"

        return jsonify({
            'success': True,
            'data': {
                'statistics': result['statistics'],
                'start_year': start_year,
                'end_year': end_year,
                'rainfall_statistics': rainfall_stats,
                'erosion_class_breakdown': erosion_classes,
                'period': {
                    'start_year': start_year,
                    'end_year': end_year,
                    'label': period_label
                }
            }
        }), 200
        
    except ValueError as e:
        logger.error(f"Validation error: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 400
    except Exception as e:
        logger.error(f"Error computing RUSLE: {str(e)}")
        return jsonify({
            'success': False,
            'error': f'Failed to compute erosion: {str(e)}'
        }), 500

@app.route('/api/rusle/factors', methods=['POST'])
def compute_rusle_factors():
    """
    Compute individual RUSLE factors (R, K, LS, C, P) for an area
    Input: {area_geometry: GeoJSON, start_year: int, end_year: int, factors: ['r','k','ls','c','p'] or 'all'}
    Output: {factors: {r: {...}, k: {...}, ...}, success: bool}
    """
    try:
        data = request.get_json()
        
        # Validate input
        if not data or 'area_geometry' not in data:
            return jsonify({
                'success': False,
                'error': 'Missing required parameter: area_geometry'
            }), 400
        
        area_geometry = data['area_geometry']
        start_year = data.get('start_year', data.get('year'))
        end_year = data.get('end_year', start_year)

        if start_year is None:
            return jsonify({
                'success': False,
                'error': 'Missing required parameter: start_year'
            }), 400

        start_year = int(start_year)
        end_year = int(end_year) if end_year is not None else start_year
        requested_factors = data.get('factors', 'all')  # 'all' or list like ['r','k','ls']
        
        # Validate year range
        if start_year < Config.RUSLE_START_YEAR or end_year > Config.RUSLE_END_YEAR:
            return jsonify({
                'success': False,
                'error': f'Years must be between {Config.RUSLE_START_YEAR} and {Config.RUSLE_END_YEAR}'
            }), 400

        if end_year < start_year:
            return jsonify({
                'success': False,
                'error': 'end_year must be greater than or equal to start_year'
            }), 400
        
        # Convert GeoJSON to EE Geometry
        geometry = gee_service.geometry_from_geojson(area_geometry)
        
        # Determine which factors to compute
        if requested_factors == 'all':
            factors_to_compute = ['r', 'k', 'ls', 'c', 'p']
        else:
            factors_to_compute = [f.lower() for f in requested_factors] if isinstance(requested_factors, list) else [requested_factors.lower()]
        
        # Validate factor names
        valid_factors = ['r', 'k', 'ls', 'c', 'p']
        invalid_factors = [f for f in factors_to_compute if f not in valid_factors]
        if invalid_factors:
            return jsonify({
                'success': False,
                'error': f'Invalid factor names: {invalid_factors}. Valid: {valid_factors}'
            }), 400
        
        logger.info(f"Computing factors: {factors_to_compute} for period {start_year}-{end_year}")
        
        # Compute requested factors
        factors_result = {}
        scale = data.get('scale', 100)  # Default 100m for faster computation
        
        if 'r' in factors_to_compute:
            logger.info("Computing R-factor...")
            if end_year != start_year:
                r_factor = rusle_calculator.compute_r_factor_range(start_year, end_year, geometry)
            else:
                r_factor = rusle_calculator.compute_r_factor(start_year, geometry)
            r_stats = gee_service.compute_statistics(r_factor, geometry, scale=scale)
            # Handle None values - don't use defaults, raise error if data is missing
            r_mean = r_stats.get('R_factor_mean')
            r_min = r_stats.get('R_factor_min')
            r_max = r_stats.get('R_factor_max')
            r_std = r_stats.get('R_factor_stdDev')
            
            if r_mean is None:
                raise ValueError("R-factor statistics not available - computation may have failed")
            
            factors_result['r'] = {
                'mean': round(float(r_mean), 2) if r_mean is not None else None,
                'min': round(float(r_min), 2) if r_min is not None else None,
                'max': round(float(r_max), 2) if r_max is not None else None,
                'std_dev': round(float(r_std), 2) if r_std is not None else None,
                'unit': 'MJ mm/(ha h yr)',
                'description': 'Rainfall Erosivity'
            }
        
        if 'k' in factors_to_compute:
            logger.info("Computing K-factor...")
            k_factor = rusle_calculator.compute_k_factor()
            k_stats = gee_service.compute_statistics(k_factor, geometry, scale=scale)
            k_mean = k_stats.get('K_factor_mean')
            k_min = k_stats.get('K_factor_min')
            k_max = k_stats.get('K_factor_max')
            k_std = k_stats.get('K_factor_stdDev')
            
            if k_mean is None:
                raise ValueError("K-factor statistics not available - computation may have failed")
            
            factors_result['k'] = {
                'mean': round(float(k_mean), 3) if k_mean is not None else None,
                'min': round(float(k_min), 3) if k_min is not None else None,
                'max': round(float(k_max), 3) if k_max is not None else None,
                'std_dev': round(float(k_std), 3) if k_std is not None else None,
                'unit': 't ha h/(ha MJ mm)',
                'description': 'Soil Erodibility'
            }
        
        if 'ls' in factors_to_compute:
            logger.info("Computing LS-factor...")
            ls_factor = rusle_calculator.compute_ls_factor()
            ls_stats = gee_service.compute_statistics(ls_factor, geometry, scale=scale)
            ls_mean = ls_stats.get('LS_factor_mean')
            ls_min = ls_stats.get('LS_factor_min')
            ls_max = ls_stats.get('LS_factor_max')
            ls_std = ls_stats.get('LS_factor_stdDev')
            
            if ls_mean is None:
                raise ValueError("LS-factor statistics not available - computation may have failed")
            
            factors_result['ls'] = {
                'mean': round(float(ls_mean), 2) if ls_mean is not None else None,
                'min': round(float(ls_min), 2) if ls_min is not None else None,
                'max': round(float(ls_max), 2) if ls_max is not None else None,
                'std_dev': round(float(ls_std), 2) if ls_std is not None else None,
                'unit': 'dimensionless',
                'description': 'Topographic (Slope Length & Steepness)'
            }
        
        if 'c' in factors_to_compute:
            logger.info("Computing C-factor...")
            c_factor = rusle_calculator.compute_c_factor(end_year, geometry)
            c_stats = gee_service.compute_statistics(c_factor, geometry, scale=scale)
            c_mean = c_stats.get('C_factor_mean')
            c_min = c_stats.get('C_factor_min')
            c_max = c_stats.get('C_factor_max')
            c_std = c_stats.get('C_factor_stdDev')
            
            if c_mean is None:
                raise ValueError("C-factor statistics not available - computation may have failed")
            
            factors_result['c'] = {
                'mean': round(float(c_mean), 3) if c_mean is not None else None,
                'min': round(float(c_min), 3) if c_min is not None else None,
                'max': round(float(c_max), 3) if c_max is not None else None,
                'std_dev': round(float(c_std), 3) if c_std is not None else None,
                'unit': '0-1',
                'description': 'Cover Management'
            }
        
        if 'p' in factors_to_compute:
            logger.info("Computing P-factor...")
            p_factor = rusle_calculator.compute_p_factor(end_year, geometry)
            p_stats = gee_service.compute_statistics(p_factor, geometry, scale=scale)
            p_mean = p_stats.get('P_factor_mean')
            p_min = p_stats.get('P_factor_min')
            p_max = p_stats.get('P_factor_max')
            p_std = p_stats.get('P_factor_stdDev')
            
            if p_mean is None:
                raise ValueError("P-factor statistics not available - computation may have failed")
            
            factors_result['p'] = {
                'mean': round(float(p_mean), 3) if p_mean is not None else None,
                'min': round(float(p_min), 3) if p_min is not None else None,
                'max': round(float(p_max), 3) if p_max is not None else None,
                'std_dev': round(float(p_std), 3) if p_std is not None else None,
                'unit': '0-1',
                'description': 'Support Practice'
            }
        
        # If all factors computed, also compute final soil erosion and severity breakdown
        soil_erosion = None
        if set(factors_to_compute) == set(valid_factors):
            logger.info("Computing final soil erosion (A = R × K × LS × C × P)...")
            if end_year != start_year:
                r_factor = rusle_calculator.compute_r_factor_range(start_year, end_year, geometry)
                rusle_result = rusle_calculator.compute_rusle(
                    start_year,
                    geometry,
                    scale=scale,
                    compute_stats=True,
                    r_factor_image=r_factor
                )
            else:
                rusle_result = rusle_calculator.compute_rusle(start_year, geometry, scale=scale, compute_stats=True)

            soil_erosion = rusle_result['statistics']

            if soil_erosion:
                try:
                    breakdown = rusle_calculator.compute_erosion_class_breakdown(
                        rusle_result['image'],
                        geometry,
                        scale=scale
                    )

                    class_labels = [
                        ('very_low', 'Very Low'),
                        ('low', 'Low'),
                        ('moderate', 'Moderate'),
                        ('severe', 'Severe'),
                        ('excessive', 'Excessive'),
                    ]

                    severity_distribution = []
                    for key, label in class_labels:
                        class_info = breakdown.get(key, {}) or {}
                        area_ha = float(class_info.get('area_hectares') or 0.0)
                        percentage = float(class_info.get('percentage') or 0.0)
                        severity_distribution.append(
                            {
                                'class': label,
                                'area': round(area_ha, 2),
                                'percentage': round(percentage, 2),
                            }
                        )

                    soil_erosion['severity_distribution'] = severity_distribution
                    soil_erosion['total_area_hectares'] = round(
                        float(breakdown.get('total_area_hectares') or 0.0), 2
                    )
                    soil_erosion['erosion_class_breakdown'] = breakdown
                except Exception as error:
                    logger.warning(
                        f"Failed to compute erosion class breakdown: {str(error)}",
                        exc_info=True,
                    )
                    soil_erosion['severity_distribution'] = []
 
        period_label = str(start_year) if start_year == end_year else f"{start_year}-{end_year}"

        return jsonify({
            'success': True,
            'data': {
                'factors': factors_result,
                'soil_erosion': soil_erosion,
                'start_year': start_year,
                'end_year': end_year,
                'period': {
                    'start_year': start_year,
                    'end_year': end_year,
                    'label': period_label
                },
                'scale': scale
            }
        }), 200
        
    except ValueError as e:
        logger.error(f"Validation error: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 400
    except Exception as e:
        logger.error(f"Error computing RUSLE factors: {str(e)}", exc_info=True)
        return jsonify({
            'success': False,
            'error': f'Failed to compute factors: {str(e)}'
        }), 500

@app.route('/api/rusle/detailed-grid', methods=['POST'])
def compute_detailed_grid():
    """
    Compute detailed erosion grid for visualization
    Input: {area_geometry: GeoJSON, year: int, grid_size: int}
    Output: {cells: [...], statistics: {...}, success: bool}
    """
    try:
        data = request.get_json()
        
        # Validate input
        if not data or 'area_geometry' not in data or 'year' not in data:
            return jsonify({
                'success': False,
                'error': 'Missing required parameters: area_geometry, year'
            }), 400
        
        area_geometry = data['area_geometry']
        year = int(data['year'])
        grid_size = int(data.get('grid_size', Config.DEFAULT_GRID_SIZE))
        bbox = data.get('bbox', None)  # Optional pre-calculated bbox
        
        # Validate parameters
        if year < Config.RUSLE_START_YEAR or year > Config.RUSLE_END_YEAR:
            return jsonify({
                'success': False,
                'error': f'Year must be between {Config.RUSLE_START_YEAR} and {Config.RUSLE_END_YEAR}'
            }), 400
        
        if grid_size < 10 or grid_size > Config.MAX_GRID_SIZE:
            return jsonify({
                'success': False,
                'error': f'Grid size must be between 10 and {Config.MAX_GRID_SIZE}'
            }), 400
        
        # Convert GeoJSON to EE Geometry
        geometry = gee_service.geometry_from_geojson(area_geometry)
        
        # Log bbox info
        if bbox:
            logger.info(f"Using pre-calculated bbox: {bbox}")
        
        # Compute detailed grid with optional bbox and geojson for complexity analysis
        result = rusle_calculator.compute_detailed_grid(
            year, 
            geometry, 
            grid_size, 
            bbox=bbox,
            geojson=area_geometry  # Pass original GeoJSON for complexity analysis
        )
        
        return jsonify({
            'success': True,
            'data': result
        }), 200
        
    except ValueError as e:
        logger.error(f"Validation error: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 400
    except Exception as e:
        logger.error(f"Error computing detailed grid: {str(e)}")
        return jsonify({
            'success': False,
            'error': f'Failed to compute detailed grid: {str(e)}'
        }), 500

@app.route('/api/rusle/time-series', methods=['POST'])
def compute_time_series():
    """
    Compute erosion time series for an area
    Input: {area_geometry: GeoJSON, start_year: int, end_year: int}
    Output: {yearly_data: [...], success: bool}
    """
    try:
        data = request.get_json()
        
        # Validate input
        if not data or 'area_geometry' not in data:
            return jsonify({
                'success': False,
                'error': 'Missing required parameter: area_geometry'
            }), 400
        
        area_geometry = data['area_geometry']
        start_year = int(data.get('start_year', Config.RUSLE_START_YEAR))
        end_year = int(data.get('end_year', Config.RUSLE_END_YEAR))
        
        # Validate year range
        if start_year < Config.RUSLE_START_YEAR or end_year > Config.RUSLE_END_YEAR:
            return jsonify({
                'success': False,
                'error': f'Years must be between {Config.RUSLE_START_YEAR} and {Config.RUSLE_END_YEAR}'
            }), 400
        
        if start_year > end_year:
            return jsonify({
                'success': False,
                'error': 'start_year must be less than or equal to end_year'
            }), 400
        
        # Convert GeoJSON to EE Geometry
        geometry = gee_service.geometry_from_geojson(area_geometry)
        
        # Compute time series
        yearly_data = []
        for year in range(start_year, end_year + 1):
            result = rusle_calculator.compute_rusle(year, geometry)
            yearly_data.append({
                'year': year,
                'statistics': result['statistics']
            })
        
        return jsonify({
            'success': True,
            'data': {
                'yearly_data': yearly_data,
                'start_year': start_year,
                'end_year': end_year
            }
        }), 200
        
    except ValueError as e:
        logger.error(f"Validation error: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 400
    except Exception as e:
        logger.error(f"Error computing time series: {str(e)}")
        return jsonify({
            'success': False,
            'error': f'Failed to compute time series: {str(e)}'
        }), 500

@app.route('/api/gee/diagnose', methods=['GET'])
def diagnose_gee():
    """
    Diagnostic endpoint to troubleshoot Earth Engine configuration
    Returns detailed information about GEE status and configuration
    """
    try:
        result = {
            'credentials_configured': bool(Config.GEE_SERVICE_ACCOUNT_EMAIL and Config.GEE_PRIVATE_KEY_PATH),
            'project_id': Config.GEE_PROJECT_ID,
            'service_account': Config.GEE_SERVICE_ACCOUNT_EMAIL,
            'private_key_path': Config.GEE_PRIVATE_KEY_PATH,
            'initialized': gee_service.is_initialized(),
            'configuration': {
                'start_year': Config.RUSLE_START_YEAR,
                'end_year': Config.RUSLE_END_YEAR,
                'default_grid_size': Config.DEFAULT_GRID_SIZE,
                'max_grid_size': Config.MAX_GRID_SIZE
            }
        }
        
        # Check if private key file exists
        import os
        if Config.GEE_PRIVATE_KEY_PATH:
            result['private_key_exists'] = os.path.exists(Config.GEE_PRIVATE_KEY_PATH)
        else:
            result['private_key_exists'] = False
        
        # If initialized, test Earth Engine operations
        if gee_service.is_initialized():
            try:
                # Test 1: Simple image access
                test_image = ee.Image('USGS/SRTMGL1_003')
                test_info = test_image.getInfo()
                result['test_image_access'] = 'success'
                result['test_image_bands'] = [b['id'] for b in test_info.get('bands', [])]
                
                # Test 2: Simple computation
                test_geom = ee.Geometry.Point([68.7870, 38.5598])  # Dushanbe coordinates
                test_value = test_image.sample(test_geom, 30).first().getInfo()
                result['test_computation'] = 'success'
                result['test_elevation'] = test_value.get('properties', {}).get('elevation')
                
                # Test 3: Check access to required datasets
                datasets_to_test = {
                    'CHIRPS_Precipitation': 'UCSB-CHG/CHIRPS/DAILY',
                    'SoilGrids_Clay': 'projects/soilgrids-isric/clay_mean',
                    'SRTM_DEM': 'USGS/SRTMGL1_003',
                    'Sentinel2': 'COPERNICUS/S2_SR_HARMONIZED',
                    'ESA_WorldCover': 'ESA/WorldCover/v100/2020'
                }
                
                dataset_access = {}
                for name, dataset_id in datasets_to_test.items():
                    try:
                        if 'ImageCollection' in name or 'CHIRPS' in name or 'Sentinel2' in name:
                            test_ds = ee.ImageCollection(dataset_id).limit(1).first()
                        else:
                            test_ds = ee.Image(dataset_id)
                        _ = test_ds.getInfo()
                        dataset_access[name] = 'accessible'
                    except Exception as e:
                        dataset_access[name] = f'error: {str(e)}'
                
                result['dataset_access'] = dataset_access
                
            except Exception as e:
                result['test_operation'] = 'failed'
                result['test_error'] = str(e)
                result['test_error_type'] = type(e).__name__
        else:
            result['message'] = 'Earth Engine not initialized - check logs for initialization errors'
        
        return jsonify(result), 200
        
    except Exception as e:
        logger.error(f"Error in diagnose endpoint: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

@app.errorhandler(404)
def not_found(error):
    """Handle 404 errors"""
    return jsonify({
        'success': False,
        'error': 'Endpoint not found'
    }), 404

@app.errorhandler(500)
def internal_error(error):
    """Handle 500 errors"""
    logger.error(f"Internal server error: {str(error)}")
    return jsonify({
        'success': False,
        'error': 'Internal server error'
    }), 500

# ==========================================
# Precomputation Endpoints (Celery Tasks)
# ==========================================

@app.route('/api/rusle/precompute', methods=['POST'])
def trigger_precompute():
    """
    Trigger background precomputation of erosion map
    Queues a Celery task to generate GeoTIFF + tiles
    """
    if not CELERY_AVAILABLE:
        return jsonify({
            'success': False,
            'error': 'Celery not available - background processing disabled'
        }), 503
    
    try:
        data = request.get_json()
        
        # Validate required fields
        required_fields = ['area_type', 'area_id', 'area_geometry']
        for field in required_fields:
            if field not in data:
                return jsonify({
                    'success': False,
                    'error': f'Missing required field: {field}'
                }), 400
        
        area_type = data.get('area_type')  # 'region' or 'district'
        area_id = data.get('area_id')
        start_year = data.get('start_year') or data.get('year')
        end_year = data.get('end_year') if data.get('end_year') is not None else start_year
        geometry = data.get('area_geometry')
        bbox = data.get('bbox')
        
        if start_year is None:
            return jsonify({
                'success': False,
                'error': 'Missing required field: start_year'
            }), 400
        
        try:
            start_year = int(start_year)
            end_year = int(end_year)
        except (TypeError, ValueError):
            return jsonify({
                'success': False,
                'error': 'start_year and end_year must be integers'
            }), 400
        
        if end_year < start_year:
            return jsonify({
                'success': False,
                'error': 'end_year must be greater than or equal to start_year'
            }), 400
        
        period_label = str(start_year) if start_year == end_year else f"{start_year}-{end_year}"
        
        # Validate area_type
        if area_type not in ['region', 'district']:
            return jsonify({
                'success': False,
                'error': 'area_type must be "region" or "district"'
            }), 400
        
        # Queue background task
        logger.info(f"Queueing precomputation: {area_type} {area_id}, period {period_label}")
        
        task = generate_erosion_map_task.delay(
            area_type,
            area_id,
            start_year,
            geometry,
            bbox,
            end_year  # Pass as positional argument (6th parameter)
        )
        
        logger.info(f"Task queued with ID: {task.id}")
        
        return jsonify({
            'success': True,
            'task_id': task.id,
            'status': 'queued',
            'message': f'Precomputation queued for {area_type} {area_id}, period {period_label}'
        }), 202
        
    except Exception as e:
        logger.error(f"Failed to queue precomputation: {str(e)}", exc_info=True)
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

@app.route('/api/rusle/task-status/<task_id>', methods=['GET'])
def get_task_status(task_id):
    """
    Check the status of a background task
    Returns task state and progress information
    """
    if not CELERY_AVAILABLE:
        return jsonify({
            'success': False,
            'error': 'Celery not available'
        }), 503
    
    try:
        task = generate_erosion_map_task.AsyncResult(task_id)
        
        if task.state == 'PENDING':
            response = {
                'task_id': task_id,
                'status': 'pending',
                'message': 'Task is waiting to be processed'
            }
        elif task.state == 'PROCESSING':
            info = task.info or {}
            response = {
                'task_id': task_id,
                'status': 'processing',
                'step': info.get('step', ''),
                'progress': info.get('progress', 0),
                'message': f"Processing: {info.get('step', 'In progress...')}"
            }
        elif task.state == 'SUCCESS':
            result = task.result
            response = {
                'task_id': task_id,
                'status': 'completed',
                'result': result,
                'message': 'Task completed successfully'
            }
        elif task.state == 'FAILURE':
            response = {
                'task_id': task_id,
                'status': 'failed',
                'error': str(task.info),
                'message': 'Task failed'
            }
        else:
            response = {
                'task_id': task_id,
                'status': task.state.lower(),
                'message': f'Task state: {task.state}'
            }
        
        return jsonify(response), 200
        
    except Exception as e:
        logger.error(f"Failed to get task status: {str(e)}", exc_info=True)
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

# Rainfall Analysis Endpoints

@app.route('/api/rainfall/slope', methods=['POST'])
def rainfall_slope():
    """
    Compute rainfall slope/trend (temporal change in rainfall)
    
    Request body:
    {
        "area_geometry": {...},  # GeoJSON geometry
        "start_year": 1993,
        "end_year": 2024
    }
    """
    try:
        data = request.get_json()
        
        # Validate required fields
        if not data or 'area_geometry' not in data:
            return jsonify({
                'success': False,
                'error': 'Missing required field: area_geometry'
            }), 400
        
        if 'start_year' not in data or 'end_year' not in data:
            return jsonify({
                'success': False,
                'error': 'Missing required fields: start_year and end_year'
            }), 400
        
        geometry_json = data['area_geometry']
        start_year = int(data['start_year'])
        end_year = int(data['end_year'])
        
        logger.info(f"Computing rainfall slope: {start_year}-{end_year}")
        
        # Convert GeoJSON to EE Geometry
        geometry = gee_service.geometry_from_geojson(geometry_json)
        
        # Compute rainfall slope
        result = rainfall_calculator.compute_rainfall_slope(
            geometry,
            start_year,
            end_year
        )
        
        return jsonify({
            'success': True,
            'data': result
        }), 200
        
    except ValueError as e:
        logger.error(f"Validation error: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 400
    except Exception as e:
        logger.error(f"Failed to compute rainfall slope: {str(e)}", exc_info=True)
        return jsonify({
            'success': False,
            'error': f'Failed to compute rainfall slope: {str(e)}'
        }), 500

@app.route('/api/rainfall/cv', methods=['POST'])
def rainfall_cv():
    """
    Compute rainfall coefficient of variation (inter-annual variability)
    
    Request body:
    {
        "area_geometry": {...},  # GeoJSON geometry
        "start_year": 1993,
        "end_year": 2024
    }
    """
    try:
        data = request.get_json()
        
        # Validate required fields
        if not data or 'area_geometry' not in data:
            return jsonify({
                'success': False,
                'error': 'Missing required field: area_geometry'
            }), 400
        
        if 'start_year' not in data or 'end_year' not in data:
            return jsonify({
                'success': False,
                'error': 'Missing required fields: start_year and end_year'
            }), 400
        
        geometry_json = data['area_geometry']
        start_year = int(data['start_year'])
        end_year = int(data['end_year'])
        
        logger.info(f"Computing rainfall CV: {start_year}-{end_year}")
        
        # Convert GeoJSON to EE Geometry
        geometry = gee_service.geometry_from_geojson(geometry_json)
        
        # Compute rainfall CV
        result = rainfall_calculator.compute_rainfall_cv(
            geometry,
            start_year,
            end_year
        )
        
        return jsonify({
            'success': True,
            'data': result
        }), 200
        
    except ValueError as e:
        logger.error(f"Validation error: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 400
    except Exception as e:
        logger.error(f"Failed to compute rainfall CV: {str(e)}", exc_info=True)
        return jsonify({
            'success': False,
            'error': f'Failed to compute rainfall CV: {str(e)}'
        }), 500

@app.route('/api/rainfall/slope-grid', methods=['POST'])
def rainfall_slope_grid():
    """
    Compute rainfall slope with spatial grid data for map visualization
    
    Request body:
    {
        "area_geometry": {...},  # GeoJSON geometry
        "start_year": 1993,
        "end_year": 2024,
        "grid_size": 50  # Optional, default 50
    }
    """
    try:
        data = request.get_json()
        
        # Validate required fields
        if not data or 'area_geometry' not in data:
            return jsonify({
                'success': False,
                'error': 'Missing required field: area_geometry'
            }), 400
        
        if 'start_year' not in data or 'end_year' not in data:
            return jsonify({
                'success': False,
                'error': 'Missing required fields: start_year and end_year'
            }), 400
        
        geometry_json = data['area_geometry']
        start_year = int(data['start_year'])
        end_year = int(data['end_year'])
        grid_size = int(data.get('grid_size', 50))
        
        logger.info(f"Computing rainfall slope grid: {start_year}-{end_year}, grid_size={grid_size}")
        
        # Convert GeoJSON to EE Geometry
        geometry = gee_service.geometry_from_geojson(geometry_json)
        
        # Compute rainfall slope grid
        result = rainfall_calculator.compute_rainfall_slope_grid(
            geometry,
            start_year,
            end_year,
            grid_size=grid_size
        )
        
        return jsonify({
            'success': True,
            'data': result
        }), 200
        
    except ValueError as e:
        logger.error(f"Validation error: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 400
    except Exception as e:
        logger.error(f"Failed to compute rainfall slope grid: {str(e)}", exc_info=True)
        return jsonify({
            'success': False,
            'error': f'Failed to compute rainfall slope grid: {str(e)}'
        }), 500

@app.route('/api/rainfall/cv-grid', methods=['POST'])
def rainfall_cv_grid():
    """
    Compute rainfall CV with spatial grid data for map visualization
    
    Request body:
    {
        "area_geometry": {...},  # GeoJSON geometry
        "start_year": 1993,
        "end_year": 2024,
        "grid_size": 50  # Optional, default 50
    }
    """
    try:
        data = request.get_json()
        
        # Validate required fields
        if not data or 'area_geometry' not in data:
            return jsonify({
                'success': False,
                'error': 'Missing required field: area_geometry'
            }), 400
        
        if 'start_year' not in data or 'end_year' not in data:
            return jsonify({
                'success': False,
                'error': 'Missing required fields: start_year and end_year'
            }), 400
        
        geometry_json = data['area_geometry']
        start_year = int(data['start_year'])
        end_year = int(data['end_year'])
        grid_size = int(data.get('grid_size', 50))
        
        logger.info(f"Computing rainfall CV grid: {start_year}-{end_year}, grid_size={grid_size}")
        
        # Convert GeoJSON to EE Geometry
        geometry = gee_service.geometry_from_geojson(geometry_json)
        
        # Compute rainfall CV grid
        result = rainfall_calculator.compute_rainfall_cv_grid(
            geometry,
            start_year,
            end_year,
            grid_size=grid_size
        )
        
        return jsonify({
            'success': True,
            'data': result
        }), 200
        
    except ValueError as e:
        logger.error(f"Validation error: {str(e)}")
        return jsonify({
            'success': False,
            'error': str(e)
        }), 400
    except Exception as e:
        logger.error(f"Failed to compute rainfall CV grid: {str(e)}", exc_info=True)
        return jsonify({
            'success': False,
            'error': f'Failed to compute rainfall CV grid: {str(e)}'
        }), 500

if __name__ == '__main__':
    app.run(
        host=Config.HOST,
        port=Config.PORT,
        debug=Config.DEBUG
    )

