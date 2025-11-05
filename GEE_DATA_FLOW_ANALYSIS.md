# GEE Data Flow Analysis - Erosion Calculation

This document explains what data is sent to Google Earth Engine API and identifies the boundary shape issue in PNG tiles.

## Data Sent to GEE API

### 1. **Input Data** (from Laravel → Python Service)

**Endpoint:** `POST /api/rusle/detailed-grid` or `/api/rusle/generate-tiles`

**Payload Structure:**
```json
{
  "area_geometry": {
    "type": "Polygon" | "MultiPolygon",
    "coordinates": [[[lon, lat], ...], ...]
  },
  "year": 2024,
  "grid_size": 50,
  "bbox": [minLon, minLat, maxLon, maxLat]  // Optional
}
```

**Location:** `python-gee-service/app.py` lines 110-159

### 2. **Geometry Processing Flow**

#### Step 1: GeoJSON → EE Geometry Conversion
- **File:** `gee_service.py:112-118`
- **Code:** `geometry = ee.Geometry(geojson)`
- **Result:** Original geometry converted to Earth Engine object

#### Step 2: Geometry Simplification ⚠️ **PROBLEM**
- **File:** `raster_generator.py:48-49`
- **Code:** `simplified_geom = geometry.simplify(maxError=tolerance)`
- **Tolerance values:**
  - Small areas: 500m
  - Medium areas: 1000m  
  - Large areas: 2000m
- **Issue:** This simplification **distorts boundaries** before sending to GEE

#### Step 3: RUSLE Computation
- **File:** `rusle_calculator.py:217-282`
- **What's sent to GEE:**
  - **Simplified geometry** (not original!)
  - **Year** (for temporal data selection)
  - **Scale** (resolution: 100m-300m depending on complexity)
- **GEE Operations:**
  1. Compute R-factor (rainfall) - uses CHIRPS data
  2. Compute K-factor (soil erodibility) - uses SoilGrids
  3. Compute LS-factor (slope) - uses SRTM DEM
  4. Compute C-factor (cover) - uses Sentinel-2 NDVI
  5. Compute P-factor (conservation) - uses ESA WorldCover
  6. Combine: `A = R × K × LS × C × P`
  7. Clip to **simplified geometry**

### 3. **Raster Export**

#### Method A: Small Areas (< 2000 km²)
- **File:** `raster_generator.py:116-166`
- **Method:** `image.getThumbURL()` with `region: simplified_geometry`
- **Issue:** Uses simplified geometry for clipping

#### Method B: Large Areas (≥ 2000 km²)
- **File:** `raster_generator.py:168-249`
- **Method:** Sampling at grid points
- **Process:**
  1. Creates 50×50 grid
  2. Samples points at cell centers
  3. Filters to `simplified_geometry` (line 205)
  4. Creates raster from samples
- **Issue:** Grid cells are rectangles, not clipped to actual boundaries

## Boundary Shape Issue - Root Causes

### Issue 1: Geometry Simplification
**Location:** `raster_generator.py:49`, `rusle_calculator.py:338`

**Problem:**
```python
# Original geometry has precise boundaries
geometry = ee.Geometry(geojson)  # Accurate boundaries

# Simplified geometry loses boundary detail
simplified_geom = geometry.simplify(maxError=1000)  # 1km tolerance!
# This distorts boundaries, especially for complex districts
```

**Impact:**
- Boundaries become rounded/approximated
- Small features may be lost
- Complex coastlines/rivers become simplified

### Issue 2: Tile Generation Without Boundary Clipping
**Location:** `tile_generator.py:94-177`

**Problem:**
```python
# Tiles are generated from bounding box, not clipped to actual boundaries
def _render_tile(self, colored_data, data_bounds, tile_bounds, ...):
    # Extracts data from bounding box
    # No clipping to actual geometry boundaries
    data_slice = colored_data[y1:y2, x1:x2]  # Rectangular slice
```

**Impact:**
- Tiles show data outside actual boundaries
- No transparency mask for areas outside the region
- Boundaries appear rectangular instead of following actual shape

### Issue 3: Grid Cell Sampling
**Location:** `rusle_calculator.py:380-382`

**Problem:**
```python
# Grid cells are rectangles, intersection is computed but not used for rendering
cell_geom = ee.Geometry.Rectangle([min_lon, min_lat, max_lon, max_lat])
clipped_cell = cell_geom.intersection(simplified_geometry, ee.ErrorMargin(1))
# clipped_cell is computed but result uses simple bbox (line 503-514)
```

**Impact:**
- Cells outside boundaries are still included
- No visual clipping to actual boundaries

## Recommended Fixes

### ✅ Fix 1: Use Original Geometry for Clipping (Keep Simplified for Computation) - **IMPLEMENTED**

**File:** `raster_generator.py:47-70`

**Changes:**
- Reduced simplification tolerance cap from 2000m to 500m
- Keep original geometry for boundary clipping
- Clip final image to original geometry (not simplified)

**Code:**
```python
# Reduce tolerance for better boundary accuracy (max 500m instead of 2000m)
tolerance = min(tolerance, 500)
simplified_geom = geometry.simplify(maxError=tolerance)

# Keep original geometry for accurate boundary clipping
original_geom = geometry  # No simplification for boundaries

# Clip to original geometry for accurate boundaries
soil_loss_image = rusle_result['image'].clip(original_geom)
```

### ✅ Fix 2: Add Boundary Masking to Tiles - **IMPLEMENTED**

**File:** `tile_generator.py:133-271`

**Changes:**
- Added `geometry_json` parameter to `generate_tiles()` and `_render_tile()`
- Implemented `_create_geometry_mask()` using Shapely
- Apply mask to each tile to clip to actual boundaries

**Code:**
```python
# Apply boundary mask if geometry is provided
if geometry_json:
    mask = self._create_geometry_mask(geometry_json, tile_bounds, self.tile_size)
    if mask:
        # Apply mask: pixels outside geometry become transparent
        img = Image.composite(img, Image.new('RGBA', img.size, (0, 0, 0, 0)), mask)
```

### ✅ Fix 3: Reduce Simplification Tolerance - **IMPLEMENTED**

**File:** `raster_generator.py:50`

**Changes:**
- Capped simplification tolerance at 500m (was up to 2000m)
- Better boundary accuracy while maintaining performance

### ✅ Fix 4: Pass Geometry to Tile Generator - **IMPLEMENTED**

**File:** `tasks.py:96-100`

**Changes:**
- Pass original geometry from task to tile generator
- Store original geometry in metadata

## Implementation Status

✅ **All fixes have been implemented**

### Dependencies Added:
- `shapely>=2.0.0` (added to `requirements.txt`)

### Testing:
1. Regenerate erosion maps to see improved boundaries
2. Check that tiles show accurate boundary shapes
3. Verify that areas outside boundaries are transparent

### Next Steps:
1. Install shapely: `pip install shapely>=2.0.0`
2. Restart Celery worker to load new code
3. Regenerate existing erosion maps to apply fixes

## Current Data Flow Summary

```
Laravel Request
    ↓
area_geometry (GeoJSON - original boundaries)
    ↓
Python Service: app.py
    ↓
geometry = ee.Geometry(geojson)  ✅ Original
    ↓
simplified_geom = geometry.simplify(maxError=1000m)  ❌ Distorted
    ↓
GEE API: compute_rusle(simplified_geom)  ❌ Uses simplified
    ↓
Export: getThumbURL(region=simplified_geom)  ❌ Clipped to simplified
    ↓
Tile Generation: No boundary mask  ❌ Shows rectangular areas
    ↓
Result: PNG tiles with incorrect boundaries
```

## Verification

To verify what's actually sent to GEE, check logs:

```bash
tail -f /var/log/rusle-celery-worker.log | grep -i "geometry\|simplify\|boundary"
```

Or add debug logging:

```python
logger.info(f"Original geometry coordinates: {len(geojson['coordinates'])}")
logger.info(f"Simplified tolerance: {tolerance}m")
logger.info(f"Using simplified geometry for GEE computation")
```

