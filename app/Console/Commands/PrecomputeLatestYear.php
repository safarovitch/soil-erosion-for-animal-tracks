<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ErosionTileService;
use App\Models\Region;
use App\Models\District;
use App\Models\PrecomputedErosionMap;

class PrecomputeLatestYear extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erosion:precompute-latest-year 
                            {--year= : Specific year to precompute (defaults to current year)}
                            {--type=all : Type to precompute: region, district, or all}
                            {--force : Recompute even if already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Precompute erosion maps for the latest year (scheduled to run twice per year)';

    /**
     * Execute the console command.
     */
    public function handle(ErosionTileService $service)
    {
        $this->info("========================================");
        $this->info(" Latest Year Erosion Map Precomputation");
        $this->info("========================================");
        $this->newLine();

        // Determine year to precompute
        $year = $this->option('year') ?: now()->year;
        $type = $this->option('type');
        $force = $this->option('force');

        $this->info("Configuration:");
        $this->line("  Year: {$year}");
        $this->line("  Type: {$type}");
        $this->line("  Force recompute: " . ($force ? 'Yes' : 'No'));
        $this->newLine();

        $totalJobs = 0;
        $skipped = 0;
        $recomputed = 0;

        // Process Regions
        if (in_array($type, ['all', 'region'])) {
            $regions = Region::all();
            $this->info("Processing {$regions->count()} regions for year {$year}...");
            
            $progressBar = $this->output->createProgressBar($regions->count());
            $progressBar->start();

            foreach ($regions as $region) {
                // Check if already exists
                $existing = PrecomputedErosionMap::where([
                    'area_type' => 'region',
                    'area_id' => $region->id,
                    'year' => $year
                ])->first();

                if ($existing && $existing->status === 'completed' && !$force) {
                    $skipped++;
                } elseif ($existing && $existing->status === 'completed' && $force) {
                    // Force recompute
                    $existing->delete();
                    $service->getOrQueueMap('region', $region->id, $year);
                    $recomputed++;
                    $totalJobs++;
                } else {
                    $service->getOrQueueMap('region', $region->id, $year);
                    $totalJobs++;
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);
        }

        // Process Districts
        if (in_array($type, ['all', 'district'])) {
            $districts = District::all();
            $this->info("Processing {$districts->count()} districts for year {$year}...");
            
            $progressBar = $this->output->createProgressBar($districts->count());
            $progressBar->start();

            foreach ($districts as $district) {
                // Check if already exists
                $existing = PrecomputedErosionMap::where([
                    'area_type' => 'district',
                    'area_id' => $district->id,
                    'year' => $year
                ])->first();

                if ($existing && $existing->status === 'completed' && !$force) {
                    $skipped++;
                } elseif ($existing && $existing->status === 'completed' && $force) {
                    // Force recompute
                    $existing->delete();
                    $service->getOrQueueMap('district', $district->id, $year);
                    $recomputed++;
                    $totalJobs++;
                } else {
                    $service->getOrQueueMap('district', $district->id, $year);
                    $totalJobs++;
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);
        }

        // Summary
        $this->info("========================================");
        $this->info(" Summary");
        $this->info("========================================");
        $this->line("  Year: {$year}");
        $this->line("  New jobs queued: {$totalJobs}");
        $this->line("  Recomputed: {$recomputed}");
        $this->line("  Skipped (already exists): {$skipped}");
        $this->newLine();

        if ($totalJobs > 0) {
            $estimatedMinutes = $totalJobs * 5;
            $estimatedHours = round($estimatedMinutes / 60, 1);
            $this->info("Estimated completion time: ~{$estimatedHours} hours");
            $this->newLine();

            $this->info("Monitor progress with:");
            $this->line("  sudo tail -f /var/log/rusle-celery-worker.log");
            $this->newLine();
        }

        // Log execution
        $this->info("✓ Precomputation for year {$year} initiated!");
        \Log::info("Automated precomputation executed for year {$year}", [
            'jobs_queued' => $totalJobs,
            'skipped' => $skipped,
            'recomputed' => $recomputed,
            'type' => $type
        ]);

        return 0;
    }
}
