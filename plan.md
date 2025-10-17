# Transform GEE App to Laravel+Inertia+Vue for Tajikistan

## Architecture Overview

**Backend**: Laravel 11 with Inertia.js

**Frontend**: Vue 3 + OpenLayers for mapping

**GEE Integration**: Google Earth Engine REST API

**Database**: PostgreSQL with PostGIS extension for spatial data

**File Storage**: Laravel filesystem for GeoTIFF uploads

**Processing**: GDAL/rasterio for GeoTIFF processing

## Core Components

### 1. Backend Structure

**Laravel Setup**

- Fresh Laravel 11 installation with Inertia.js + Vue 3
- Install packages: `laravel/sanctum`, `inertiajs/inertia-laravel`, PostGIS support
- Google Earth Engine REST API client service
- GDAL/rasterio integration for GeoTIFF processing

**Database Schema**

- `users` - Admin authentication (extend default)
- `regions` - Tajikistan regions (viloyat level)
- `districts` - Tajikistan districts (nohiya level) 
- `custom_datasets` - Admin-uploaded GeoTIFF metadata
- `erosion_cache` - Cached GEE computation results
- `user_queries` - Usage history tracking
- `time_series_data` - Temporal erosion data

**Key Models**

- `User` (with admin role)
- `Region`, `District` (Tajikistan administrative boundaries)
- `CustomDataset` (uploaded GeoTIFF tracking)
- `ErosionCache` (computation results)
- `UserQuery` (analytics)

### 2. Google Earth Engine Integration

**GEE REST API Service** (`app/Services/GoogleEarthEngineService.php`)

- Authenticate using service account credentials
- Translate core RUSLE calculations from `RUSLE_factors.js` to GEE REST API calls
- Methods:
  - `computeErosionForRegion($geometry, $startDate, $endDate)`
  - `getBareSoilFrequency($geometry, $year)`
  - `getSustainabilityFactor($geometry, $year)`
  - `getTimeSeriesData($geometry, $startYear, $endYear)`

**API Endpoints** (`routes/api.php`)

- `POST /api/erosion/compute` - Trigger erosion calculation
- `GET /api/erosion/cached/{region_id}` - Retrieve cached results
- `POST /api/erosion/timeseries` - Get temporal data
- `POST /api/drawing/analyze` - Analyze user-drawn geometry

### 3. Custom Data Upload System

**Admin Controller** (`app/Http/Controllers/Admin/DatasetController.php`)

- Upload GeoTIFF files (rainfall, custom erosion data)
- Validate file format using GDAL
- Extract metadata (bounds, resolution, CRS)
- Convert to web-optimized tiles (COG - Cloud Optimized GeoTIFF)
- Store original + processed versions

**GeoTIFF Processing** (`app/Services/GeoTiffProcessor.php`)

- Use `symfony/process` to call GDAL commands:
  - `gdalinfo` - Extract metadata
  - `gdal_translate` - Convert to COG format
  - `gdal2tiles.py` - Generate XYZ tiles for OpenLayers
- Store tiles in `storage/app/public/tiles/{dataset_id}/`

**Dataset API Endpoints**

- `POST /api/admin/datasets/upload` (auth:sanctum, admin)
- `GET /api/datasets` - List available custom datasets
- `GET /api/datasets/{id}/tiles/{z}/{x}/{y}.png` - Serve tiles

### 4. Frontend (Vue 3 + OpenLayers)

**Page Components** (`resources/js/Pages/`)

- `Map.vue` - Main map interface
- `Admin/Dashboard.vue` - Admin panel
- `Admin/DatasetUpload.vue` - Upload interface
- `Admin/UsageHistory.vue` - Analytics

**Map Component Features** (`resources/js/Components/Map/`)

- `MapView.vue` - OpenLayers map instance
- `RegionSelector.vue` - Dropdown for region/district selection
- `DrawingTools.vue` - Point/polygon drawing tools
- `LayerControl.vue` - Toggle erosion layers
- `TimeSeriesSlider.vue` - Year slider for temporal visualization
- `LegendPanel.vue` - Color legends
- `ChartPanel.vue` - Charts (using Chart.js)

**OpenLayers Integration**

```javascript
// Base layers: OpenStreetMap
// Vector layers: Tajikistan boundaries (GeoJSON)
// Raster layers: Erosion data (XYZ tiles from GEE or custom uploads)
// Draw interaction: User geometry collection
```

**State Management** (Pinia)

- `useMapStore` - Map state, layers, selected region
- `useDatasetStore` - Available datasets
- `useAuthStore` - User authentication

### 5. Tajikistan Boundaries

**Administrative Data**

- Source: GADM or OpenStreetMap for Tajikistan boundaries
- Seed database with regions (Dushanbe, Sughd, Khatlon, GBAO, RRS)
- Seed districts within each region
- Store geometries in PostGIS

**Migration** (`database/migrations/create_tajikistan_boundaries.php`)

```php
Schema::create('regions', function (Blueprint $table) {
    $table->id();
    $table->string('name_en');
    $table->string('name_tj'); // Tajik name
    $table->geometry('geometry');
    $table->timestamps();
});
```

### 6. Time Series Visualization

**Backend**

- Store pre-computed erosion values per year (2016-2024)
- Cache time series results in `time_series_data` table
- API endpoint: `GET /api/erosion/timeseries/{region_id}`

**Frontend**

- Year slider component (2016-2024)
- Animate erosion layer changes
- Line chart showing erosion trend over time
- Compare multiple years side-by-side

### 7. Authentication & Authorization

**User Roles**

- Public: View maps, select regions, draw areas (no login)
- Admin: Upload datasets, view usage history (requires login)

**Middleware**

- `auth:sanctum` for admin routes
- `role:admin` for upload/analytics

**Admin Panel Routes** (`routes/web.php`)

```php
Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::post('/datasets/upload', [DatasetController::class, 'upload']);
    Route::get('/usage-history', [AnalyticsController::class, 'history']);
});
```

## Implementation Files

### Critical New Files

- `app/Services/GoogleEarthEngineService.php` - GEE REST API client
- `app/Services/GeoTiffProcessor.php` - GDAL wrapper
- `app/Http/Controllers/ErosionController.php` - Erosion calculations
- `app/Http/Controllers/Admin/DatasetController.php` - Upload handler
- `resources/js/Pages/Map.vue` - Main map page
- `resources/js/Components/Map/MapView.vue` - OpenLayers integration
- `resources/js/Components/Map/DrawingTools.vue` - Drawing interface
- `resources/js/Components/Map/TimeSeriesSlider.vue` - Temporal control
- `database/seeders/TajikistanBoundariesSeeder.php` - Load boundaries

### Configuration Files

- `.env` - Add `GEE_SERVICE_ACCOUNT_EMAIL`, `GEE_PRIVATE_KEY_PATH`
- `config/earthengine.php` - GEE configuration
- `config/filesystems.php` - Add 'geotiff' disk

## Dependencies

**Backend (composer.json)**

```json
{
  "laravel/framework": "^11.0",
  "inertiajs/inertia-laravel": "^1.0",
  "laravel/sanctum": "^4.0",
  "guzzlehttp/guzzle": "^7.0"
}
```

**Frontend (package.json)**

```json
{
  "@inertiajs/vue3": "^1.0",
  "vue": "^3.4",
  "ol": "^9.0",
  "chart.js": "^4.0",
  "pinia": "^2.1"
}
```

**System Dependencies**

- GDAL 3.x (for GeoTIFF processing)
- PostgreSQL 14+ with PostGIS 3.x
- Python 3.x (optional, for additional geospatial tools)

## Migration from GEE JavaScript

**Key Translations**

- `RUSLE_factors.js` → GEE REST API calls in `GoogleEarthEngineService.php`
- `drawing_tools.js` → Vue component with OpenLayers Draw interaction
- `charts.js` → Chart.js in Vue components
- `legends.js` → Vue component with dynamic legends
- Pre-computed Kenya data → Cache system for Tajikistan

**Data Flow**

1. User selects region/district → Load boundary from PostGIS
2. Frontend requests erosion data → Check cache
3. If not cached → Call GEE REST API → Cache result
4. Return tiles/GeoJSON to frontend → Render in OpenLayers
5. User draws geometry → Send to backend → Analyze → Return stats
