# Boundary Shape Fix Summary

## Problem Identified

The erosion calculation PNG tiles were showing incorrect boundary shapes because:

1. **Geometry Simplification**: Boundaries were simplified (up to 2000m tolerance) before sending to GEE, distorting the actual region shapes
2. **No Boundary Masking**: Tiles were generated from bounding boxes without clipping to actual geometry boundaries
3. **Simplified Geometry Used for Clipping**: Even when clipping occurred, it used the simplified geometry instead of the original

## Data Sent to GEE API

### Input Parameters:
- `area_geometry`: GeoJSON geometry (Polygon or MultiPolygon)
- `year`: Year for temporal data (CHIRPS, Sentinel-2)
- `scale`: Resolution (100m-300m depending on area complexity)

### GEE Operations:
1. **R-factor**: Rainfall erosivity from CHIRPS daily precipitation
2. **K-factor**: Soil erodibility from SoilGrids (clay/silt/sand)
3. **LS-factor**: Slope length/steepness from SRTM DEM
4. **C-factor**: Cover management from Sentinel-2 NDVI
5. **P-factor**: Conservation practices from ESA WorldCover
6. **Final**: `A = R × K × LS × C × P` (soil loss in t/ha/yr)

## Fixes Implemented

### ✅ Fix 1: Reduced Simplification Tolerance
**File:** `raster_generator.py:50`
- Capped at 500m (was up to 2000m)
- Better boundary accuracy while maintaining performance

### ✅ Fix 2: Use Original Geometry for Clipping
**File:** `raster_generator.py:54-70`
- Keep original (unsimplified) geometry for boundary clipping
- Use simplified geometry only for computation (faster)
- Clip final image to original geometry: `soil_loss_image.clip(original_geom)`

### ✅ Fix 3: Add Boundary Masking to Tiles
**File:** `tile_generator.py:190-271`
- Added `_create_geometry_mask()` function using Shapely
- Creates pixel-level mask from original geometry
- Applies mask to each tile so areas outside boundaries are transparent
- Supports both Polygon and MultiPolygon geometries

### ✅ Fix 4: Pass Geometry Through Pipeline
**File:** `tasks.py:96-100`
- Pass original geometry from task to tile generator
- Store original geometry in metadata for future use

## Files Modified

1. `python-gee-service/raster_generator.py`
   - Reduced simplification tolerance
   - Use original geometry for clipping
   - Store original geometry in metadata

2. `python-gee-service/tile_generator.py`
   - Added `geometry_json` parameter
   - Implemented `_create_geometry_mask()` method
   - Apply mask to rendered tiles

3. `python-gee-service/tasks.py`
   - Pass geometry to tile generator

4. `python-gee-service/requirements.txt`
   - Added `shapely>=2.0.0` dependency

## Next Steps

1. **Install dependency:**
   ```bash
   cd /var/www/rusle-icarda/python-gee-service
   source venv/bin/activate
   pip install shapely>=2.0.0
   ```

2. **Restart Celery worker:**
   ```bash
   # Stop current worker
   pkill -f "celery.*worker"
   
   # Start new worker
   cd /var/www/rusle-icarda/python-gee-service
   celery -A celery_app worker --loglevel=info
   ```

3. **Regenerate erosion maps:**
   - New maps will automatically use the improved boundary handling
   - Existing maps need to be regenerated to apply fixes

## Expected Results

After regeneration:
- ✅ Tiles show accurate boundary shapes matching the region/district boundaries
- ✅ Areas outside boundaries are transparent (not showing erosion data)
- ✅ Boundaries follow the actual geometry, not simplified approximations
- ✅ Better visual accuracy when overlaying on maps

## Testing

To verify the fix:
1. Generate a new erosion map for a region/district
2. Check the generated PNG tiles
3. Verify boundaries match the actual region geometry
4. Confirm areas outside boundaries are transparent


