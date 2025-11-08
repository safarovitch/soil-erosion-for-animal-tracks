#!/usr/bin/env php
<?php
/**
 * Fix Script: Mark completed tasks from Celery logs
 * 
 * This script extracts successful task completions from the Celery log
 * and updates the database accordingly.
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\PrecomputedErosionMap;
use Illuminate\Support\Facades\Log;

echo "===========================================\n";
echo " Fix Completed Tasks from Celery Logs\n";
echo "===========================================\n\n";

// Read Celery log
$logFile = '/var/log/rusle-celery-worker.log';

if (!file_exists($logFile)) {
    echo "❌ Log file not found: $logFile\n";
    exit(1);
}

echo "📖 Reading Celery log...\n";
$logContent = file_get_contents($logFile);

// Extract all successful task completions
preg_match_all(
    '/Task tasks\.generate_erosion_map\[([a-f0-9\-]+)\] succeeded.*?\'area_type\': \'(\w+)\', \'area_id\': (\d+), \'year\': (\d+)/s',
    $logContent,
    $matches,
    PREG_SET_ORDER
);

echo "✓ Found " . count($matches) . " completed tasks in logs\n\n";

if (empty($matches)) {
    echo "ℹ️  No completed tasks to fix\n";
    exit(0);
}

$updated = 0;
$alreadyDone = 0;
$errors = 0;

foreach ($matches as $match) {
    $taskId = $match[1];
    $areaType = $match[2];
    $areaId = (int)$match[3];
    $year = (int)$match[4];

    // Find task result details in log
    $pattern = '/Task tasks\.generate_erosion_map\[' . preg_quote($taskId, '/') . '\] succeeded.*?\{(.*?)\}/s';
    if (preg_match($pattern, $logContent, $resultMatch)) {
        try {
            // Extract the result JSON
            $resultJson = '{' . $resultMatch[1] . '}';
            
            // Clean up the JSON (it's Python repr format, not pure JSON)
            $resultJson = preg_replace("/'/", '"', $resultJson);
            $result = json_decode($resultJson, true);
            
            if (!$result) {
                // Manual extraction
                preg_match("/'geotiff_path': '([^']+)'/", $resultMatch[1], $geotiffMatch);
                preg_match("/'tiles_path': '([^']+)'/", $resultMatch[1], $tilesMatch);
                
                $result = [
                    'geotiff_path' => $geotiffMatch[1] ?? null,
                    'tiles_path' => $tilesMatch[1] ?? null,
                    'statistics' => [],
                    'metadata' => []
                ];
            }

            // Check if already updated
            $map = PrecomputedErosionMap::where([
                'area_type' => $areaType,
                'area_id' => $areaId,
                'year' => $year
            ])->first();

            if ($map && $map->status === 'completed') {
                echo "⏭️  $areaType $areaId, year $year - Already marked completed\n";
                $alreadyDone++;
                continue;
            }

            if (!$map) {
                echo "⚠️  $areaType $areaId, year $year - No database record found, creating...\n";
                $map = PrecomputedErosionMap::create([
                    'area_type' => $areaType,
                    'area_id' => $areaId,
                    'year' => $year,
                    'status' => 'processing'
                ]);
            }

            // Update to completed
            $map->update([
                'status' => 'completed',
                'geotiff_path' => $result['geotiff_path'] ?? null,
                'tiles_path' => $result['tiles_path'] ?? null,
                'statistics' => $result['statistics'] ?? null,
                'metadata' => array_merge(
                    $map->metadata ?? [],
                    ['task_id' => $taskId],
                    $result['metadata'] ?? []
                ),
                'computed_at' => now(),
                'error_message' => null
            ]);

            echo "✅ $areaType $areaId, year $year - Marked as completed\n";
            $updated++;

        } catch (Exception $e) {
            echo "❌ $areaType $areaId, year $year - Error: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
}

echo "\n===========================================\n";
echo " Summary\n";
echo "===========================================\n";
echo "✅ Updated: $updated\n";
echo "⏭️  Already done: $alreadyDone\n";
echo "❌ Errors: $errors\n";
echo "📊 Total processed: " . count($matches) . "\n\n";

// Final stats
$totalCompleted = PrecomputedErosionMap::where('status', 'completed')->count();
$totalProcessing = PrecomputedErosionMap::where('status', 'processing')->count();
$totalAll = PrecomputedErosionMap::count();

echo "Database Status:\n";
echo "  Completed: $totalCompleted\n";
echo "  Processing: $totalProcessing\n";
echo "  Total: $totalAll\n";
echo "\n";

if ($updated > 0) {
    Log::info("Fixed $updated completed tasks from Celery logs");
}

echo "Done! ✨\n";










