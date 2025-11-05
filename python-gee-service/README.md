# Python Google Earth Engine Service

This Python microservice provides REST API endpoints for RUSLE (Revised Universal Soil Loss Equation) computations using Google Earth Engine.

## Features

- **RUSLE Computation**: Complete soil erosion calculation using R, K, LS, C, and P factors
- **Detailed Grid Generation**: Cell-by-cell erosion data for visualization
- **Time Series Analysis**: Multi-year erosion trends
- **Official Earth Engine Library**: Uses `earthengine-api` for proper expression serialization

## Prerequisites

- Python 3.8 or higher
- Google Earth Engine service account with credentials
- pip package manager

## Installation

### 1. Install Python Dependencies

```bash
cd python-gee-service
pip install -r requirements.txt
```

### 2. Configure Environment

Copy the example environment file and configure it:

```bash
cp .env.example .env
```

Edit `.env` and set your Google Earth Engine credentials:

```
GEE_SERVICE_ACCOUNT_EMAIL=your-service-account@your-project.iam.gserviceaccount.com
GEE_PRIVATE_KEY_PATH=../storage/app/gee-private-key.json
GEE_PROJECT_ID=your-gee-project-id
```

### 3. Verify GEE Credentials

Ensure your GEE private key JSON file is accessible at the path specified in `GEE_PRIVATE_KEY_PATH`.

## Running the Service

### Development Mode

```bash
python app.py
```

### Production Mode with Gunicorn

```bash
gunicorn -w 4 -b 127.0.0.1:5000 app:app
```

### As Systemd Service

Create `/etc/systemd/system/python-gee-service.service`:

```ini
[Unit]
Description=Python GEE Service
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/rusle-icarda/python-gee-service
Environment="PATH=/usr/bin:/usr/local/bin"
ExecStart=/usr/bin/gunicorn -w 4 -b 127.0.0.1:5000 app:app
Restart=always

[Install]
WantedBy=multi-user.target
```

Then enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable python-gee-service
sudo systemctl start python-gee-service
sudo systemctl status python-gee-service
```

## API Endpoints

### GET /api/health

Health check endpoint.

**Response:**
```json
{
  "status": "ok",
  "service": "python-gee-service",
  "gee": {
    "status": "healthy",
    "message": "Earth Engine is operational",
    "project_id": "your-project-id"
  }
}
```

### POST /api/rusle/compute

Compute RUSLE erosion for an area.

**Request:**
```json
{
  "area_geometry": {
    "type": "Polygon",
    "coordinates": [[[lon, lat], ...]]
  },
  "year": 2020,
  "period": "annual"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "statistics": {
      "mean": 15.5,
      "min": 2.3,
      "max": 45.8,
      "std_dev": 8.2
    },
    "year": 2020
  }
}
```

### POST /api/rusle/detailed-grid

Compute detailed erosion grid for visualization.

**Request:**
```json
{
  "area_geometry": {
    "type": "Polygon",
    "coordinates": [[[lon, lat], ...]]
  },
  "year": 2020,
  "grid_size": 10
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "cells": [
      {
        "x": 0,
        "y": 0,
        "erosion_rate": 12.5,
        "geometry": {...}
      },
      ...
    ],
    "statistics": {
      "mean": 15.5,
      "min": 2.3,
      "max": 45.8,
      "std_dev": 8.2
    },
    "grid_size": 10,
    "bbox": [min_lon, min_lat, max_lon, max_lat],
    "cell_count": 100
  }
}
```

### POST /api/rusle/time-series

Compute erosion time series.

**Request:**
```json
{
  "area_geometry": {
    "type": "Polygon",
    "coordinates": [[[lon, lat], ...]]
  },
  "start_year": 2016,
  "end_year": 2024
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "yearly_data": [
      {
        "year": 2016,
        "statistics": {...}
      },
      ...
    ],
    "start_year": 2016,
    "end_year": 2024
  }
}
```

## Error Handling

All endpoints return structured error responses:

```json
{
  "success": false,
  "error": "Error message here"
}
```

HTTP status codes:
- `200`: Success
- `400`: Bad request (validation error)
- `500`: Internal server error

## Logging

Logs are written to stdout/stderr. Configure log level in `.env`:

```
LOG_LEVEL=INFO  # DEBUG, INFO, WARNING, ERROR, CRITICAL
```

## Integration with PHP

The PHP application (`app/Services/GoogleEarthEngineService.php`) should be updated to make HTTP requests to this service instead of directly calling the GEE REST API.

Example PHP integration:
```php
$response = Http::post('http://127.0.0.1:5000/api/rusle/compute', [
    'area_geometry' => $geometry,
    'year' => $year,
    'period' => 'annual'
]);
```

## Troubleshooting

### Service won't start

1. Check if all dependencies are installed: `pip list`
2. Verify GEE credentials are configured correctly
3. Check logs: `sudo journalctl -u python-gee-service -f`

### GEE authentication fails

1. Verify service account email is correct
2. Ensure private key file exists and is readable
3. Check project ID matches your GEE project
4. Verify service account has Earth Engine access

### Performance issues

1. Increase number of Gunicorn workers: `-w 8`
2. Enable caching in PHP layer
3. Optimize grid_size parameter (lower = faster)

## Development

### Running tests

```bash
# TODO: Add pytest tests
```

### Code structure

- `app.py`: Flask application and API endpoints
- `gee_service.py`: Earth Engine initialization and core operations
- `rusle_calculator.py`: RUSLE factor computations
- `config.py`: Configuration management

## License

This service is part of the RUSLE-ICARDA project.

