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
     * @param int $year Year (2015-2024)
     * @return array Status and information about the map
     */
    public function getOrQueueMap(string $areaType, int $areaId, int $year): array
    {
        // Check if already precomputed
        $map = PrecomputedErosionMap::where([
            'area_type' => $areaType,
            'area_id' => $areaId,
            'year' => $year
        ])->first();

        if ($map && $map->isAvailable()) {
            return [
                'status' => 'available',
                'tiles_url' => $map->tile_url,
                'statistics' => $map->statistics,
                'metadata' => $map->metadata,
                'computed_at' => $map->computed_at?->toISOString()
            ];
        }

        if ($map && in_array($map->status, ['queued', 'processing'])) {
            return [
                'status' => $map->status,
                'task_id' => $map->metadata['task_id'] ?? null,
                'message' => $map->status === 'queued' 
                    ? 'Map generation is queued' 
                    : 'Map is currently being generated'
            ];
        }

        if ($map && $map->status === 'failed') {
            // Retry failed computation
            Log::info("Retrying failed computation for {$areaType} {$areaId}, year {$year}");
            return $this->queueComputation($areaType, $areaId, $year, $map);
        }

        // Queue new computation
        return $this->queueComputation($areaType, $areaId, $year);
    }

    /**
     * Queue a new computation task
     */
    private function queueComputation(string $areaType, int $areaId, int $year, ?PrecomputedErosionMap $existingMap = null): array
    {
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

            Log::info("Queueing computation for {$areaType} {$areaId}, year {$year}");

            // Call Python service to queue task
            $response = Http::timeout(30)->post(
                "{$this->pythonServiceUrl}/api/rusle/precompute",
                [
                    'area_type' => $areaType,
                    'area_id' => $areaId,
                    'year' => $year,
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
                $existingMap->update([
                    'status' => 'queued',
                    'metadata' => ['task_id' => $taskId],
                    'error_message' => null
                ]);
            } else {
                PrecomputedErosionMap::create([
                    'area_type' => $areaType,
                    'area_id' => $areaId,
                    'year' => $year,
                    'status' => 'queued',
                    'metadata' => ['task_id' => $taskId, 'bbox' => $bbox]
                ]);
            }

            Log::info("Task queued with ID: {$taskId}");

            return [
                'status' => 'queued',
                'task_id' => $taskId,
                'message' => "Computation queued for {$areaType} {$areaId}, year {$year}"
            ];

        } catch (\Exception $e) {
            Log::error("Failed to queue computation: " . $e->getMessage());
            
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
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
        $year = $data['year'] ?? null;

        if (!$taskId || !$areaType || !$areaId || !$year) {
            throw new \InvalidArgumentException('Missing required fields: task_id, area_type, area_id, year');
        }

        // Find and update the map record
        $map = PrecomputedErosionMap::where([
            'area_type' => $areaType,
            'area_id' => $areaId,
            'year' => $year
        ])->first();

        if ($map) {
            $map->update([
                'status' => 'processing'
            ]);
            
            Log::info("Map status updated to processing: {$areaType} {$areaId}, year {$year}");
        } else {
            Log::warning("No map found for task started: {$areaType} {$areaId}, year {$year}");
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
        $year = $data['year'] ?? null;

        if (!$taskId || !$areaType || !$areaId || !$year) {
            throw new \InvalidArgumentException('Missing required fields: task_id, area_type, area_id, year');
        }

        // Find the map record
        $map = PrecomputedErosionMap::where([
            'area_type' => $areaType,
            'area_id' => $areaId,
            'year' => $year
        ])->first();

        if (!$map) {
            Log::warning("No map found for {$areaType} {$areaId}, year {$year}");
            
            // Create new record
            $map = PrecomputedErosionMap::create([
                'area_type' => $areaType,
                'area_id' => $areaId,
                'year' => $year,
                'status' => 'completed',
                'geotiff_path' => $data['geotiff_path'] ?? null,
                'tiles_path' => $data['tiles_path'] ?? null,
                'statistics' => $data['statistics'] ?? null,
                'metadata' => array_merge(
                    ['task_id' => $taskId],
                    $data['metadata'] ?? []
                ),
                'computed_at' => now()
            ]);
            
            Log::info("New map record created: {$areaType} {$areaId}, year {$year}");
        } else {
            // Update existing record
            $map->update([
                'status' => 'completed',
                'geotiff_path' => $data['geotiff_path'] ?? null,
                'tiles_path' => $data['tiles_path'] ?? null,
                'statistics' => $data['statistics'] ?? null,
                'metadata' => array_merge(
                    $map->metadata ?? [],
                    ['task_id' => $taskId],
                    $data['metadata'] ?? []
                ),
                'computed_at' => now(),
                'error_message' => null
            ]);
            
            Log::info("Map updated to completed: {$areaType} {$areaId}, year {$year}");
        }

        return [
            'status' => 'completed',
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



