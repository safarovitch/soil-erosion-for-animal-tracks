#!/usr/bin/env php
<?php
/**
 * List all available erosion data by area, year, and zoom level
 * 
 * Usage: php scripts/list-available-erosion-data.php [--format=json|table]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$format = $argv[1] ?? 'table';
if (strpos($format, '--format=') === 0) {
    $format = substr($format, 9);
}

$maps = \App\Models\PrecomputedErosionMap::where('status', 'completed')
    ->with(['area'])
    ->orderBy('area_type')
    ->orderBy('area_id')
    ->orderBy('year')
    ->get();

// Group by area
$grouped = [];
foreach ($maps as $map) {
    $areaName = $map->area ? $map->area->name_en : 'Unknown (ID: ' . $map->area_id . ')';
    $key = $map->area_type . '_' . $map->area_id;
    
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'area_type' => $map->area_type,
            'area_id' => $map->area_id,
            'area_name' => $areaName,
            'years' => []
        ];
    }
    
    // Check actual zoom levels from filesystem
    $tilesDir = storage_path('rusle-tiles/tiles/' . $map->area_type . '_' . $map->area_id . '/' . $map->year);
    $zoomLevels = [];
    if (file_exists($tilesDir) && is_dir($tilesDir)) {
        $dirs = array_filter(glob($tilesDir . '/*'), 'is_dir');
        foreach ($dirs as $dir) {
            $zoom = basename($dir);
            if (is_numeric($zoom)) {
                $zoomLevels[] = (int)$zoom;
            }
        }
        sort($zoomLevels);
    }
    
    $grouped[$key]['years'][$map->year] = [
        'year' => $map->year,
        'computed_at' => $map->computed_at ? $map->computed_at->format('Y-m-d H:i:s') : null,
        'zoom_levels' => $zoomLevels,
        'zoom_levels_str' => !empty($zoomLevels) ? implode(', ', $zoomLevels) : 'N/A',
        'tiles_path' => $tilesDir,
        'available' => file_exists($tilesDir) && is_dir($tilesDir)
    ];
}

if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode([
        'summary' => [
            'total_maps' => $maps->count(),
            'total_areas' => count($grouped),
            'generated_at' => date('Y-m-d H:i:s')
        ],
        'areas' => array_values($grouped)
    ], JSON_PRETTY_PRINT);
} else {
    // Table format
    echo "=== EROSION DATA AVAILABILITY SUMMARY ===" . PHP_EOL . PHP_EOL;
    echo "Total completed maps: " . $maps->count() . PHP_EOL;
    echo "Total areas: " . count($grouped) . PHP_EOL;
    echo "Generated at: " . date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL;
    
    foreach ($grouped as $area) {
        echo "--- " . strtoupper($area['area_type']) . ": " . $area['area_name'] . " (ID: " . $area['area_id'] . ") ---" . PHP_EOL;
        echo "Years available: " . count($area['years']) . PHP_EOL;
        
        // Group years by zoom levels
        $yearsByZoom = [];
        foreach ($area['years'] as $yearData) {
            $zoomKey = $yearData['zoom_levels_str'];
            if (!isset($yearsByZoom[$zoomKey])) {
                $yearsByZoom[$zoomKey] = [];
            }
            $yearsByZoom[$zoomKey][] = $yearData['year'];
        }
        
        foreach ($yearsByZoom as $zoomStr => $years) {
            sort($years);
            $yearRange = min($years) . '-' . max($years);
            $yearList = implode(', ', $years);
            echo "  Zoom levels [" . $zoomStr . "]: Years " . $yearRange . " (" . $yearList . ")" . PHP_EOL;
        }
        echo PHP_EOL;
    }
    
    // Detailed year-by-year breakdown
    echo "=== DETAILED YEAR-BY-YEAR BREAKDOWN ===" . PHP_EOL . PHP_EOL;
    foreach ($grouped as $area) {
        echo strtoupper($area['area_type']) . ": " . $area['area_name'] . " (ID: " . $area['area_id'] . ")" . PHP_EOL;
        ksort($area['years']);
        foreach ($area['years'] as $yearData) {
            echo sprintf("  %4d: Zoom [%s] | Computed: %s", 
                $yearData['year'],
                $yearData['zoom_levels_str'],
                $yearData['computed_at'] ?? 'N/A'
            ) . PHP_EOL;
        }
        echo PHP_EOL;
    }
}







