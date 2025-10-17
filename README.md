# Soil Erosion Watch - Tajikistan

A Laravel-based web application for monitoring and analyzing soil erosion in Tajikistan using Google Earth Engine and the Revised Universal Soil Loss Equation (RUSLE).

## Features

- **Interactive Map Interface**: Built with Vue 3, Inertia.js, and OpenLayers
- **RUSLE Calculations**: Integration with Google Earth Engine for soil erosion modeling
- **Time Series Analysis**: Temporal visualization of erosion trends (2016-2024)
- **Custom Data Upload**: Admin interface for uploading GeoTIFF datasets
- **Drawing Tools**: User-defined area analysis
- **Administrative Boundaries**: Tajikistan regions and districts
- **Real-time Analytics**: Usage tracking and performance monitoring

## Technology Stack

- **Backend**: Laravel 11, PHP 8.2+
- **Frontend**: Vue 3, Inertia.js, OpenLayers, Chart.js
- **Database**: PostgreSQL with PostGIS extension
- **Geospatial Processing**: Google Earth Engine REST API, GDAL
- **Authentication**: Laravel Sanctum
- **Styling**: Tailwind CSS

## Prerequisites

- PHP 8.2 or higher
- Composer
- Node.js 18+ and npm
- PostgreSQL 14+ with PostGIS 3.x
- GDAL 3.x (for GeoTIFF processing)
- Google Earth Engine service account credentials

## Installation

1. **Clone the repository**:
   ```bash
   git clone <repository-url>
   cd soil-erosion-app
   ```

2. **Install PHP dependencies**:
   ```bash
   composer install
   ```

3. **Install JavaScript dependencies**:
   ```bash
   npm install
   ```

4. **Environment setup**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

5. **Configure environment variables**:
   ```env
   # Database
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=soil_erosion_app
   DB_USERNAME=postgres
   DB_PASSWORD=your_password

   # Google Earth Engine
   GEE_SERVICE_ACCOUNT_EMAIL=your-service-account@project.iam.gserviceaccount.com
   GEE_PRIVATE_KEY_PATH=gee/private-key.json
   GEE_PROJECT_ID=your-gee-project-id

   # GDAL (if not in PATH)
   GDAL_PATH=gdal
   GDAL2TILES_PATH=gdal2tiles.py
   ```

6. **Database setup**:
   ```bash
   # Create PostgreSQL database with PostGIS
   createdb soil_erosion_app
   psql soil_erosion_app -c "CREATE EXTENSION postgis;"

   # Run migrations
   php artisan migrate

   # Seed database
   php artisan db:seed
   ```

7. **Storage setup**:
   ```bash
   php artisan storage:link
   ```

8. **Build frontend assets**:
   ```bash
   npm run build
   ```

## Google Earth Engine Setup

1. **Create a Google Cloud Project** and enable the Earth Engine API
2. **Create a Service Account** and download the JSON key file
3. **Place the key file** in `storage/gee/private-key.json`
4. **Update environment variables** with your service account details

## GDAL Setup

### Ubuntu/Debian:
```bash
sudo apt-get update
sudo apt-get install gdal-bin
```

### macOS:
```bash
brew install gdal
```

### Windows:
Download from [OSGeo4W](https://trac.osgeo.org/osgeo4w/) or use conda:
```bash
conda install -c conda-forge gdal
```

## Usage

### Development Server

1. **Start Laravel server**:
   ```bash
   php artisan serve
   ```

2. **Start Vite dev server** (in another terminal):
   ```bash
   npm run dev
   ```

3. **Access the application** at `http://localhost:8000`

### Admin Access

- **Default admin credentials**:
  - Email: `admin@soil-erosion.tj`
  - Password: `admin123` (change in production)

### API Endpoints

- **Public API**:
  - `POST /api/erosion/compute` - Compute erosion data
  - `GET /api/erosion/regions` - Get regions list
  - `GET /api/erosion/districts` - Get districts for region

- **Admin API**:
  - `POST /api/admin/datasets/upload` - Upload GeoTIFF datasets
  - `GET /api/admin/datasets` - List datasets
  - `DELETE /api/admin/datasets/{id}` - Delete dataset

## Project Structure

```
app/
├── Http/Controllers/
│   ├── ErosionController.php          # Erosion data endpoints
│   └── Admin/DatasetController.php    # Admin dataset management
├── Models/
│   ├── Region.php                     # Tajikistan regions
│   ├── District.php                   # Tajikistan districts
│   ├── ErosionCache.php              # Cached computation results
│   └── CustomDataset.php             # Uploaded datasets
└── Services/
    ├── GoogleEarthEngineService.php   # GEE API integration
    └── GeoTiffProcessor.php          # GDAL processing

resources/js/
├── Pages/
│   ├── Map.vue                       # Main map interface
│   └── Admin/                        # Admin pages
├── Components/Map/
│   ├── MapView.vue                   # OpenLayers map
│   ├── RegionSelector.vue            # Region/district selection
│   ├── TimeSeriesSlider.vue          # Temporal controls
│   ├── LayerControl.vue              # Layer management
│   ├── DrawingTools.vue              # Drawing interface
│   └── ChartPanel.vue                # Analytics charts
```

## Configuration

### GeoTIFF Processing
Configure in `config/geotiff.php`:
- Upload limits
- Processing parameters
- Output settings

### Google Earth Engine
Configure in `config/earthengine.php`:
- Service account settings
- Cache configuration
- Default parameters

## Deployment

### Production Setup

1. **Set production environment variables**
2. **Optimize Laravel**:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

3. **Build production assets**:
   ```bash
   npm run build
   ```

4. **Set up web server** (Apache/Nginx)
5. **Configure SSL certificates**
6. **Set up database backups**

### Docker Deployment

A `Dockerfile` and `docker-compose.yml` can be created for containerized deployment.

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## License

This project is licensed under the MIT License.

## Support

For support and questions:
- Create an issue in the repository
- Contact the development team

## Acknowledgments

- Google Earth Engine for geospatial computation capabilities
- The original RUSLE JavaScript implementation
- Tajikistan administrative boundary data providers
- OpenLayers and Vue.js communities
