<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Region;
use App\Models\District;

echo "Loading GeoJSON file..." . PHP_EOL;
$geojson = json_decode(file_get_contents(__DIR__ . '/storage/app/public/geoBoundaries-TJK-ADM2.geojson'), true);
echo "Total features: " . count($geojson['features']) . PHP_EOL . PHP_EOL;

// Create lookup by district name
$geometryLookup = [];
foreach ($geojson['features'] as $feature) {
    $name = $feature['properties']['shapeName'];
    $geometryLookup[$name] = $feature['geometry'];
}

// Import district geometries
echo "Importing district geometries..." . PHP_EOL;
$districts = District::all();
$importedCount = 0;

foreach ($districts as $district) {
    $districtName = $district->name_en;
    
    if (isset($geometryLookup[$districtName])) {
        $district->geometry = $geometryLookup[$districtName];
        $district->save();
        echo "  ✓ {$districtName}" . PHP_EOL;
        $importedCount++;
    } else {
        echo "  ✗ {$districtName} (not found in GeoJSON)" . PHP_EOL;
    }
}

echo PHP_EOL . "Imported {$importedCount} district geometries." . PHP_EOL . PHP_EOL;

// Create region geometries by merging districts
echo "Creating region geometries..." . PHP_EOL;

// For Dushanbe - use Vahdat district as it's closest to the capital
echo "  Dushanbe region..." . PHP_EOL;
$dushanbe = Region::where('name_en', 'Dushanbe')->first();
if ($dushanbe && isset($geometryLookup['Vahdat District'])) {
    // Use Vahdat district geometry as it includes Dushanbe city area
    $dushanbe->geometry = $geometryLookup['Vahdat District'];
    $dushanbe->save();
    echo "    ✓ Using Vahdat District geometry for Dushanbe city" . PHP_EOL;
}

// For other regions - merge their district geometries
$regions = Region::where('name_en', '!=', 'Dushanbe')->get();
foreach ($regions as $region) {
    $regionDistricts = $region->districts;
    
    if ($regionDistricts->count() > 0) {
        // Get all district geometries
        $allCoords = [];
        $geometries = [];
        
        foreach ($regionDistricts as $district) {
            if ($district->geometry) {
                $geometries[] = $district->geometry;
                // Collect all coordinates for bounding box
                if ($district->geometry['type'] === 'Polygon') {
                    $allCoords = array_merge($allCoords, $district->geometry['coordinates'][0]);
                } elseif ($district->geometry['type'] === 'MultiPolygon') {
                    foreach ($district->geometry['coordinates'] as $polygon) {
                        $allCoords = array_merge($allCoords, $polygon[0]);
                    }
                }
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


