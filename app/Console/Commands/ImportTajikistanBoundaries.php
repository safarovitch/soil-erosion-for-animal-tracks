<?php

namespace App\Console\Commands;

use Database\Seeders\TajikistanBoundariesSeeder;
use Illuminate\Console\Command;

class ImportTajikistanBoundaries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'boundaries:import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import Tajikistan administrative boundaries from GeoJSON file';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Importing Tajikistan boundaries...');
        
        $seeder = new TajikistanBoundariesSeeder();
        $seeder->run();
        
        $this->info('Boundaries imported successfully!');
        
        return 0;
    }
}
