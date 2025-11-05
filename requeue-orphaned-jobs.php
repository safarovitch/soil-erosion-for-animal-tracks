#!/usr/bin/env php
<?php
/**
 * Re-queue orphaned jobs that exist in database but not in Celery
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\PrecomputedErosionMap;
use App\Services\ErosionTileService;

echo "===========================================\n";
echo " Re-queue Orphaned Jobs\n";
echo "===========================================\n\n";

$service = app(ErosionTileService::class);

// Find all orphaned jobs (queued or processing but not completed)
$orphaned = PrecomputedErosionMap::whereIn('status', ['queued', 'processing'])
    ->orderBy('year', 'desc')
    ->orderBy('area_type')
    ->orderBy('area_id')
    ->get();

echo "Found {$orphaned->count()} orphaned jobs\n\n";

if ($orphaned->isEmpty()) {
    echo "✓ No orphaned jobs to re-queue\n";
    exit(0);
}

// Ask for confirmation
echo "This will:\n";
echo "  1. Delete these database records\n";
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

$queued = 0;
$errors = 0;

foreach ($orphaned as $map) {
    try {
        echo "Re-queuing {$map->area_type} {$map->area_id}, year {$map->year}... ";
        
        // Delete the old record
        $map->delete();
        
        // Re-queue via service (which will create new record + queue to Celery)
        $result = $service->getOrQueueMap(
            $map->area_type,
            $map->area_id,
            $map->year
        );
        
        if ($result['status'] === 'queued') {
            echo "✓ Queued (task_id: {$result['task_id']})\n";
            $queued++;
        } elseif ($result['status'] === 'available') {
            echo "⏭️  Already completed\n";
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
echo "✅ Re-queued: $queued\n";
echo "❌ Errors: $errors\n\n";

// Check Redis queue
exec('redis-cli llen celery 2>/dev/null', $output, $ret);
if ($ret === 0) {
    echo "🔄 Redis queue now has: " . $output[0] . " jobs\n\n";
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
echo "  sudo tail -f /var/log/rusle-celery-worker.log\n";
echo "  redis-cli llen celery\n";
echo "\n";





