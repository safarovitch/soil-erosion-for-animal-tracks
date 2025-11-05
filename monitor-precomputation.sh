#!/bin/bash
# Monitor precomputation progress with live updates

cd /var/www/rusle-icarda

# Function to display status
show_status() {
    clear
    echo "========================================"
    echo " Precomputation Status Monitor (LIVE)"
    echo "========================================"
    echo "Time: $(date '+%Y-%m-%d %H:%M:%S')"
    echo ""
    
    # Get status from database
    php artisan tinker --execute="
    \$total = \App\Models\PrecomputedErosionMap::count();
    \$completed = \App\Models\PrecomputedErosionMap::where('status', 'completed')->count();
    \$processing = \App\Models\PrecomputedErosionMap::where('status', 'processing')->count();
    \$queued = \App\Models\PrecomputedErosionMap::where('status', 'queued')->count();
    \$failed = \App\Models\PrecomputedErosionMap::where('status', 'failed')->count();
    
    echo '📊 STATUS BREAKDOWN:' . PHP_EOL;
    echo '────────────────────────────────────────' . PHP_EOL;
    echo 'Total Jobs:      ' . \$total . PHP_EOL;
    echo '✅ Completed:     ' . \$completed . ' (' . round((\$completed/\$total)*100, 1) . '%)' . PHP_EOL;
    echo '⚙️  Processing:    ' . \$processing . PHP_EOL;
    echo '📋 Queued:        ' . \$queued . PHP_EOL;
    echo '❌ Failed:        ' . \$failed . PHP_EOL . PHP_EOL;
    
    if (\$completed < \$total) {
        \$progress = round((\$completed / \$total) * 100, 1);
        \$remaining = \$total - \$completed;
        \$estimatedHours = round((\$remaining * 5) / 60 / 4, 1);
        
        // Progress bar
        \$barWidth = 40;
        \$filled = round((\$progress / 100) * \$barWidth);
        \$empty = \$barWidth - \$filled;
        echo 'Progress: [' . str_repeat('█', \$filled) . str_repeat('░', \$empty) . '] ' . \$progress . '%' . PHP_EOL;
        echo 'Remaining: ' . \$remaining . ' jobs (~' . \$estimatedHours . ' hours)' . PHP_EOL . PHP_EOL;
    } else {
        echo '🎉 ALL JOBS COMPLETED!' . PHP_EOL . PHP_EOL;
    }
    " 2>/dev/null
    
    # Check Redis queue and workers
    REDIS_QUEUE=$(redis-cli llen celery 2>/dev/null || echo "0")
    WORKER_COUNT=$(ps aux | grep -c "[c]elery worker" 2>/dev/null || echo "0")
    
    echo "🔄 SYSTEM STATUS:"
    echo "────────────────────────────────────────"
    echo "Redis queue:     $REDIS_QUEUE jobs waiting"
    echo "Celery workers:  $WORKER_COUNT active"
    echo ""
    
    # Recent activity
    php artisan tinker --execute="
    if (\App\Models\PrecomputedErosionMap::where('status', 'processing')->exists()) {
        echo '⚙️  CURRENTLY PROCESSING:' . PHP_EOL;
        echo '────────────────────────────────────────' . PHP_EOL;
        \$processing = \App\Models\PrecomputedErosionMap::where('status', 'processing')
            ->orderBy('updated_at', 'desc')
            ->limit(4)
            ->get();
        foreach (\$processing as \$map) {
            echo '  • ' . ucfirst(\$map->area_type) . ' ' . \$map->area_id . ', year ' . \$map->year;
            echo ' (started ' . \$map->updated_at->diffForHumans() . ')' . PHP_EOL;
        }
        echo PHP_EOL;
    }
    
    \$recentCompleted = \App\Models\PrecomputedErosionMap::where('status', 'completed')
        ->where('updated_at', '>', now()->subMinutes(5))
        ->orderBy('updated_at', 'desc')
        ->limit(5)
        ->get();
        
    if (\$recentCompleted->count() > 0) {
        echo '✅ RECENTLY COMPLETED (last 5 min):' . PHP_EOL;
        echo '────────────────────────────────────────' . PHP_EOL;
        foreach (\$recentCompleted as \$map) {
            echo '  • ' . ucfirst(\$map->area_type) . ' ' . \$map->area_id . ', year ' . \$map->year;
            echo ' (' . \$map->updated_at->format('H:i:s') . ')' . PHP_EOL;
        }
        echo PHP_EOL;
    }
    
    if (\App\Models\PrecomputedErosionMap::where('status', 'failed')->exists()) {
        echo '❌ FAILED TASKS:' . PHP_EOL;
        echo '────────────────────────────────────────' . PHP_EOL;
        \$failed = \App\Models\PrecomputedErosionMap::where('status', 'failed')
            ->orderBy('updated_at', 'desc')
            ->limit(3)
            ->get();
        foreach (\$failed as \$map) {
            echo '  • ' . ucfirst(\$map->area_type) . ' ' . \$map->area_id . ', year ' . \$map->year . PHP_EOL;
        }
        echo PHP_EOL;
    }
    " 2>/dev/null
    
    echo "========================================"
    echo "Press Ctrl+C to exit | Updates every 5 seconds"
    echo ""
}

# Check if --once flag is provided
if [[ "$1" == "--once" ]]; then
    show_status
    exit 0
fi

# Live monitoring loop
echo "Starting live monitor (updates every 5 seconds)..."
echo "Press Ctrl+C to exit"
sleep 2

while true; do
    show_status
    sleep 5
done
