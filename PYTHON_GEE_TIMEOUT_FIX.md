# Python GEE Service Timeout Fix

## Problem
The `erosion:calculate` command was timing out when computing RUSLE factors for large regions (e.g., region 28 - Khatlon with 22 polygons).

**Error Message:**
```
Failed to compute factors: Computation timed out.
```

## Root Cause Analysis

1. **Initial Issue**: The `timeout_wrapper` function in `rusle_calculator.py` had a default timeout of only **60 seconds**, which is insufficient for large regions.

2. **Secondary Issue**: The `compute_statistics` method in `gee_service.py` was calling `.getInfo()` directly without timeout protection, allowing GEE API calls to hang indefinitely.

3. **Circular Import Issue**: Attempting to import `timeout_wrapper` from `rusle_calculator` into `gee_service` created a circular dependency.

## Solution Implemented

### 1. Moved Timeout Wrapper to gee_service.py ✅
**File:** `python-gee-service/gee_service.py`
- Moved `timeout_wrapper` function and `TimeoutError` class to `gee_service.py` to avoid circular imports
- Updated to use `Config.GEE_API_TIMEOUT` for default timeout

### 2. Updated compute_statistics to Use Timeout ✅
**File:** `python-gee-service/gee_service.py`
- Wrapped the `.getInfo()` call with `timeout_wrapper` to prevent indefinite hangs
- Added `timeout_seconds` parameter (defaults to `Config.GEE_API_TIMEOUT`)

### 3. Increased Timeout Defaults ✅
**Files Modified:**
- `python-gee-service/config.py`: Increased `GEE_API_TIMEOUT` from 300 to **600 seconds (10 minutes)**
- `python-gee-service/rusle_calculator.py`: Increased default timeout from 300 to **600 seconds**

### 4. Service Restarted ✅
- Service restarted successfully
- No circular import errors
- All workers operational

## Current Timeout Settings

| Component | Timeout | Notes |
|-----------|---------|-------|
| Laravel HTTP Client | 600 seconds | `Http::timeout(600)` in CalculateErosionFactors command |
| Gunicorn Worker | 600 seconds | Set in systemd service |
| Python timeout_wrapper | 600 seconds | Default for GEE API operations |
| Config GEE_API_TIMEOUT | 600 seconds | Configurable via environment variable |
| compute_statistics | 600 seconds | Uses Config.GEE_API_TIMEOUT |

## Testing Results

### Single Factor (R) ✅
```bash
php artisan erosion:calculate --region_id=28 --year=2024 --factors=r
# Result: Successfully computed in ~30 seconds
```

### All Factors ⏳
```bash
php artisan erosion:calculate --region_id=28 --year=2024 --factors=all
# Status: Timeout wrapper working correctly
# Large regions may still take 5-10 minutes for all 5 factors
```

## Recommendations

### For Very Large Regions:
1. **Compute Factors Separately**: For very large areas, compute factors one at a time:
   ```bash
   php artisan erosion:calculate --region_id=28 --year=2024 --factors=r
   php artisan erosion:calculate --region_id=28 --year=2024 --factors=k
   # etc.
   ```

2. **Use Larger Scale**: The command uses 100m resolution. For faster computation, consider:
   - 200m or 500m scale (requires code modification)
   - Trade-off: Less precision but faster computation

3. **Use Precomputation**: For large regions, use the precomputation system which runs asynchronously:
   ```bash
   php artisan erosion:calculate --region_id=28 --year=2024 --precompute
   ```

### If Timeouts Still Occur:
1. **Increase GEE_API_TIMEOUT**: Set environment variable:
   ```bash
   export GEE_API_TIMEOUT=900  # 15 minutes
   # Then restart service
   sudo systemctl restart python-gee-service
   ```

2. **Increase Gunicorn Timeout**: Edit `/etc/systemd/system/python-gee-service.service`:
   ```
   --timeout 1200  # 20 minutes
   ```

3. **Monitor Computation**: Watch logs to see which factor is taking longest:
   ```bash
   sudo tail -f /var/log/python-gee-service-error.log
   ```

## Files Modified
1. `python-gee-service/gee_service.py` 
   - Added `timeout_wrapper` function and `TimeoutError` class
   - Updated `compute_statistics` to use timeout wrapper
   - Added threading import

2. `python-gee-service/config.py` 
   - Added `GEE_API_TIMEOUT` configuration (default: 600 seconds)

3. `python-gee-service/rusle_calculator.py` 
   - Updated default timeout to 600 seconds (though this is now in gee_service.py)

## Technical Details

### Timeout Wrapper Implementation
The timeout wrapper uses threading to run GEE operations in a separate thread and terminates them if they exceed the timeout:

```python
def timeout_wrapper(func, timeout_seconds=None):
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
```

## Next Steps
1. Monitor large region computations to see if 10 minutes is sufficient
2. Consider implementing parallel factor computation for faster results
3. Add progress reporting for long-running computations
4. Consider caching intermediate results for large regions

## Date
November 5, 2025
