<?php

namespace App\Services;

use App\Models\ErosionCache;
use App\Models\Region;
use App\Models\District;
use App\Exceptions\GoogleEarthEngineException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class GoogleEarthEngineService
{
    private string $baseUrl = 'https://earthengine.googleapis.com/v1alpha';
    private string $serviceAccountEmail;
    private string $privateKeyPath;
    private string $projectId;
    private ?string $accessToken = null;
    private bool $isConfigured = false;

    public function __construct()
    {
        $this->serviceAccountEmail = config('earthengine.service_account_email');
        $this->privateKeyPath = config('earthengine.private_key_path');
        $this->projectId = config('earthengine.project_id');

        // Check if GEE is properly configured
        $this->isConfigured = $this->checkConfiguration();
    }

    /**
     * Check if GEE is properly configured.
     */
    private function checkConfiguration(): bool
    {
        if (!$this->serviceAccountEmail || !$this->projectId) {
            return false;
        }

        $privateKeyFilePath = storage_path($this->privateKeyPath);
        return file_exists($privateKeyFilePath);
    }

    /**
     * Check if GEE is configured and available.
     */
    public function isAvailable(): bool
    {
        return $this->isConfigured;
    }

    /**
     * Authenticate with Google Earth Engine using service account.
     */
    public function authenticate(): bool
    {
        try {
            // Check if private key file exists
            $privateKeyFilePath = storage_path($this->privateKeyPath);
            if (!file_exists($privateKeyFilePath)) {
                throw GoogleEarthEngineException::authenticationFailed(
                    "Private key file not found at: {$privateKeyFilePath}. Please configure GEE_PRIVATE_KEY_PATH in .env"
                );
            }

            // Load and parse the JSON key file
            $keyFileContents = file_get_contents($privateKeyFilePath);
            $keyData = json_decode($keyFileContents, true);
            
            if (!$keyData || !isset($keyData['private_key'])) {
                throw GoogleEarthEngineException::authenticationFailed(
                    "Invalid private key file format at: {$privateKeyFilePath}. Expected JSON with 'private_key' field."
                );
            }

            // Extract the private key from JSON
            $privateKey = $keyData['private_key'];

            // Create JWT token for authentication
            $header = json_encode(['typ' => 'JWT', 'alg' => 'RS256']);
            $now = time();
            $payload = json_encode([
                'iss' => $this->serviceAccountEmail,
                'scope' => 'https://www.googleapis.com/auth/earthengine',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now,
            ]);

            $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
            $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

            $signature = '';
            if (!openssl_sign($base64Header . '.' . $base64Payload, $signature, $privateKey, 'SHA256')) {
                throw GoogleEarthEngineException::authenticationFailed('Failed to sign JWT token');
            }
            $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

            $jwt = $base64Header . '.' . $base64Payload . '.' . $base64Signature;

            // Exchange JWT for access token
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->successful()) {
                $this->accessToken = $response->json('access_token');
                return true;
            }

            $errorBody = $response->json() ?? [];
            $errorMessage = is_array($errorBody['error'] ?? null) 
                ? ($errorBody['error']['message'] ?? $response->body())
                : ($errorBody['error'] ?? $response->body());
            throw GoogleEarthEngineException::apiRequestFailed(
                $response->status(),
                $errorMessage,
                $errorBody
            );
        } catch (GoogleEarthEngineException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('GEE Authentication error', ['error' => $e->getMessage()]);
            throw GoogleEarthEngineException::authenticationFailed($e->getMessage(), $e);
        }
    }

    /**
     * Compute erosion for a region or district.
     */
    public function computeErosionForArea(Region|District $area, int $year, string $period = 'annual'): array
    {
        // Check cache first
        $cacheKey = $this->generateCacheKey($area, $year, $period);
        $cached = ErosionCache::findByParameters(
            get_class($area),
            $area->id,
            $year,
            $period
        );

        if ($cached && !$cached->isExpired()) {
            return $cached->data;
        }

        try {
            // Use Python GEE service for all computations - never use mock data
            $pythonServiceUrl = config('services.gee.url', env('PYTHON_GEE_SERVICE_URL', 'http://127.0.0.1:5000'));
            
            // Convert geometry to GeoJSON
            $geometry = $this->convertGeometryToGeoJSON($area);
            
            Log::info('Computing erosion via Python service', [
                'area' => $area->name_en,
                'year' => $year,
                'period' => $period
            ]);
            
            // Get all factors and soil erosion from Python service
            $response = Http::timeout(600)->post("{$pythonServiceUrl}/api/rusle/factors", [
                'area_geometry' => $geometry,
                'year' => $year,
                'factors' => 'all',
                'scale' => 100
            ]);
            
            if (!$response->successful()) {
                $error = $response->json() ?? [];
                throw GoogleEarthEngineException::apiRequestFailed(
                    $response->status(),
                    $error['error'] ?? 'Python GEE service request failed',
                    $error
                );
            }
            
            $result = $response->json();
            
            if (!$result['success']) {
                throw GoogleEarthEngineException::apiRequestFailed(
                    500,
                    $result['error'] ?? 'Failed to compute RUSLE factors',
                    $result
                );
            }
            
            $data = $result['data'];
            $factors = $data['factors'] ?? [];
            $soilErosion = $data['soil_erosion'] ?? null;
            
            // Validate that we have real data - throw error if missing
            if (empty($factors)) {
                throw GoogleEarthEngineException::noDataAvailable(
                    $area->name_en,
                    $year,
                    ['message' => 'No factor data returned from Python service']
                );
            }
            
            if (!$soilErosion) {
                throw GoogleEarthEngineException::noDataAvailable(
                    $area->name_en,
                    $year,
                    ['message' => 'No soil erosion data returned from Python service']
                );
            }
            
            // Build statistics array with real data only
            $totalArea = $area->area_km2 * 100; // Convert to hectares
            $meanErosion = $soilErosion['mean'] ?? null;
            $minErosion = $soilErosion['min'] ?? null;
            $maxErosion = $soilErosion['max'] ?? null;
            $stdDev = $soilErosion['std_dev'] ?? null;
            
            // Validate required statistics
            if ($meanErosion === null || $minErosion === null || $maxErosion === null) {
                throw GoogleEarthEngineException::noDataAvailable(
                    $area->name_en,
                    $year,
                    ['message' => 'Incomplete soil erosion statistics from Python service']
                );
            }
            
            $cv = $stdDev ? ($stdDev / $meanErosion * 100) : 0;
            
            $stats = [
                'tiles' => null,
                'statistics' => [
                    'mean_erosion_rate' => round($meanErosion, 2),
                    'min_erosion_rate' => round($minErosion, 2),
                    'max_erosion_rate' => round($maxErosion, 2),
                    'erosion_cv' => round($cv, 1),
                    'bare_soil_frequency' => null, // Not provided by Python service
                    'sustainability_factor' => null, // Not provided by Python service
                    'rainfall_slope' => null, // Would be calculated from time series
                    'rainfall_cv' => null, // Would be calculated from time series
                    'total_area' => $totalArea,
                    'severity_distribution' => [], // Would need histogram data
                    'rusle_factors' => [
                        'r' => $factors['r']['mean'] ?? null,
                        'k' => $factors['k']['mean'] ?? null,
                        'ls' => $factors['ls']['mean'] ?? null,
                        'c' => $factors['c']['mean'] ?? null,
                        'p' => $factors['p']['mean'] ?? null,
                    ],
                    'top_eroding_areas' => [],
                ],
                'source' => 'python_gee_service',
                'timestamp' => now()->toIso8601String(),
                'factors' => $factors,
            ];
            
            // Cache the result
            $this->cacheResult($area, $year, $period, $stats);

            return $stats;
        } catch (GoogleEarthEngineException $e) {
            Log::error('GEE computation error', [
                'area' => $area->name_en,
                'year' => $year,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (Exception $e) {
            Log::error('GEE computation error', [
                'area' => $area->name_en,
                'year' => $year,
                'error' => $e->getMessage(),
            ]);
            throw GoogleEarthEngineException::apiRequestFailed(
                500,
                "Failed to compute erosion: " . $e->getMessage(),
                [],
                $e
            );
        }
    }

    /**
     * Get bare soil frequency for an area.
     */
    public function getBareSoilFrequency(Region|District $area, int $year): array
    {
        return $this->computeErosionForArea($area, $year, 'annual');
    }

    /**
     * Get sustainability factor for an area.
     */
    public function getSustainabilityFactor(Region|District $area, int $year): array
    {
        return $this->computeErosionForArea($area, $year, 'annual');
    }

    /**
     * Get time series data for an area.
     */
    public function getTimeSeriesData(Region|District $area, int $startYear, int $endYear): array
    {
        $timeSeriesData = [];

        for ($year = $startYear; $year <= $endYear; $year++) {
            try {
                $data = $this->computeErosionForArea($area, $year, 'annual');
                $timeSeriesData[$year] = $data;
            } catch (Exception $e) {
                Log::warning("Failed to get data for year {$year}", ['error' => $e->getMessage()]);
                $timeSeriesData[$year] = null;
            }
        }

        return $timeSeriesData;
    }

    /**
     * Analyze user-drawn geometry.
     */
    public function analyzeGeometry(array $geometry, int $year): array
    {
        try {
            // Get Python GEE service URL
            $pythonServiceUrl = config('app.python_gee_service_url', env('PYTHON_GEE_SERVICE_URL', 'http://127.0.0.1:5000'));
            
            // Call Python GEE service
            $response = Http::timeout(600)->post("{$pythonServiceUrl}/api/rusle/compute", [
                'area_geometry' => $geometry,
                'year' => $year
            ]);
            
            if (!$response->successful()) {
                $error = $response->json() ?? [];
                throw GoogleEarthEngineException::apiRequestFailed(
                    $response->status(),
                    $error['error'] ?? 'Python GEE service request failed',
                    $error
                );
            }
            
            $result = $response->json();
            
            if (!$result['success']) {
                throw GoogleEarthEngineException::apiRequestFailed(
                    500,
                    $result['error'] ?? 'Failed to analyze geometry',
                    $result
                );
            }
            
            // Create a temporary area object for compatibility
            $tempArea = new \stdClass();
            $tempArea->area_km2 = $this->calculateAreaFromGeometry($geometry);
            $tempArea->name_en = 'Custom Area';
            
            // Get individual factors from Python service
            $factorsResponse = Http::timeout(600)->post("{$pythonServiceUrl}/api/rusle/factors", [
                'area_geometry' => $geometry,
                'year' => $year,
                'factors' => 'all',
                'scale' => 100
            ]);
            
            $factorsData = null;
            if ($factorsResponse->successful()) {
                $factorsResult = $factorsResponse->json();
                if ($factorsResult['success'] && isset($factorsResult['data']['factors'])) {
                    $factorsData = $factorsResult['data']['factors'];
                }
            }
            
            // Return in expected format - use real data or throw error
            if (!$factorsData) {
                throw GoogleEarthEngineException::apiRequestFailed(
                    500,
                    "Failed to retrieve RUSLE factors from Python service",
                    []
                );
            }
            
            return [
                'soil_loss' => $result['data']['statistics']['mean'] ?? null,
                'r_factor' => $factorsData['r']['mean'] ?? null,
                'k_factor' => $factorsData['k']['mean'] ?? null,
                'ls_factor' => $factorsData['ls']['mean'] ?? null,
                'c_factor' => $factorsData['c']['mean'] ?? null,
                'p_factor' => $factorsData['p']['mean'] ?? null,
                'area_km2' => $tempArea->area_km2,
                'statistics' => $result['data']['statistics'],
                'factors' => $factorsData,
                'year' => $year,
                'source' => 'python_gee_service'
            ];
            
        } catch (GoogleEarthEngineException $e) {
            Log::error('GEE geometry analysis error', [
                'year' => $year,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (Exception $e) {
            Log::error('GEE geometry analysis error', [
                'year' => $year,
                'error' => $e->getMessage()
            ]);
            throw GoogleEarthEngineException::apiRequestFailed(
                500,
                "Failed to analyze geometry: " . $e->getMessage(),
                [],
                $e
            );
        }
    }

    /**
     * Calculate area in km² from geometry.
     */
    private function calculateAreaFromGeometry(array $geometry): float
    {
        // Simple bbox-based approximation
        // In production, use proper geodesic calculation
        $coords = $geometry['coordinates'][0] ?? [];
        if (empty($coords)) {
            return 100; // Default
        }
        
        $minLon = $maxLon = $coords[0][0];
        $minLat = $maxLat = $coords[0][1];
        
        foreach ($coords as $coord) {
            $minLon = min($minLon, $coord[0]);
            $maxLon = max($maxLon, $coord[0]);
            $minLat = min($minLat, $coord[1]);
            $maxLat = max($maxLat, $coord[1]);
        }
        
        // Approximate area (very rough)
        $width = ($maxLon - $minLon) * 111; // km per degree at equator
        $height = ($maxLat - $minLat) * 111;
        
        return $width * $height;
    }

    /**
     * Get Rainfall Erosivity (R-factor) layer for visualization.
     */
    public function getRainfallErosivity(Region|District $area, int $year): array
    {
        return $this->getLayerData($area, $year, 'r_factor');
    }

    /**
     * Get Soil Erodibility (K-factor) layer for visualization.
     */
    public function getSoilErodibility(Region|District $area, int $year): array
    {
        return $this->getLayerData($area, $year, 'k_factor');
    }

    /**
     * Get Topographic Factor (LS-factor) layer for visualization.
     */
    public function getTopographicFactor(Region|District $area, int $year): array
    {
        return $this->getLayerData($area, $year, 'ls_factor');
    }

    /**
     * Get Cover Management Factor (C-factor) layer for visualization.
     */
    public function getCoverManagementFactor(Region|District $area, int $year): array
    {
        return $this->getLayerData($area, $year, 'c_factor');
    }

    /**
     * Get Support Practice Factor (P-factor) layer for visualization.
     */
    public function getSupportPracticeFactor(Region|District $area, int $year): array
    {
        return $this->getLayerData($area, $year, 'p_factor');
    }

    /**
     * Get Rainfall Slope/Trend (temporal change in rainfall).
     * Uses Python GEE service for actual computation.
     */
    public function getRainfallSlope(Region|District $area, int $startYear, int $endYear): array
    {
        try {
            // Get Python GEE service URL
            $pythonServiceUrl = config('app.python_gee_service_url', env('PYTHON_GEE_SERVICE_URL', 'http://127.0.0.1:5000'));
            
            // Convert area geometry to GeoJSON
            $geometry = $this->convertGeometryToGeoJSON($area);
            
            Log::info('Computing rainfall slope via Python service', [
                'area' => $area->name_en,
                'start_year' => $startYear,
                'end_year' => $endYear,
            ]);
            
            // Call Python GEE service
            $response = Http::timeout(300)->post("{$pythonServiceUrl}/api/rainfall/slope", [
                'area_geometry' => $geometry,
                'start_year' => $startYear,
                'end_year' => $endYear
            ]);
            
            if (!$response->successful()) {
                $error = $response->json() ?? [];
                throw GoogleEarthEngineException::apiRequestFailed(
                    $response->status(),
                    $error['error'] ?? 'Python GEE service request failed',
                    $error
                );
            }
            
            $result = $response->json();
            
            if (!$result['success']) {
                throw GoogleEarthEngineException::apiRequestFailed(
                    500,
                    $result['error'] ?? 'Failed to compute rainfall slope',
                    $result
                );
            }
            
            $data = $result['data'];
            
            return [
                'mean' => $data['mean'],
                'min' => $data['min'],
                'max' => $data['max'],
                'stdDev' => $data['std_dev'],
                'yearRange' => $data['year_range'],
                'startYear' => $data['start_year'],
                'endYear' => $data['end_year'],
                'area' => $area->name_en,
                'unit' => $data['unit'] ?? 'mm/year per year',
                'interpretation' => $data['interpretation'] ?? '',
                'source' => 'gee_python_service'
            ];
            
        } catch (GoogleEarthEngineException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Rainfall slope computation error', [
                'area' => $area->name_en,
                'start_year' => $startYear,
                'end_year' => $endYear,
                'error' => $e->getMessage(),
            ]);
            throw GoogleEarthEngineException::apiRequestFailed(
                500,
                "Failed to compute rainfall slope: " . $e->getMessage(),
                [],
                $e
            );
        }
    }

    /**
     * Get Rainfall Coefficient of Variation (CV).
     * Uses Python GEE service for actual computation.
     */
    public function getRainfallCV(Region|District $area, int $startYear, int $endYear): array
    {
        try {
            // Get Python GEE service URL
            $pythonServiceUrl = config('app.python_gee_service_url', env('PYTHON_GEE_SERVICE_URL', 'http://127.0.0.1:5000'));
            
            // Convert area geometry to GeoJSON
            $geometry = $this->convertGeometryToGeoJSON($area);
            
            Log::info('Computing rainfall CV via Python service', [
                'area' => $area->name_en,
                'start_year' => $startYear,
                'end_year' => $endYear,
            ]);
            
            // Call Python GEE service
            $response = Http::timeout(300)->post("{$pythonServiceUrl}/api/rainfall/cv", [
                'area_geometry' => $geometry,
                'start_year' => $startYear,
                'end_year' => $endYear
            ]);
            
            if (!$response->successful()) {
                $error = $response->json() ?? [];
                throw GoogleEarthEngineException::apiRequestFailed(
                    $response->status(),
                    $error['error'] ?? 'Python GEE service request failed',
                    $error
                );
            }
            
            $result = $response->json();
            
            if (!$result['success']) {
                throw GoogleEarthEngineException::apiRequestFailed(
                    500,
                    $result['error'] ?? 'Failed to compute rainfall CV',
                    $result
                );
            }
            
            $data = $result['data'];
            
            return [
                'mean' => $data['mean'],
                'min' => $data['min'],
                'max' => $data['max'],
                'stdDev' => $data['std_dev'],
                'yearRange' => $data['year_range'],
                'startYear' => $data['start_year'],
                'endYear' => $data['end_year'],
                'area' => $area->name_en,
                'unit' => $data['unit'] ?? 'percent (%)',
                'interpretation' => $data['interpretation'] ?? '',
                'source' => 'gee_python_service'
            ];
            
        } catch (GoogleEarthEngineException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Rainfall CV computation error', [
                'area' => $area->name_en,
                'start_year' => $startYear,
                'end_year' => $endYear,
                'error' => $e->getMessage(),
            ]);
            throw GoogleEarthEngineException::apiRequestFailed(
                500,
                "Failed to compute rainfall CV: " . $e->getMessage(),
                [],
                $e
            );
        }
    }
    
    /**
     * Get rainfall CV grid data for visualization.
     */
    public function getRainfallCVGrid(Region|District $area, int $startYear, int $endYear): array
    {
        try {
            $pythonServiceUrl = config('app.python_gee_service_url', env('PYTHON_GEE_SERVICE_URL', 'http://127.0.0.1:5000'));
            $geometry = $this->convertGeometryToGeoJSON($area);
            
            Log::info('Computing rainfall CV grid via Python service', [
                'area' => $area->name_en,
                'start_year' => $startYear,
                'end_year' => $endYear,
            ]);
            
            $response = Http::timeout(300)->post("{$pythonServiceUrl}/api/rainfall/cv-grid", [
                'area_geometry' => $geometry,
                'start_year' => $startYear,
                'end_year' => $endYear,
                'grid_size' => 50
            ]);
            
            if (!$response->successful()) {
                $error = $response->json() ?? [];
                throw GoogleEarthEngineException::apiRequestFailed(
                    $response->status(),
                    $error['error'] ?? 'Python GEE service request failed',
                    $error
                );
            }
            
            $result = $response->json();
            
            if (!$result['success']) {
                throw GoogleEarthEngineException::apiRequestFailed(
                    500,
                    $result['error'] ?? 'Failed to compute rainfall CV grid',
                    $result
                );
            }
            
            return $result['data'];
        } catch (Exception $e) {
            Log::error('Rainfall CV grid computation error', [
                'area' => $area->name_en,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Get rainfall slope grid data for visualization.
     */
    public function getRainfallSlopeGrid(Region|District $area, int $startYear, int $endYear): array
    {
        try {
            $pythonServiceUrl = config('app.python_gee_service_url', env('PYTHON_GEE_SERVICE_URL', 'http://127.0.0.1:5000'));
            $geometry = $this->convertGeometryToGeoJSON($area);
            
            Log::info('Computing rainfall slope grid via Python service', [
                'area' => $area->name_en,
                'start_year' => $startYear,
                'end_year' => $endYear,
            ]);
            
            $response = Http::timeout(300)->post("{$pythonServiceUrl}/api/rainfall/slope-grid", [
                'area_geometry' => $geometry,
                'start_year' => $startYear,
                'end_year' => $endYear,
                'grid_size' => 50
            ]);
            
            if (!$response->successful()) {
                $error = $response->json() ?? [];
                throw GoogleEarthEngineException::apiRequestFailed(
                    $response->status(),
                    $error['error'] ?? 'Python GEE service request failed',
                    $error
                );
            }
            
            $result = $response->json();
            
            if (!$result['success']) {
                throw GoogleEarthEngineException::apiRequestFailed(
                    500,
                    $result['error'] ?? 'Failed to compute rainfall slope grid',
                    $result
                );
            }
            
            return $result['data'];
        } catch (Exception $e) {
            Log::error('Rainfall slope grid computation error', [
                'area' => $area->name_en,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get specific layer data from RUSLE computation.
     */
    private function getLayerData(Region|District $area, int $year, string $band): array
    {
        // Use Python service for factor calculations - never use mock data
        $pythonServiceUrl = config('services.gee.url', env('PYTHON_GEE_SERVICE_URL', 'http://127.0.0.1:5000'));
        
        // Map band names to factor keys
        $bandMapping = [
            'r_factor' => 'r',
            'k_factor' => 'k', 
            'ls_factor' => 'ls',
            'c_factor' => 'c',
            'p_factor' => 'p'
        ];
        
        $factorKey = $bandMapping[$band] ?? null;
        if (!$factorKey) {
            throw GoogleEarthEngineException::apiRequestFailed(
                400,
                "Invalid factor band: {$band}",
                []
            );
        }
        
        // Convert area geometry to GeoJSON
        $geometry = $this->convertGeometryToGeoJSON($area);
        
        Log::info("Fetching {$band} from Python service", [
            'area' => $area->name_en,
            'year' => $year,
            'factor' => $factorKey
        ]);
        
        // Call Python service for specific factor
        $response = Http::timeout(600)->post("{$pythonServiceUrl}/api/rusle/factors", [
            'area_geometry' => $geometry,
            'year' => $year,
            'factors' => [$factorKey],  // Request only the specific factor
            'scale' => 100
        ]);
        
        if (!$response->successful()) {
            $error = $response->json() ?? [];
            throw GoogleEarthEngineException::apiRequestFailed(
                $response->status(),
                $error['error'] ?? "Failed to retrieve {$band} from Python service",
                $error
            );
        }
        
        $result = $response->json();
        
        if (!$result['success']) {
            throw GoogleEarthEngineException::apiRequestFailed(
                500,
                $result['error'] ?? "Failed to compute {$band}",
                $result
            );
        }
        
        $factors = $result['data']['factors'] ?? [];
        $factorData = $factors[$factorKey] ?? null;
        
        // If factor data not found, throw error - never return mock data
        if (!$factorData || $factorData['mean'] === null) {
            throw GoogleEarthEngineException::noDataAvailable(
                $area->name_en,
                $year,
                [
                    'band' => $band,
                    'factor' => $factorKey,
                    'message' => "Factor '{$factorKey}' data not available from Python service"
                ]
            );
        }
        
        return [
            'layer' => $band,
            'mean' => $factorData['mean'],
            'min' => $factorData['min'],
            'max' => $factorData['max'],
            'stdDev' => $factorData['std_dev'],
            'year' => $year,
            'area' => $area->name_en,
            'unit' => $factorData['unit'] ?? '',
            'description' => $factorData['description'] ?? '',
            'source' => 'python_gee_service'
        ];
    }

    /**
     * Get detailed erosion grid data for selected area.
     * Returns aggregated pixel data in a grid format for detailed visualization.
     */
    public function getDetailedErosionGrid(Region|District $area, int $year, int $gridSize = 50): array
    {
        // Check cache first
        $cacheKey = "detailed_grid_{$area->id}_{$year}_{$gridSize}";
        $cached = Cache::get($cacheKey);
        
        if ($cached) {
            return $cached;
        }

        try {
            // Get Python GEE service URL
            $pythonServiceUrl = config('app.python_gee_service_url', env('PYTHON_GEE_SERVICE_URL', 'http://127.0.0.1:5000'));
            
            // Convert area geometry to GeoJSON
            $geometry = $this->convertGeometryToGeoJSON($area);
            
            // Simplify complex geometries to improve performance
            $geometry = $this->simplifyGeometry($geometry);
            
            // Log geometry being sent
            Log::info('Sending geometry to Python service', [
                'area' => $area->name_en,
                'geometry_type' => $geometry['type'] ?? 'unknown',
                'has_coordinates' => isset($geometry['coordinates']),
                'coordinate_count' => $this->countCoordinates($geometry)
            ]);
            
            // Pre-calculate bounding box to avoid GEE timeout on complex geometries
            $bbox = $this->calculateBoundingBox($geometry);
            
            Log::info('Pre-calculated bounding box', ['bbox' => $bbox]);
            
            Log::info('Calling Python GEE service with pre-calculated bbox', [
                'url' => "{$pythonServiceUrl}/api/rusle/detailed-grid",
                'pythonServiceUrl' => $pythonServiceUrl,
                'geometry' => $geometry,
                'year' => $year,
                'grid_size' => $gridSize,
                'bbox' => $bbox
            ]);

            // Call Python GEE service with pre-calculated bbox
            $response = Http::timeout(600)->post("{$pythonServiceUrl}/api/rusle/detailed-grid", [
                'area_geometry' => $geometry,
                'year' => $year,
                'grid_size' => $gridSize,
                'bbox' => $bbox  // Send pre-calculated bbox to skip GEE bbox calculation
            ]);
            
            Log::info('Received response from Python service', [
                'status' => $response->status(),
                'successful' => $response->successful()
            ]);
            
            if (!$response->successful()) {
                $error = $response->json() ?? [];
                Log::error('Python service error response', ['error' => $error]);
                throw GoogleEarthEngineException::apiRequestFailed(
                    $response->status(),
                    $error['error'] ?? 'Python GEE service request failed',
                    $error
                );
            }
            
            $result = $response->json();
            
            Log::info('Parsed Python service response', [
                'success' => $result['success'] ?? false,
                'has_data' => isset($result['data']),
                'cell_count' => $result['data']['cell_count'] ?? 0
            ]);
            
            if (!$result['success']) {
                throw GoogleEarthEngineException::apiRequestFailed(
                    500,
                    $result['error'] ?? 'Failed to compute detailed grid',
                    $result ?? []
                );
            }
            
            $gridData = $result['data'];
            
            // Cache for 1 hour
            Cache::put($cacheKey, $gridData, 3600);
            
            Log::info('Detailed grid cached successfully', [
                'cell_count' => count($gridData['cells'] ?? [])
            ]);
            
            return $gridData;
            
        } catch (GoogleEarthEngineException $e) {
            Log::error('Detailed grid generation error', [
                'area' => $area->name_en,
                'year' => $year,
                'error' => $e->getMessage(),
                'context' => $e->getContext(),
            ]);
            throw $e;
        } catch (Exception $e) {
            Log::error('Detailed grid generation error', [
                'area' => $area->name_en,
                'year' => $year,
                'error' => $e->getMessage(),
            ]);
            throw GoogleEarthEngineException::apiRequestFailed(
                500,
                "Failed to retrieve detailed grid data: " . $e->getMessage(),
                [],
                $e
            );
        }
    }

    /**
     * Generate empty grid structure for error cases
     */
    private function generateEmptyGrid(int $gridSize): array
    {
        return [
            'cells' => [],
            'statistics' => [
                'mean' => 0,
                'min' => 0,
                'max' => 0,
                'stdDev' => 0,
            ],
            'grid_size' => $gridSize,
            'bbox' => null,
        ];
    }

    /**
     * Get available years for a specific area from GEE datasets
     */
    public function getAvailableYears(Region|District $area): array
    {
        try {
            if (!$this->accessToken) {
                $this->authenticate();
            }

            $geometry = $this->convertGeometryToGeoJSON($area);
            $expression = $this->buildAvailableYearsExpression($geometry);

            $requestBody = [
                'expression' => $expression,
                'fileFormat' => 'JSON',
            ];

            $response = Http::withToken($this->accessToken)
                ->timeout(600)
                ->post("{$this->baseUrl}/projects/{$this->projectId}/image:computeStatistics", $requestBody);

            if (!$response->successful()) {
                // Fallback to known range if GEE query fails
                Log::warning('Failed to query available years from GEE, using fallback range', [
                    'area' => $area->name_en,
                    'error' => $response->body(),
                ]);
                return $this->processAvailableYears([]);
            }

            $result = $response->json();
            return $this->processAvailableYears($result);
            
        } catch (Exception $e) {
            Log::warning('Error querying available years from GEE, using fallback range', [
                'area' => $area->name_en,
                'error' => $e->getMessage(),
            ]);
            // Return fallback years instead of throwing - this is a non-critical operation
            return $this->processAvailableYears([]);
        }
    }

    /**
     * Build GEE expression for detailed grid computation.
     */
    private function buildDetailedGridExpression(int $year, array $geometry, array $bbox, int $gridSize): string
    {
        $cellWidth = ($bbox[2] - $bbox[0]) / $gridSize;
        $cellHeight = ($bbox[3] - $bbox[1]) / $gridSize;

        return "
            // Build RUSLE computation
            var startDate = '{$year}-01-01';
            var endDate = '{$year}-12-31';
            
            // Get erosion layer (from main RUSLE computation)
            var chirps = ee.ImageCollection('UCSB-CHG/CHIRPS/DAILY')
                .filterDate(startDate, endDate)
                .select('precipitation');
            var annualPrecip = chirps.sum();
            var R_factor = annualPrecip.pow(1.61).multiply(0.0483);
            
            var clay = ee.Image('projects/soilgrids-isric/clay_mean').divide(100.0);
            var silt = ee.Image('projects/soilgrids-isric/silt_mean').divide(100.0);
            var sand = ee.Image('projects/soilgrids-isric/sand_mean').divide(100.0);
            var M = silt.add(sand.multiply(0.1)).multiply(100);
            var K_factor = M.multiply(0.0001).multiply(12).subtract(0.02)
                .multiply(sand.multiply(0.02).add(0.03))
                .clamp(0.01, 0.7);
            
            var dem = ee.Image('USGS/SRTMGL1_003').select('elevation');
            var slope = ee.Terrain.slope(dem);
            var flowAcc = dem.focal_max(90).subtract(dem);
            var slopeLength = flowAcc.multiply(30);
            var m = slope.divide(100).add(1).multiply(0.5);
            var L_factor = slopeLength.divide(22.13).pow(m);
            var slopeRad = slope.multiply(Math.PI / 180);
            var S_factor = slope.lt(9)
                .where(slope.lt(9), slope.multiply(0.065).multiply(1.7).add(0.065))
                .where(slope.gte(9), slopeRad.sin().multiply(16.8).subtract(0.5));
            var LS_factor = L_factor.multiply(S_factor);
            
            var s2 = ee.ImageCollection('COPERNICUS/S2_SR_HARMONIZED')
                .filterDate(startDate, endDate)
                .filter(ee.Filter.lt('CLOUDY_PIXEL_PERCENTAGE', 20));
            var ndvi = s2.map(function(img) {
                var nir = img.select('B8');
                var red = img.select('B4');
                return img.addBands(nir.subtract(red).divide(nir.add(red)).rename('NDVI'));
            });
            var meanNDVI = ndvi.select('NDVI').mean();
            var C_factor = meanNDVI.multiply(-1).add(1).divide(2).clamp(0.001, 1);
            
            var landCover = ee.Image('ESA/WorldCover/v100/2020').select('Map');
            var P_factor = landCover
                .where(landCover.eq(10), 0.1)
                .where(landCover.eq(20), 0.2)
                .where(landCover.eq(30), 0.3)
                .where(landCover.eq(40), 0.5)
                .where(landCover.eq(50), 1.0)
                .where(landCover.eq(60), 1.0);
            
            // Calculate soil loss
            var soilLoss = R_factor.multiply(K_factor).multiply(LS_factor)
                .multiply(C_factor).multiply(P_factor);
            
            // Create grid and aggregate
            var scale = Math.max({$cellWidth}, {$cellHeight}) * 111000; // Convert degrees to meters
            
            // Reduce to grid cells with statistics
            var grid = soilLoss.reduceRegion({
                reducer: ee.Reducer.mean()
                    .combine(ee.Reducer.min(), '', true)
                    .combine(ee.Reducer.max(), '', true)
                    .combine(ee.Reducer.stdDev(), '', true),
                geometry: ee.Geometry(geometry),
                scale: scale,
                maxPixels: 1e9,
                bestEffort: true,
            });
            
            return soilLoss;
        ";
    }

    /**
     * Process GEE grid data into frontend-friendly format.
     * Only includes cells that intersect with the actual area boundary.
     */
    private function processGridData(array $geeData, int $gridSize, array $bbox, array $areaGeometry = null): array
    {
        $cells = [];
        $cellWidth = ($bbox[2] - $bbox[0]) / $gridSize;
        $cellHeight = ($bbox[3] - $bbox[1]) / $gridSize;

        // Extract pixel values from GEE response
        $pixelData = $geeData['properties'] ?? $geeData;
        $meanErosion = $pixelData['mean'] ?? 15;
        $minErosion = $pixelData['min'] ?? 5;
        $maxErosion = $pixelData['max'] ?? 50;
        $stdDev = $pixelData['stdDev'] ?? 10;

        // Create grid cells with interpolated values
        for ($i = 0; $i < $gridSize; $i++) {
            for ($j = 0; $j < $gridSize; $j++) {
                $x1 = $bbox[0] + ($i * $cellWidth);
                $y1 = $bbox[1] + ($j * $cellHeight);
                $x2 = $x1 + $cellWidth;
                $y2 = $y1 + $cellHeight;
                
                // Calculate cell center point
                $centerX = ($x1 + $x2) / 2;
                $centerY = ($y1 + $y2) / 2;

                // Check if cell center is within area boundary (if geometry provided)
                if ($areaGeometry && !$this->isPointInGeometry($centerX, $centerY, $areaGeometry)) {
                    continue; // Skip cells outside the area boundary
                }

                // Interpolate erosion value based on position and statistics
                $positionFactor = sin(($i / $gridSize) * M_PI) * cos(($j / $gridSize) * M_PI);
                $erosionRate = $meanErosion + ($positionFactor * $stdDev);
                $erosionRate = max($minErosion, min($maxErosion, $erosionRate));

                $cells[] = [
                    'x' => $i,
                    'y' => $j,
                    'erosion_rate' => round($erosionRate, 2),
                    'bbox' => [$x1, $y1, $x2, $y2],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[
                            [$x1, $y1],
                            [$x2, $y1],
                            [$x2, $y2],
                            [$x1, $y2],
                            [$x1, $y1],
                        ]],
                    ],
                ];
            }
        }

        return [
            'cells' => $cells,
            'statistics' => [
                'mean' => round($meanErosion, 2),
                'min' => round($minErosion, 2),
                'max' => round($maxErosion, 2),
                'stdDev' => round($stdDev, 2),
            ],
            'grid_size' => $gridSize,
            'bbox' => $bbox,
        ];
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
     * Build the RUSLE computation expression.
     * RUSLE Formula: A = R × K × LS × C × P
     * Where:
     * A = Soil loss (tons/hectare/year)
     * R = Rainfall erosivity factor (MJ mm ha⁻¹ h⁻¹ year⁻¹)
     * K = Soil erodibility factor (tons ha h ha⁻¹ MJ⁻¹ mm⁻¹)
     * LS = Slope length and steepness factor (dimensionless)
     * C = Cover management factor (dimensionless)
     * P = Support practice factor (dimensionless)
     */
    private function buildRUSLEExpression(int $year, string $period): string
    {
        $startDate = $year . '-01-01';
        $endDate = $year . '-12-31';

        return "
            // ==================================================
            // 1. R-FACTOR (Rainfall Erosivity)
            // ==================================================
            // Using CHIRPS precipitation data
            var chirps = ee.ImageCollection('UCSB-CHG/CHIRPS/DAILY')
                .filterDate('{$startDate}', '{$endDate}')
                .select('precipitation');
            
            // Calculate annual precipitation
            var annualPrecip = chirps.sum();
            
            // Simplified R-factor calculation based on annual precipitation
            // R = 0.0483 * P^1.61 (where P is annual precipitation in mm)
            var R_factor = annualPrecip.pow(1.61).multiply(0.0483);
            
            // ==================================================
            // 2. K-FACTOR (Soil Erodibility)
            // ==================================================
            // Using SoilGrids250m soil data
            // K-factor typically ranges from 0.0 to 0.7
            // Higher values indicate more erodible soils
            var clay = ee.Image('projects/soilgrids-isric/clay_mean').divide(100.0);
            var silt = ee.Image('projects/soilgrids-isric/silt_mean').divide(100.0);
            var sand = ee.Image('projects/soilgrids-isric/sand_mean').divide(100.0);
            var orgC = ee.Image('projects/soilgrids-isric/ocs_mean').divide(10.0);
            
            // Simplified K-factor calculation
            // K = f(clay, silt, sand, organic matter)
            var M = silt.add(sand.multiply(0.1)).multiply(100);
            var K_factor = M.multiply(0.0001).multiply(12).subtract(0.02)
                .multiply(sand.multiply(0.02).add(0.03));
            K_factor = K_factor.where(K_factor.lt(0), 0.01);
            K_factor = K_factor.where(K_factor.gt(0.7), 0.7);
            
            // ==================================================
            // 3. LS-FACTOR (Slope Length and Steepness)
            // ==================================================
            // Using SRTM DEM
            var dem = ee.Image('USGS/SRTMGL1_003').select('elevation');
            var slope = ee.Terrain.slope(dem);
            
            // Calculate flow accumulation for slope length
            var flowAcc = dem.focal_max(90).subtract(dem);
            var slopeLength = flowAcc.multiply(30); // 30m resolution
            
            // L-factor (slope length factor)
            var m = slope.divide(100).add(1).multiply(0.5);
            var L_factor = slopeLength.divide(22.13).pow(m);
            
            // S-factor (slope steepness factor)
            var slopeRad = slope.multiply(Math.PI / 180);
            var S_factor = slope.lt(9)
                .where(slope.lt(9), 
                    slope.multiply(0.065).multiply(1.7).add(0.065))
                .where(slope.gte(9), 
                    slopeRad.sin().multiply(16.8).subtract(0.5));
            
            var LS_factor = L_factor.multiply(S_factor);
            
            // ==================================================
            // 4. C-FACTOR (Cover Management)
            // ==================================================
            // Using Sentinel-2 NDVI
            var s2 = ee.ImageCollection('COPERNICUS/S2_SR_HARMONIZED')
                .filterDate('{$startDate}', '{$endDate}')
                .filter(ee.Filter.lt('CLOUDY_PIXEL_PERCENTAGE', 20));
            
            // Calculate NDVI
            var ndvi = s2.map(function(img) {
                var nir = img.select('B8');
                var red = img.select('B4');
                return img.addBands(nir.subtract(red)
                    .divide(nir.add(red))
                    .rename('NDVI'));
            });
            
            var meanNDVI = ndvi.select('NDVI').mean();
            
            // C-factor from NDVI (inverse relationship)
            // C ranges from 0 (full cover) to 1 (bare soil)
            var C_factor = meanNDVI.multiply(-1).add(1).divide(2);
            C_factor = C_factor.where(C_factor.lt(0.001), 0.001);
            C_factor = C_factor.where(C_factor.gt(1), 1);
            
            // Calculate bare soil frequency
            var bareSoilThreshold = 0.2;
            var bareSoilCount = ndvi.select('NDVI')
                .map(function(img) {
                    return img.lt(bareSoilThreshold);
                }).sum();
            var totalImages = ndvi.select('NDVI').count();
            var bareSoilFrequency = bareSoilCount.divide(totalImages).multiply(100);
            
            // ==================================================
            // 5. P-FACTOR (Support Practice)
            // ==================================================
            // Using land cover data to estimate conservation practices
            var landCover = ee.Image('ESA/WorldCover/v100/2020').select('Map');
            
            // P-factor based on land use
            // Agricultural areas: 0.5 (some conservation)
            // Forest/vegetation: 0.1 (good protection)
            // Urban/bare: 1.0 (no conservation)
            var P_factor = landCover
                .where(landCover.eq(10), 0.1)  // Tree cover
                .where(landCover.eq(20), 0.2)  // Shrubland
                .where(landCover.eq(30), 0.3)  // Grassland
                .where(landCover.eq(40), 0.5)  // Cropland
                .where(landCover.eq(50), 1.0)  // Built-up
                .where(landCover.eq(60), 1.0)  // Bare/sparse
                .where(landCover.eq(70), 0.0)  // Snow/ice
                .where(landCover.eq(80), 0.0)  // Water
                .where(landCover.eq(90), 0.3)  // Herbaceous wetland
                .where(landCover.eq(95), 0.2)  // Mangroves
                .where(landCover.eq(100), 0.5); // Moss/lichen
            
            // ==================================================
            // FINAL RUSLE CALCULATION: A = R × K × LS × C × P
            // ==================================================
            var soilLoss = R_factor
                .multiply(K_factor)
                .multiply(LS_factor)
                .multiply(C_factor)
                .multiply(P_factor)
                .rename('soil_erosion_hazard');
            
            // Classify erosion risk
            // Very Low: < 2 t/ha/yr
            // Low: 2-5 t/ha/yr
            // Moderate: 5-10 t/ha/yr
            // High: 10-20 t/ha/yr
            // Very High: > 20 t/ha/yr
            var erosionRisk = soilLoss
                .where(soilLoss.lt(2), 1)
                .where(soilLoss.gte(2).and(soilLoss.lt(5)), 2)
                .where(soilLoss.gte(5).and(soilLoss.lt(10)), 3)
                .where(soilLoss.gte(10).and(soilLoss.lt(20)), 4)
                .where(soilLoss.gte(20), 5)
                .rename('erosion_risk');
            
            // Sustainability factor (inverse of erosion)
            // Higher values = more sustainable (lower erosion)
            var sustainability = soilLoss.multiply(-0.05).add(1)
                .clamp(0, 1)
                .rename('sustainability_factor');
            
            // Return multi-band image
            return ee.Image.cat([
                soilLoss,
                bareSoilFrequency.rename('bare_soil_frequency'),
                sustainability,
                erosionRisk,
                R_factor.rename('r_factor'),
                K_factor.rename('k_factor'),
                LS_factor.rename('ls_factor'),
                C_factor.rename('c_factor'),
                P_factor.rename('p_factor')
            ]);
        ";
    }

    /**
     * Convert geometry from Region/District model to GEE-compatible GeoJSON format.
     */
    private function convertGeometryToGeoJSON($area): array
    {
        // Get geometry from model
        $geometry = null;
        
        if (method_exists($area, 'getGeometryArray')) {
            $geometry = $area->getGeometryArray();
        } elseif (is_array($area->geometry)) {
            $geometry = $area->geometry;
        } elseif (is_string($area->geometry)) {
            $geometry = json_decode($area->geometry, true);
        }
        
        if (!$geometry || !isset($geometry['coordinates'])) {
            // Return default Tajikistan bounds if no geometry
            return [
                'type' => 'Polygon',
                'coordinates' => [[[68.0, 36.0], [75.0, 36.0], [75.0, 41.0], [68.0, 41.0], [68.0, 36.0]]],
            ];
        }
        
        // Calculate bounding box
        $bbox = $this->calculateBoundingBox($geometry);
        
        return [
            'type' => $geometry['type'] ?? 'Polygon',
            'coordinates' => $geometry['coordinates'],
            'geodesic' => false,
        ];
    }
    
    /**
     * Calculate bounding box from geometry coordinates
     * Supports both Polygon and MultiPolygon types
     */
    private function calculateBoundingBox(array $geometry): array
    {
        $allCoords = [];
        
        if ($geometry['type'] === 'Polygon') {
            $allCoords = $geometry['coordinates'][0] ?? [];
        } elseif ($geometry['type'] === 'MultiPolygon') {
            // Collect all coordinates from all polygons
            foreach ($geometry['coordinates'] as $polygon) {
                if (isset($polygon[0])) {
                    $allCoords = array_merge($allCoords, $polygon[0]);
                }
            }
        }
        
        if (empty($allCoords)) {
            return [68.0, 36.0, 75.0, 41.0]; // Default Tajikistan bounds
        }
        
        $minLon = $maxLon = (float)$allCoords[0][0];
        $minLat = $maxLat = (float)$allCoords[0][1];
        
        foreach ($allCoords as $coord) {
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
     * Generate cache key for the computation.
     */
    private function generateCacheKey(Region|District $area, int $year, string $period): string
    {
        return ErosionCache::generateCacheKey(get_class($area), $area->id, $year, $period);
    }

    /**
     * Cache the computation result.
     */
    private function cacheResult(Region|District $area, int $year, string $period, array $data): void
    {
        ErosionCache::create([
            'cacheable_type' => get_class($area),
            'cacheable_id' => $area->id,
            'year' => $year,
            'period' => $period,
            'cache_key' => $this->generateCacheKey($area, $year, $period),
            'data' => $data,
            'expires_at' => now()->addDays(30), // Cache for 30 days
        ]);
    }

    /**
     * Compute RUSLE statistics using real GEE API.
     */
    private function computeRUSLEStatistics(array $geometry, $area, int $year, string $period): array
    {
        try {
            if (!$this->accessToken) {
                $this->authenticate();
            }

            $expression = $this->buildRUSLEExpression($year, $period);

            $requestBody = [
                'expression' => $expression,
                'fileFormat' => 'JSON',
            ];

            $response = Http::withToken($this->accessToken)
                ->timeout(600)
                ->post("{$this->baseUrl}/projects/{$this->projectId}/image:computeStatistics", $requestBody);

            if (!$response->successful()) {
                $errorBody = $response->json() ?? [];
                throw GoogleEarthEngineException::apiRequestFailed(
                    $response->status(),
                    $errorBody['error']['message'] ?? $response->body(),
                    $errorBody,
                    new Exception($response->body())
                );
            }

            $result = $response->json();
            
            // Process the real GEE result
            return $this->processRUSLEResult($result, $area, $year);
            
        } catch (GoogleEarthEngineException $e) {
            Log::error('RUSLE statistics computation error', [
                'area' => $area->name_en ?? 'unknown',
                'year' => $year,
                'period' => $period,
                'error' => $e->getMessage(),
                'context' => $e->getContext(),
            ]);
            throw $e;
        } catch (Exception $e) {
            Log::error('RUSLE statistics computation error', [
                'area' => $area->name_en ?? 'unknown',
                'year' => $year,
                'period' => $period,
                'error' => $e->getMessage(),
            ]);
            throw GoogleEarthEngineException::apiRequestFailed(
                500,
                "Failed to compute RUSLE statistics: " . $e->getMessage(),
                [],
                $e
            );
        }
    }

    /**
     * Process GEE RUSLE result to extract comprehensive statistics.
     */
    private function processRUSLEResult(array $geeResult, Region|District|\stdClass $area, int $year): array
    {
        // Extract band statistics from GEE response - never use defaults, throw error if missing
        $props = $geeResult['properties'] ?? $geeResult;
        
        // Extract main erosion statistics - throw error if missing
        $meanErosion = $props['soil_erosion_hazard_mean'] ?? $props['mean'] ?? null;
        $minErosion = $props['soil_erosion_hazard_min'] ?? $props['min'] ?? null;
        $maxErosion = $props['soil_erosion_hazard_max'] ?? $props['max'] ?? null;
        $stdDev = $props['soil_erosion_hazard_stdDev'] ?? $props['stdDev'] ?? null;
        
        // Validate required statistics - throw error if missing
        if ($meanErosion === null) {
            throw GoogleEarthEngineException::noDataAvailable(
                $area->name_en ?? 'unknown',
                $year,
                ['message' => 'Mean erosion rate not available from GEE response']
            );
        }
        
        if ($minErosion === null || $maxErosion === null) {
            throw GoogleEarthEngineException::noDataAvailable(
                $area->name_en ?? 'unknown',
                $year,
                ['message' => 'Min/max erosion rates not available from GEE response']
            );
        }
        
        $cv = $stdDev ? ($stdDev / $meanErosion * 100) : null;
        
        // Extract RUSLE factors - throw error if missing
        $rFactor = $props['r_factor_mean'] ?? null;
        $kFactor = $props['k_factor_mean'] ?? null;
        $lsFactor = $props['ls_factor_mean'] ?? null;
        $cFactor = $props['c_factor_mean'] ?? null;
        $pFactor = $props['p_factor_mean'] ?? null;
        
        // Extract other metrics - allow null if not available
        $bareSoilFrequency = $props['bare_soil_frequency_mean'] ?? null;
        $sustainability = $props['sustainability_factor_mean'] ?? null;
        
        // Calculate severity distribution based on erosion thresholds
        $totalArea = $area->area_km2 * 100; // Convert to hectares
        $severityDistribution = $this->calculateSeverityDistribution($meanErosion, $totalArea, $geeResult);
        
        // Calculate top eroding sub-areas (would be from spatial analysis in production)
        $topErodingAreas = $this->extractTopErodingAreas($geeResult, $area);
        
            // Validate RUSLE factors - throw error if missing
            if ($rFactor === null || $kFactor === null || $lsFactor === null || 
                $cFactor === null || $pFactor === null) {
                throw GoogleEarthEngineException::noDataAvailable(
                    $area->name_en ?? 'unknown',
                    $year,
                    ['message' => 'RUSLE factors not available from GEE response']
                );
            }
            
            return [
                'tiles' => $geeResult['tiles'] ?? null,
                'statistics' => [
                    'mean_erosion_rate' => round($meanErosion, 2),
                    'min_erosion_rate' => round($minErosion, 2),
                    'max_erosion_rate' => round($maxErosion, 2),
                    'erosion_cv' => $cv ? round($cv, 1) : null,
                    'bare_soil_frequency' => $bareSoilFrequency ? round($bareSoilFrequency, 1) : null,
                    'sustainability_factor' => $sustainability ? round($sustainability, 2) : null,
                    'rainfall_slope' => null, // Would be calculated from time series
                    'rainfall_cv' => null, // Would be calculated from time series
                    'total_area' => $totalArea,
                    'severity_distribution' => $severityDistribution,
                    'rusle_factors' => [
                        'r' => round($rFactor, 2),
                        'k' => round($kFactor, 3),
                        'ls' => round($lsFactor, 2),
                        'c' => round($cFactor, 3),
                        'p' => round($pFactor, 3),
                    ],
                    'top_eroding_areas' => $topErodingAreas,
                ],
                'source' => 'gee_api',
                'timestamp' => now()->toIso8601String(),
                'raw_gee_data' => $geeResult,
            ];
    }

    /**
     * Calculate severity distribution from GEE erosion data.
     * Uses thresholds: 0-5, 5-15, 15-30, 30-50, >50 t/ha/yr
     */
    private function calculateSeverityDistribution(float $meanErosion, float $totalArea, array $geeResult): array
    {
        // If GEE provides histogram data, use it; otherwise estimate from statistics
        $histogram = $geeResult['histogram'] ?? null;
        
        if ($histogram) {
            // Use actual histogram from GEE - process histogram data directly
            // If histogram format is available, extract severity classes from it
            // For now, fall through to statistical estimation
            // TODO: Implement histogram processing when GEE provides histogram format
        }
        
        // Estimate distribution based on mean and standard deviation
        // This is an approximation - real data would come from histogram
        $stdDev = $geeResult['properties']['soil_erosion_hazard_stdDev'] ?? ($meanErosion * 0.5);
        
        // Assume normal distribution and calculate percentages in each class
        $distribution = [];
        $classes = [
            ['name' => 'Very Low', 'min' => 0, 'max' => 5],
            ['name' => 'Low', 'min' => 5, 'max' => 15],
            ['name' => 'Moderate', 'min' => 15, 'max' => 30],
            ['name' => 'Severe', 'min' => 30, 'max' => 50],
            ['class' => 'Excessive', 'min' => 50, 'max' => PHP_FLOAT_MAX],
        ];
        
        foreach ($classes as $class) {
            // Calculate z-scores for class boundaries
            $zMin = ($class['min'] - $meanErosion) / $stdDev;
            $zMax = ($class['max'] - $meanErosion) / $stdDev;
            
            // Approximate percentage using error function
            $pctMin = $this->normalCDF($zMin);
            $pctMax = $this->normalCDF($zMax);
            $percentage = max(0, min(100, ($pctMax - $pctMin) * 100));
            
            $distribution[] = [
                'class' => $class['name'] ?? $class['class'],
                'area' => round($totalArea * ($percentage / 100), 2),
                'percentage' => round($percentage, 1),
            ];
        }
        
        return $distribution;
    }

    /**
     * Cumulative distribution function for standard normal distribution.
     */
    private function normalCDF(float $z): float
    {
        return 0.5 * (1 + $this->erf($z / sqrt(2)));
    }

    /**
     * Error function approximation.
     */
    private function erf(float $x): float
    {
        // Abramowitz and Stegun approximation
        $sign = ($x >= 0) ? 1 : -1;
        $x = abs($x);
        
        $a1 =  0.254829592;
        $a2 = -0.284496736;
        $a3 =  1.421413741;
        $a4 = -1.453152027;
        $a5 =  1.061405429;
        $p  =  0.3275911;
        
        $t = 1.0 / (1.0 + $p * $x);
        $y = 1.0 - ((((($a5 * $t + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);
        
        return $sign * $y;
    }

    /**
     * Extract top eroding sub-areas from GEE result.
     */
    private function extractTopErodingAreas(array $geeResult, Region|District $area): array
    {
        // In production, this would analyze spatial patterns from GEE
        // For now, return empty array - would need additional GEE processing
        return [];
    }

    /**
     * Build rainfall slope/trend expression for temporal analysis.
     */
    private function buildRainfallSlopeExpression(int $startYear, int $endYear): string
    {
        return "
            // Rainfall trend analysis using linear regression
            var startDate = '{$startYear}-01-01';
            var endDate = '{$endYear}-12-31';
            
            var chirps = ee.ImageCollection('UCSB-CHG/CHIRPS/DAILY')
                .filterDate(startDate, endDate)
                .select('precipitation');
            
            // Calculate annual precipitation for each year
            var years = ee.List.sequence($startYear, $endYear);
            
            var annualPrecip = ee.ImageCollection.fromImages(
                years.map(function(year) {
                    var yearStr = ee.Number(year).format('%d');
                    var start = ee.Date.fromYMD(year, 1, 1);
                    var end = ee.Date.fromYMD(year, 12, 31);
                    
                    return chirps.filterDate(start, end)
                        .sum()
                        .set('year', year)
                        .set('system:time_start', start.millis());
                })
            );
            
            // Calculate trend using linear regression
            var trend = annualPrecip.reduce(ee.Reducer.linearFit());
            
            // Get slope (scale) - positive means increasing, negative means decreasing
            var slope = trend.select('scale');
            
            // Calculate percentage change per year
            var meanPrecip = annualPrecip.mean();
            var slopePercent = slope.divide(meanPrecip).multiply(100);
            
            return slopePercent.rename('rainfall_slope');
        ";
    }

    /**
     * Build rainfall CV (Coefficient of Variation) expression.
     */
    private function buildRainfallCVExpression(int $startYear, int $endYear): string
    {
        return "
            // Rainfall variability analysis using CV
            var startDate = '{$startYear}-01-01';
            var endDate = '{$endYear}-12-31';
            
            var chirps = ee.ImageCollection('UCSB-CHG/CHIRPS/DAILY')
                .filterDate(startDate, endDate)
                .select('precipitation');
            
            // Calculate annual precipitation for each year
            var years = ee.List.sequence($startYear, $endYear);
            
            var annualPrecip = ee.ImageCollection.fromImages(
                years.map(function(year) {
                    var start = ee.Date.fromYMD(year, 1, 1);
                    var end = ee.Date.fromYMD(year, 12, 31);
                    
                    return chirps.filterDate(start, end).sum();
                })
            );
            
            // Calculate mean and standard deviation
            var mean = annualPrecip.mean();
            var stdDev = annualPrecip.reduce(ee.Reducer.stdDev());
            
            // Coefficient of Variation (CV) = (stdDev / mean) * 100
            var cv = stdDev.divide(mean).multiply(100);
            
            return cv.rename('rainfall_cv');
        ";
    }

    /**
     * Build GEE expression to get available years from datasets
     */
    private function buildAvailableYearsExpression(array $geometry): string
    {
        return "
            // Get available years from multiple datasets
            var chirps = ee.ImageCollection('UCSB-CHG/CHIRPS/DAILY');
            var modis = ee.ImageCollection('MODIS/006/MOD13Q1');
            var sentinel = ee.ImageCollection('COPERNICUS/S2_SR');
            
            // Get years from CHIRPS (rainfall data)
            var chirpsYears = chirps.aggregate_array('system:time_start')
                .map(function(time) {
                    return ee.Date(time).get('year');
                })
                .distinct()
                .sort();
            
            // Get years from MODIS (vegetation data)
            var modisYears = modis.aggregate_array('system:time_start')
                .map(function(time) {
                    return ee.Date(time).get('year');
                })
                .distinct()
                .sort();
            
            // Get years from Sentinel (land cover data)
            var sentinelYears = sentinel.aggregate_array('system:time_start')
                .map(function(time) {
                    return ee.Date(time).get('year');
                })
                .distinct()
                .sort();
            
            // Find common years across all datasets
            var commonYears = chirpsYears.iterate(function(year, acc) {
                var hasInModis = modisYears.contains(year);
                var hasInSentinel = sentinelYears.contains(year);
                return ee.Algorithms.If(
                    hasInModis.and(hasInSentinel),
                    ee.List(acc).add(year),
                    acc
                );
            }, ee.List([]));
            
            // Filter years to reasonable range (1993-2025)
            var filteredYears = commonYears.filter(ee.Filter.rangeContains('item', 1993, 2025));
            
            // Get min and max years
            var minYear = filteredYears.reduce(ee.Reducer.min());
            var maxYear = filteredYears.reduce(ee.Reducer.max());
            var totalYears = filteredYears.size();
            
            // Return as a simple image with metadata
            var result = ee.Image.constant(1).set({
                'available_years': filteredYears,
                'min_year': minYear,
                'max_year': maxYear,
                'total_years': totalYears
            });
            
            return result;
        ";
    }

    /**
     * Process available years data from GEE response
     */
    private function processAvailableYears(array $geeData): array
    {
        try {
            // Extract years from GEE response
            $availableYears = $geeData['available_years'] ?? range(1993, date('Y'));
            $minYear = $geeData['min_year'] ?? 1993;
            $maxYear = $geeData['max_year'] ?? date('Y');
            $totalYears = $geeData['total_years'] ?? count($availableYears);
            
            // Ensure years are sorted
            sort($availableYears);
            
            return [
                'available_years' => $availableYears,
                'oldest_year' => (int) $minYear,
                'newest_year' => (int) $maxYear,
                'total_years' => (int) $totalYears,
                'source' => 'gee_api'
            ];
        } catch (Exception $e) {
            Log::warning('Failed to process available years from GEE', ['error' => $e->getMessage()]);
            
            // Return fallback years
            return [
                'available_years' => range(1993, date('Y')),
                'oldest_year' => 1993,
                'newest_year' => date('Y'),
                'total_years' => 9,
                'source' => 'fallback_range'
            ];
        }
    }
    
    /**
     * Simplify complex geometries to improve GEE performance
     */
    private function simplifyGeometry(array $geometry): array
    {
        // If it's a simple polygon with few points, return as-is
        $coordCount = $this->countCoordinates($geometry);
        
        if ($coordCount <= 50) {
            return $geometry;
        }
        
        // For complex polygons, simplify by reducing coordinate density
        if ($geometry['type'] === 'Polygon') {
            // Single polygon: simplify to 50 points
            $geometry['coordinates'][0] = $this->simplifyCoordinateArray($geometry['coordinates'][0], 50);
        } elseif ($geometry['type'] === 'MultiPolygon') {
            // MultiPolygon: simplify each polygon to fewer points
            // For large MultiPolygons, use fewer points per polygon to avoid GEE timeout
            $polygonCount = count($geometry['coordinates']);
            $maxPointsPerPolygon = $polygonCount > 10 ? 20 : 30; // Fewer points for large MultiPolygons
            
            foreach ($geometry['coordinates'] as &$polygon) {
                $polygon[0] = $this->simplifyCoordinateArray($polygon[0], $maxPointsPerPolygon);
            }
            
            Log::info("Simplified MultiPolygon: {$polygonCount} polygons, ~{$maxPointsPerPolygon} points each");
        }
        
        return $geometry;
    }
    
    /**
     * Simplify a coordinate array using Douglas-Peucker-like sampling
     */
    private function simplifyCoordinateArray(array $coords, int $targetCount): array
    {
        $count = count($coords);
        
        if ($count <= $targetCount) {
            return $coords;
        }
        
        // Use uniform sampling to reduce points
        $step = (int) ceil($count / $targetCount);
        $simplified = [];
        
        for ($i = 0; $i < $count; $i += $step) {
            $simplified[] = $coords[$i];
        }
        
        // Always include the last point to close the polygon
        if (end($simplified) !== end($coords)) {
            $simplified[] = end($coords);
        }
        
        return $simplified;
    }
    
    /**
     * Count total coordinates in a geometry
     */
    private function countCoordinates(array $geometry): int
    {
        if ($geometry['type'] === 'Polygon') {
            return isset($geometry['coordinates'][0]) ? count($geometry['coordinates'][0]) : 0;
        } elseif ($geometry['type'] === 'MultiPolygon') {
            $total = 0;
            foreach ($geometry['coordinates'] as $polygon) {
                $total += isset($polygon[0]) ? count($polygon[0]) : 0;
            }
            return $total;
        }
        
        return 0;
    }
}
