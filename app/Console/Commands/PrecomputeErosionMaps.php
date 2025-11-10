<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ErosionTileService;
use App\Models\Region;
use App\Models\District;
use App\Models\PrecomputedErosionMap;

class PrecomputeErosionMaps extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erosion:precompute-all 
                            {--years=1993,current : Year range (start,end|current)}
                            {--type=all : Type to precompute: region, district, or all}
                            {--force : Recompute existing maps}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Precompute erosion maps for all regions and districts';

    /**
     * Execute the console command.
     */
    public function handle(ErosionTileService $service)
    {
        $this->info("========================================");
        $this->info(" Erosion Map Precomputation");
        $this->info("========================================");
        $this->newLine();

        // Parse year range
        $yearOption = $this->option('years');
        $years = array_map('trim', explode(',', $yearOption));

        $minYear = (int) config('earthengine.defaults.start_year', 1993);
        $maxYear = max($minYear, (int) config('earthengine.defaults.end_year', date('Y')));

        $startYearInput = $years[0] ?? $minYear;
        $endYearInput = $years[1] ?? $startYearInput;

        $startYear = strtolower((string) $startYearInput) === 'current'
            ? $maxYear
            : (int) $startYearInput;

        $endYear = strtolower((string) $endYearInput) === 'current'
            ? $maxYear
            : (int) $endYearInput;

        if ($startYear < $minYear || $endYear > $maxYear) {
            $this->error("Years must be between {$minYear} and {$maxYear}");
            return 1;
        }

        if ($startYear > $endYear) {
            $this->error('Start year cannot be greater than end year.');
            return 1;
        }

        $periodLabel = $startYear === $endYear
            ? (string) $startYear
            : "{$startYear}-{$endYear}";
        $type = $this->option('type');
        $force = $this->option('force');

        $this->info("Configuration:");
        $this->line("  Period: {$periodLabel}");
        $this->line("  Type: {$type}");
        $this->line("  Force recompute: " . ($force ? 'Yes' : 'No'));
        $this->newLine();

        if (!$this->confirm("This will queue one job per area for period {$periodLabel}. Continue?", true)) {
            $this->info('Cancelled.');
            return 0;
        }

        $totalJobs = 0;
        $skipped = 0;

        // Process Regions
        if (in_array($type, ['all', 'region'])) {
            $regions = Region::all();
            $this->info("Processing {$regions->count()} regions...");
            $this->newLine();

            $progressBar = $this->output->createProgressBar($regions->count());
            $progressBar->start();

            foreach ($regions as $region) {
                // Check if already exists and not forcing
                if (!$force) {
                    $existing = PrecomputedErosionMap::where([
                        'area_type' => 'region',
                        'area_id' => $region->id,
                        'year' => $startYear
                    ])
                    ->when(
                        $endYear !== $startYear,
                        fn ($query) => $query->where('metadata->period->end_year', $endYear)
                    )
                    ->whereIn('status', ['completed', 'processing'])
                    ->first();

                    if ($existing) {
                        $skipped++;
                        $progressBar->advance();
                        continue;
                    }
                }

                $service->getOrQueueMap('region', $region->id, $startYear, $endYear);
                $totalJobs++;
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);
        }

        // Process Districts
        if (in_array($type, ['all', 'district'])) {
            $districts = District::all();
            $this->info("Processing {$districts->count()} districts...");
            $this->newLine();

            $progressBar = $this->output->createProgressBar($districts->count());
            $progressBar->start();

            foreach ($districts as $district) {
                // Check if already exists and not forcing
                if (!$force) {
                    $existing = PrecomputedErosionMap::where([
                        'area_type' => 'district',
                        'area_id' => $district->id,
                        'year' => $startYear
                    ])
                    ->when(
                        $endYear !== $startYear,
                        fn ($query) => $query->where('metadata->period->end_year', $endYear)
                    )
                    ->whereIn('status', ['completed', 'processing'])
                    ->first();

                    if ($existing) {
                        $skipped++;
                        $progressBar->advance();
                        continue;
                    }
                }

                $service->getOrQueueMap('district', $district->id, $startYear, $endYear);
                $totalJobs++;
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);
        }

        // Summary
        $this->info("========================================");
        $this->info(" Summary");
        $this->info("========================================");
        $this->line("  Jobs queued: {$totalJobs}");
        $this->line("  Jobs skipped: {$skipped}");
        $this->newLine();

        if ($totalJobs > 0) {
            $estimatedMinutes = $totalJobs * 5;  // Estimate 5 min per job
            $estimatedHours = round($estimatedMinutes / 60, 1);
            $this->info("Estimated completion time: ~{$estimatedHours} hours");
            $this->newLine();

            $this->info("Monitor progress with:");
            $this->line("  sudo tail -f /var/log/rusle-celery-worker.log");
            $this->newLine();
            $this->info("Check status with:");
            $this->line("  php artisan tinker --execute=\"echo 'Completed: ' . \App\Models\PrecomputedErosionMap::where('status', 'completed')->count();\"");
        }

        $this->newLine();
        $this->info("✓ Precomputation initiated!");

        return 0;
    }
}

