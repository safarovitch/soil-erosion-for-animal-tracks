# Shapefile to GeoJSON Conversion Guide

This guide explains how to convert the UBZ_TJK shapefile to GeoJSON format, filtering only Tajikistan (TJK) boundaries.

## Prerequisites

1. **Shapefile files** - Ensure you have all required shapefile components:
   - `UBZ_TJK.shp` (main shapefile)
   - `UBZ_TJK.shx` (index file)
   - `UBZ_TJK.dbf` (attribute data)
   - `UBZ_TJK.prj` (projection - optional but recommended)
   - `UBZ_TJK.cpg` (code page - optional)

2. **GDAL tools** - Required for conversion:
   ```bash
   sudo apt-get install gdal-bin
   ```

## Step 1: Place Shapefile Files

Copy all shapefile components to the storage directory:

```bash
cp UBZ_TJK.* /var/www/rusle-icarda/storage/app/public/
```

Or place them in any directory and specify the path when running the conversion.

## Step 2: Convert to GeoJSON

You have two options:

### Option A: Using PHP Script (Recommended)

```bash
cd /var/www/rusle-icarda
php scripts/convert-shapefile-to-geojson.php [path/to/shapefile/directory]
```

If shapefiles are in `storage/app/public/`, you can omit the path:
```bash
php scripts/convert-shapefile-to-geojson.php
```

### Option B: Using Bash Script

```bash
cd /var/www/rusle-icarda
./scripts/convert-shapefile-to-geojson.sh [path/to/shapefile/directory]
```

## Step 3: Verify Output

The script will create:
- `storage/app/public/UBZ_TJK-boundaries.geojson`

Check the output:
```bash
# Count features
jq '.features | length' storage/app/public/UBZ_TJK-boundaries.geojson

# View sample properties
jq '.features[0].properties' storage/app/public/UBZ_TJK-boundaries.geojson
```

## Step 4: Import Geometries

Import the converted GeoJSON into the database:

```bash
php import-ubz-geometries.php
```

This script will:
1. Load the GeoJSON file
2. Match district names and import geometries
3. Create region geometries by merging district geometries

## Troubleshooting

### Shapefile not found
- Ensure all shapefile components (.shp, .shx, .dbf) are in the same directory
- Check file permissions

### No features after filtering
- The script filters by country code (TJK) or name (Tajikistan)
- Check the shapefile attributes to see what fields are available:
  ```bash
  ogrinfo -al UBZ_TJK.shp | head -20
  ```

### District names don't match
- The import script tries multiple name matching strategies
- Check the output to see which districts couldn't be matched
- You may need to manually adjust district names in the database

### Coordinate system issues
- The script converts to WGS84 (EPSG:4326) automatically
- If you see coordinate errors, check the .prj file

## Manual Conversion (Alternative)

If the scripts don't work, you can convert manually:

```bash
# Convert to GeoJSON (all features)
ogr2ogr -f "GeoJSON" \
  -t_srs EPSG:4326 \
  -lco COORDINATE_PRECISION=6 \
  UBZ_TJK-boundaries.geojson \
  UBZ_TJK.shp

# Filter only Tajikistan features (if country code field exists)
ogr2ogr -f "GeoJSON" \
  -t_srs EPSG:4326 \
  -where "ISO_A3='TJK'" \
  UBZ_TJK-boundaries.geojson \
  UBZ_TJK.shp
```

## Notes

- The conversion filters out Uzbekistan (UZB) and other countries, keeping only Tajikistan (TJK)
- All coordinates are converted to WGS84 (EPSG:4326) for compatibility
- The output GeoJSON is formatted with 6 decimal places for coordinates
- District names are matched flexibly (with/without "District" suffix)


