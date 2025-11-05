# Data Storage Architecture for Precalculated Factors and Soil Erosion

## Overview

The system uses a **multi-tier storage architecture** for precalculated RUSLE factors and soil erosion data:

1. **Database Tables** - Store metadata, statistics, and references
2. **JSON in Database** - Store detailed factor data and statistics
3. **File System** - Store GeoTIFF rasters and map tiles
4. **Cache System** - Laravel cache for temporary data

---

## Storage Components

### 1. ErosionCache Table (`erosion_caches`)

**Purpose**: Stores computed RUSLE factors and soil erosion statistics for quick retrieval

**Table Structure**:
```sql
erosion_caches
├── id (bigint, primary key)
├── cacheable_type (varchar) - Model class name (Region or District)
├── cacheable_id (bigint) - Region or District ID
├── year (integer) - Year of computation
├── period (varchar) - 'annual', 'monthly', 'seasonal'
├── cache_key (varchar, unique) - Generated key: "erosion_Region_28_2024_annual"
├── data (json) - Complete computation results
├── tile_url (varchar, nullable) - URL to pre-generated tiles
├── expires_at (timestamp, nullable) - Cache expiration (default: 30 days)
├── created_at, updated_at (timestamps)
```

**Data Structure in `data` JSON Field**:
```json
{
  "tiles": null,
  "statistics": {
    "mean_erosion_rate": 60.23,
    "min_erosion_rate": 0.5,
    "max_erosion_rate": 100.45,
    "erosion_cv": 60.1,
    "total_area": 127062.0,
    "rusle_factors": {
      "r": 75.85,
      "k": 0.129,
      "ls": 15.21,
      "c": 0.13,
      "p": 0.336
    },
    "severity_distribution": [],
    "top_eroding_areas": []
  },
  "source": "python_gee_service",
  "timestamp": "2025-11-05T10:30:00Z",
  "factors": {
    "r": {
      "mean": 75.85,
      "min": 98.23,
      "max": 156.78,
      "std_dev": 12.34,
      "unit": "MJ mm/(ha h yr)",
      "description": "Rainfall Erosivity"
    },
    "k": {
      "mean": 0.129,
      "min": 0.180,
      "max": 0.320,
      "std_dev": 0.035,
      "unit": "t ha h/(ha MJ mm)",
      "description": "Soil Erodibility"
    },
    "ls": { ... },
    "c": { ... },
    "p": { ... }
  }
}
```

**Cache Lifecycle**:
- **Created**: When `computeErosionForArea()` is called and computation completes
- **Expiration**: 30 days from creation (`expires_at = now()->addDays(30)`)
- **Retrieval**: Checked before computing new data
- **Cleanup**: Expired entries are ignored but not automatically deleted

**Usage**:
```php
// Check cache before computation
$cached = ErosionCache::findByParameters(
    Region::class,
    28,
    2024,
    'annual'
);

if ($cached && !$cached->isExpired()) {
    return $cached->data; // Return cached data
}

// After computation, cache the result
ErosionCache::create([
    'cacheable_type' => Region::class,
    'cacheable_id' => 28,
    'year' => 2024,
    'period' => 'annual',
    'cache_key' => 'erosion_Region_28_2024_annual',
    'data' => $computedData,
    'expires_at' => now()->addDays(30)
]);
```

---

### 2. PrecomputedErosionMap Table (`precomputed_erosion_maps`)

**Purpose**: Stores metadata and file paths for precomputed raster maps (GeoTIFF + tiles)

**Table Structure**:
```sql
precomputed_erosion_maps
├── id (bigint, primary key)
├── area_type (varchar) - 'region' or 'district'
├── area_id (bigint) - Region or District ID
├── year (integer) - Year of computation
├── status (varchar) - 'pending', 'processing', 'completed', 'failed'
├── geotiff_path (text, nullable) - Path to GeoTIFF file
├── tiles_path (text, nullable) - Path to tiles directory
├── statistics (json, nullable) - Mean, min, max, std_dev
├── metadata (json, nullable) - bbox, cell_count, task_id, etc.
├── computed_at (timestamp, nullable) - When computation completed
├── error_message (text, nullable) - Error details if failed
├── created_at, updated_at (timestamps)
```

**Data Structure in `statistics` JSON Field**:
```json
{
  "mean": 60.23,
  "min": 0.5,
  "max": 100.45,
  "std_dev": 15.67
}
```

**Data Structure in `metadata` JSON Field**:
```json
{
  "task_id": "celery-task-uuid",
  "bbox": [68.0, 36.0, 75.0, 41.0],
  "cell_count": 100,
  "grid_size": 10,
  "scale": 100,
  "computation_time": 120.5
}
```

**File Storage Structure**:
```
storage/rusle-tiles/
├── geotiffs/
│   └── {area_type}_{area_id}/
│       └── {year}/
│           └── erosion_{year}.tif
└── tiles/
    └── {area_type}_{area_id}/
        └── {year}/
            ├── {z}/
            │   ├── {x}/
            │   │   └── {y}.png
            │   └── ...
            └── ...
```

**Example Paths**:
- GeoTIFF: `storage/rusle-tiles/geotiffs/district_162/2024/erosion_2024.tif`
- Tiles: `storage/rusle-tiles/tiles/district_162/2024/8/123/456.png`

**Status Workflow**:
1. `pending` - Task queued but not started
2. `processing` - Task started, computation in progress
3. `completed` - GeoTIFF and tiles generated successfully
4. `failed` - Computation failed, error_message contains details

**Usage**:
```php
// Check if precomputed map exists
$map = PrecomputedErosionMap::where([
    'area_type' => 'district',
    'area_id' => 162,
    'year' => 2024
])->first();

if ($map && $map->isAvailable()) {
    // Use precomputed tiles
    $tileUrl = $map->tile_url;
    // Returns: /api/erosion/tiles/district/162/2024/{z}/{x}/{y}.png
}
```

---

### 3. TimeSeriesData Table (`time_series_data`)

**Purpose**: Stores time series erosion data for trend analysis

**Table Structure**:
```sql
time_series_data
├── id (bigint, primary key)
├── area_type (varchar) - Model class name
├── area_id (bigint) - Region or District ID
├── year (integer)
├── period (varchar) - 'annual', 'monthly', 'seasonal'
├── mean_erosion_rate (decimal 10,3)
├── max_erosion_rate (decimal 10,3)
├── min_erosion_rate (decimal 10,3)
├── total_area_ha (decimal 15,3)
├── erosion_prone_area_ha (decimal 15,3)
├── bare_soil_frequency (decimal 5,2)
├── sustainability_factor (decimal 5,3)
├── monthly_data (json, nullable) - Monthly breakdown
├── created_at, updated_at (timestamps)
```

**Usage**: Historical trend analysis, not currently actively populated by the main computation flow.

---

## Storage Flow

### Computation Flow:

```
1. User Request
   ↓
2. Check ErosionCache (30-day cache)
   ├─ If found and not expired → Return cached data
   └─ If not found or expired → Continue
   ↓
3. Call Python GEE Service
   ↓
4. Python Service computes:
   - R-factor (Rainfall Erosivity)
   - K-factor (Soil Erodibility)
   - LS-factor (Topographic)
   - C-factor (Cover Management)
   - P-factor (Support Practice)
   - Soil Erosion (R × K × LS × C × P)
   ↓
5. Store in ErosionCache
   - JSON data with all factors and statistics
   - Expires in 30 days
   ↓
6. Return data to user
```

### Precomputation Flow (Tiles):

```
1. User requests precomputation
   ↓
2. Create PrecomputedErosionMap record (status: 'pending')
   ↓
3. Queue Celery task
   ↓
4. Update status to 'processing'
   ↓
5. Python service generates:
   - GeoTIFF raster file
   - Map tiles (zoom levels 8-12)
   ↓
6. Store files in storage/rusle-tiles/
   ↓
7. Update PrecomputedErosionMap:
   - status: 'completed'
   - geotiff_path: file path
   - tiles_path: directory path
   - statistics: JSON stats
   - metadata: JSON metadata
   - computed_at: timestamp
```

---

## Data Access Patterns

### 1. Get Cached Erosion Data
```php
$cached = ErosionCache::findByParameters(
    Region::class,
    28,
    2024,
    'annual'
);

if ($cached && !$cached->isExpired()) {
    $data = $cached->data;
    $factors = $data['factors'];
    $statistics = $data['statistics'];
}
```

### 2. Get Precomputed Tiles
```php
$map = PrecomputedErosionMap::where([
    'area_type' => 'district',
    'area_id' => 162,
    'year' => 2024
])->first();

if ($map && $map->isAvailable()) {
    $tileUrl = $map->tile_url; // Returns URL pattern
}
```

### 3. Check Cache Statistics
```php
// Count cached entries
$cacheCount = ErosionCache::count();

// Count precomputed maps
$mapCount = PrecomputedErosionMap::completed()->count();

// Find expired caches
$expired = ErosionCache::where('expires_at', '<', now())->count();
```

---

## Current Storage Statistics

Based on database query:
- **ErosionCache entries**: 64 unique cache entries
- **PrecomputedErosionMap entries**: Multiple completed maps (e.g., district 162 for years 2017-2024)
- **File storage**: Tiles stored in `storage/rusle-tiles/tiles/` for various districts

---

## Key Features

### 1. **Automatic Caching**
- All computations are automatically cached for 30 days
- Prevents redundant GEE API calls
- Speeds up repeated queries

### 2. **Polymorphic Relationships**
- `ErosionCache` uses polymorphic relationships
- Works with both `Region` and `District` models
- Flexible and extensible

### 3. **File Organization**
- GeoTIFFs organized by area and year
- Tiles organized in zoom/x/y structure
- Easy to serve via tile server

### 4. **Status Tracking**
- Precomputed maps track computation status
- Can monitor progress and handle failures
- Metadata includes task IDs for Celery integration

### 5. **JSON Storage**
- Flexible JSON fields for statistics and metadata
- Easy to extend without schema changes
- Efficient querying with PostgreSQL JSON support

---

## Maintenance

### Cleanup Expired Caches
```php
// Manual cleanup (not automated)
ErosionCache::where('expires_at', '<', now())->delete();
```

### Check Storage Usage
```bash
# Check database size
sudo -u postgres psql -d rusle_icarda -c "SELECT pg_size_pretty(pg_database_size('rusle_icarda'));"

# Check file storage size
du -sh /var/www/rusle-icarda/storage/rusle-tiles/
```

---

## Future Enhancements

1. **Automated Cleanup**: Scheduled job to delete expired caches
2. **Cache Warming**: Pre-compute commonly accessed data
3. **Compression**: Compress JSON data for large datasets
4. **Partitioning**: Partition tables by year for better performance
5. **Redis Cache**: Add Redis layer for faster access

---

## Date
November 5, 2025

