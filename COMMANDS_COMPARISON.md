# Erosion Calculation vs Precomputation Commands

## Overview

There are two main commands for generating erosion data, each serving different purposes:

1. **`erosion:calculate`** - Calculates RUSLE factors and statistics
2. **`erosion:precompute-all`** - Generates visual map tiles and raster files

## Command Comparison

### 1. `erosion:calculate` (CalculateErosionFactors)

**Purpose**: Calculate RUSLE factors and soil erosion statistics

**What it generates**:
- ✅ RUSLE factors (R, K, LS, C, P) with statistics (mean, min, max, std_dev)
- ✅ Soil erosion statistics (mean, min, max, std_dev)
- ✅ Stores in `ErosionCache` table (JSON data)
- ❌ Does NOT generate visual map tiles
- ❌ Does NOT generate GeoTIFF files

**Storage**:
- **Table**: `erosion_caches`
- **Data**: JSON with factors and statistics
- **Expiration**: 30 days (cached)
- **Access**: Via API endpoints for statistics panel

**Usage**:
```bash
# Calculate factors and statistics only
php artisan erosion:calculate --region_id=28 --year=2024 --factors=all

# Calculate and also queue tile generation
php artisan erosion:calculate --region_id=28 --year=2024 --precompute
```

**Output**:
- Displays factors and statistics in terminal
- Stores in database cache
- Optionally queues tile generation (if --precompute flag used)

---

### 2. `erosion:precompute-all` (PrecomputeErosionMaps)

**Purpose**: Generate visual map tiles and raster files for map display

**What it generates**:
- ✅ GeoTIFF raster files (full resolution)
- ✅ Map tiles (zoom levels 8-12) for web display
- ✅ Statistics (mean, min, max, std_dev) - computed as part of tile generation
- ❌ Does NOT store detailed factor breakdowns (only statistics)

**Storage**:
- **Table**: `precomputed_erosion_maps`
- **Files**: 
  - GeoTIFF: `storage/rusle-tiles/geotiffs/{area_type}_{area_id}/{year}/`
  - Tiles: `storage/rusle-tiles/tiles/{area_type}_{area_id}/{year}/{z}/{x}/{y}.png`
- **Data**: Statistics JSON + file paths
- **Expiration**: Permanent (until manually deleted)

**Usage**:
```bash
# Precompute all areas for all years
php artisan erosion:precompute-all --years=2020,2024 --type=all

# Precompute only districts for 2024
php artisan erosion:precompute-all --years=2024 --type=district

# Force recompute existing maps
php artisan erosion:precompute-all --years=2024 --type=all --force
```

**Output**:
- Queues background tasks (Celery)
- Generates files asynchronously
- Updates database with file paths and statistics

---

## Key Differences

| Feature | `erosion:calculate` | `erosion:precompute-all` |
|---------|---------------------|--------------------------|
| **Primary Purpose** | Calculate statistics | Generate visual maps |
| **RUSLE Factors** | ✅ Full breakdown (R, K, LS, C, P) | ❌ Only statistics |
| **Statistics** | ✅ Detailed | ✅ Basic (mean, min, max, std_dev) |
| **Visual Maps** | ❌ No tiles | ✅ GeoTIFF + tiles |
| **Storage Table** | `erosion_caches` | `precomputed_erosion_maps` |
| **Storage Duration** | 30 days (cache) | Permanent (files) |
| **Computation** | Synchronous (waits for result) | Asynchronous (queues tasks) |
| **Use Case** | Statistics panel, factor analysis | Map visualization, tile serving |

---

## When to Use Each Command

### Use `erosion:calculate` when:
- ✅ You need RUSLE factor values (R, K, LS, C, P)
- ✅ You need detailed statistics for analysis
- ✅ You want quick results (synchronous)
- ✅ You're testing or debugging
- ✅ You need data for a specific area/year

### Use `erosion:precompute-all` when:
- ✅ You need visual map tiles for display
- ✅ You want to precompute maps for many areas/years
- ✅ You need GeoTIFF files for analysis
- ✅ You're setting up production data
- ✅ You want permanent file storage

### Use BOTH when:
- ✅ You need both statistics AND visual maps
- ✅ You want comprehensive data for an area

---

## Recommended Workflow

### Option 1: Calculate Only (Statistics)
```bash
# For quick statistics lookup
php artisan erosion:calculate --region_id=28 --year=2024 --factors=all
```
**Result**: Statistics stored in `erosion_caches` (30-day cache)

### Option 2: Precompute Only (Visual Maps)
```bash
# For map visualization
php artisan erosion:precompute-all --years=2024 --type=district
```
**Result**: Tiles + GeoTIFF files stored permanently

### Option 3: Calculate + Precompute (Both)
```bash
# Option A: Run separately
php artisan erosion:calculate --region_id=28 --year=2024 --factors=all
php artisan erosion:precompute-all --years=2024 --type=district

# Option B: Use --precompute flag (calculates + queues tiles)
php artisan erosion:calculate --region_id=28 --year=2024 --precompute
```
**Result**: Both statistics AND visual maps

---

## What Each Command Generates

### `erosion:calculate` Output:
```
ErosionCache Record:
{
  "statistics": {
    "mean_erosion_rate": 60.23,
    "min_erosion_rate": 0.5,
    "max_erosion_rate": 100.45,
    "rusle_factors": {
      "r": 75.85,
      "k": 0.129,
      "ls": 15.21,
      "c": 0.13,
      "p": 0.336
    }
  },
  "factors": {
    "r": { "mean": 75.85, "min": 98.23, "max": 156.78, ... },
    "k": { "mean": 0.129, "min": 0.180, "max": 0.320, ... },
    ...
  }
}
```

### `erosion:precompute-all` Output:
```
PrecomputedErosionMap Record:
{
  "statistics": {
    "mean": 60.23,
    "min": 0.5,
    "max": 100.45,
    "std_dev": 15.67
  },
  "geotiff_path": "storage/rusle-tiles/geotiffs/district_162/2024/erosion_2024.tif",
  "tiles_path": "storage/rusle-tiles/tiles/district_162/2024/"
}
```

---

## Answer to Your Question

**Do you need to run both commands separately?**

**Answer**: It depends on what you need:

1. **If you only need statistics/factors**: 
   - Run `erosion:calculate` only
   - ✅ You get RUSLE factors and statistics

2. **If you only need visual maps**:
   - Run `erosion:precompute-all` only
   - ✅ You get map tiles and basic statistics

3. **If you need BOTH statistics AND visual maps**:
   - **Option A**: Run both commands separately
   - **Option B**: Run `erosion:calculate --precompute` (does both)

**Note**: The `--precompute` flag on `erosion:calculate` will:
- Calculate factors and statistics (stored in ErosionCache)
- Queue tile generation (stored in PrecomputedErosionMap)

So you can get both by running `erosion:calculate --precompute` once!

---

## Summary

| Need | Command | What You Get |
|------|---------|--------------|
| Statistics only | `erosion:calculate` | Factors + statistics in cache |
| Maps only | `erosion:precompute-all` | Tiles + GeoTIFF files |
| Both | `erosion:calculate --precompute` | Factors + statistics + tiles |

**Recommendation**: For production, run `erosion:precompute-all` to generate all maps, then use `erosion:calculate` for specific factor analysis when needed.

