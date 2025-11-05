#!/usr/bin/env php
<?php
/**
 * Convert UBZ_TJK shapefile to GeoJSON, filtering only Tajikistan (TJK) features
 * 
 * Usage: php scripts/convert-shapefile-to-geojson.php [path/to/shapefile]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Configuration
$shapefileDir = $argv[1] ?? __DIR__ . '/../storage/app/public';
$shapefileName = 'UBZ_TJK';
$outputDir = __DIR__ . '/../storage/app/public';
$outputFile = $outputDir . '/UBZ_TJK-boundaries.geojson';

echo "=== Converting Shapefile to GeoJSON (TJK only) ===" . PHP_EOL . PHP_EOL;

// Check if shapefile exists
$shapefilePath = $shapefileDir . '/' . $shapefileName . '.shp';
if (!file_exists($shapefilePath)) {
    echo "❌ Error: Shapefile not found at {$shapefilePath}" . PHP_EOL;
    echo PHP_EOL . "Please ensure the following files are present:" . PHP_EOL;
    echo "  - {$shapefileName}.shp" . PHP_EOL;
    echo "  - {$shapefileName}.shx" . PHP_EOL;
    echo "  - {$shapefileName}.dbf" . PHP_EOL;
    echo "  - {$shapefileName}.prj (optional)" . PHP_EOL;
    echo "  - {$shapefileName}.cpg (optional)" . PHP_EOL . PHP_EOL;
    exit(1);
}

echo "✓ Shapefile found: {$shapefilePath}" . PHP_EOL . PHP_EOL;

// Check if ogr2ogr is available
$ogr2ogrPath = trim(shell_exec('which ogr2ogr 2>/dev/null'));
if (empty($ogr2ogrPath)) {
    echo "❌ Error: ogr2ogr (GDAL) is not installed" . PHP_EOL;
    echo "Install it with: sudo apt-get install gdal-bin" . PHP_EOL;
    exit(1);
}

echo "✓ GDAL tools available" . PHP_EOL . PHP_EOL;

// Create temporary output file
$tempFile = $outputDir . '/UBZ_TJK-temp.geojson';

echo "Converting shapefile to GeoJSON..." . PHP_EOL;

// Convert shapefile to GeoJSON
$command = sprintf(
    'ogr2ogr -f "GeoJSON" -t_srs EPSG:4326 -lco COORDINATE_PRECISION=6 "%s" "%s" 2>&1',
    escapeshellarg($tempFile),
    escapeshellarg($shapefilePath)
);

exec($command, $output, $returnCode);

if ($returnCode !== 0) {
    echo "❌ Error converting shapefile:" . PHP_EOL;
    echo implode(PHP_EOL, $output) . PHP_EOL;
    exit(1);
}

if (!file_exists($tempFile)) {
    echo "❌ Error: Temporary GeoJSON file was not created" . PHP_EOL;
    exit(1);
}

echo "✓ Conversion complete. Filtering Tajikistan features..." . PHP_EOL;

// Load and filter GeoJSON
$geojson = json_decode(file_get_contents($tempFile), true);

if (!$geojson || !isset($geojson['features'])) {
    echo "❌ Error: Invalid GeoJSON format" . PHP_EOL;
    unlink($tempFile);
    exit(1);
}

echo "Total features in shapefile: " . count($geojson['features']) . PHP_EOL;

// Filter features - keep only Tajikistan
$tjkFeatures = [];
$skippedFeatures = [];

foreach ($geojson['features'] as $feature) {
    $props = $feature['properties'] ?? [];
    
    // Check various possible field names for country identification
    $countryFields = [
        $props['ISO_A3'] ?? null,
        $props['ISO'] ?? null,
        $props['ADM0_A3'] ?? null,
        $props['COUNTRY'] ?? null,
        $props['NAME_EN'] ?? null,
        $props['NAME'] ?? null,
        $props['ADMIN'] ?? null,
        $props['NAME_0'] ?? null,
        $props['shapeISO'] ?? null,
        $props['shapeGroup'] ?? null,
    ];
    
    // Check if any field contains Tajikistan or TJK
    $isTjk = false;
    foreach ($countryFields as $fieldValue) {
        if ($fieldValue) {
            $fieldStr = strtoupper((string)$fieldValue);
            if (strpos($fieldStr, 'TJK') !== false || strpos($fieldStr, 'TAJIKISTAN') !== false) {
                $isTjk = true;
                break;
            }
        }
    }
    
    if ($isTjk) {
        $tjkFeatures[] = $feature;
    } else {
        $skippedFeatures[] = $feature;
    }
}

// Update GeoJSON with filtered features
$geojson['features'] = $tjkFeatures;

// Save filtered GeoJSON
file_put_contents($outputFile, json_encode($geojson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Clean up temp file
unlink($tempFile);

echo PHP_EOL . "✓ Filtering complete!" . PHP_EOL;
echo "  Tajikistan features: " . count($tjkFeatures) . PHP_EOL;
echo "  Skipped features: " . count($skippedFeatures) . PHP_EOL;
echo "  Output: {$outputFile}" . PHP_EOL . PHP_EOL;

// Show sample of properties from first feature
if (count($tjkFeatures) > 0) {
    echo "Sample properties from first Tajikistan feature:" . PHP_EOL;
    $sampleProps = $tjkFeatures[0]['properties'] ?? [];
    foreach ($sampleProps as $key => $value) {
        if (is_string($value) && strlen($value) > 100) {
            $value = substr($value, 0, 100) . '...';
        }
        echo "  {$key}: " . (is_array($value) ? json_encode($value) : $value) . PHP_EOL;
    }
    echo PHP_EOL;
}

echo "✅ Done! GeoJSON is ready for import." . PHP_EOL . PHP_EOL;
echo "To import, update import-geometries.php to use:" . PHP_EOL;
echo "  storage/app/public/UBZ_TJK-boundaries.geojson" . PHP_EOL . PHP_EOL;


