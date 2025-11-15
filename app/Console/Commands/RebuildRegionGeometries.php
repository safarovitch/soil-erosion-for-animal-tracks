<?php

namespace App\Console\Commands;

use App\Models\District;
use App\Models\Region;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RebuildRegionGeometries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'boundaries:rebuild-regions {regionIds* : One or more region IDs to rebuild}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild region boundary geometries by aggregating the geometries of their districts';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $regionIds = collect($this->argument('regionIds'))
            ->map(fn ($id) => is_numeric($id) ? (int) $id : null)
            ->filter(fn ($id) => !is_null($id))
            ->unique()
            ->values();

        if ($regionIds->isEmpty()) {
            $this->error('Please provide at least one valid numeric region ID.');
            return self::FAILURE;
        }

        $regions = Region::with('districts')
            ->whereIn('id', $regionIds)
            ->orderBy('id')
            ->get();

        if ($regions->isEmpty()) {
            $this->error('No matching regions found for the provided IDs.');
            return self::FAILURE;
        }

        $missingRegionIds = $regionIds->diff($regions->pluck('id'));
        if ($missingRegionIds->isNotEmpty()) {
            $this->warn('The following region IDs were not found: ' . $missingRegionIds->implode(', '));
        }

        $this->info('Rebuilding geometries for regions: ' . $regions->pluck('id')->implode(', '));

        try {
            DB::transaction(function () use ($regions): void {
                $regions->each(function (Region $region): void {
                    $this->rebuildGeometryForRegion($region);
                });
            });
        } catch (\Throwable $exception) {
            $this->error('Failed to rebuild region geometries: ' . $exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $this->info('✓ Geometry rebuild complete.');

        return self::SUCCESS;
    }

    private function rebuildGeometryForRegion(Region $region): void
    {
        $this->line('');
        $this->line("Processing region [{$region->id}] {$region->name_en}");

        // Clear existing geometry
        $region->geometry = null;
        $region->save();
        $this->comment('  - Cleared existing geometry');

        /** @var Collection<int, District> $districts */
        $districts = $region->districts;

        if ($districts->isEmpty()) {
            $this->warn('  ! Region has no districts; skipping rebuild');
            return;
        }

        $multiPolygonCoords = [];
        $contributingDistricts = 0;
        $missingGeometries = [];

        foreach ($districts as $district) {
            $geometry = $district->getGeometryArray();

            if (!$geometry || !isset($geometry['type'], $geometry['coordinates'])) {
                $missingGeometries[] = $district->name_en;
                continue;
            }

            if ($geometry['type'] === 'Polygon') {
                $multiPolygonCoords[] = $geometry['coordinates'];
                $contributingDistricts++;
            } elseif ($geometry['type'] === 'MultiPolygon') {
                $multiPolygonCoords = array_merge($multiPolygonCoords, $geometry['coordinates']);
                $contributingDistricts++;
            } else {
                $this->warn("  ! Unsupported geometry type '{$geometry['type']}' for district {$district->name_en}; skipping");
            }
        }

        if (!empty($missingGeometries)) {
            $this->warn('  ! Districts missing geometry: ' . implode(', ', $missingGeometries));
        }

        if (empty($multiPolygonCoords)) {
            $this->warn('  ! No district geometries available; region geometry remains empty');
            return;
        }

        // Normalize to Polygon if only a single polygon is present
        $newGeometry = count($multiPolygonCoords) === 1
            ? [
                'type' => 'Polygon',
                'coordinates' => $multiPolygonCoords[0],
            ]
            : [
                'type' => 'MultiPolygon',
                'coordinates' => $multiPolygonCoords,
            ];

        $region->geometry = $newGeometry;
        $region->save();

        $this->info('  ✓ Geometry rebuilt successfully');
        $this->line('    - Total districts processed: ' . $districts->count());
        $this->line('    - Districts contributing geometry: ' . $contributingDistricts);
        $this->line('    - Polygons in resulting geometry: ' . count($multiPolygonCoords));
    }
}


