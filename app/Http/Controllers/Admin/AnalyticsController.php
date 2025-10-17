<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserQuery;
use App\Models\CustomDataset;
use App\Models\ErosionCache;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('role:admin');
    }

    /**
     * Get dashboard statistics.
     */
    public function getStats(): JsonResponse
    {
        $stats = [
            'totalQueries' => UserQuery::count(),
            'totalDatasets' => CustomDataset::count(),
            'activeUsers' => User::where('created_at', '>=', now()->subDays(30))->count(),
            'cacheSize' => $this->getCacheSize(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get recent activity.
     */
    public function getRecentActivity(): JsonResponse
    {
        $activities = UserQuery::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($query) {
                return [
                    'id' => $query->id,
                    'description' => $this->formatActivityDescription($query),
                    'timestamp' => $query->created_at->diffForHumans(),
                    'icon' => $this->getActivityIcon($query->query_type),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $activities,
        ]);
    }

    /**
     * Get system status.
     */
    public function getSystemStatus(): JsonResponse
    {
        $status = [
            'database' => $this->checkDatabaseConnection(),
            'gee' => $this->checkGeeConnection(),
            'gdal' => $this->checkGdalAvailability(),
            'storage' => $this->checkStorageAvailability(),
        ];

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }

    /**
     * Get usage statistics.
     */
    public function getUsageStats(): JsonResponse
    {
        $stats = [
            'totalQueries' => UserQuery::count(),
            'uniqueUsers' => UserQuery::distinct('ip_address')->count(),
            'mostQueriedRegion' => $this->getMostQueriedRegion(),
            'avgProcessingTime' => UserQuery::avg('processing_time') ?? 0,
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get usage history with pagination.
     */
    public function getUsageHistory(Request $request): JsonResponse
    {
        $query = UserQuery::with('user');

        // Apply filters
        if ($request->has('dateRange')) {
            $query->where('created_at', '>=', $this->getDateRangeStart($request->dateRange));
        }

        if ($request->has('queryType') && $request->queryType) {
            $query->where('query_type', $request->queryType);
        }

        if ($request->has('region') && $request->region) {
            $query->where('queryable_id', $request->region);
        }

        if ($request->has('userType') && $request->userType) {
            if ($request->userType === 'authenticated') {
                $query->whereNotNull('user_id');
            } elseif ($request->userType === 'anonymous') {
                $query->whereNull('user_id');
            }
        }

        $queries = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $queries,
        ]);
    }

    /**
     * Clear cache.
     */
    public function clearCache(): JsonResponse
    {
        try {
            $deletedCount = ErosionCache::truncate();

            return response()->json([
                'success' => true,
                'message' => 'Cache cleared successfully',
                'deleted_count' => $deletedCount,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to clear cache: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export usage data.
     */
    public function exportUsage(Request $request)
    {
        $query = UserQuery::with('user');

        // Apply same filters as getUsageHistory
        if ($request->has('dateRange')) {
            $query->where('created_at', '>=', $this->getDateRangeStart($request->dateRange));
        }

        if ($request->has('queryType') && $request->queryType) {
            $query->where('query_type', $request->queryType);
        }

        $queries = $query->orderBy('created_at', 'desc')->get();

        $csvData = "Timestamp,User,Query Type,Area,Year,Processing Time,IP Address\n";

        foreach ($queries as $query) {
            $csvData .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s\n",
                $query->created_at->format('Y-m-d H:i:s'),
                $query->user ? $query->user->name : 'Anonymous',
                $query->query_type,
                $this->getAreaName($query),
                $query->year ?? '-',
                $query->processing_time ?? '-',
                $query->ip_address
            );
        }

        return response($csvData, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="usage-history-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    /**
     * Get cache size in MB.
     */
    private function getCacheSize(): string
    {
        try {
            $cacheSize = ErosionCache::count() * 0.001; // Rough estimate
            return number_format($cacheSize, 1) . ' MB';
        } catch (\Exception $e) {
            return '0 MB';
        }
    }

    /**
     * Format activity description.
     */
    private function formatActivityDescription(UserQuery $query): string
    {
        $areaName = $this->getAreaName($query);
        $userName = $query->user ? $query->user->name : 'Anonymous user';

        switch ($query->query_type) {
            case 'erosion_compute':
                return "{$userName} computed erosion data for {$areaName}";
            case 'time_series':
                return "{$userName} requested time series data for {$areaName}";
            case 'geometry_analysis':
                return "{$userName} analyzed custom geometry";
            default:
                return "{$userName} performed {$query->query_type}";
        }
    }

    /**
     * Get activity icon.
     */
    private function getActivityIcon(string $type): string
    {
        return match ($type) {
            'erosion_compute' => '🌍',
            'time_series' => '📊',
            'geometry_analysis' => '✏️',
            default => '🔍',
        };
    }

    /**
     * Check database connection.
     */
    private function checkDatabaseConnection(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check GEE connection.
     */
    private function checkGeeConnection(): bool
    {
        // This would check if GEE service is accessible
        // For now, return true if configured
        return !empty(config('earthengine.service_account_email'));
    }

    /**
     * Check GDAL availability.
     */
    private function checkGdalAvailability(): bool
    {
        try {
            $output = shell_exec('gdalinfo --version 2>&1');
            return !empty($output);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check storage availability.
     */
    private function checkStorageAvailability(): bool
    {
        try {
            $path = storage_path('app');
            return is_writable($path);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get most queried region.
     */
    private function getMostQueriedRegion(): string
    {
        $mostQueried = UserQuery::whereNotNull('queryable_id')
            ->where('queryable_type', 'App\Models\Region')
            ->selectRaw('queryable_id, COUNT(*) as count')
            ->groupBy('queryable_id')
            ->orderBy('count', 'desc')
            ->first();

        if ($mostQueried) {
            $region = \App\Models\Region::find($mostQueried->queryable_id);
            return $region ? $region->name_en : 'Unknown';
        }

        return 'None';
    }

    /**
     * Get date range start.
     */
    private function getDateRangeStart(string $range): string
    {
        return match ($range) {
            'today' => now()->startOfDay()->toDateString(),
            'week' => now()->subWeek()->toDateString(),
            'month' => now()->subMonth()->toDateString(),
            'year' => now()->subYear()->toDateString(),
            default => '1900-01-01',
        };
    }

    /**
     * Get area name from query.
     */
    private function getAreaName(UserQuery $query): string
    {
        if (!$query->queryable_id || !$query->queryable_type) {
            return 'Custom Area';
        }

        try {
            $model = $query->queryable_type::find($query->queryable_id);
            return $model ? $model->name_en : 'Unknown';
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }
}
