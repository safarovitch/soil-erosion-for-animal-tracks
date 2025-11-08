<?php

namespace App\Http\Controllers;

use App\Services\ErosionTileService;
use App\Models\Region;
use App\Models\District;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
    public function serveTile($areaType, $areaId, $year, $z, $x, $y)
    {
        $tilePath = storage_path(
            "rusle-tiles/tiles/{$areaType}_{$areaId}/{$year}/{$z}/{$x}/{$y}.png"
        );

        if (!file_exists($tilePath)) {
            return response()->json(['error' => 'Tile not found'], 404);
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
        $validated = $request->validate([
            'area_type' => 'required|in:region,district',
            'area_id' => 'required|integer',
            'year' => 'required|integer|min:2015|max:2024'
        ]);

        $result = $this->service->getOrQueueMap(
            $validated['area_type'],
            $validated['area_id'],
            $validated['year']
        );

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
                'year' => $request->input('year')
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
                'year' => $request->input('year'),
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
                'year' => $request->input('year'),
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
        $years = range(2015, 2024);
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
}



