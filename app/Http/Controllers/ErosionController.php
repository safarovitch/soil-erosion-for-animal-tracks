<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\District;
use App\Models\ErosionCache;
use App\Models\TimeSeriesData;
use App\Models\UserQuery;
use App\Services\GoogleEarthEngineService;
use App\Exceptions\GoogleEarthEngineException;
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
            'area_type' => 'required|in:region,district,country',
            'area_id' => 'required|integer',
            'year' => 'required|integer|min:1993',
            'period' => 'required|string|in:annual,monthly,seasonal',
        ]);

        try {
            $area = $this->getArea($request->area_type, $request->area_id);
            if (!$area) {
                return response()->json(['error' => 'Area not found'], 404);
            }

            // Check if GEE is configured
            if (!$this->geeService->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Google Earth Engine is not configured. Please configure GEE credentials in the .env file.',
                    'details' => 'Contact administrator to configure GEE_SERVICE_ACCOUNT_EMAIL, GEE_PROJECT_ID, and GEE_PRIVATE_KEY_PATH',
                ], 503); // Service Unavailable
            }

            // Log the query for analytics
            $this->logQuery($request, $area, 'erosion_compute');

            // Compute erosion data directly from GEE
            $data = $this->geeService->computeErosionForArea($area, $request->year, $request->period);

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
        } catch (GoogleEarthEngineException $e) {
            Log::error('Erosion computation failed', [
                'request' => $request->all(),
                'error' => $e->getMessage(),
                'context' => $e->getContext(),
                'http_status' => $e->getHttpStatus(),
            ]);

            $statusCode = $e->getHttpStatus() ?? 500;
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'details' => $e->getContext(),
                'error_code' => $e->getGeeErrorCode(),
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error('Erosion computation failed', [
                'request' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'GEE computation failed: ' . $e->getMessage(),
                'details' => 'Please check GEE credentials and try again.',
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
            'year' => 'required|integer|min:1993',
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
            'start_year' => 'integer|min:1993',
            'end_year' => 'integer|min:1993',
        ]);

        try {
            $area = $this->getArea($request->area_type, $request->area_id);
            if (!$area) {
                return response()->json(['error' => 'Area not found'], 404);
            }

            $startYear = $request->start_year ?? 1993;
            $endYear = $request->end_year ?? date('Y');

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
            'year' => 'required|integer|min:1993',
        ]);

        try {
            // Check if GEE is configured
            if (!$this->geeService->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Google Earth Engine is not configured.',
                    'details' => 'Please configure GEE credentials to analyze custom geometries.',
                ], 503);
            }

            // Log the query
            $this->logQuery($request, null, 'geometry_analysis', [
                'geometry' => $request->geometry,
                'year' => $request->year,
            ]);

            // Analyze the geometry directly with GEE
            $data = $this->geeService->analyzeGeometry($request->geometry, $request->year);

            return response()->json([
                'success' => true,
                'data' => $data,
                'geometry' => $request->geometry,
                'year' => $request->year,
            ]);
        } catch (GoogleEarthEngineException $e) {
            Log::error('Geometry analysis failed', [
                'request' => $request->all(),
                'error' => $e->getMessage(),
                'context' => $e->getContext(),
                'http_status' => $e->getHttpStatus(),
            ]);

            $statusCode = $e->getHttpStatus() ?? 500;
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'details' => $e->getContext(),
                'error_code' => $e->getGeeErrorCode(),
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error('Geometry analysis failed', [
                'request' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'GEE analysis failed: ' . $e->getMessage(),
                'details' => 'Please check your geometry and try again.',
            ], 500);
        }
    }

    /**
     * Get regions list.
     */
    public function getRegions(): JsonResponse
    {
        $regions = Region::select('id', 'name_en', 'name_tj', 'code', 'area_km2', 'geometry')
            ->orderBy('name_en')
            ->get();

        return response()->json([
            'success' => true,
            'regions' => $regions,
        ]);
    }

    /**
     * Get districts for a region.
     */
    public function getDistricts(Request $request): JsonResponse
    {
        $regionId = $request->input('region_id');
        
        $query = District::query();
        
        if ($regionId) {
            $request->validate([
                'region_id' => 'integer|exists:regions,id',
            ]);
            $query->where('region_id', $regionId);
        }
        
        $districts = $query
            ->select('id', 'region_id', 'name_en', 'name_tj', 'code', 'area_km2', 'geometry')
            ->orderBy('name_en')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $districts->map(function ($district) {
                return [
                    'id' => $district->id,
                    'region_id' => $district->region_id,
                    'name' => $district->name_en,
                    'name_en' => $district->name_en,
                    'name_tj' => $district->name_tj,
                    'code' => $district->code,
                    'area_km2' => $district->area_km2,
                    'geometry' => $district->getGeometryArray(),
                    'center' => $district->getCenterPoint(),
                    'bbox' => $district->getBoundingBox(),
                ];
            }),
        ]);
    }
    
    /**
     * Get districts as GeoJSON FeatureCollection
     */
    public function getDistrictsGeoJSON(Request $request): JsonResponse
    {
        $regionId = $request->input('region_id');
        
        $query = District::query();
        
        if ($regionId) {
            $query->where('region_id', $regionId);
        }
        
        $districts = $query
            ->select('id', 'region_id', 'name_en', 'name_tj', 'code', 'area_km2', 'geometry')
            ->get();
        
        $features = $districts->map(function ($district) {
            return $district->toGeoJSONFeature();
        });
        
        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
            'totalCount' => $features->count(),
        ]);
    }

    /**
     * Get RUSLE R-factor layer data.
     */
    public function getRFactorLayer(Request $request): JsonResponse
    {
        return $this->getLayerData($request, 'r_factor');
    }

    /**
     * Get RUSLE K-factor layer data.
     */
    public function getKFactorLayer(Request $request): JsonResponse
    {
        return $this->getLayerData($request, 'k_factor');
    }

    /**
     * Get RUSLE LS-factor layer data.
     */
    public function getLSFactorLayer(Request $request): JsonResponse
    {
        return $this->getLayerData($request, 'ls_factor');
    }

    /**
     * Get RUSLE C-factor layer data.
     */
    public function getCFactorLayer(Request $request): JsonResponse
    {
        return $this->getLayerData($request, 'c_factor');
    }

    /**
     * Get RUSLE P-factor layer data.
     */
    public function getPFactorLayer(Request $request): JsonResponse
    {
        return $this->getLayerData($request, 'p_factor');
    }

    /**
     * Get rainfall slope/trend data.
     */
    public function getRainfallSlope(Request $request): JsonResponse
    {
        $request->validate([
            'area_type' => 'required|in:region,district,country',
            'area_id' => 'required|integer',
            'start_year' => 'required|integer|min:1993',
            'end_year' => 'required|integer|min:1993',
        ]);

        try {
            // Handle country-wide requests
            if ($request->area_type === 'country') {
                // For country-wide, we'll use a default region or create a country-wide area
                $area = Region::first(); // Use first region as proxy for country-wide
                if (!$area) {
                    return response()->json(['error' => 'No regions available for country-wide data'], 404);
                }
            } else {
                $area = $this->getArea($request->area_type, $request->area_id);
                if (!$area) {
                    return response()->json(['error' => 'Area not found'], 404);
                }
            }

            // Check if GEE is configured
            if (!$this->geeService->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Google Earth Engine is not configured.',
                ], 503);
            }

            // Fetch grid data for visualization
            $data = $this->geeService->getRainfallSlopeGrid($area, $request->start_year, $request->end_year);

            return response()->json([
                'success' => true,
                'data' => $data,
                'area_type' => $request->area_type,
                'area_id' => $request->area_id,
            ]);
        } catch (GoogleEarthEngineException $e) {
            Log::error('Rainfall slope retrieval failed', [
                'error' => $e->getMessage(),
                'context' => $e->getContext(),
                'http_status' => $e->getHttpStatus(),
            ]);
            $statusCode = $e->getHttpStatus() ?? 500;
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'details' => $e->getContext(),
                'error_code' => $e->getGeeErrorCode(),
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error('Rainfall slope retrieval failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve rainfall slope data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get rainfall CV data.
     */
    public function getRainfallCV(Request $request): JsonResponse
    {
        $request->validate([
            'area_type' => 'required|in:region,district,country',
            'area_id' => 'required|integer',
            'start_year' => 'required|integer|min:1993',
            'end_year' => 'required|integer|min:1993',
        ]);

        try {
            // Handle country-wide requests
            if ($request->area_type === 'country') {
                // For country-wide, we'll use a default region or create a country-wide area
                $area = Region::first(); // Use first region as proxy for country-wide
                if (!$area) {
                    return response()->json(['error' => 'No regions available for country-wide data'], 404);
                }
            } else {
                $area = $this->getArea($request->area_type, $request->area_id);
                if (!$area) {
                    return response()->json(['error' => 'Area not found'], 404);
                }
            }

            // Check if GEE is configured
            if (!$this->geeService->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Google Earth Engine is not configured.',
                ], 503);
            }

            // Fetch grid data for visualization
            $data = $this->geeService->getRainfallCVGrid($area, $request->start_year, $request->end_year);

            return response()->json([
                'success' => true,
                'data' => $data,
                'area_type' => $request->area_type,
                'area_id' => $request->area_id,
            ]);
        } catch (GoogleEarthEngineException $e) {
            Log::error('Rainfall CV retrieval failed', [
                'error' => $e->getMessage(),
                'context' => $e->getContext(),
                'http_status' => $e->getHttpStatus(),
            ]);
            $statusCode = $e->getHttpStatus() ?? 500;
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'details' => $e->getContext(),
                'error_code' => $e->getGeeErrorCode(),
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error('Rainfall CV retrieval failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve rainfall CV data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed erosion grid for selected area.
     */
    public function getDetailedGrid(Request $request): JsonResponse
    {
        $request->validate([
            'area_type' => 'required|in:region,district,country',
            'area_id' => 'required|integer',
            'year' => 'required|integer|min:1993',
            'grid_size' => 'integer|min:10|max:500',
        ]);

        try {
            $area = $this->getArea($request->area_type, $request->area_id);
            if (!$area) {
                return response()->json(['error' => 'Area not found'], 404);
            }

            // Check if precomputed tiles exist first
            $map = \App\Models\PrecomputedErosionMap::where([
                'area_type' => $request->area_type,
                'area_id' => $request->area_id,
                'year' => $request->year
            ])->first();

            if ($map && $map->isAvailable()) {
                // Return tiles URL for tile layer rendering
                Log::info('Using precomputed tiles for erosion layer', [
                    'area' => $area->name_en,
                    'year' => $request->year,
                    'tiles_url' => $map->tile_url
                ]);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'tiles' => $map->tile_url,
                        'statistics' => $map->statistics,
                        'metadata' => $map->metadata
                    ],
                ]);
            }

            // Check if GEE is configured
            if (!$this->geeService->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Google Earth Engine is not configured.',
                ], 503);
            }

            $gridSize = $request->grid_size ?? 50;

            // Fetch directly from GEE if tiles don't exist
            Log::info('Computing erosion grid from GEE (no tiles available)', [
                'area' => $area->name_en,
                'year' => $request->year
            ]);

            $gridData = $this->geeService->getDetailedErosionGrid($area, $request->year, $gridSize);

            return response()->json([
                'success' => true,
                'data' => $gridData,
            ]);
        } catch (GoogleEarthEngineException $e) {
            Log::error('Detailed grid retrieval failed', [
                'area' => $request->area_id,
                'error' => $e->getMessage(),
                'context' => $e->getContext(),
                'http_status' => $e->getHttpStatus(),
            ]);
            $statusCode = $e->getHttpStatus() ?? 500;
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'details' => $e->getContext(),
                'error_code' => $e->getGeeErrorCode(),
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error('Detailed grid retrieval failed', [
                'area' => $request->area_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve detailed grid data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available years for a specific area from GEE
     */
    public function getAvailableYears(Request $request): JsonResponse
    {
        $request->validate([
            'area_type' => 'required|string|in:region,district',
            'area_id' => 'required|integer',
        ]);

        try {
            $area = $this->getArea($request->area_type, $request->area_id);
            if (!$area) {
                return response()->json([
                    'success' => false,
                    'error' => 'Area not found',
                ], 404);
            }

            // Check if GEE is available
            if (!$this->geeService->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Google Earth Engine is not configured. Please configure GEE credentials in the .env file.',
                    'details' => 'Contact administrator to configure GEE_SERVICE_ACCOUNT_EMAIL, GEE_PROJECT_ID, and GEE_PRIVATE_KEY_PATH',
                ], 503);
            }

            // Log the query
            $this->logQuery($request, $area, 'available_years', []);

            // Fetch available years from GEE
            $availableYears = $this->geeService->getAvailableYears($area);

            return response()->json([
                'success' => true,
                'data' => $availableYears,
                'area' => [
                    'type' => $request->area_type,
                    'id' => $area->id,
                    'name' => $area->name_en,
                ],
            ]);
        } catch (GoogleEarthEngineException $e) {
            Log::error('Available years error', [
                'area' => $area->name_en ?? 'unknown',
                'error' => $e->getMessage(),
                'context' => $e->getContext(),
                'http_status' => $e->getHttpStatus(),
            ]);
            $statusCode = $e->getHttpStatus() ?? 500;
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'details' => $e->getContext(),
                'error_code' => $e->getGeeErrorCode(),
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error('Available years error', [
                'area' => $area->name_en ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch available years',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate bounding box for area.
     */
    private function calculateBoundingBox($area): array
    {
        $geometry = null;
        
        if (is_array($area->geometry)) {
            $geometry = $area->geometry;
        } elseif (is_string($area->geometry)) {
            $geometry = json_decode($area->geometry, true);
        }
        
        if (!$geometry || !isset($geometry['coordinates'])) {
            return [68.0, 36.0, 75.0, 41.0]; // Default Tajikistan bounds
        }
        
        $coords = [];
        if ($geometry['type'] === 'Polygon') {
            $coords = $geometry['coordinates'][0] ?? [];
        } elseif ($geometry['type'] === 'MultiPolygon') {
            $coords = $geometry['coordinates'][0][0] ?? [];
        }
        
        if (empty($coords)) {
            return [68.0, 36.0, 75.0, 41.0];
        }
        
        $minLon = $maxLon = (float)$coords[0][0];
        $minLat = $maxLat = (float)$coords[0][1];
        
        foreach ($coords as $coord) {
            if (!is_array($coord) || count($coord) < 2) {
                continue;
            }
            
            $lon = (float)$coord[0];
            $lat = (float)$coord[1];
            
            $minLon = min($minLon, $lon);
            $maxLon = max($maxLon, $lon);
            $minLat = min($minLat, $lat);
            $maxLat = max($maxLat, $lat);
        }
        
        return [$minLon, $minLat, $maxLon, $maxLat];
    }

    /**
     * Check if a point is within a geometry using ray casting algorithm.
     */
    private function isPointInGeometry(float $x, float $y, array $geometry): bool
    {
        // Get coordinates from geometry
        $coords = [];
        
        if ($geometry['type'] === 'Polygon') {
            $coords = $geometry['coordinates'][0] ?? [];
        } elseif ($geometry['type'] === 'MultiPolygon') {
            $coords = $geometry['coordinates'][0][0] ?? [];
        } else {
            return true; // If not polygon, include by default
        }
        
        if (empty($coords)) {
            return true;
        }

        // Ray casting algorithm for point-in-polygon test
        $inside = false;
        $n = count($coords);
        
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $coords[$i][0];
            $yi = $coords[$i][1];
            $xj = $coords[$j][0];
            $yj = $coords[$j][1];
            
            $intersect = (($yi > $y) != ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);
            
            if ($intersect) {
                $inside = !$inside;
            }
        }
        
        return $inside;
    }

    /**
     * Get area by type and ID.
     */
    private function getArea(string $type, int $id): Region|District|null
    {
        return match ($type) {
            'region' => Region::find($id),
            'district' => District::find($id),
            'country' => Region::first(), // Use first region as proxy for country-wide
            default => null,
        };
    }

    /**
     * Generic method to get RUSLE factor layer data.
     */
    private function getLayerData(Request $request, string $factor): JsonResponse
    {
        $request->validate([
            'area_type' => 'required|in:region,district,country',
            'area_id' => 'required|integer',
            'year' => 'required|integer|min:1993',
        ]);

        try {
            // Handle country-wide requests
            if ($request->area_type === 'country') {
                // For country-wide, we'll use a default region or create a country-wide area
                $area = Region::first(); // Use first region as proxy for country-wide
                if (!$area) {
                    return response()->json(['error' => 'No regions available for country-wide data'], 404);
                }
            } else {
                $area = $this->getArea($request->area_type, $request->area_id);
                if (!$area) {
                    return response()->json(['error' => 'Area not found'], 404);
                }
            }

            // Check if GEE is configured
            if (!$this->geeService->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Google Earth Engine is not configured.',
                ], 503);
            }

            // Call the appropriate GEE service method directly
            $methodMap = [
                'r_factor' => 'getRainfallErosivity',
                'k_factor' => 'getSoilErodibility',
                'ls_factor' => 'getTopographicFactor',
                'c_factor' => 'getCoverManagementFactor',
                'p_factor' => 'getSupportPracticeFactor',
            ];

            $method = $methodMap[$factor] ?? null;
            if (!$method) {
                return response()->json(['error' => 'Invalid layer'], 400);
            }

            $data = $this->geeService->$method($area, $request->year);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (GoogleEarthEngineException $e) {
            Log::error("Layer {$factor} retrieval failed", [
                'error' => $e->getMessage(),
                'context' => $e->getContext(),
                'http_status' => $e->getHttpStatus(),
            ]);
            $statusCode = $e->getHttpStatus() ?? 500;
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'details' => $e->getContext(),
                'error_code' => $e->getGeeErrorCode(),
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error("Layer {$factor} retrieval failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => "Failed to retrieve {$factor} data: " . $e->getMessage(),
            ], 500);
        }
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

}
