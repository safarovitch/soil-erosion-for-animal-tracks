<?php

namespace App\Services;

use App\Models\Region;
use App\Models\District;
use App\Models\PrecomputedErosionMap;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ErosionTileService
{
    private string $pythonServiceUrl;

    public function __construct()
    {
        $this->pythonServiceUrl = config('services.gee.url', 'http://localhost:5000');
    }

    /**
     * Get or queue erosion map for an area/year
     * 
     * @param string $areaType 'region' or 'district'
     * @param int $areaId ID of the region or district
     * @param int $startYear Start year
     * @param int|null $endYear Inclusive end year (defaults to start year)
     * @return array Status and information about the map
     */
    public function getOrQueueMap(string $areaType, int $areaId, int $startYear, ?int $endYear = null): array
    {
        $startYear = (int) $startYear;
        $endYear = (int) ($endYear ?? $startYear);
        $periodLabel = $this->buildPeriodLabel($startYear, $endYear);

        // Check if already precomputed
        $mapQuery = PrecomputedErosionMap::where([
            'area_type' => $areaType,
            'area_id' => $areaId,
            'year' => $startYear
        ]);

        if ($endYear !== $startYear) {
            $mapQuery->where('metadata->period->end_year', $endYear);
        }

        $map = $mapQuery->first();

        if ($map && $map->isAvailable()) {
            $statistics = $this->enrichStatistics($map->statistics ?? null, $map->metadata ?? null);
            return [
                'status' => 'available',
                'tiles_url' => $map->tile_url,
                'statistics' => $statistics,
                'computed_at' => $map->computed_at?->toISOString(),
                'start_year' => $startYear,
                'end_year' => $endYear,
                'period_label' => $map->period_label,
            ];
        }

        if ($map && in_array($map->status, ['queued', 'processing'])) {
            return [
                'status' => $map->status,
                'task_id' => $map->metadata['task_id'] ?? null,
                 'start_year' => $startYear,
                 'end_year' => $endYear,
                'period_label' => $map->period_label,
                'message' => $map->status === 'queued' 
                    ? 'Map generation is queued' 
                    : 'Map is currently being generated'
            ];
        }

        if ($map && $map->status === 'failed') {
            // Retry failed computation
            Log::info("Retrying failed computation for {$areaType} {$areaId}, period {$periodLabel}");
            return $this->queueComputation($areaType, $areaId, $startYear, $endYear, $map);
        }

        // Queue new computation
        return $this->queueComputation($areaType, $areaId, $startYear, $endYear);
    }

    /**
     * Queue a new computation task
     */
    private function queueComputation(string $areaType, int $areaId, int $startYear, int $endYear, ?PrecomputedErosionMap $existingMap = null): array
    {
        $startYear = (int) $startYear;
        $endYear = (int) $endYear;
        try {
            // Get geometry
            $area = $areaType === 'region' 
                ? Region::find($areaId) 
                : District::find($areaId);

            if (!$area) {
                return [
                    'status' => 'error',
                    'error' => "Area not found: {$areaType} {$areaId}"
                ];
            }

            $geometry = is_array($area->geometry) 
                ? $area->geometry 
                : json_decode($area->geometry, true);

            // Calculate bbox
            $bbox = $this->calculateBbox($geometry);

            $periodLabel = $this->buildPeriodLabel($startYear, $endYear);
            Log::info("Queueing computation for {$areaType} {$areaId}, period {$periodLabel}");

            // Call Python service to queue task
            $response = Http::timeout(30)->post(
                "{$this->pythonServiceUrl}/api/rusle/precompute",
                [
                    'area_type' => $areaType,
                    'area_id' => $areaId,
                    'start_year' => $startYear,
                    'end_year' => $endYear,
                    'area_geometry' => $geometry,
                    'bbox' => $bbox
                ]
            );

            if (!$response->successful()) {
                Log::error("Failed to queue computation: " . $response->body());
                return [
                    'status' => 'error',
                    'error' => 'Failed to queue computation: ' . $response->body()
                ];
            }

            $taskId = $response->json('task_id');

            // Create or update database record
            if ($existingMap) {
                $metadata = $existingMap->metadata ?? [];
                $metadata['task_id'] = $taskId;
                $metadata['period'] = [
                    'start_year' => $startYear,
                    'end_year' => $endYear,
                    'label' => $periodLabel,
                ];
                $existingMap->update([
                    'status' => 'queued',
                    'metadata' => $metadata,
                    'error_message' => null
                ]);
            } else {
                PrecomputedErosionMap::create([
                    'area_type' => $areaType,
                    'area_id' => $areaId,
                    'year' => $startYear,
                    'status' => 'queued',
                    'metadata' => [
                        'task_id' => $taskId,
                        'bbox' => $bbox,
                        'period' => [
                            'start_year' => $startYear,
                            'end_year' => $endYear,
                            'label' => $periodLabel,
                        ],
                    ]
                ]);
            }

            Log::info("Task queued with ID: {$taskId}");

            return [
                'status' => 'queued',
                'task_id' => $taskId,
                'start_year' => $startYear,
                'end_year' => $endYear,
                'period_label' => $periodLabel,
                'message' => "Computation queued for {$areaType} {$areaId}, period {$periodLabel}"
            ];

        } catch (\Exception $e) {
            Log::error("Failed to queue computation: " . $e->getMessage());
            
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    private function buildPeriodLabel(int $startYear, int $endYear): string
    {
        return $startYear === $endYear
            ? (string) $startYear
            : "{$startYear}-{$endYear}";
    }

    private function enrichStatistics(?array $statistics, ?array $metadata): ?array
    {
        if (!is_array($statistics)) {
            $statistics = $statistics === null ? [] : (array) $statistics;
        }

        $rainfallStats = $metadata['rainfall_statistics'] ?? null;

        if (!is_array($rainfallStats) || empty($rainfallStats)) {
            return $statistics ?: null;
        }

        $meanAnnual = $rainfallStats['mean_annual_rainfall_mm'] ?? null;
        $trend = $rainfallStats['trend_mm_per_year'] ?? null;

        $slopePercent = null;
        if (is_numeric($trend)) {
            if (is_numeric($meanAnnual) && (float) $meanAnnual !== 0.0) {
                $slopePercent = round(((float) $trend / (float) $meanAnnual) * 100, 2);
            } else {
                $slopePercent = round((float) $trend, 2);
            }
        }

        if ($slopePercent !== null) {
            $statistics['rainfallSlope'] = $slopePercent;
        }

        if (isset($rainfallStats['coefficient_of_variation_percent']) && is_numeric($rainfallStats['coefficient_of_variation_percent'])) {
            $statistics['rainfallCV'] = round((float) $rainfallStats['coefficient_of_variation_percent'], 2);
        }

        $statistics['rainfallStatistics'] = $rainfallStats;

        return $statistics;
    }

    /**
     * Calculate bounding box from GeoJSON geometry
     */
    private function calculateBbox(array $geometry): array
    {
        $coordinates = $geometry['coordinates'] ?? [];
        
        $allLons = [];
        $allLats = [];

        // Extract all coordinates based on geometry type
        if ($geometry['type'] === 'Polygon') {
            foreach ($coordinates as $ring) {
                foreach ($ring as $coord) {
                    $allLons[] = $coord[0];
                    $allLats[] = $coord[1];
                }
            }
        } elseif ($geometry['type'] === 'MultiPolygon') {
            foreach ($coordinates as $polygon) {
                foreach ($polygon as $ring) {
                    foreach ($ring as $coord) {
                        $allLons[] = $coord[0];
                        $allLats[] = $coord[1];
                    }
                }
            }
        }

        if (empty($allLons) || empty($allLats)) {
            return [0, 0, 0, 0];
        }

        return [
            min($allLons),  // west
            min($allLats),  // south
            max($allLons),  // east
            max($allLats)   // north
        ];
    }

    /**
     * Check task status from Python service
     */
    public function checkTaskStatus(string $taskId): array
    {
        try {
            $response = Http::timeout(10)->get(
                "{$this->pythonServiceUrl}/api/rusle/task-status/{$taskId}"
            );

            if (!$response->successful()) {
                return [
                    'status' => 'error',
                    'error' => 'Failed to check task status'
                ];
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error("Failed to check task status: " . $e->getMessage());
            
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Handle task started callback from Python service
     */
    public function handleTaskStarted(array $data): array
    {
        $taskId = $data['task_id'] ?? null;
        $areaType = $data['area_type'] ?? null;
        $areaId = $data['area_id'] ?? null;
        $startYear = $data['start_year'] ?? $data['year'] ?? null;
        $endYear = $data['end_year'] ?? $startYear;
        $periodLabel = $data['period_label'] ?? $this->buildPeriodLabel((int) $startYear, (int) $endYear);

        if (!$taskId || !$areaType || !$areaId || $startYear === null) {
            throw new \InvalidArgumentException('Missing required fields: task_id, area_type, area_id, start_year');
        }

        // Find and update the map record
        $mapQuery = PrecomputedErosionMap::where([
            'area_type' => $areaType,
            'area_id' => $areaId,
            'year' => $startYear
        ]);

        if ($endYear !== $startYear) {
            $mapQuery->where('metadata->period->end_year', $endYear);
        }

        $map = $mapQuery->first();

        if ($map) {
            $map->update([
                'status' => 'processing'
            ]);
            
            Log::info("Map status updated to processing: {$areaType} {$areaId}, period {$periodLabel}");
        } else {
            Log::warning("No map found for task started: {$areaType} {$areaId}, period {$periodLabel}");
        }

        return [
            'status' => 'processing',
            'map_id' => $map->id ?? null
        ];
    }

    /**
     * Handle task completion callback from Python service
     */
    public function handleTaskCompletion(array $data): array
    {
        $taskId = $data['task_id'] ?? null;
        $areaType = $data['area_type'] ?? null;
        $areaId = $data['area_id'] ?? null;
        $startYear = $data['start_year'] ?? $data['year'] ?? null;
        $endYear = $data['end_year'] ?? $startYear;
        $startYear = $startYear !== null ? (int) $startYear : null;
        $endYear = $endYear !== null ? (int) $endYear : $startYear;
        $periodLabel = $data['period_label'] ?? $this->buildPeriodLabel((int) $startYear, (int) $endYear);

        if (!$taskId || !$areaType || !$areaId || $startYear === null) {
            throw new \InvalidArgumentException('Missing required fields: task_id, area_type, area_id, start_year');
        }

        // Find the map record
        $mapQuery = PrecomputedErosionMap::where([
            'area_type' => $areaType,
            'area_id' => $areaId,
            'year' => $startYear
        ]);

        if ($endYear !== $startYear) {
            $mapQuery->where('metadata->period->end_year', $endYear);
        }

        $map = $mapQuery->first();

        if (!$map) {
            Log::warning("No map found for {$areaType} {$areaId}, period {$periodLabel}");
            
            // Create new record
            $statistics = $this->enrichStatistics($data['statistics'] ?? null, $data['metadata'] ?? null);
            $map = PrecomputedErosionMap::create([
                'area_type' => $areaType,
                'area_id' => $areaId,
                'year' => $startYear,
                'status' => 'completed',
                'geotiff_path' => $data['geotiff_path'] ?? null,
                'tiles_path' => $data['tiles_path'] ?? null,
                'statistics' => $statistics,
                'metadata' => array_merge(
                    [
                        'task_id' => $taskId,
                        'period' => [
                            'start_year' => $startYear,
                            'end_year' => $endYear,
                            'label' => $periodLabel,
                        ],
                    ],
                    $data['metadata'] ?? []
                ),
                'computed_at' => now()
            ]);
            
            Log::info("New map record created: {$areaType} {$areaId}, period {$periodLabel}");
        } else {
            // Update existing record
            $updatedMetadata = array_merge(
                $map->metadata ?? [],
                ['task_id' => $taskId],
                $data['metadata'] ?? []
            );

            $updatedMetadata['period'] = [
                'start_year' => $startYear,
                'end_year' => $endYear,
                'label' => $periodLabel,
            ];

            $statistics = $this->enrichStatistics($data['statistics'] ?? null, $data['metadata'] ?? null);
            $map->update([
                'status' => 'completed',
                'geotiff_path' => $data['geotiff_path'] ?? null,
                'tiles_path' => $data['tiles_path'] ?? null,
                'statistics' => $statistics,
                'metadata' => $updatedMetadata,
                'computed_at' => now(),
                'error_message' => null
            ]);
            
            Log::info("Map updated to completed: {$areaType} {$areaId}, period {$periodLabel}");
        }

        $statistics = $this->enrichStatistics($data['statistics'] ?? null, $data['metadata'] ?? null);

        return [
            'status' => 'completed',
            'map_id' => $map->id,
            'statistics' => $statistics
        ];
    }

    /**
     * Handle task failure callback from Python service
     */
    public function handleTaskFailure(array $data): array
    {
        $taskId = $data['task_id'] ?? null;
        $areaType = $data['area_type'] ?? null;
        $areaId = $data['area_id'] ?? null;
        $startYear = $data['start_year'] ?? $data['year'] ?? null;
        $endYear = $data['end_year'] ?? $startYear;
        $startYear = $startYear !== null ? (int) $startYear : null;
        $endYear = $endYear !== null ? (int) $endYear : $startYear;
        $periodLabel = $data['period_label'] ?? ($startYear !== null ? $this->buildPeriodLabel((int) $startYear, (int) $endYear) : 'unknown');
        $error = $data['error'] ?? 'Unknown error';
        $errorType = $data['error_type'] ?? 'Exception';

        if (!$taskId || !$areaType || !$areaId || $startYear === null) {
            throw new \InvalidArgumentException('Missing required fields: task_id, area_type, area_id, start_year');
        }

        // Find the map record
        $mapQuery = PrecomputedErosionMap::where([
            'area_type' => $areaType,
            'area_id' => $areaId,
            'year' => $startYear
        ]);

        if ($endYear !== $startYear) {
            $mapQuery->where('metadata->period->end_year', $endYear);
        }

        $map = $mapQuery->first();

        if (!$map) {
            Log::warning("No map found for failed task: {$areaType} {$areaId}, period {$periodLabel}");
            
            // Create new record with failed status
            $map = PrecomputedErosionMap::create([
                'area_type' => $areaType,
                'area_id' => $areaId,
                'year' => $startYear,
                'status' => 'failed',
                'error_message' => $error,
                'metadata' => array_merge(
                    $data['metadata'] ?? [],
                    [
                        'task_id' => $taskId,
                        'error_type' => $errorType,
                        'period' => [
                            'start_year' => $startYear,
                            'end_year' => $endYear,
                            'label' => $periodLabel,
                        ],
                    ]
                )
            ]);
            
            Log::info("New failed map record created: {$areaType} {$areaId}, period {$periodLabel}");
        } else {
            // Update existing record to failed
            $updatedMetadata = array_merge(
                $map->metadata ?? [],
                $data['metadata'] ?? []
            );

            $updatedMetadata['task_id'] = $taskId;
            $updatedMetadata['error_type'] = $errorType;
            $updatedMetadata['failed_at'] = now()->toIso8601String();
            $updatedMetadata['period'] = [
                'start_year' => $startYear,
                'end_year' => $endYear,
                'label' => $periodLabel,
            ];

            $map->update([
                'status' => 'failed',
                'error_message' => $error,
                'metadata' => $updatedMetadata
            ]);
            
            Log::info("Map updated to failed: {$areaType} {$areaId}, period {$periodLabel}");
        }

        return [
            'status' => 'failed',
            'map_id' => $map->id
        ];
    }

    /**
     * Update database after task completion
     * Called via callback or polling
     */
    public function updateMapStatus(string $taskId, array $result): void
    {
        $map = PrecomputedErosionMap::where('metadata->task_id', $taskId)->first();

        if (!$map) {
            Log::warning("No map found for task ID: {$taskId}");
            return;
        }

        if ($result['status'] === 'completed') {
            $map->update([
                'status' => 'completed',
                'geotiff_path' => $result['result']['geotiff_path'] ?? null,
                'tiles_path' => $result['result']['tiles_path'] ?? null,
                'statistics' => $result['result']['statistics'] ?? null,
                'metadata' => array_merge(
                    $map->metadata ?? [],
                    $result['result']['metadata'] ?? []
                ),
                'computed_at' => now()
            ]);

            Log::info("Map completed: {$map->area_type} {$map->area_id}, year {$map->year}");

        } elseif ($result['status'] === 'failed') {
            $map->update([
                'status' => 'failed',
                'error_message' => $result['error'] ?? 'Unknown error'
            ]);

            Log::error("Map failed: {$map->area_type} {$map->area_id}, year {$map->year}");
        }
    }
}



