# Tile Projection and Orientation Fix

## Problems Identified

1. **Tiles covering entire country**: Tiles at zoom level 6 are covering the entire country instead of just region 26
2. **Tiles rotated 180 degrees and flipped left-right**: Image orientation is incorrect

## Root Causes

1. **GeoTIFF in wrong CRS**: The GeoTIFF files are in EPSG:4326 (lat/lon) instead of EPSG:3857 (Web Mercator)
2. **Negative pixel height**: The rasterio transform has negative pixel height, causing array to be flipped vertically
3. **Coordinate transformation**: The mapping from Web Mercator coordinates to array indices may be incorrect

## Fixes Applied

### 1. Improved Reprojection Handling
- Added explicit CRS comparison and reprojection logging
- Handle negative pixel height by flipping array and adjusting transform
- Ensure array is always oriented with row 0 at top (north)

### 2. Enhanced Bounds Validation
- Added bounds validation and error handling
- Verify bounds order (west < east, south < north)
- Added debug logging for tile generation bounds

### 3. Coordinate Transformation
- Improved comments explaining Web Mercator vs array coordinate systems
- Fixed Y coordinate mapping to account for Web Mercator Y increasing northward
- Added proper clamping and ordering checks

## Files Modified
- `python-gee-service/tile_generator.py`:
  - Enhanced `_load_raster_data_webmercator()` to handle negative pixel height
  - Improved bounds validation in `_generate_tiles_for_zoom()`
  - Enhanced coordinate transformation in `_render_tile()`

## Testing
After fix, regenerate tiles for region 26 (2013-2023) and verify:
1. Tiles only cover region 26, not entire country
2. Tiles are correctly oriented (not rotated/flipped)
3. Tiles align properly at all zoom levels

## Next Steps
1. Delete existing incorrect tiles: `rm -rf /var/www/rusle-icarda/storage/rusle-tiles/tiles/region_26/2013-2023`
2. Regenerate tiles by re-queuing the calculation
3. Verify tile alignment and orientation














