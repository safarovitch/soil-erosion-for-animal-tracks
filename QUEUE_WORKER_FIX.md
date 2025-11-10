# Queue Worker Fix

## Problem
Queue workers were receiving tasks but failing to process them with the error:
```
TypeError: generate_erosion_map_task() got an unexpected keyword argument 'end_year'
```

## Root Cause
The Celery task was being called with mixed positional and keyword arguments:
```python
# WRONG - Mixed positional and keyword arguments
task = generate_erosion_map_task.delay(
    area_type,      # positional
    area_id,        # positional
    start_year,     # positional
    geometry,       # positional
    bbox,           # positional
    end_year=end_year  # keyword - causes error
)
```

When calling Celery tasks with `.delay()`, you cannot mix positional and keyword arguments in this way.

## Solution
Changed the task call to pass all arguments positionally:
```python
# CORRECT - All positional arguments
task = generate_erosion_map_task.delay(
    area_type,
    area_id,
    start_year,
    geometry,
    bbox,
    end_year  # Pass as positional argument (6th parameter)
)
```

## Files Modified
- `python-gee-service/app.py` - Fixed task call on line 680-687

## Verification
1. Worker restarted successfully
2. Check worker logs: `sudo tail -f /var/log/rusle-celery-worker.log`
3. Monitor queue: `redis-cli llen celery`
4. Check database status: `php artisan tinker --execute="echo \App\Models\PrecomputedErosionMap::where('status', 'queued')->count();"`

## Next Steps
1. Test by queuing a new calculation request
2. Monitor logs to ensure tasks are processing successfully
3. If issues persist, check:
   - Redis connectivity
   - Python service logs
   - Worker process health

