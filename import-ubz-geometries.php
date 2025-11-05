<?php
/**
 * Import geometries from UBZ_TJK-boundaries.geojson
 * This script is specifically for importing the converted shapefile
 * 
 * Usage:
 *   php import-ubz-geometries.php [mode]
 * 
 * Modes:
 *   update          - Update all geometries (default, overwrites existing)
 *   skip-existing   - Only import geometries that don't exist yet
 *   backup          - Create backup before updating geometries
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Region;
use App\Models\District;

$geojsonPath = __DIR__ . '/storage/app/public/UBZ_TJK-boundaries.geojson';

if (!file_exists($geojsonPath)) {
    echo "❌ Error: GeoJSON file not found at: {$geojsonPath}" . PHP_EOL;
    echo PHP_EOL . "Please run the conversion script first:" . PHP_EOL;
    echo "  php scripts/convert-shapefile-to-geojson.php" . PHP_EOL;
    exit(1);
}

echo "Loading GeoJSON file..." . PHP_EOL;
$geojsonContent = file_get_contents($geojsonPath);
$geojson = json_decode($geojsonContent, true);

if (!$geojson || !isset($geojson['features'])) {
    echo "❌ Error: Invalid GeoJSON format" . PHP_EOL;
    exit(1);
}

echo "Total features: " . count($geojson['features']) . PHP_EOL . PHP_EOL;

// Inspect the first feature to see what properties are available
if (count($geojson['features']) > 0) {
    $firstFeature = $geojson['features'][0];
    echo "Available properties in GeoJSON:" . PHP_EOL;
    foreach ($firstFeature['properties'] ?? [] as $key => $value) {
        if (is_string($value) && strlen($value) > 50) {
            $value = substr($value, 0, 50) . '...';
        }
        echo "  - {$key}: " . (is_array($value) ? json_encode($value) : $value) . PHP_EOL;
    }
    echo PHP_EOL;
}

// Create lookup by district name
// Try to match by various possible property names
$geometryLookup = [];
foreach ($geojson['features'] as $feature) {
    $props = $feature['properties'] ?? [];
    
    // Try to extract district name from various possible fields
    $districtName = null;
    $possibleNameFields = ['NAME_EN', 'NAME', 'shapeName', 'ADMIN', 'NAME_1', 'NAME_2', 'DISTRICT'];
    
    foreach ($possibleNameFields as $field) {
        if (isset($props[$field]) && !empty($props[$field])) {
            $districtName = $props[$field];
            // Remove "District" suffix if present to match our naming
            $districtName = preg_replace('/\s+District$/i', '', $districtName);
            break;
        }
    }
    
    if ($districtName) {
        // Try exact match first
        $geometryLookup[$districtName] = $feature['geometry'];
        
        // Also try with "District" suffix
        $geometryLookup[$districtName . ' District'] = $feature['geometry'];
        
        // Store original name for reference
        echo "  Found geometry for: {$districtName} (from field: " . array_search($districtName, $props) . ")" . PHP_EOL;
    }
}

echo PHP_EOL . "Importing district geometries..." . PHP_EOL;

// Check command line arguments for import mode
$mode = $argv[1] ?? 'update'; // 'update', 'skip-existing', or 'backup'
$backupFile = null;

if ($mode === 'backup') {
    $backupFile = __DIR__ . '/storage/app/public/geometry-backup-' . date('Y-m-d-His') . '.json';
    echo "Backup mode: Creating backup at {$backupFile}" . PHP_EOL;
}

$districts = District::all();
$importedCount = 0;
$updatedCount = 0;
$skippedCount = 0;
$notFoundDistricts = [];
$backupData = [];

foreach ($districts as $district) {
    $districtName = $district->name_en;
    $districtNameNoSuffix = preg_replace('/\s+District$/i', '', $districtName);
    
    $newGeometry = null;
    
    // Try exact match
    if (isset($geometryLookup[$districtName])) {
        $newGeometry = $geometryLookup[$districtName];
    }
    // Try without "District" suffix
    elseif (isset($geometryLookup[$districtNameNoSuffix])) {
        $newGeometry = $geometryLookup[$districtNameNoSuffix];
    }
    // Try with "District" suffix
    elseif (isset($geometryLookup[$districtName . ' District'])) {
        $newGeometry = $geometryLookup[$districtName . ' District'];
    }
    
    if ($newGeometry) {
        // Backup existing geometry if in backup mode
        if ($mode === 'backup' && $district->geometry) {
            $backupData[] = [
                'id' => $district->id,
                'name' => $districtName,
                'geometry' => $district->geometry
            ];
        }
        
        // Check if geometry already exists
        $hasExistingGeometry = !empty($district->geometry);
        
        if ($mode === 'skip-existing' && $hasExistingGeometry) {
            echo "  ⊘ {$districtName} (skipped - geometry already exists)" . PHP_EOL;
            $skippedCount++;
            continue;
        }
        
        // Update geometry
        $district->geometry = $newGeometry;
        $district->save();
        
        if ($hasExistingGeometry) {
            echo "  ⟳ {$districtName} (updated)" . PHP_EOL;
            $updatedCount++;
        } else {
            echo "  ✓ {$districtName} (imported)" . PHP_EOL;
            $importedCount++;
        }
    } else {
        echo "  ✗ {$districtName} (not found in GeoJSON)" . PHP_EOL;
        $notFoundDistricts[] = $districtName;
    }
}

// Save backup if requested
if ($mode === 'backup' && !empty($backupData)) {
    file_put_contents($backupFile, json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo PHP_EOL . "✓ Backup saved: {$backupFile}" . PHP_EOL;
}

echo PHP_EOL . "Import summary:" . PHP_EOL;
echo "  New geometries: {$importedCount}" . PHP_EOL;
echo "  Updated geometries: {$updatedCount}" . PHP_EOL;
if ($skippedCount > 0) {
    echo "  Skipped (already exist): {$skippedCount}" . PHP_EOL;
}
echo "  Total processed: " . ($importedCount + $updatedCount) . PHP_EOL;

if (count($notFoundDistricts) > 0) {
    echo PHP_EOL . "⚠ Districts not found in GeoJSON:" . PHP_EOL;
    foreach ($notFoundDistricts as $name) {
        echo "  - {$name}" . PHP_EOL;
    }
    echo PHP_EOL . "Available names in GeoJSON:" . PHP_EOL;
    foreach ($geometryLookup as $name => $geom) {
        echo "  - {$name}" . PHP_EOL;
    }
}

echo PHP_EOL . "Creating region geometries..." . PHP_EOL;

// For Dushanbe - use Vahdat district as it's closest to the capital
echo "  Dushanbe region..." . PHP_EOL;
$dushanbe = Region::where('name_en', 'Dushanbe')->first();
if ($dushanbe && isset($geometryLookup['Vahdat'])) {
    $dushanbe->geometry = $geometryLookup['Vahdat'];
    $dushanbe->save();
    echo "    ✓ Using Vahdat District geometry for Dushanbe city" . PHP_EOL;
}

// For other regions - merge their district geometries
$regions = Region::where('name_en', '!=', 'Dushanbe')->get();
foreach ($regions as $region) {
    $regionDistricts = $region->districts;
    
    if ($regionDistricts->count() > 0) {
        $geometries = [];
        
        foreach ($regionDistricts as $district) {
            if ($district->geometry) {
                $geometries[] = $district->geometry;
            }
        }
        
        if (count($geometries) > 0) {
            // Create a MultiPolygon from all district polygons
            $multiPolygonCoords = [];
            foreach ($geometries as $geom) {
                if ($geom['type'] === 'Polygon') {
                    $multiPolygonCoords[] = $geom['coordinates'];
                } elseif ($geom['type'] === 'MultiPolygon') {
                    $multiPolygonCoords = array_merge($multiPolygonCoords, $geom['coordinates']);
                }
            }
            
            $region->geometry = [
                'type' => 'MultiPolygon',
                'coordinates' => $multiPolygonCoords
            ];
            $region->save();
            echo "    ✓ {$region->name_en} ({$regionDistricts->count()} districts merged)" . PHP_EOL;
        } else {
            echo "    ✗ {$region->name_en} (no district geometries available)" . PHP_EOL;
        }
    } else {
        echo "    ✗ {$region->name_en} (no districts)" . PHP_EOL;
    }
}

echo PHP_EOL . "✓ Geometry import complete!" . PHP_EOL;

