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
            $geometry = $this->convertGeometryToGeoJSON($area);

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
     */
    private function calculateBoundingBox(array $geometry): array
    {
        $coords = $geometry['coordinates'][0] ?? [];
        
        if (empty($coords)) {
            return [68.0, 36.0, 75.0, 41.0]; // Default Tajikistan bounds
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
