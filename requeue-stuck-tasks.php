#!/usr/bin/env php
<?php
/**
 * Re-queue stuck tasks that exist in database but not in Celery/Redis
 * This script checks if tasks are actually stuck (not in Redis) before re-queuing
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\PrecomputedErosionMap;
use App\Services\ErosionTileService;

echo "===========================================\n";
echo " Re-queue Stuck Tasks\n";
echo "===========================================\n\n";

$service = app(ErosionTileService::class);

// Find all queued tasks
$queued = PrecomputedErosionMap::where('status', 'queued')
    ->orderBy('created_at', 'asc')
    ->get();

echo "Found {$queued->count()} queued tasks in database\n\n";

if ($queued->isEmpty()) {
    echo "✓ No queued tasks found\n";
    exit(0);
}

// Check Redis queue length
exec('redis-cli llen celery 2>/dev/null', $redisOutput, $redisRet);
$redisQueueLength = ($redisRet === 0 && isset($redisOutput[0])) ? (int)$redisOutput[0] : 0;
echo "Redis queue length: $redisQueueLength\n\n";

// Check which tasks are actually stuck (not in Redis)
$stuckTasks = [];
foreach ($queued as $map) {
    $taskId = $map->metadata['task_id'] ?? null;
    
    if (!$taskId) {
        echo "⚠️  Task {$map->id} ({$map->area_type} {$map->area_id}, year {$map->year}) has no task_id - marking as stuck\n";
        $stuckTasks[] = $map;
        continue;
    }
    
    // Check if task exists in Redis (check both active and scheduled tasks)
    // Note: This is a simple check - if Redis queue is empty and task is old, it's likely stuck
    $taskAge = $map->created_at->diffInMinutes(now());
    
    // If task is older than 5 minutes and Redis queue is empty or very small, consider it stuck
    // Also check if task has been queued for more than 10 minutes (definitely stuck)
    if ($taskAge > 10 || ($taskAge > 5 && $redisQueueLength < 2)) {
        echo "⚠️  Task {$map->id} (task_id: {$taskId}) is {$taskAge} minutes old and likely stuck\n";
        $stuckTasks[] = $map;
    } else {
        echo "✓ Task {$map->id} (task_id: {$taskId}) may still be processing (age: {$taskAge} min)\n";
    }
}

echo "\n";

if (empty($stuckTasks)) {
    echo "✓ No stuck tasks found - all queued tasks appear to be processing\n";
    exit(0);
}

echo "Found " . count($stuckTasks) . " stuck task(s) to re-queue:\n";
foreach ($stuckTasks as $map) {
    $period = $map->metadata['period']['label'] ?? $map->year;
    echo "  - {$map->area_type} {$map->area_id}, period {$period} (ID: {$map->id})\n";
}
echo "\n";

// Ask for confirmation unless --auto flag is provided
$autoMode = in_array('--auto', $argv ?? []);
if (!$autoMode) {
    echo "This will:\n";
    echo "  1. Delete the stuck database records\n";
    echo "  2. Re-queue them to Celery\n\n";
    
    echo "Continue? (y/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if(trim($line) != 'y'){
        echo "Cancelled.\n";
        exit(0);
    }
    fclose($handle);
    echo "\n";
}

$requeued = 0;
$errors = 0;
$skipped = 0;

foreach ($stuckTasks as $map) {
    try {
        $period = $map->metadata['period']['label'] ?? $map->year;
        echo "Re-queuing {$map->area_type} {$map->area_id}, period {$period}... ";
        
        // Get geometry for custom areas
        $geometry = null;
        if ($map->area_type === 'custom') {
            $geometry = $map->geometry_snapshot ?? $map->metadata['geometry_snapshot'] ?? null;
            if (!$geometry) {
                echo "❌ Error: No geometry found for custom area\n";
                $errors++;
                continue;
            }
        }
        
        // Get end_year from metadata if it's a period
        $startYear = $map->year;
        $endYear = $map->metadata['period']['end_year'] ?? $startYear;
        
        // Delete the old record
        $map->delete();
        
        // Re-queue via service
        if ($map->area_type === 'custom') {
            $result = $service->getOrQueueCustomMap(
                $geometry,
                $startYear,
                $endYear !== $startYear ? $endYear : null
            );
        } else {
            $result = $service->getOrQueueMap(
                $map->area_type,
                $map->area_id,
                $startYear,
                $endYear !== $startYear ? $endYear : null
            );
        }
        
        if ($result['status'] === 'queued') {
            echo "✓ Queued (new task_id: {$result['task_id']})\n";
            $requeued++;
        } elseif ($result['status'] === 'available') {
            echo "⏭️  Already completed (was processed while stuck)\n";
            $skipped++;
        } elseif ($result['status'] === 'processing') {
            echo "⏭️  Already processing\n";
            $skipped++;
        } else {
            echo "❌ Error: " . ($result['error'] ?? 'Unknown'). "\n";
            $errors++;
        }
        
        // Small delay to avoid overwhelming the Python service
        usleep(50000); // 50ms
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n===========================================\n";
echo " Summary\n";
echo "===========================================\n";
echo "✅ Re-queued: $requeued\n";
echo "⏭️  Skipped: $skipped\n";
echo "❌ Errors: $errors\n\n";

// Check Redis queue after re-queuing
exec('redis-cli llen celery 2>/dev/null', $redisOutputAfter, $redisRetAfter);
if ($redisRetAfter === 0 && isset($redisOutputAfter[0])) {
    echo "🔄 Redis queue now has: " . $redisOutputAfter[0] . " jobs\n\n";
}

// Final DB status
$total = PrecomputedErosionMap::count();
$completed = PrecomputedErosionMap::where('status', 'completed')->count();
$queued_db = PrecomputedErosionMap::where('status', 'queued')->count();
$processing = PrecomputedErosionMap::where('status', 'processing')->count();

echo "Database Status:\n";
echo "  Total: $total\n";
echo "  Completed: $completed\n";
echo "  Queued: $queued_db\n";
echo "  Processing: $processing\n";
echo "\n";

echo "Done! ✨\n";
echo "\nMonitor progress with:\n";
echo "  ./monitor-precomputation.sh\n";
echo "  redis-cli llen celery\n";
echo "\n";

