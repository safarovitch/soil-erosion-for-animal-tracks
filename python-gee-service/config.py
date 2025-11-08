"""
Configuration management for GEE Service
"""
import os
from datetime import datetime
from pathlib import Path
from dotenv import load_dotenv

# Load environment variables
load_dotenv()

class Config:
    """Configuration class for GEE Service"""
    
    # Flask configuration
    FLASK_ENV = os.getenv('FLASK_ENV', 'production')
    DEBUG = os.getenv('DEBUG', 'False').lower() == 'true'
    HOST = os.getenv('HOST', '127.0.0.1')
    PORT = int(os.getenv('PORT', 5000))
    
    # Google Earth Engine configuration
    GEE_SERVICE_ACCOUNT_EMAIL = os.getenv('GEE_SERVICE_ACCOUNT_EMAIL')
    GEE_PRIVATE_KEY_PATH = os.getenv('GEE_PRIVATE_KEY_PATH')
    GEE_PROJECT_ID = os.getenv('GEE_PROJECT_ID')
    
    # RUSLE configuration
    RUSLE_START_YEAR = 1993
    _rusle_end_year_raw = os.getenv('RUSLE_END_YEAR')
    try:
        _rusle_end_year = int(_rusle_end_year_raw) if _rusle_end_year_raw else datetime.now().year
    except (TypeError, ValueError):
        _rusle_end_year = datetime.now().year
    RUSLE_END_YEAR = max(RUSLE_START_YEAR, _rusle_end_year)
    DEFAULT_GRID_SIZE = int(os.getenv('DEFAULT_GRID_SIZE', 10))
    MAX_GRID_SIZE = int(os.getenv('MAX_GRID_SIZE', 500))
    
    # Logging
    LOG_LEVEL = os.getenv('LOG_LEVEL', 'INFO')
    
    # Redis Configuration
    REDIS_HOST = os.getenv('REDIS_HOST', 'localhost')
    REDIS_PORT = int(os.getenv('REDIS_PORT', 6379))
    REDIS_DB = int(os.getenv('REDIS_DB', 0))
    
    # Storage paths
    STORAGE_PATH = os.getenv('STORAGE_PATH', '/var/www/rusle-icarda/storage/rusle-tiles')
    
    # Laravel callback configuration
    LARAVEL_BASE_URL = os.getenv('LARAVEL_BASE_URL', 'https://soil-erosion-tjk.wyzo.app')
    LARAVEL_HOST_HEADER = os.getenv('LARAVEL_HOST_HEADER', 'soil-erosion-tjk.wyzo.app')
    LARAVEL_VERIFY_TLS = os.getenv('LARAVEL_VERIFY_TLS', 'true').lower() != 'false'
    
    # Performance tuning constants
    COMPLEX_GEOMETRY_THRESHOLD = int(os.getenv('COMPLEX_GEOMETRY_THRESHOLD', 500))  # coordinate count
    LARGE_AREA_THRESHOLD_KM2 = float(os.getenv('LARGE_AREA_THRESHOLD_KM2', 1000))
    MAX_SAMPLES_LARGE_AREA = int(os.getenv('MAX_SAMPLES_LARGE_AREA', 50))
    BATCH_SIZE_OPTIMIZED = int(os.getenv('BATCH_SIZE_OPTIMIZED', 50))
    MAX_WORKERS_OPTIMIZED = int(os.getenv('MAX_WORKERS_OPTIMIZED', 8))
    
    # Timeout settings (in seconds)
    GEE_API_TIMEOUT = int(os.getenv('GEE_API_TIMEOUT', 600))  # 10 minutes default for GEE operations (matches Laravel timeout)
    
    @classmethod
    def validate(cls):
        """Validate required configuration"""
        required_vars = [
            'GEE_SERVICE_ACCOUNT_EMAIL',
            'GEE_PRIVATE_KEY_PATH',
            'GEE_PROJECT_ID'
        ]
        
        missing = [var for var in required_vars if not getattr(cls, var)]
        
        if missing:
            raise ValueError(f"Missing required environment variables: {', '.join(missing)}")
        
        # Check if private key file exists
        key_path = Path(cls.GEE_PRIVATE_KEY_PATH)
        if not key_path.exists():
            raise FileNotFoundError(f"GEE private key file not found: {cls.GEE_PRIVATE_KEY_PATH}")
        
        return True

