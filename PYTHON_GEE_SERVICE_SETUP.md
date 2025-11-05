# Python Google Earth Engine Service - Setup Complete ✅

## Implementation Summary

A complete Python microservice has been implemented to handle Google Earth Engine API calls with proper expression serialization. This solves the "Invalid JSON payload" errors by using the official Earth Engine Python library.

## What Was Implemented

### ✅ Files Created (7 files)

1. **`python-gee-service/app.py`** (320 lines)
   - Flask REST API server
   - 4 endpoints: health, compute, detailed-grid, time-series
   - Full error handling and validation

2. **`python-gee-service/gee_service.py`** (120 lines)
   - Earth Engine initialization with service account
   - Geometry conversion and processing
   - Statistics computation

3. **`python-gee-service/rusle_calculator.py`** (380 lines)
   - Complete RUSLE implementation
   - All 5 factors: R, K, LS, C, P
   - Detailed grid generation
   - Boundary-aware cell clipping

4. **`python-gee-service/config.py`** (55 lines)
   - Environment variable management
   - Configuration validation
   - Flask and GEE settings

5. **`python-gee-service/requirements.txt`** (8 packages)
   - Earth Engine API
   - Flask ecosystem
   - All dependencies resolved

6. **`python-gee-service/README.md`** (300+ lines)
   - Complete API documentation
   - Installation guide
   - Configuration instructions
   - Troubleshooting tips

7. **`python-gee-service/PYTHON_GEE_SERVICE_IMPLEMENTATION.md`**
   - Implementation details
   - Architecture diagram
   - Next steps guide

### ✅ Dependencies Installed

All Python packages successfully installed in virtual environment:
- `earthengine-api==1.6.14`
- `flask==3.1.2`
- `gunicorn==23.0.0`
- `python-dotenv==1.2.1`
- `requests==2.32.5`
- `numpy==2.3.4`
- `flask-cors==6.0.1`
- `geojson==3.2.0`

### ✅ Virtual Environment Created

Location: `/var/www/rusle-icarda/python-gee-service/venv/`
- Python 3.12.3
- Isolated from system packages
- Ready to activate and run

## Quick Start Guide

### Step 1: Configure Environment

Create `.env` file in `python-gee-service/` directory:

```bash
cd /var/www/rusle-icarda/python-gee-service
nano .env
```

Use the same GEE credentials from your Laravel `.env`:

```env
FLASK_ENV=production
DEBUG=False
HOST=127.0.0.1
PORT=5000

# Copy these from Laravel .env
GEE_SERVICE_ACCOUNT_EMAIL=your-email@project.iam.gserviceaccount.com
GEE_PRIVATE_KEY_PATH=../storage/app/gee-private-key.json
GEE_PROJECT_ID=your-project-id

RUSLE_START_YEAR=2016
RUSLE_END_YEAR=2024
DEFAULT_GRID_SIZE=10
MAX_GRID_SIZE=50
LOG_LEVEL=INFO
```

### Step 2: Test the Service

```bash
cd /var/www/rusle-icarda/python-gee-service
source venv/bin/activate
python app.py
```

In another terminal, test:
```bash
curl http://127.0.0.1:5000/api/health
```

Expected response:
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

### Step 3: Set Up Systemd Service

```bash
sudo nano /etc/systemd/system/python-gee-service.service
```

Paste this configuration:

```ini
[Unit]
Description=Python GEE Service for RUSLE
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/rusle-icarda/python-gee-service
Environment="PATH=/var/www/rusle-icarda/python-gee-service/venv/bin:/usr/bin"
ExecStart=/var/www/rusle-icarda/python-gee-service/venv/bin/gunicorn -w 4 -b 127.0.0.1:5000 app:app
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable python-gee-service
sudo systemctl start python-gee-service
sudo systemctl status python-gee-service
```

### Step 4: Update Laravel Configuration

Add to Laravel `.env`:

```env
PYTHON_GEE_SERVICE_URL=http://127.0.0.1:5000
```

### Step 5: Update PHP Service (Next Task)

The `app/Services/GoogleEarthEngineService.php` needs to be updated to call the Python service instead of directly calling GEE API. The methods that need updating:

1. `getDetailedErosionGrid()` - Change to call `POST http://127.0.0.1:5000/api/rusle/detailed-grid`
2. `analyzeGeometry()` - Change to call `POST http://127.0.0.1:5000/api/rusle/compute`
3. Keep existing caching and error handling

## API Endpoints Reference

### 1. Health Check
**GET** `/api/health`

Response:
```json
{
  "status": "ok",
  "service": "python-gee-service",
  "gee": {
    "status": "healthy",
    "message": "Earth Engine is operational",
    "project_id": "..."
  }
}
```

### 2. Compute RUSLE
**POST** `/api/rusle/compute`

Request:
```json
{
  "area_geometry": { /* GeoJSON */ },
  "year": 2020
}
```

Response:
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

### 3. Detailed Grid
**POST** `/api/rusle/detailed-grid`

Request:
```json
{
  "area_geometry": { /* GeoJSON */ },
  "year": 2020,
  "grid_size": 10
}
```

Response:
```json
{
  "success": true,
  "data": {
    "cells": [
      {
        "x": 0,
        "y": 0,
        "erosion_rate": 12.5,
        "geometry": { /* GeoJSON */ }
      }
    ],
    "statistics": { "mean": 15.5, "min": 2.3, "max": 45.8, "std_dev": 8.2 },
    "grid_size": 10,
    "bbox": [68.7, 38.5, 68.9, 38.6],
    "cell_count": 100
  }
}
```

### 4. Time Series
**POST** `/api/rusle/time-series`

Request:
```json
{
  "area_geometry": { /* GeoJSON */ },
  "start_year": 2016,
  "end_year": 2024
}
```

Response:
```json
{
  "success": true,
  "data": {
    "yearly_data": [
      { "year": 2016, "statistics": {...} },
      { "year": 2017, "statistics": {...} },
      ...
    ],
    "start_year": 2016,
    "end_year": 2024
  }
}
```

## Architecture

```
┌──────────┐      HTTP       ┌──────────┐      HTTP       ┌─────────────┐
│ Frontend │ ──────────────> │ Laravel  │ ──────────────> │ Python GEE  │
│   Vue.js │ <────────────── │   PHP    │ <────────────── │   Service   │
└──────────┘      JSON       └──────────┘      JSON       └─────────────┘
                                                                   │
                                                                   │ EE API
                                                                   ▼
                                                           ┌──────────────┐
                                                           │ Google Earth │
                                                           │   Engine     │
                                                           └──────────────┘
```

## Troubleshooting

### Service won't start
```bash
# Check for errors
cd /var/www/rusle-icarda/python-gee-service
source venv/bin/activate
python app.py
```

### Port already in use
```bash
# Find process using port 5000
sudo lsof -i :5000
# Kill if necessary
sudo kill -9 <PID>
```

### GEE authentication error
```bash
# Verify credentials file exists
ls -la ../storage/app/gee-private-key.json

# Test GEE initialization
cd /var/www/rusle-icarda/python-gee-service
source venv/bin/activate
python -c "from gee_service import gee_service; gee_service.initialize(); print('Success!')"
```

### Check service logs
```bash
# If running as systemd service
sudo journalctl -u python-gee-service -f

# If running manually
# Logs appear in terminal
```

## Testing Commands

### Test with Dushanbe coordinates
```bash
curl -X POST http://127.0.0.1:5000/api/rusle/compute \
  -H "Content-Type: application/json" \
  -d '{
    "area_geometry": {
      "type": "Polygon",
      "coordinates": [[[68.7, 38.5], [68.9, 38.5], [68.9, 38.6], [68.7, 38.6], [68.7, 38.5]]]
    },
    "year": 2020
  }'
```

### Test detailed grid
```bash
curl -X POST http://127.0.0.1:5000/api/rusle/detailed-grid \
  -H "Content-Type: application/json" \
  -d '{
    "area_geometry": {
      "type": "Polygon",
      "coordinates": [[[68.7, 38.5], [68.9, 38.5], [68.9, 38.6], [68.7, 38.6], [68.7, 38.5]]]
    },
    "year": 2020,
    "grid_size": 5
  }'
```

## File Structure

```
/var/www/rusle-icarda/
└── python-gee-service/
    ├── venv/                  # Virtual environment (created)
    ├── app.py                 # Flask application ✅
    ├── gee_service.py         # GEE core operations ✅
    ├── rusle_calculator.py    # RUSLE implementation ✅
    ├── config.py              # Configuration ✅
    ├── requirements.txt       # Dependencies ✅
    ├── README.md              # Documentation ✅
    ├── .env                   # Environment config (needs creation)
    └── PYTHON_GEE_SERVICE_IMPLEMENTATION.md  # This file ✅
```

## Success Checklist

- ✅ Python service files created
- ✅ Virtual environment set up
- ✅ Dependencies installed
- ✅ Documentation written
- ⏳ Configure `.env` file
- ⏳ Test health endpoint
- ⏳ Test RUSLE computation
- ⏳ Set up systemd service
- ⏳ Update PHP service to call Python
- ⏳ Test full integration

## Next Steps

1. **Configure `.env`** - Copy GEE credentials from Laravel
2. **Test Python service** - Run and verify health endpoint
3. **Update PHP service** - Modify `GoogleEarthEngineService.php` to call Python API
4. **Test frontend** - Select Dushanbe, enable soil erosion layer
5. **Monitor logs** - Ensure everything works smoothly

---

**Status**: Python service implementation complete. Ready for configuration and PHP integration.

**Location**: `/var/www/rusle-icarda/python-gee-service/`

**Documentation**: See `README.md` and `PYTHON_GEE_SERVICE_IMPLEMENTATION.md` in service directory.

