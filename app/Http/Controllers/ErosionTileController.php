<?php

namespace App\Http\Controllers;

use App\Services\ErosionTileService;
use App\Models\Region;
use App\Models\District;
use App\Models\PrecomputedErosionMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class ErosionTileController extends Controller
{
    private ErosionTileService $service;

    public function __construct(ErosionTileService $service)
    {
        $this->service = $service;
    }

    /**
     * Serve a map tile
     */
    public function serveTile($areaType, $areaId, $period, $z, $x, $y)
    {
        $tileFolder = $this->resolveTileFolder($areaType, (string) $areaId);

        $tilePath = storage_path(
            "rusle-tiles/tiles/{$tileFolder}/{$period}/{$z}/{$x}/{$y}.png"
        );

        if (!file_exists($tilePath)) {
            $fallbackTilePath = $this->resolveFallbackTilePath(
                $areaType,
                (string) $areaId,
                $period,
                (int) $z,
                (int) $x,
                (int) $y
            );

            if (!$fallbackTilePath) {
                Log::warning('Requested tile missing', [
                    'area_type' => $areaType,
                    'area_id' => $areaId,
                    'period' => $period,
                    'z' => $z,
                    'x' => $x,
                    'y' => $y,
                ]);
                return response()->json(['error' => 'Tile not found'], 404);
            }

            $tilePath = $fallbackTilePath;
        }

        return response()->file($tilePath, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400'  // Cache for 1 day
        ]);
    }

    /**
     * Check availability or queue computation
     */
    public function checkAvailability(Request $request)
    {
        $minYear = (int) config('earthengine.defaults.start_year', 1993);
        $maxYear = max($minYear, (int) config('earthengine.defaults.end_year', date('Y')));

        $validated = $request->validate([
            'area_type' => 'required|in:region,district,custom',
            'area_id' => 'required_if:area_type,region,district|nullable|integer',
            'geometry' => 'required_if:area_type,custom|array',
            'start_year' => "sometimes|integer|min:{$minYear}|max:{$maxYear}",
            'end_year' => "sometimes|integer|min:{$minYear}|max:{$maxYear}",
            'year' => "sometimes|integer|min:{$minYear}|max:{$maxYear}",
        ]);

        $startYear = $validated['start_year'] ?? $validated['year'] ?? null;
        $endYear = $validated['end_year'] ?? $startYear ?? null;

        if ($startYear === null || $endYear === null) {
            return response()->json([
                'status' => 'error',
                'error' => 'start_year (or year) and end_year are required'
            ], 422);
        }

        $startYear = (int) $startYear;
        $endYear = (int) $endYear;

        if ($endYear < $startYear) {
            return response()->json([
                'status' => 'error',
                'error' => 'end_year must be greater than or equal to start_year'
            ], 422);
        }

        $user = Auth::user();

        if ($validated['area_type'] === 'custom') {
            if (empty($validated['geometry'])) {
                return response()->json([
                    'status' => 'error',
                    'error' => 'geometry is required for custom areas'
                ], 422);
            }

            $result = $this->service->getOrQueueCustomMap(
                $validated['geometry'],
                $startYear,
                $endYear,
                $user
            );
        } else {
            $result = $this->service->getOrQueueMap(
                $validated['area_type'],
                $validated['area_id'],
                $startYear,
                $endYear,
                $user
            );
        }

        return response()->json($result);
    }

    /**
     * Check task status
     */
    public function taskStatus($taskId)
    {
        $result = $this->service->checkTaskStatus($taskId);
        return response()->json($result);
    }

    /**
     * Callback from Python tasks when computation starts
     */
    public function taskStarted(Request $request)
    {
        try {
            $result = $this->service->handleTaskStarted($request->all());
            
            Log::info('Task started callback received', [
                'task_id' => $request->input('task_id'),
                'area' => $request->input('area_type') . ' ' . $request->input('area_id'),
                'period' => $request->input('period_label', $request->input('year'))
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Task started processed'
            ]);
        } catch (\Exception $e) {
            Log::error('Task started callback failed', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Callback from Python tasks when computation completes
     */
    public function taskComplete(Request $request)
    {
        try {
            $result = $this->service->handleTaskCompletion($request->all());
            
            Log::info('Task completion callback received', [
                'task_id' => $request->input('task_id'),
                'area' => $request->input('area_type') . ' ' . $request->input('area_id'),
                'period' => $request->input('period_label', $request->input('year')),
                'status' => $result['status'] ?? 'unknown'
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Task completion processed'
            ]);
        } catch (\Exception $e) {
            Log::error('Task completion callback failed', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Callback from Python tasks when computation fails
     */
    public function taskFailed(Request $request)
    {
        try {
            $result = $this->service->handleTaskFailure($request->all());
            
            Log::info('Task failure callback received', [
                'task_id' => $request->input('task_id'),
                'area' => $request->input('area_type') . ' ' . $request->input('area_id'),
                'period' => $request->input('period_label', $request->input('year')),
                'error' => $request->input('error'),
                'error_type' => $request->input('error_type')
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Task failure processed'
            ]);
        } catch (\Exception $e) {
            Log::error('Task failure callback failed', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk precompute all areas (admin only)
     */
    public function precomputeAll(Request $request)
    {
        $minYear = (int) config('earthengine.defaults.start_year', 1993);
        $maxYear = max($minYear, (int) config('earthengine.defaults.end_year', date('Y')));
        $years = range($minYear, $maxYear);
        $queued = [];
        $skipped = [];

        foreach (Region::all() as $region) {
            foreach ($years as $year) {
                $result = $this->service->getOrQueueMap('region', $region->id, $year);
                if ($result['status'] === 'queued') {
                    $queued[] = [
                        'area' => "Region {$region->id}",
                        'year' => $year,
                        'task_id' => $result['task_id']
                    ];
                } elseif ($result['status'] === 'available') {
                    $skipped[] = "Region {$region->id}, year {$year}";
                }
            }
        }

        foreach (District::all() as $district) {
            foreach ($years as $year) {
                $result = $this->service->getOrQueueMap('district', $district->id, $year);
                if ($result['status'] === 'queued') {
                    $queued[] = [
                        'area' => "District {$district->id}",
                        'year' => $year,
                        'task_id' => $result['task_id']
                    ];
                } elseif ($result['status'] === 'available') {
                    $skipped[] = "District {$district->id}, year {$year}";
                }
            }
        }

        return response()->json([
            'message' => 'Precomputation queued for all areas',
            'total_jobs' => count($queued),
            'jobs' => $queued,
            'skipped' => count($skipped),
            'skipped_details' => $skipped
        ]);
    }

    private function resolveTileFolder(string $areaType, string $areaId): string
    {
        if (ctype_digit($areaId)) {
            return "{$areaType}_{$areaId}";
        }

        if (str_starts_with($areaId, "{$areaType}_")) {
            return $areaId;
        }

        return "{$areaType}_{$areaId}";
    }

    private function resolveFallbackTilePath(
        string $areaType,
        string $requestedAreaId,
        string $requestedPeriod,
        int $z,
        int $x,
        int $y
    ): ?string {
        [$startYear, $endYear] = $this->parsePeriodYears($requestedPeriod);
        if ($startYear === null) {
            return null;
        }

        $map = $this->findMapForFallback($areaType, $requestedAreaId, $startYear, $endYear);
        if (!$map) {
            return null;
        }

        $folder = $map->tile_storage_key;
        $periodFolder = $map->period_label;
        $fallbackPath = storage_path(
            "rusle-tiles/tiles/{$folder}/{$periodFolder}/{$z}/{$x}/{$y}.png"
        );

        if (!file_exists($fallbackPath)) {
            Log::warning('Fallback tile path still missing', [
                'requested_area_id' => $requestedAreaId,
                'resolved_folder' => $folder,
                'requested_period' => $requestedPeriod,
                'resolved_period' => $periodFolder,
                'area_type' => $areaType,
                'z' => $z,
                'x' => $x,
                'y' => $y,
            ]);
            return null;
        }

        Log::info('Serving tile via fallback path', [
            'requested_area_id' => $requestedAreaId,
            'resolved_folder' => $folder,
            'requested_period' => $requestedPeriod,
            'resolved_period' => $periodFolder,
            'area_type' => $areaType,
            'z' => $z,
            'x' => $x,
            'y' => $y,
        ]);

        return $fallbackPath;
    }

    private function parsePeriodYears(string $period): array
    {
        $clean = str_replace(' ', '', trim($period));

        if (preg_match('/^(?<year>\d{4})$/', $clean, $matches)) {
            $year = (int) $matches['year'];
            return [$year, $year];
        }

        if (preg_match('/^(?<start>\d{4})-(?<end>\d{4})$/', $clean, $matches)) {
            $startYear = (int) $matches['start'];
            $endYear = (int) $matches['end'];
            return [$startYear, max($endYear, $startYear)];
        }

        return [null, null];
    }

    private function findMapForFallback(string $areaType, string $areaId, ?int $startYear, ?int $endYear): ?PrecomputedErosionMap
    {
        if ($startYear === null) {
            return null;
        }

        $query = PrecomputedErosionMap::query()
            ->where('area_type', $areaType)
            ->where('year', $startYear);

        if ($this->looksLikeTilePathKey($areaType, $areaId)) {
            $query->where('metadata->tile_path_key', $areaId);
        } else {
            $numericId = $this->extractNumericAreaId($areaType, $areaId);
            if ($numericId === null) {
                return null;
            }
            $query->where('area_id', $numericId);
        }

        if ($endYear !== null && $endYear !== $startYear) {
            $query->where('metadata->period->end_year', $endYear);
        }

        return $query->first();
    }

    private function looksLikeTilePathKey(string $areaType, string $areaId): bool
    {
        $prefix = "{$areaType}_";
        if (!str_starts_with($areaId, $prefix)) {
            return false;
        }

        $suffix = substr($areaId, strlen($prefix));
        return $suffix !== '' && !ctype_digit($suffix);
    }

    private function extractNumericAreaId(string $areaType, string $areaId): ?int
    {
        if (ctype_digit($areaId)) {
            return (int) $areaId;
        }

        $prefix = "{$areaType}_";
        if (str_starts_with($areaId, $prefix)) {
            $numericPortion = substr($areaId, strlen($prefix));
            if (ctype_digit($numericPortion)) {
                return (int) $numericPortion;
            }
        }

        return null;
    }
}



