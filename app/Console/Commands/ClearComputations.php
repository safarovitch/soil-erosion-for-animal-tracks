<?php

namespace App\Console\Commands;

use App\Models\ErosionCache;
use Illuminate\Console\Command;
use App\Models\PrecomputedErosionMap;
use Illuminate\Support\Facades\Storage;

class ClearComputations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clear-computations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all precomputed data and tiles';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Clearing all precomputed data and tiles');
        $this->newLine();
        PrecomputedErosionMap::truncate();
        $this->info('All precomputed data and tiles have been cleared');
        $this->newLine();
        ErosionCache::truncate();
        $this->info('All erosion cache data have been cleared');
        $this->newLine();
        // remove directory content in storage/rusle-tiles/
        $this->info('Removing directory content in storage/rusle-tiles/');
        Storage::disk('local')->deleteDirectory('rusle-tiles');
        $this->info('Directory content has been removed');
        $this->newLine();
    }
}
