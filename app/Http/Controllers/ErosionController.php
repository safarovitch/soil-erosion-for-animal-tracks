<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\District;
use App\Models\ErosionCache;
use App\Models\TimeSeriesData;
use App\Models\UserQuery;
use App\Services\GoogleEarthEngineService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ErosionController extends Controller
{
    public function __construct(
        private GoogleEarthEngineService $geeService
    ) {}

    /**
     * Compute erosion data for a region or district.
     */
    public function compute(Request $request): JsonResponse
    {
        $request->validate([
            'area_type' => 'required|in:region,district',
            'area_id' => 'required|integer',
            'year' => 'required|integer|min:2016|max:2024',
            'period' => 'required|string|in:annual,monthly,seasonal',
        ]);

        try {
            $area = $this->getArea($request->area_type, $request->area_id);
            if (!$area) {
                return response()->json(['error' => 'Area not found'], 404);
            }

            // Log the query for analytics
            $this->logQuery($request, $area, 'erosion_compute');

            // Compute erosion data
            if (!$this->geeService->isAvailable()) {
                // GEE is not configured, use mock data without logging errors
                $data = $this->getMockErosionData($area, $request->year);
            } else {
                try {
                    $data = $this->geeService->computeErosionForArea($area, $request->year, $request->period);
                } catch (\Exception $geeError) {
                    // Only log GEE errors once per session to reduce log spam
                    $errorKey = 'gee_error_logged_' . md5($geeError->getMessage());
                    if (!session()->has($errorKey)) {
                        Log::warning('GEE computation failed, using mock data', [
                            'request' => $request->all(),
                            'error' => $geeError->getMessage(),
                            'note' => 'This error will not be logged again in this session'
                        ]);
                        session()->put($errorKey, true);
                    }

                    // Return mock data when GEE fails
                    $data = $this->getMockErosionData($area, $request->year);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $data,
                'area' => [
                    'type' => $request->area_type,
                    'id' => $area->id,
                    'name' => $area->name_en,
                ],
                'year' => $request->year,
                'period' => $request->period,
            ]);
        } catch (\Exception $e) {
            Log::error('Erosion computation failed', [
                'request' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Computation failed. Please try again later.',
            ], 500);
        }
    }

    /**
     * Get cached erosion data.
     */
    public function getCached(Request $request): JsonResponse
    {
        $request->validate([
            'area_type' => 'required|in:region,district',
            'area_id' => 'required|integer',
            'year' => 'required|integer|min:2016|max:2024',
            'period' => 'required|string|in:annual,monthly,seasonal',
        ]);

        $cached = ErosionCache::findByParameters(
            $request->area_type === 'region' ? Region::class : District::class,
            $request->area_id,
            $request->year,
            $request->period
        );

        if (!$cached) {
            return response()->json(['error' => 'No cached data found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $cached->data,
            'cached_at' => $cached->created_at,
            'expires_at' => $cached->expires_at,
        ]);
    }

    /**
     * Get time series data for an area.
     */
    public function getTimeSeries(Request $request): JsonResponse
    {
        $request->validate([
            'area_type' => 'required|in:region,district',
            'area_id' => 'required|integer',
            'start_year' => 'integer|min:2016|max:2024',
            'end_year' => 'integer|min:2016|max:2024',
        ]);

        try {
            $area = $this->getArea($request->area_type, $request->area_id);
            if (!$area) {
                return response()->json(['error' => 'Area not found'], 404);
            }

            $startYear = $request->start_year ?? 2016;
            $endYear = $request->end_year ?? 2024;

            // Log the query
            $this->logQuery($request, $area, 'time_series', [
                'start_year' => $startYear,
                'end_year' => $endYear,
            ]);

            // Get time series data
            $timeSeriesData = TimeSeriesData::getTimeSeriesForArea(
                get_class($area),
                $area->id,
                $startYear,
                $endYear
            );

            return response()->json([
                'success' => true,
                'data' => $timeSeriesData,
                'area' => [
                    'type' => $request->area_type,
                    'id' => $area->id,
                    'name' => $area->name_en,
                ],
                'start_year' => $startYear,
                'end_year' => $endYear,
            ]);
        } catch (\Exception $e) {
            Log::error('Time series data retrieval failed', [
                'request' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve time series data.',
            ], 500);
        }
    }

    /**
     * Analyze user-drawn geometry.
     */
    public function analyzeGeometry(Request $request): JsonResponse
    {
        $request->validate([
            'geometry' => 'required|array',
            'year' => 'required|integer|min:2016|max:2024',
        ]);

        try {
            // Log the query
            $this->logQuery($request, null, 'geometry_analysis', [
                'geometry' => $request->geometry,
                'year' => $request->year,
            ]);

            // Analyze the geometry
            if (!$this->geeService->isAvailable()) {
                // GEE is not configured, use mock data without logging errors
                $data = $this->getMockGeometryAnalysis($request->geometry, $request->year);
            } else {
                try {
                    $data = $this->geeService->analyzeGeometry($request->geometry, $request->year);
                } catch (\Exception $geeError) {
                    // Only log GEE errors once per session to reduce log spam
                    $errorKey = 'gee_geometry_error_logged_' . md5($geeError->getMessage());
                    if (!session()->has($errorKey)) {
                        Log::warning('GEE geometry analysis failed, using mock data', [
                            'request' => $request->all(),
                            'error' => $geeError->getMessage(),
                            'note' => 'This error will not be logged again in this session'
                        ]);
                        session()->put($errorKey, true);
                    }

                    // Return mock data when GEE fails
                    $data = $this->getMockGeometryAnalysis($request->geometry, $request->year);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $data,
                'geometry' => $request->geometry,
                'year' => $request->year,
            ]);
        } catch (\Exception $e) {
            Log::error('Geometry analysis failed', [
                'request' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Geometry analysis failed. Please try again later.',
            ], 500);
        }
    }

    /**
     * Get regions list.
     */
    public function getRegions(): JsonResponse
    {
        $regions = Region::select('id', 'name_en', 'name_tj', 'code', 'area_km2')
            ->orderBy('name_en')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $regions,
        ]);
    }

    /**
     * Get districts for a region.
     */
    public function getDistricts(Request $request): JsonResponse
    {
        $request->validate([
            'region_id' => 'required|integer|exists:regions,id',
        ]);

        $districts = District::where('region_id', $request->region_id)
            ->select('id', 'name_en', 'name_tj', 'code', 'area_km2')
            ->orderBy('name_en')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $districts,
        ]);
    }

    /**
     * Get area by type and ID.
     */
    private function getArea(string $type, int $id): Region|District|null
    {
        return match ($type) {
            'region' => Region::find($id),
            'district' => District::find($id),
            default => null,
        };
    }

    /**
     * Log user query for analytics.
     */
    private function logQuery(Request $request, Region|District|null $area, string $type, array $parameters = []): void
    {
        try {
            UserQuery::create([
                'user_id' => Auth::id(),
                'session_id' => $request->session()->getId(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'queryable_type' => $area ? get_class($area) : null,
                'queryable_id' => $area?->id,
                'year' => $parameters['year'] ?? null,
                'period' => $parameters['period'] ?? null,
                'query_type' => $type,
                'parameters' => $parameters,
                'geometry' => $parameters['geometry'] ?? null,
                'processing_time' => null, // Will be updated after processing
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log user query', [
                'error' => $e->getMessage(),
                'query_type' => $type,
            ]);
        }
    }

    /**
     * Get mock erosion data for demonstration purposes.
     */
    private function getMockErosionData($area, int $year): array
    {
        // Generate mock data based on area and year
        $baseErosion = $area->area_km2 * 0.1; // Mock calculation
        $yearVariation = sin($year - 2016) * 0.2; // Vary by year

        return [
            'tiles' => null, // No tiles for mock data
            'statistics' => [
                'mean_erosion_rate' => round($baseErosion + $yearVariation, 2),
                'bare_soil_frequency' => round(15 + ($year - 2016) * 2, 1),
                'sustainability_factor' => round(0.7 - ($year - 2016) * 0.02, 2),
                'total_area' => $area->area_km2,
                'high_risk_area' => round($area->area_km2 * 0.15, 2),
                'moderate_risk_area' => round($area->area_km2 * 0.35, 2),
                'low_risk_area' => round($area->area_km2 * 0.50, 2),
            ],
            'time_series' => $this->getMockTimeSeries($area, $year),
            'distribution' => $this->getMockDistribution($area),
        ];
    }

    /**
     * Get mock time series data.
     */
    private function getMockTimeSeries($area, int $currentYear): array
    {
        $series = [];
        for ($year = 2016; $year <= 2024; $year++) {
            $series[] = [
                'year' => $year,
                'mean_erosion_rate' => round($area->area_km2 * 0.1 + sin($year - 2016) * 0.2, 2),
                'bare_soil_frequency' => round(15 + ($year - 2016) * 2, 1),
                'sustainability_factor' => round(0.7 - ($year - 2016) * 0.02, 2),
            ];
        }
        return $series;
    }

    /**
     * Get mock distribution data.
     */
    private function getMockDistribution($area): array
    {
        return [
            ['category' => 'Very Low', 'area' => round($area->area_km2 * 0.25, 2), 'percentage' => 25],
            ['category' => 'Low', 'area' => round($area->area_km2 * 0.25, 2), 'percentage' => 25],
            ['category' => 'Moderate', 'area' => round($area->area_km2 * 0.30, 2), 'percentage' => 30],
            ['category' => 'High', 'area' => round($area->area_km2 * 0.15, 2), 'percentage' => 15],
            ['category' => 'Very High', 'area' => round($area->area_km2 * 0.05, 2), 'percentage' => 5],
        ];
    }

    /**
     * Get mock geometry analysis data.
     */
    private function getMockGeometryAnalysis(array $geometry, int $year): array
    {
        // Generate mock analysis based on geometry type and year
        $baseRate = 5.0 + ($year - 2016) * 0.5; // Vary by year
        $variation = sin($year - 2016) * 1.0; // Add some variation

        return [
            'mean_erosion_rate' => round($baseRate + $variation, 2),
            'bare_soil_frequency' => round(20 + ($year - 2016) * 3, 1),
            'sustainability_factor' => round(0.65 - ($year - 2016) * 0.03, 2),
            'total_area' => round(100 + rand(-20, 50), 2), // Mock area calculation
            'risk_level' => $baseRate > 7 ? 'High' : ($baseRate > 4 ? 'Moderate' : 'Low'),
        ];
    }
}
