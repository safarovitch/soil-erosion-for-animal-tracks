"""
Celery application for background task processing
"""
from celery import Celery
from config import Config

# Create Celery app
celery_app = Celery(
    'rusle_tasks',
    broker=f'redis://{Config.REDIS_HOST}:{Config.REDIS_PORT}/{Config.REDIS_DB}',
    backend=f'redis://{Config.REDIS_HOST}:{Config.REDIS_PORT}/{Config.REDIS_DB + 1}',
    include=['tasks']  # Include tasks module
)

# Configure Celery
celery_app.conf.update(
    task_serializer='json',
    result_serializer='json',
    accept_content=['json'],
    timezone='UTC',
    enable_utc=True,
    task_track_started=True,
    task_time_limit=3600,  # 1 hour max per task
    task_soft_time_limit=3300,  # 55 minute soft limit
    worker_prefetch_multiplier=1,  # Fetch one task at a time
    worker_max_tasks_per_child=10,  # Restart worker after 10 tasks (prevent memory leaks)
)

