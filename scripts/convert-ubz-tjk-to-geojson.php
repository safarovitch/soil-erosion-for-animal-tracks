#!/usr/bin/env php
<?php
/**
 * Convert UBZ_TJK shapefile to GeoJSON
 * This script is specifically for the shapefile located at:
 * /var/www/rusle-icarda/storage/app/public/UBZ_TJK/
 * 
 * Usage: php scripts/convert-ubz-tjk-to-geojson.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Configuration
$shapefileDir = __DIR__ . '/../storage/app/public/UBZ_TJK';
$shapefileName = 'UBZ_TJK';
$outputDir = __DIR__ . '/../storage/app/public';
$outputFile = $outputDir . '/UBZ_TJK-boundaries.geojson';

echo "=== Converting UBZ_TJK Shapefile to GeoJSON ===" . PHP_EOL . PHP_EOL;

// Check if shapefile exists
$shapefilePath = $shapefileDir . '/' . $shapefileName . '.shp';
if (!file_exists($shapefilePath)) {
    echo "❌ Error: Shapefile not found at {$shapefilePath}" . PHP_EOL;
    exit(1);
}

echo "✓ Shapefile found: {$shapefilePath}" . PHP_EOL;

// Check required files
$requiredFiles = ['.shp', '.shx', '.dbf'];
foreach ($requiredFiles as $ext) {
    $file = $shapefileDir . '/' . $shapefileName . $ext;
    if (!file_exists($file)) {
        echo "❌ Error: Required file missing: {$file}" . PHP_EOL;
        exit(1);
    }
}
echo "✓ All required shapefile components found" . PHP_EOL . PHP_EOL;

// Check if ogr2ogr is available
$ogr2ogrPath = trim(shell_exec('which ogr2ogr 2>/dev/null'));
if (empty($ogr2ogrPath)) {
    echo "❌ Error: ogr2ogr (GDAL) is not installed" . PHP_EOL;
    echo "Install it with: sudo apt-get install gdal-bin" . PHP_EOL;
    exit(1);
}

echo "✓ GDAL tools available" . PHP_EOL . PHP_EOL;

// First, inspect the shapefile to see what attributes are available
echo "Inspecting shapefile attributes..." . PHP_EOL;
$inspectCommand = sprintf(
    'ogr2ogr -f "GeoJSON" /dev/stdout "%s" -limit 1 2>/dev/null',
    escapeshellarg($shapefilePath)
);

$sampleJson = shell_exec($inspectCommand);
$sampleData = json_decode($sampleJson, true);

if ($sampleData && isset($sampleData['features'][0]['properties'])) {
    $props = $sampleData['features'][0]['properties'];
    echo "Available properties:" . PHP_EOL;
    foreach ($props as $key => $value) {
        $displayValue = is_string($value) && strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
        echo "  - {$key}: " . (is_array($value) ? json_encode($value) : $displayValue) . PHP_EOL;
    }
    echo PHP_EOL;
}

// Count total features
$countCommand = sprintf(
    'ogrinfo -al -so "%s" 2>/dev/null | grep "Feature Count"',
    escapeshellarg($shapefilePath)
);
$countOutput = shell_exec($countCommand);
preg_match('/(\d+)/', $countOutput, $matches);
$totalFeatures = isset($matches[1]) ? (int)$matches[1] : 0;

echo "Total features in shapefile: {$totalFeatures}" . PHP_EOL . PHP_EOL;

// Convert to GeoJSON
echo "Converting shapefile to GeoJSON..." . PHP_EOL;
$tempFile = $outputDir . '/UBZ_TJK-temp.geojson';

// Use absolute paths without extra escaping
$convertCommand = sprintf(
    'cd %s && ogr2ogr -f "GeoJSON" -t_srs EPSG:4326 -lco COORDINATE_PRECISION=6 %s %s 2>&1',
    escapeshellarg($shapefileDir),
    escapeshellarg($tempFile),
    escapeshellarg($shapefileName . '.shp')
);

exec($convertCommand, $output, $returnCode);

if ($returnCode !== 0) {
    echo "❌ Error converting shapefile:" . PHP_EOL;
    echo implode(PHP_EOL, $output) . PHP_EOL;
    exit(1);
}

if (!file_exists($tempFile)) {
    echo "❌ Error: Temporary GeoJSON file was not created" . PHP_EOL;
    exit(1);
}

echo "✓ Conversion complete" . PHP_EOL . PHP_EOL;

// Load GeoJSON
$geojson = json_decode(file_get_contents($tempFile), true);

if (!$geojson || !isset($geojson['features'])) {
    echo "❌ Error: Invalid GeoJSON format" . PHP_EOL;
    unlink($tempFile);
    exit(1);
}

echo "Filtering Tajikistan features..." . PHP_EOL;

// Filter features - keep only Tajikistan
// Since the shapefile is already in UBZ_TJK directory, it should already be Tajikistan
// But we'll still filter just to be safe
$tjkFeatures = [];
$skippedFeatures = [];

foreach ($geojson['features'] as $feature) {
    $props = $feature['properties'] ?? [];
    
    // Based on metadata, this shapefile has FID fields for each country
    // Filter: keep only features where FID_TJK is not null (has Tajikistan)
    // Skip features where FID_UZB_ad is not null but FID_TJK is null (Uzbekistan only)
    
    $hasTjk = isset($props['FID_TJK']) && $props['FID_TJK'] !== null && $props['FID_TJK'] !== '';
    $hasUzb = isset($props['FID_UZB_ad']) && $props['FID_UZB_ad'] !== null && $props['FID_UZB_ad'] !== '';
    
    // Also check Name field for explicit country names
    $nameField = $props['Name'] ?? $props['NAME'] ?? $props['NAME_EN'] ?? null;
    $nameStr = $nameField ? strtoupper((string)$nameField) : '';
    
    // If it's explicitly Uzbekistan and not Tajikistan, skip it
    if ((strpos($nameStr, 'UZBEKISTAN') !== false || strpos($nameStr, 'UZB') !== false) && !$hasTjk) {
        $skippedFeatures[] = $feature;
        continue;
    }
    
    // Keep features that have Tajikistan (FID_TJK not null) or are Tajikistan by name
    if ($hasTjk || strpos($nameStr, 'TAJIKISTAN') !== false || strpos($nameStr, 'TJK') !== false) {
        $tjkFeatures[] = $feature;
    } elseif (!$hasUzb && !$hasTjk) {
        // If no clear identifier but also no UZB, keep it (might be TJK boundary)
        // This is safer since the file is named UBZ_TJK
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

echo PHP_EOL . "✓ Conversion complete!" . PHP_EOL;
echo "  Tajikistan features: " . count($tjkFeatures) . PHP_EOL;
echo "  Skipped features: " . count($skippedFeatures) . PHP_EOL;
echo "  Output: {$outputFile}" . PHP_EOL . PHP_EOL;

// Show sample of properties from first feature
if (count($tjkFeatures) > 0) {
    echo "Sample properties from first feature:" . PHP_EOL;
    $sampleProps = $tjkFeatures[0]['properties'] ?? [];
    foreach ($sampleProps as $key => $value) {
        if (is_string($value) && strlen($value) > 100) {
            $value = substr($value, 0, 100) . '...';
        }
        echo "  {$key}: " . (is_array($value) ? json_encode($value) : $value) . PHP_EOL;
    }
    echo PHP_EOL;
}

// Show geometry type
if (isset($tjkFeatures[0]['geometry']['type'])) {
    echo "Geometry type: " . $tjkFeatures[0]['geometry']['type'] . PHP_EOL;
}

echo PHP_EOL . "✅ Done! GeoJSON is ready for import." . PHP_EOL . PHP_EOL;
echo "To import, run:" . PHP_EOL;
echo "  php import-ubz-geometries.php [mode]" . PHP_EOL . PHP_EOL;
echo "Modes: update (default), skip-existing, or backup" . PHP_EOL;

