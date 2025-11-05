"""
Celery background tasks for erosion map generation
"""
from celery_app import celery_app
from raster_generator import ErosionRasterGenerator
from tile_generator import MapTileGenerator
from gee_service import gee_service
import logging
import requests
import json

logger = logging.getLogger(__name__)

@celery_app.task(bind=True, name='tasks.generate_erosion_map')
def generate_erosion_map_task(self, area_type, area_id, year, geometry, bbox):
    """
    Background task to generate erosion map (GeoTIFF + tiles)
    
    Args:
        area_type: 'region' or 'district'
        area_id: ID of the area
        year: Year to compute
        geometry: GeoJSON geometry dict
        bbox: Bounding box [minLon, minLat, maxLon, maxLat]
    
    Returns:
        dict: Result with status, paths, and statistics
    """
    try:
        logger.info(f"=== Starting erosion map generation task ===")
        logger.info(f"Area: {area_type} {area_id}, Year: {year}")
        logger.info(f"Task ID: {self.request.id}")
        
        # Notify Laravel that task has started
        try:
            callback_url = f"http://localhost/api/erosion/task-started"
            callback_data = {
                'task_id': self.request.id,
                'area_type': area_type,
                'area_id': area_id,
                'year': year
            }
            requests.post(callback_url, json=callback_data, timeout=10)
            logger.info("Task started callback sent to Laravel")
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
                'year': year
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
        raster_gen = ErosionRasterGenerator()
        
        geotiff_path, statistics, metadata = raster_gen.generate_geotiff(
            area_type, area_id, year, geometry, bbox
        )
        
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
            geotiff_path, area_type, area_id, year,
            geometry_json=original_geometry  # Pass geometry for boundary clipping
        )
        
        logger.info(f"✓ Tiles generated: {tiles_path}")
        
        # Step 4: Notify Laravel backend
        self.update_state(
            state='PROCESSING',
            meta={'step': 'Updating database', 'progress': 90}
        )
        
        # Update Laravel database via callback (optional)
        try:
            callback_url = f"http://localhost/api/erosion/task-complete"
            callback_data = {
                'task_id': self.request.id,
                'area_type': area_type,
                'area_id': area_id,
                'year': year,
                'geotiff_path': geotiff_path,
                'tiles_path': tiles_path,
                'statistics': statistics,
                'metadata': metadata
            }
            requests.post(callback_url, json=callback_data, timeout=10)
        except Exception as e:
            logger.warning(f"Callback to Laravel failed: {str(e)}")
        
        # Return results
        result = {
            'status': 'completed',
            'geotiff_path': geotiff_path,
            'tiles_path': tiles_path,
            'statistics': statistics,
            'metadata': metadata,
            'area_type': area_type,
            'area_id': area_id,
            'year': year
        }
        
        logger.info(f"=== Task completed successfully ===")
        logger.info(f"Result: {json.dumps(result, indent=2)}")
        
        return result
        
    except Exception as e:
        error_msg = str(e)
        logger.error(f"=== Task failed ===")
        logger.error(f"Error: {error_msg}", exc_info=True)
        
        # Update state to FAILURE
        self.update_state(
            state='FAILURE',
            meta={
                'error': error_msg,
                'area_type': area_type,
                'area_id': area_id,
                'year': year
            }
        )
        
        return {
            'status': 'failed',
            'error': error_msg,
            'area_type': area_type,
            'area_id': area_id,
            'year': year
        }


@celery_app.task(name='tasks.test_task')
def test_task(x, y):
    """Simple test task to verify Celery is working"""
    logger.info(f"Test task: {x} + {y}")
    result = x + y
    logger.info(f"Result: {result}")
    return {'result': result, 'status': 'success'}



