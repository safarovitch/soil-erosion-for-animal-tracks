#!/bin/bash
# Convert UBZ_TJK shapefile to GeoJSON, filtering only Tajikistan (TJK) features

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
SHAPEFILE_DIR="${1:-$PROJECT_ROOT/storage/app/public}"
SHAPEFILE_NAME="UBZ_TJK"
OUTPUT_DIR="$PROJECT_ROOT/storage/app/public"
OUTPUT_FILE="$OUTPUT_DIR/UBZ_TJK-boundaries.geojson"

echo "=== Converting Shapefile to GeoJSON (TJK only) ==="
echo ""

# Check if shapefile exists
if [ ! -f "$SHAPEFILE_DIR/$SHAPEFILE_NAME.shp" ]; then
    echo "❌ Error: Shapefile not found at $SHAPEFILE_DIR/$SHAPEFILE_NAME.shp"
    echo ""
    echo "Please ensure the following files are present:"
    echo "  - $SHAPEFILE_NAME.shp"
    echo "  - $SHAPEFILE_NAME.shx"
    echo "  - $SHAPEFILE_NAME.dbf"
    echo "  - $SHAPEFILE_NAME.prj (optional)"
    echo "  - $SHAPEFILE_NAME.cpg (optional)"
    echo ""
    exit 1
fi

echo "✓ Shapefile found: $SHAPEFILE_DIR/$SHAPEFILE_NAME.shp"
echo ""

# Check if ogr2ogr is available
if ! command -v ogr2ogr &> /dev/null; then
    echo "❌ Error: ogr2ogr (GDAL) is not installed"
    echo "Install it with: sudo apt-get install gdal-bin"
    exit 1
fi

echo "✓ GDAL tools available"
echo ""

# First, inspect the shapefile to see what attributes are available
echo "Inspecting shapefile attributes..."
echo ""
ogr2ogr -f "GeoJSON" /dev/stdout "$SHAPEFILE_DIR/$SHAPEFILE_NAME.shp" -limit 1 | jq -r '.features[0].properties | keys[]' 2>/dev/null || echo "Note: Could not inspect attributes (jq may not be installed)"
echo ""

# Check if there's a country code field to filter by
# Common field names: ISO, ISO_A3, ADM0_A3, COUNTRY, NAME_EN, etc.
# We'll try to filter by country code or name

echo "Converting shapefile to GeoJSON (filtering TJK only)..."
echo ""

# Try different filtering approaches
# Method 1: Filter by ISO country code if available
if ogr2ogr -f "GeoJSON" /dev/stdout "$SHAPEFILE_DIR/$SHAPEFILE_NAME.shp" -where "ISO_A3='TJK' OR ISO='TJK' OR ADM0_A3='TJK' OR COUNTRY='Tajikistan'" 2>/dev/null | jq -e '.features | length > 0' > /dev/null 2>&1; then
    echo "Using country code filter..."
    ogr2ogr -f "GeoJSON" \
        -t_srs EPSG:4326 \
        -lco COORDINATE_PRECISION=6 \
        "$OUTPUT_FILE" \
        "$SHAPEFILE_DIR/$SHAPEFILE_NAME.shp" \
        -where "ISO_A3='TJK' OR ISO='TJK' OR ADM0_A3='TJK' OR COUNTRY='Tajikistan'"
    
# Method 2: Convert all and filter in post-processing
else
    echo "Converting all features, will filter Tajikistan in post-processing..."
    TEMP_FILE="$OUTPUT_DIR/UBZ_TJK-temp.geojson"
    
    ogr2ogr -f "GeoJSON" \
        -t_srs EPSG:4326 \
        -lco COORDINATE_PRECISION=6 \
        "$TEMP_FILE" \
        "$SHAPEFILE_DIR/$SHAPEFILE_NAME.shp"
    
    # Filter using Python/jq to keep only Tajikistan features
    if command -v python3 &> /dev/null; then
        echo "Filtering features using Python..."
        python3 << 'PYTHON_SCRIPT'
import json
import sys

with open('$TEMP_FILE', 'r') as f:
    data = json.load(f)

# Filter features - look for Tajikistan in various fields
tjk_features = []
for feature in data.get('features', []):
    props = feature.get('properties', {})
    
    # Check various possible field names for country identification
    country_fields = [
        props.get('ISO_A3'),
        props.get('ISO'),
        props.get('ADM0_A3'),
        props.get('COUNTRY'),
        props.get('NAME_EN'),
        props.get('NAME'),
        props.get('ADMIN'),
        props.get('NAME_0'),
    ]
    
    # Check if any field contains Tajikistan or TJK
    is_tjk = False
    for field_value in country_fields:
        if field_value:
            field_str = str(field_value).upper()
            if 'TJK' in field_str or 'TAJIKISTAN' in field_str:
                is_tjk = True
                break
    
    if is_tjk:
        tjk_features.append(feature)

# Update GeoJSON with filtered features
data['features'] = tjk_features

with open('$OUTPUT_FILE', 'w') as f:
    json.dump(data, f, indent=2)

print(f"Filtered to {len(tjk_features)} Tajikistan features")
PYTHON_SCRIPT
        rm -f "$TEMP_FILE"
    elif command -v jq &> /dev/null; then
        echo "Filtering features using jq..."
        jq '.features |= map(select(.properties.ISO_A3 == "TJK" or .properties.ISO == "TJK" or .properties.ADM0_A3 == "TJK" or (.properties.COUNTRY // "" | ascii_upcase | contains("TAJIKISTAN"))))' "$TEMP_FILE" > "$OUTPUT_FILE"
        rm -f "$TEMP_FILE"
    else
        echo "⚠ Warning: No filtering tool available (python3 or jq)"
        echo "Keeping all features. Please manually filter if needed."
        mv "$TEMP_FILE" "$OUTPUT_FILE"
    fi
fi

# Verify output
if [ -f "$OUTPUT_FILE" ]; then
    FEATURE_COUNT=$(jq '.features | length' "$OUTPUT_FILE" 2>/dev/null || echo "unknown")
    echo ""
    echo "✓ Conversion complete!"
    echo "  Output: $OUTPUT_FILE"
    echo "  Features: $FEATURE_COUNT"
    echo ""
    
    # Show sample of properties
    echo "Sample properties from first feature:"
    jq '.features[0].properties' "$OUTPUT_FILE" 2>/dev/null || echo "  (Could not display - jq may not be installed)"
    echo ""
else
    echo "❌ Error: Output file was not created"
    exit 1
fi

echo "✅ Done! GeoJSON is ready for import."
echo ""
echo "To import, run:"
echo "  php import-geometries.php"
echo ""
echo "Or update import-geometries.php to use:"
echo "  storage/app/public/UBZ_TJK-boundaries.geojson"


