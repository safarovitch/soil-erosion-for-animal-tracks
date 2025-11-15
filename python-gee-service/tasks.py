"""
Celery background tasks for erosion map generation
"""
from celery_app import celery_app
from raster_generator import ErosionRasterGenerator
from tile_generator import MapTileGenerator, DEFAULT_ZOOM_LEVELS
from gee_service import gee_service
from config import Config
from rusle_config import build_config
import logging
import requests
import json
from urllib.parse import urljoin

logger = logging.getLogger(__name__)


def _post_to_laravel(path, payload, timeout=10):
    """
    Helper to send JSON payloads to the Laravel callback endpoints
    """
    base_url = Config.LARAVEL_BASE_URL.rstrip('/')
    url = urljoin(base_url + '/', path.lstrip('/'))
    
    headers = {}
    if Config.LARAVEL_HOST_HEADER:
        headers['Host'] = Config.LARAVEL_HOST_HEADER
    
    response = requests.post(
        url,
        json=payload,
        timeout=timeout,
        headers=headers,
        verify=Config.LARAVEL_VERIFY_TLS
    )
    response.raise_for_status()
    return response


@celery_app.task(bind=True, name='tasks.generate_erosion_map')
def generate_erosion_map_task(
    self,
    area_type,
    area_id,
    start_year,
    geometry,
    bbox,
    end_year=None,
    config_overrides=None,
    user_id=None,
    defaults_version=None,
    geometry_hash=None,
    storage_key=None,
):
    """
    Background task to generate erosion map (GeoTIFF + tiles)
    
    Args:
        area_type: 'region' or 'district'
        area_id: ID of the area
        start_year: Start year (or single year) to compute
        end_year: Optional inclusive end year (defaults to start year)
        geometry: GeoJSON geometry dict
        bbox: Bounding box [minLon, minLat, maxLon, maxLat]
    
    Returns:
        dict: Result with status, paths, and statistics
    """
    try:
        end_year = end_year if end_year is not None else start_year
        period_label = str(start_year) if end_year == start_year else f"{start_year}-{end_year}"
        
        logger.info(f"=== Starting erosion map generation task ===")
        logger.info(f"Area: {area_type} {area_id}, Period: {period_label}")
        logger.info(f"Task ID: {self.request.id}")
        tile_path_key = storage_key or f"{area_type}_{area_id}"
        
        # Notify Laravel that task has started
        try:
            callback_data = {
                'task_id': self.request.id,
                'area_type': area_type,
                'area_id': area_id,
                'year': start_year,  # Legacy field for backward compatibility
                'start_year': start_year,
                'end_year': end_year,
                'period_label': period_label,
            }
            if config_overrides:
                callback_data['config_overrides'] = config_overrides
            if user_id is not None:
                callback_data['user_id'] = user_id
            if defaults_version is not None:
                callback_data['defaults_version'] = defaults_version
            if geometry_hash is not None:
                callback_data['geometry_hash'] = geometry_hash
            if tile_path_key:
                callback_data['tile_path_key'] = tile_path_key
            _post_to_laravel('/api/erosion/task-started', callback_data)
            logger.info("Task started callback acknowledged by Laravel")
        except Exception as e:
            logger.warning(f"Task started callback failed: {str(e)}")
        
        # Update status to processing
        self.update_state(
            state='PROCESSING',
            meta={
                'step': 'Initializing',
                'progress': 0,
                'area_type': area_type,
                'area_id': area_id,
                'start_year': start_year,
                'end_year': end_year,
                'period_label': period_label
            }
        )
        
        # Step 1: Initialize GEE (if not already done)
        self.update_state(
            state='PROCESSING',
            meta={'step': 'Initializing Google Earth Engine', 'progress': 5}
        )
        
        if not gee_service.is_initialized():
            logger.info("Initializing GEE...")
            gee_service.initialize()
        
        # Step 2: Generate GeoTIFF raster
        self.update_state(
            state='PROCESSING',
            meta={'step': 'Computing RUSLE raster', 'progress': 20}
        )
        
        logger.info("Starting raster generation...")
        rusle_config = build_config(config_overrides)
        if config_overrides:
            logger.info("Applying custom RUSLE configuration overrides for task %s", self.request.id)
        raster_gen = ErosionRasterGenerator(rusle_config=rusle_config)
        metadata_defaults = {}
        tile_zoom_levels = list(DEFAULT_ZOOM_LEVELS)
        if config_overrides:
            metadata_defaults['config_overrides'] = config_overrides
        if user_id is not None:
            metadata_defaults['user_id'] = user_id
        if defaults_version is not None:
            metadata_defaults['defaults_version'] = defaults_version
        if geometry_hash is not None:
            metadata_defaults['geometry_hash'] = geometry_hash
        if tile_zoom_levels:
            metadata_defaults['max_zoom'] = max(tile_zoom_levels)
        if tile_path_key:
            metadata_defaults['tile_path_key'] = tile_path_key
        if rusle_config.get("logging.include_config_snapshot", True):
            metadata_defaults['rusle_config'] = rusle_config.to_dict()
        metadata = {}

        geotiff_path, statistics, metadata = raster_gen.generate_geotiff(
            area_type,
            area_id,
            start_year,
            geometry,
            bbox,
            end_year=end_year,
            storage_key=tile_path_key
        )
        if metadata_defaults:
            merged_metadata = dict(metadata_defaults)
            if metadata:
                merged_metadata.update(metadata)
            metadata = merged_metadata
        
        logger.info(f"✓ GeoTIFF generated: {geotiff_path}")
        logger.info(f"  Statistics: {statistics}")
        
        # Step 3: Generate map tiles
        self.update_state(
            state='PROCESSING',
            meta={'step': 'Generating map tiles', 'progress': 60}
        )
        
        logger.info("Starting tile generation...")
        tile_gen = MapTileGenerator()
        
        # Pass original geometry for boundary masking
        original_geometry = geometry  # Already in GeoJSON format from task args
        
        tiles_path = tile_gen.generate_tiles(
            geotiff_path,
            area_type,
            area_id,
            start_year,
            zoom_levels=tile_zoom_levels,
            geometry_json=original_geometry,  # Pass geometry for boundary clipping
            end_year=end_year,
            storage_key=tile_path_key
        )
        
        logger.info(f"✓ Tiles generated: {tiles_path}")
        
        # Step 4: Notify Laravel backend
        self.update_state(
            state='PROCESSING',
            meta={'step': 'Updating database', 'progress': 90}
        )
        
        # Update Laravel database via callback (optional)
        try:
            callback_data = {
                'task_id': self.request.id,
                'area_type': area_type,
                'area_id': area_id,
                'year': start_year,  # Legacy field for backward compatibility
                'start_year': start_year,
                'end_year': end_year,
                'period_label': period_label,
                'geotiff_path': geotiff_path,
                'tiles_path': tiles_path,
                'statistics': statistics,
                'metadata': metadata
            }
            if tile_zoom_levels:
                callback_data['max_zoom'] = max(tile_zoom_levels)
            if config_overrides:
                callback_data['config_overrides'] = config_overrides
            if user_id is not None:
                callback_data['user_id'] = user_id
            if defaults_version is not None:
                callback_data['defaults_version'] = defaults_version
            if geometry_hash is not None:
                callback_data['geometry_hash'] = geometry_hash
            if tile_path_key:
                callback_data['tile_path_key'] = tile_path_key
            if rusle_config.get("logging.include_config_snapshot", True):
                callback_data['rusle_config'] = rusle_config.to_dict()
            _post_to_laravel('/api/erosion/task-complete', callback_data, timeout=30)
            logger.info("Task completion callback acknowledged by Laravel")
        except Exception as e:
            logger.warning(f"Task completion callback failed: {str(e)}")
        
        # Return results
        result = {
            'status': 'completed',
            'geotiff_path': geotiff_path,
            'tiles_path': tiles_path,
            'statistics': statistics,
            'metadata': metadata,
            'area_type': area_type,
            'area_id': area_id,
            'start_year': start_year,
            'end_year': end_year,
            'period_label': period_label
        }
        if config_overrides:
            result['config_overrides'] = config_overrides
        if user_id is not None:
            result['user_id'] = user_id
        if defaults_version is not None:
            result['defaults_version'] = defaults_version
        if rusle_config.get("logging.include_config_snapshot", True):
            result['rusle_config'] = rusle_config.to_dict()
        
        logger.info(f"=== Task completed successfully ===")
        # logger.info(f"Result: {json.dumps(result, indent=2)}")
        
        return result
        
    except Exception as e:
        error_msg = str(e)
        error_type = type(e).__name__
        logger.error(f"=== Task failed ===")
        logger.error(f"Error Type: {error_type}")
        logger.error(f"Error: {error_msg}", exc_info=True)
        
        # Notify Laravel that task failed
        try:
            callback_data = {
                'task_id': self.request.id,
                'area_type': area_type,
                'area_id': area_id,
                'year': start_year,
                'start_year': start_year,
                'end_year': end_year,
                'period_label': period_label,
                'error': error_msg,
                'error_type': error_type,
                'metadata': metadata
            }
            if config_overrides:
                callback_data['config_overrides'] = config_overrides
            if user_id is not None:
                callback_data['user_id'] = user_id
            if defaults_version is not None:
                callback_data['defaults_version'] = defaults_version
            if rusle_config.get("logging.include_config_snapshot", True):
                callback_data['rusle_config'] = rusle_config.to_dict()
            _post_to_laravel('/api/erosion/task-failed', callback_data, timeout=30)
            logger.info("Task failed callback acknowledged by Laravel")
        except Exception as callback_error:
            logger.warning(f"Task failed callback failed: {str(callback_error)}")
        
        # Properly raise the exception for Celery to track
        # This ensures Celery can properly serialize the exception
        raise


@celery_app.task(name='tasks.test_task')
def test_task(x, y):
    """Simple test task to verify Celery is working"""
    logger.info(f"Test task: {x} + {y}")
    result = x + y
    logger.info(f"Result: {result}")
    return {'result': result, 'status': 'success'}



