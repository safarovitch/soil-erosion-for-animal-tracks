<?php

namespace App\Services;

use App\Models\ErosionCache;
use App\Models\Region;
use App\Models\District;
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
                Log::warning('GEE private key file not found, skipping authentication', [
                    'expected_path' => $privateKeyFilePath,
                    'message' => 'Please configure GEE service account key file or disable GEE integration'
                ]);
                return false;
            }

            // Load the private key
            $privateKey = file_get_contents($privateKeyFilePath);

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
            openssl_sign($base64Header . '.' . $base64Payload, $signature, $privateKey, 'SHA256');
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

            Log::error('GEE Authentication failed', ['response' => $response->body()]);
            return false;
        } catch (Exception $e) {
            Log::error('GEE Authentication error', ['error' => $e->getMessage()]);
            return false;
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

        // Authenticate if needed
        if (!$this->accessToken && !$this->authenticate()) {
            throw new Exception('Failed to authenticate with Google Earth Engine');
        }

        try {
            // Convert geometry to GeoJSON
            $geometry = $this->convertGeometryToGeoJSON($area->geometry);

            // Build the RUSLE computation request
            $requestBody = [
                'expression' => $this->buildRUSLEExpression($year, $period),
                'fileFormat' => 'GEO_TIFF',
                'bandIds' => ['soil_erosion_hazard', 'bare_soil_frequency', 'sustainability_factor'],
                'grid' => [
                    'dimensions' => ['width' => 1000, 'height' => 1000],
                    'affineTransform' => [
                        'scaleX' => 0.001, // ~1km resolution
                        'shearY' => 0,
                        'shearX' => 0,
                        'scaleY' => -0.001,
                        'translateX' => $geometry['bbox'][0],
                        'translateY' => $geometry['bbox'][3],
                    ],
                ],
                'region' => $geometry,
            ];

            // Submit the computation job
            $response = Http::withToken($this->accessToken)
                ->post("{$this->baseUrl}/projects/{$this->projectId}/image:computePixels", $requestBody);

            if (!$response->successful()) {
                throw new Exception('GEE computation failed: ' . $response->body());
            }

            $result = $response->json();

            // Cache the result
            $this->cacheResult($area, $year, $period, $result);

            return $result;
        } catch (Exception $e) {
            Log::error('GEE computation error', [
                'area' => $area->name_en,
                'year' => $year,
                'error' => $e->getMessage(),
            ]);
            throw $e;
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
        // Authenticate if needed
        if (!$this->accessToken && !$this->authenticate()) {
            throw new Exception('Failed to authenticate with Google Earth Engine');
        }

        try {
            $requestBody = [
                'expression' => $this->buildRUSLEExpression($year, 'annual'),
                'fileFormat' => 'GEO_TIFF',
                'region' => $geometry,
            ];

            $response = Http::withToken($this->accessToken)
                ->post("{$this->baseUrl}/projects/{$this->projectId}/image:computePixels", $requestBody);

            if (!$response->successful()) {
                throw new Exception('GEE geometry analysis failed: ' . $response->body());
            }

            return $response->json();
        } catch (Exception $e) {
            Log::error('GEE geometry analysis error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Build the RUSLE computation expression.
     * This translates the JavaScript RUSLE factors to GEE REST API format.
     */
    private function buildRUSLEExpression(int $year, string $period): string
    {
        // This is a simplified version - in practice, you'd need to translate
        // the complex RUSLE_factors.js logic to GEE REST API format

        $startDate = $year . '-01-01';
        $endDate = $year . '-12-31';

        return "
            var s2 = ee.ImageCollection('COPERNICUS/S2_SR')
                .filterDate('{$startDate}', '{$endDate}')
                .filter(ee.Filter.lt('CLOUDY_PIXEL_PERCENTAGE', 20));

            var dem = ee.Image('USGS/SRTMGL1_003');
            var slope = ee.Terrain.slope(dem);

            // Simplified RUSLE calculation
            // In practice, you'd implement the full RUSLE_factors.js logic here
            var R = ee.Image(100); // Rainfall erosivity factor
            var K = ee.Image(0.05); // Soil erodibility factor
            var LS = slope.multiply(0.1); // Slope length and steepness factor
            var C = ee.Image(0.1); // Cover management factor
            var P = ee.Image(1); // Support practice factor

            var A = R.multiply(K).multiply(LS).multiply(C).multiply(P)
                .rename('soil_erosion_hazard');

            var bs_freq = s2.select('B4').count().divide(s2.size()).rename('bare_soil_frequency');
            var sustainability = A.multiply(0.1).rename('sustainability_factor');

            return ee.Image([A, bs_freq, sustainability]);
        ";
    }

    /**
     * Convert PostGIS geometry to GeoJSON format.
     */
    private function convertGeometryToGeoJSON(array $geometry): array
    {
        // This is a simplified conversion - in practice, you'd use a proper
        // geometry conversion library or PostGIS functions
        return [
            'type' => 'Polygon',
            'coordinates' => $geometry['coordinates'] ?? [],
            'bbox' => $geometry['bbox'] ?? [68.0, 36.0, 75.0, 41.0], // Tajikistan bounds
        ];
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
}
