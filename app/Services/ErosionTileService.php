<?php

namespace App\Services;

use App\Models\Region;
use App\Models\District;
use App\Models\PrecomputedErosionMap;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\RusleConfigService;
use Illuminate\Support\Arr;

class ErosionTileService
{
    private const DEFAULT_GEOMETRY_HASH = '';
    private const DEFAULT_MAX_ZOOM = 10;

    private string $pythonServiceUrl;
    private RusleConfigService $configService;

    public function __construct(RusleConfigService $configService)
    {
        $this->pythonServiceUrl = config('services.gee.url', 'http://localhost:5000');
        $this->configService = $configService;
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
    public function getOrQueueMap(string $areaType, int $areaId, int $startYear, ?int $endYear = null, ?User $user = null, ?array $geometry = null): array
    {
        $startYear = (int) $startYear;
        $endYear = (int) ($endYear ?? $startYear);
        $periodLabel = $this->buildPeriodLabel($startYear, $endYear);
        $geometrySnapshot = $geometry ? $this->normaliseGeometry($geometry) : null;
        $geometryHash = $this->computeGeometryHash($geometrySnapshot);

        $configContext = $this->resolveConfigContext($user);
        $userId = $configContext['user_id'];
        $configHash = $configContext['hash'];
        $overrides = $configContext['overrides'];
        $configSnapshot = $configContext['snapshot'];

        // Check if already precomputed
        $mapQuery = PrecomputedErosionMap::where([
            'area_type' => $areaType,
            'area_id' => $areaId,
            'year' => $startYear,
            'user_id' => $userId,
            'config_hash' => $configHash,
            'geometry_hash' => $geometryHash,
        ]);

        if ($endYear !== $startYear) {
            $mapQuery->where('metadata->period->end_year', $endYear);
        }

        $map = $mapQuery->first();

        if ($map && $map->isAvailable()) {
            $maxZoom = (int) ($map->metadata['max_zoom'] ?? self::DEFAULT_MAX_ZOOM);
            $statistics = $this->enrichStatistics($map->statistics ?? null, $map->metadata ?? null);
            $components = $map->metadata['components'] ?? null;
            return [
                'status' => 'available',
                'tiles_url' => $map->tile_url,
                'statistics' => $statistics,
                'components' => is_array($components) ? $components : [],
                'computed_at' => $map->computed_at?->toISOString(),
                'start_year' => $startYear,
                'end_year' => $endYear,
                'period_label' => $map->period_label,
                'geometry_hash' => $map->geometry_hash,
                'tile_path_key' => $map->tile_storage_key,
                'max_zoom' => $maxZoom,
            ];
        }

        if ($map && in_array($map->status, ['queued', 'processing'])) {
            return [
                'status' => $map->status,
                'task_id' => $map->metadata['task_id'] ?? null,
                 'start_year' => $startYear,
                 'end_year' => $endYear,
                'period_label' => $map->period_label,
                'geometry_hash' => $geometryHash,
                'max_zoom' => (int) ($map->metadata['max_zoom'] ?? self::DEFAULT_MAX_ZOOM),
                'message' => $map->status === 'queued' 
                    ? 'Map generation is queued' 
                    : 'Map is currently being generated'
            ];
        }

        if ($map && $map->status === 'failed') {
            // Retry failed computation
            Log::info("Retrying failed computation for {$areaType} {$areaId}, period {$periodLabel}");
            return $this->queueComputation($areaType, $areaId, $startYear, $endYear, $configContext, $map, $geometrySnapshot, $geometryHash);
        }

        // Queue new computation
        return $this->queueComputation($areaType, $areaId, $startYear, $endYear, $configContext, null, $geometrySnapshot, $geometryHash);
    }

    public function getOrQueueCustomMap(array $geometry, int $startYear, ?int $endYear = null, ?User $user = null): array
    {
        return $this->getOrQueueMap('custom', 0, $startYear, $endYear, $user, $geometry);
    }

    /**
     * Queue a new computation task
     */
    private function queueComputation(
        string $areaType,
        int $areaId,
        int $startYear,
        int $endYear,
        array $configContext,
        ?PrecomputedErosionMap $existingMap = null,
        ?array $geometrySnapshot = null,
        ?string $geometryHash = null
    ): array
    {
        $startYear = (int) $startYear;
        $endYear = (int) $endYear;
        try {
            if ($geometrySnapshot === null) {
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
                $geometrySnapshot = $this->normaliseGeometry($geometry ?? []);
            }

            if (!$geometrySnapshot) {
                return [
                    'status' => 'error',
                    'error' => 'Geometry is required to queue computation.'
                ];
            }

            $geometryHash = $geometryHash ?? $this->computeGeometryHash($geometrySnapshot);

            // Calculate bbox
            $bbox = $this->calculateBbox($geometrySnapshot);

            $periodLabel = $this->buildPeriodLabel($startYear, $endYear);
            Log::info("Queueing computation for {$areaType} {$areaId}, period {$periodLabel}");

            $userId = $configContext['user_id'];
            $configHash = $configContext['hash'];
            $overrides = $configContext['overrides'];
            $configSnapshot = $configContext['snapshot'];
            $defaultsVersion = $configContext['version'];

            // Call Python service to queue task
            $tileStorageKey = $this->buildTileStorageKey($areaType, $areaId, $geometryHash);

            $payload = [
                'area_type' => $areaType,
                'area_id' => $areaId,
                'start_year' => $startYear,
                'end_year' => $endYear,
                'area_geometry' => $geometrySnapshot,
                'bbox' => $bbox,
                'geometry_hash' => $geometryHash,
                'storage_key' => $tileStorageKey,
            ];

            if ($userId !== null) {
                $payload['user_id'] = $userId;
            }

            if (!empty($overrides)) {
                $payload['config_overrides'] = $overrides;
            }

            if ($defaultsVersion) {
                $payload['defaults_version'] = $defaultsVersion;
            }

            $response = Http::timeout(30)->post(
                "{$this->pythonServiceUrl}/api/rusle/precompute",
                $payload
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
            // Use updateOrCreate to handle race conditions where multiple requests
            // might try to create the same record simultaneously
            $metadata = [
                'task_id' => $taskId,
                'bbox' => $bbox,
                'period' => [
                    'start_year' => $startYear,
                    'end_year' => $endYear,
                    'label' => $periodLabel,
                ],
                'config' => [
                    'hash' => $configHash,
                    'overrides' => $overrides,
                    'defaults_version' => $defaultsVersion,
                ],
                'user_id' => $userId,
                'geometry_hash' => $geometryHash,
                'tile_path_key' => $tileStorageKey,
                'geometry_snapshot' => $geometrySnapshot,
                'max_zoom' => $existingMap?->metadata['max_zoom'] ?? self::DEFAULT_MAX_ZOOM,
            ];

            // If we have an existing map, merge its existing metadata
            if ($existingMap && is_array($existingMap->metadata)) {
                $metadata = array_merge($existingMap->metadata, $metadata);
            }

            PrecomputedErosionMap::updateOrCreate(
                [
                    'area_type' => $areaType,
                    'area_id' => $areaId,
                    'year' => $startYear,
                    'user_id' => $userId,
                    'config_hash' => $configHash,
                    'geometry_hash' => $geometryHash,
                ],
                [
                    'status' => 'queued',
                    'metadata' => array_merge($metadata, [
                        'tile_path_key' => $tileStorageKey,
                        'geometry_hash' => $geometryHash,
                    ]),
                    'error_message' => null,
                    'config_snapshot' => $configSnapshot,
                    'geometry_snapshot' => $geometrySnapshot,
                ]
            );

            Log::info("Task queued with ID: {$taskId}");

            return [
                'status' => 'queued',
                'task_id' => $taskId,
                'start_year' => $startYear,
                'end_year' => $endYear,
                'period_label' => $periodLabel,
                'geometry_hash' => $geometryHash,
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

        if (isset($statistics['mean']) && !isset($statistics['meanErosionRate'])) {
            $statistics['meanErosionRate'] = (float) $statistics['mean'];
        }

        if (isset($statistics['min']) && !isset($statistics['minErosionRate'])) {
            $statistics['minErosionRate'] = (float) $statistics['min'];
        }

        if (isset($statistics['max']) && !isset($statistics['maxErosionRate'])) {
            $statistics['maxErosionRate'] = (float) $statistics['max'];
        }

        if (!isset($statistics['erosionCV'])) {
            $meanValue = $statistics['meanErosionRate'] ?? null;
            $stdDevValue = $statistics['std_dev'] ?? null;

            if (is_numeric($meanValue) && (float) $meanValue !== 0.0 && is_numeric($stdDevValue)) {
                $statistics['erosionCV'] = round(((float) $stdDevValue / (float) $meanValue) * 100, 1);
            }
        }

        $rainfallStats = $statistics['rainfallStatistics']
            ?? $statistics['rainfall_statistics']
            ?? $metadata['rainfall_statistics']
            ?? null;

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

    private function resolveConfigContext(?User $user): array
    {
        $defaultsVersion = $this->configService->getDefaultsVersion();

        if (!$user || $user->role !== 'admin') {
            return [
                'user_id' => null,
                'overrides' => [],
                'snapshot' => null,
                'hash' => $this->computeConfigHash(null, $defaultsVersion),
                'version' => $defaultsVersion,
            ];
        }

        $configModel = $this->configService->getUserConfig($user);
        $rawOverrides = $configModel->overrides ?? [];
        $filteredOverrides = $this->configService->filterOverrides($rawOverrides);

        if (empty($filteredOverrides)) {
            return [
                'user_id' => null,
                'overrides' => [],
                'snapshot' => null,
                'hash' => $this->computeConfigHash(null, $defaultsVersion),
                'version' => $defaultsVersion,
            ];
        }

        $normalisedOverrides = $this->normaliseOverrides($filteredOverrides);
        $effective = $configModel->effective($this->configService->getDefaults());

        return [
            'user_id' => $user->id,
            'overrides' => $normalisedOverrides,
            'snapshot' => $effective,
            'hash' => $this->computeConfigHash($normalisedOverrides, $defaultsVersion),
            'version' => $configModel->defaults_version ?? $defaultsVersion,
        ];
    }

    private function computeConfigHash(?array $overrides, ?string $version): string
    {
        if (empty($overrides)) {
            return 'default';
        }

        $payload = [
            'version' => $version ?? 'default',
            'overrides' => $overrides,
        ];

        return hash('sha256', json_encode($payload, JSON_PRESERVE_ZERO_FRACTION));
    }

    private function normaliseOverrides(array $value): array
    {
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $this->normaliseOverrides($child);
            }
        }

        if ($this->isAssoc($value)) {
            ksort($value);
        } else {
            $normalised = [];
            foreach ($value as $child) {
                $normalised[] = is_array($child) ? $this->normaliseOverrides($child) : $child;
            }
            $value = $normalised;
        }

        return $value;
    }

    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function configContextFromPayload(array $data): array
    {
        $defaultsVersion = Arr::get($data, 'defaults_version')
            ?? Arr::get($data, 'metadata.config.defaults_version')
            ?? $this->configService->getDefaultsVersion();

        $overrides = Arr::get($data, 'config_overrides', Arr::get($data, 'metadata.config.overrides', []));
        $overrides = is_array($overrides) ? $this->configService->filterOverrides($overrides) : [];

        $snapshot = Arr::get($data, 'rusle_config', Arr::get($data, 'metadata.rusle_config'));

        $userId = Arr::get($data, 'user_id', Arr::get($data, 'metadata.user_id'));

        if (empty($overrides)) {
            return [
                'user_id' => null,
                'overrides' => [],
                'snapshot' => $snapshot,
                'hash' => $this->computeConfigHash(null, $defaultsVersion),
                'version' => $defaultsVersion,
            ];
        }

        $normalised = $this->normaliseOverrides($overrides);

        return [
            'user_id' => $userId,
            'overrides' => $normalised,
            'snapshot' => $snapshot,
            'hash' => $this->computeConfigHash($normalised, $defaultsVersion),
            'version' => $defaultsVersion,
        ];
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

        if (!$taskId || !$areaType || $areaId === null || $startYear === null) {
            throw new \InvalidArgumentException('Missing required fields: task_id, area_type, area_id, start_year');
        }

        // Find and update the map record
        $configContext = $this->configContextFromPayload($data);
        $userId = $configContext['user_id'];
        $configHash = $configContext['hash'];

        $geometryHash = $this->getGeometryHashFromData($data);
        $maxZoom = (int) ($data['max_zoom'] ?? Arr::get($data, 'metadata.max_zoom', self::DEFAULT_MAX_ZOOM) ?? self::DEFAULT_MAX_ZOOM);
        $maxZoom = (int) ($data['max_zoom'] ?? Arr::get($data, 'metadata.max_zoom', self::DEFAULT_MAX_ZOOM) ?? self::DEFAULT_MAX_ZOOM);

        $mapQuery = PrecomputedErosionMap::where([
            'area_type' => $areaType,
            'area_id' => $areaId,
            'year' => $startYear,
            'user_id' => $userId,
            'config_hash' => $configHash,
            'geometry_hash' => $geometryHash,
        ]);

        if ($endYear !== $startYear) {
            $mapQuery->where('metadata->period->end_year', $endYear);
        }

        $map = $mapQuery->first();

        if ($map) {
            $map->update([
                'status' => 'processing',
                'config_hash' => $configHash,
                'config_snapshot' => $configContext['snapshot'] ?? $map->config_snapshot,
                'metadata' => array_merge($map->metadata ?? [], [
                    'config' => [
                        'hash' => $configHash,
                        'overrides' => $configContext['overrides'],
                        'defaults_version' => $configContext['version'],
                    ],
                    'user_id' => $userId,
                    'geometry_hash' => $geometryHash,
                ]),
                'geometry_hash' => $geometryHash,
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

        if (!$taskId || !$areaType || $areaId === null || $startYear === null) {
            throw new \InvalidArgumentException('Missing required fields: task_id, area_type, area_id, start_year');
        }

        // Find the map record
        $configContext = $this->configContextFromPayload($data);
        $userId = $configContext['user_id'];
        $configHash = $configContext['hash'];
        $configSnapshot = $configContext['snapshot'];

        $geometryHash = $this->getGeometryHashFromData($data);
        $maxZoom = (int) ($data['max_zoom'] ?? Arr::get($data, 'metadata.max_zoom', self::DEFAULT_MAX_ZOOM) ?? self::DEFAULT_MAX_ZOOM);

        $mapQuery = PrecomputedErosionMap::where([
            'area_type' => $areaType,
            'area_id' => $areaId,
            'year' => $startYear,
            'user_id' => $userId,
            'config_hash' => $configHash,
            'geometry_hash' => $geometryHash,
        ]);

        if ($endYear !== $startYear) {
            $mapQuery->where('metadata->period->end_year', $endYear);
        }

        $map = $mapQuery->first();

        $statistics = $this->enrichStatistics($data['statistics'] ?? null, $data['metadata'] ?? null);
        $components = $data['components'] ?? $data['metadata']['components'] ?? null;
        
        $tilePathKey = $this->getTilePathKeyFromData($data, $areaType, $areaId, $geometryHash);

        $baseMetadata = [
            'task_id' => $taskId,
            'period' => [
                'start_year' => $startYear,
                'end_year' => $endYear,
                'label' => $periodLabel,
            ],
            'config' => [
                'hash' => $configHash,
                'overrides' => $configContext['overrides'],
                'defaults_version' => $configContext['version'],
            ],
            'user_id' => $userId,
            'geometry_hash' => $geometryHash,
            'tile_path_key' => $tilePathKey,
            'max_zoom' => $maxZoom,
        ];

        if ($map) {
            // Update existing record
            $updatedMetadata = array_merge(
                $map->metadata ?? [],
                $baseMetadata,
                $data['metadata'] ?? []
            );
            if ($components !== null) {
                $updatedMetadata['components'] = $components;
            }

            $map->update([
                'status' => 'completed',
                'geotiff_path' => $data['geotiff_path'] ?? null,
                'tiles_path' => $data['tiles_path'] ?? null,
                'statistics' => $statistics,
                'metadata' => $updatedMetadata,
                'computed_at' => now(),
                'error_message' => null,
                'user_id' => $userId,
                'config_hash' => $configHash,
                'config_snapshot' => $configSnapshot ?? Arr::get($updatedMetadata, 'rusle_config'),
                'geometry_hash' => $geometryHash,
                'geometry_snapshot' => $map->geometry_snapshot ?? Arr::get($data, 'geometry_snapshot'),
            ]);
            
            Log::info("Map updated to completed: {$areaType} {$areaId}, period {$periodLabel}");
        } else {
            // Use updateOrCreate to handle potential race conditions
            $map = PrecomputedErosionMap::updateOrCreate(
                [
                    'area_type' => $areaType,
                    'area_id' => $areaId,
                    'year' => $startYear,
                    'user_id' => $userId,
                    'config_hash' => $configHash,
                    'geometry_hash' => $geometryHash,
                ],
                [
                    'status' => 'completed',
                    'geotiff_path' => $data['geotiff_path'] ?? null,
                    'tiles_path' => $data['tiles_path'] ?? null,
                    'statistics' => $statistics,
                    'metadata' => array_merge(
                        $baseMetadata,
                        $data['metadata'] ?? [],
                        $components !== null ? ['components' => $components] : []
                    ),
                    'computed_at' => now(),
                    'error_message' => null,
                    'config_snapshot' => $configSnapshot ?? Arr::get($data, 'metadata.rusle_config'),
                    'geometry_snapshot' => Arr::get($data, 'geometry_snapshot'),
                ]
            );
            
            Log::info("Map record created/updated to completed: {$areaType} {$areaId}, period {$periodLabel}");
        }

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

        if (!$taskId || !$areaType || $areaId === null || $startYear === null) {
            throw new \InvalidArgumentException('Missing required fields: task_id, area_type, area_id, start_year');
        }

        // Find the map record
        $configContext = $this->configContextFromPayload($data);
        $userId = $configContext['user_id'];
        $configHash = $configContext['hash'];

        $geometryHash = $this->getGeometryHashFromData($data);

        $mapQuery = PrecomputedErosionMap::where([
            'area_type' => $areaType,
            'area_id' => $areaId,
            'year' => $startYear,
            'user_id' => $userId,
            'config_hash' => $configHash,
            'geometry_hash' => $geometryHash,
        ]);

        if ($endYear !== $startYear) {
            $mapQuery->where('metadata->period->end_year', $endYear);
        }

        $map = $mapQuery->first();

        $baseMetadata = array_merge(
            $data['metadata'] ?? [],
            [
                'task_id' => $taskId,
                'error_type' => $errorType,
                'period' => [
                    'start_year' => $startYear,
                    'end_year' => $endYear,
                    'label' => $periodLabel,
                ],
                'config' => [
                    'hash' => $configHash,
                    'overrides' => $configContext['overrides'],
                    'defaults_version' => $configContext['version'],
                ],
                'user_id' => $userId,
                'geometry_hash' => $geometryHash,
            ]
        );

        if ($map) {
            // Update existing record to failed
            $updatedMetadata = array_merge(
                $map->metadata ?? [],
                $baseMetadata
            );
            $updatedMetadata['failed_at'] = now()->toIso8601String();

            $map->update([
                'status' => 'failed',
                'error_message' => $error,
                'metadata' => $updatedMetadata,
                'user_id' => $userId,
                'config_hash' => $configHash,
                'config_snapshot' => $configContext['snapshot'] ?? $map->config_snapshot,
                'geometry_hash' => $geometryHash,
            ]);
            
            Log::info("Map updated to failed: {$areaType} {$areaId}, period {$periodLabel}");
        } else {
            // Use updateOrCreate to handle potential race conditions
            $metadata = $baseMetadata;
            $metadata['failed_at'] = now()->toIso8601String();
            
            $map = PrecomputedErosionMap::updateOrCreate(
                [
                    'area_type' => $areaType,
                    'area_id' => $areaId,
                    'year' => $startYear,
                    'user_id' => $userId,
                    'config_hash' => $configHash,
                    'geometry_hash' => $geometryHash,
                ],
                [
                    'status' => 'failed',
                    'error_message' => $error,
                    'metadata' => $metadata,
                    'config_snapshot' => $configContext['snapshot'],
                ]
            );
            
            Log::info("Map record created/updated to failed: {$areaType} {$areaId}, period {$periodLabel}");
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

    private function normaliseGeometry(?array $geometry): ?array
    {
        if ($geometry === null) {
            return null;
        }

        return $this->roundGeometryRecursive($geometry);
    }

    private function roundGeometryRecursive(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $child) {
                $result[$key] = $this->roundGeometryRecursive($child);
            }
            return $result;
        }

        if (is_float($value) || is_int($value)) {
            return round((float) $value, 6);
        }

        return $value;
    }

    private function computeGeometryHash(?array $geometry): string
    {
        if (empty($geometry)) {
            return self::DEFAULT_GEOMETRY_HASH;
        }

        return hash('sha256', json_encode($geometry, JSON_PRESERVE_ZERO_FRACTION));
    }

    private function buildTileStorageKey(string $areaType, int $areaId, ?string $geometryHash = null): string
    {
        if ($geometryHash && $geometryHash !== self::DEFAULT_GEOMETRY_HASH) {
            return "{$areaType}_" . substr($geometryHash, 0, 24);
        }

        return "{$areaType}_{$areaId}";
    }

    private function getGeometryHashFromData(array $data): string
    {
        $hash = Arr::get($data, 'geometry_hash');

        if (is_string($hash) && $hash !== '') {
            return $hash;
        }

        $hash = Arr::get($data, 'metadata.geometry_hash');
        
        if (is_string($hash) && $hash !== '') {
            return $hash;
        }

        return self::DEFAULT_GEOMETRY_HASH;
    }

    private function getTilePathKeyFromData(array $data, string $areaType, int $areaId, ?string $geometryHash): string
    {
        return Arr::get(
            $data,
            'tile_path_key',
            Arr::get(
                $data,
                'metadata.tile_path_key',
                $this->buildTileStorageKey($areaType, $areaId, $geometryHash)
            )
        );
    }
}



