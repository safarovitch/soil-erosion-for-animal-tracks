<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Region;
use App\Models\District;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CalculateErosionFactors extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erosion:calculate 
                            {--region_id= : Region ID to calculate for}
                            {--district_id= : District ID to calculate for}
                            {--year= : Year to calculate (required)}
                            {--factors=all : Which factors to compute: all, r, k, ls, c, p, or comma-separated list like r,k,ls}
                            {--precompute : Also trigger precomputation (generate tiles)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate RUSLE factors (R, K, LS, C, P) and soil erosion for a specific region or district and year';

    /**
     * Python GEE service URL
     */
    private string $pythonServiceUrl;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->pythonServiceUrl = config('services.gee.url', 'http://localhost:5000');

        $this->info("========================================");
        $this->info(" RUSLE Factors Calculation");
        $this->info("========================================");
        $this->newLine();

        // Validate inputs
        $regionId = $this->option('region_id');
        $districtId = $this->option('district_id');
        $year = $this->option('year');
        $factors = $this->option('factors');
        $precompute = $this->option('precompute');

        // Validate that either region_id or district_id is provided
        if (!$regionId && !$districtId) {
            $this->error('Either --region_id or --district_id must be provided');
            return 1;
        }

        if ($regionId && $districtId) {
            $this->error('Provide either --region_id OR --district_id, not both');
            return 1;
        }

        // Validate year
        if (!$year) {
            $this->error('--year is required');
            return 1;
        }

        $year = (int) $year;
        $minYear = (int) config('earthengine.defaults.start_year', 1993);
        $maxYear = max($minYear, (int) config('earthengine.defaults.end_year', date('Y')));

        if ($year < $minYear || $year > $maxYear) {
            $this->error("Year must be between {$minYear} and {$maxYear}");
            return 1;
        }

        // Get the area
        $areaType = $regionId ? 'region' : 'district';
        $areaId = $regionId ?: $districtId;

        $area = $areaType === 'region' 
            ? Region::find($areaId)
            : District::find($areaId);

        if (!$area) {
            $this->error("{$areaType} with ID {$areaId} not found");
            return 1;
        }

        // Get geometry
        $geometry = is_array($area->geometry) 
            ? $area->geometry 
            : json_decode($area->geometry, true);

        if (!$geometry) {
            $this->error("No geometry found for {$areaType} {$areaId}");
            return 1;
        }

        // Display area information
        $this->info("Area Information:");
        $this->line("  Type: " . strtoupper($areaType));
        $this->line("  ID: {$areaId}");
        $this->line("  Name: " . ($area->name_en ?? $area->name_tj ?? 'N/A'));
        $this->line("  Year: {$year}");
        $this->line("  Factors: {$factors}");
        $this->newLine();

        // Prepare factors list
        $factorsList = $factors === 'all' 
            ? 'all' 
            : array_map('trim', explode(',', $factors));

        // Call Python service
        $this->info("Connecting to Python GEE service...");
        $this->line("  URL: {$this->pythonServiceUrl}");
        $this->newLine();

        // Test connection first
        try {
            $healthCheck = Http::timeout(5)->get("{$this->pythonServiceUrl}/api/health");
            if (!$healthCheck->successful()) {
                $this->warn("Service health check failed. Service may be overloaded.");
            }
        } catch (\Exception $e) {
            $this->warn("Could not check service health: " . $e->getMessage());
        }

        try {
            $this->info("Computing RUSLE factors...");
            $this->line("  This may take several minutes for large areas...");
            $this->newLine();

            $response = Http::timeout(1800)
                ->connectTimeout(10)  // Connection timeout
                ->post(
                    "{$this->pythonServiceUrl}/api/rusle/factors",
                    [
                        'area_geometry' => $geometry,
                        'year' => $year,
                        'factors' => $factorsList,
                        'scale' => 100  // 100m resolution for faster computation
                    ]
                );

            if (!$response->successful()) {
                $errorData = $response->json();
                $error = $errorData['error'] ?? $response->body() ?? 'Unknown error';
                $this->error("Failed to compute factors: {$error}");
                $this->newLine();
                $this->warn("This indicates the Python GEE service is not responding correctly.");
                $this->line("Check service status: sudo systemctl status python-gee-service");
                $this->line("Check service logs: sudo tail -f /var/log/python-gee-service-error.log");
                Log::error("Erosion calculation failed", [
                    'area_type' => $areaType,
                    'area_id' => $areaId,
                    'year' => $year,
                    'error' => $error,
                    'response_status' => $response->status(),
                    'response_body' => $response->body()
                ]);
                return 1;
            }

            $result = $response->json();
            
            if (!$result || !isset($result['success'])) {
                $this->error("Invalid response from Python service");
                $this->line("Response: " . $response->body());
                Log::error("Invalid Python service response", [
                    'response' => $response->body(),
                    'status' => $response->status()
                ]);
                return 1;
            }
            
            if (!$result['success']) {
                $error = $result['error'] ?? 'Unknown error from Python service';
                $this->error("Python service returned error: {$error}");
                Log::error("Python service error", [
                    'error' => $error,
                    'result' => $result
                ]);
                return 1;
            }
            
            $result = $result['data'] ?? null;
            
            if (!$result) {
                $this->error("No data returned from Python service");
                Log::error("No data in Python service response", [
                    'response' => $response->json()
                ]);
                return 1;
            }

            // Display results
            $this->displayResults($result, $year);

            // If precompute option is set, also trigger precomputation
            if ($precompute) {
                $this->newLine();
                $this->info("Triggering precomputation (tile generation)...");
                
                $precomputeResponse = Http::timeout(30)->post(
                    "{$this->pythonServiceUrl}/api/rusle/precompute",
                    [
                        'area_type' => $areaType,
                        'area_id' => $areaId,
                        'year' => $year,
                        'area_geometry' => $geometry,
                        'bbox' => $this->calculateBbox($geometry)
                    ]
                );

                if ($precomputeResponse->successful()) {
                    $taskId = $precomputeResponse->json('task_id');
                    $this->info("✓ Precomputation queued with task ID: {$taskId}");
                    $this->line("  Monitor progress with: php artisan tinker --execute=\"echo 'Task: {$taskId}';\"");
                } else {
                    $this->warn("Failed to queue precomputation: " . $precomputeResponse->body());
                }
            }

            $this->newLine();
            $this->info("✓ Calculation completed successfully!");

            return 0;

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->error("Connection error: Cannot connect to Python GEE service at {$this->pythonServiceUrl}");
            $this->newLine();
            $this->warn("Possible causes:");
            $this->line("  1. Python service is not running");
            $this->line("  2. Service is running on a different port");
            $this->line("  3. Firewall is blocking the connection");
            $this->line("  4. All workers are busy processing other requests");
            $this->newLine();
            $this->line("Troubleshooting:");
            $this->line("  Check service: sudo systemctl status python-gee-service");
            $this->line("  Test endpoint: curl http://localhost:5000/api/health");
            $this->line("  Check worker status: ps aux | grep gunicorn | wc -l");
            $this->line("  Check logs: sudo tail -f /var/log/python-gee-service-error.log");
            $this->newLine();
            $this->warn("If service is running but connection fails, workers may be busy.");
            $this->line("Try again in a few minutes or check for stuck workers.");
            Log::error("Python service connection failed", [
                'area_type' => $areaType,
                'area_id' => $areaId,
                'year' => $year,
                'url' => $this->pythonServiceUrl,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->newLine();
            $this->warn("This is a real error - no mock data will be returned.");
            $this->line("Please check the error details above and ensure:");
            $this->line("  1. Python GEE service is running");
            $this->line("  2. GEE credentials are configured correctly");
            $this->line("  3. The area geometry is valid");
            Log::error("Erosion calculation exception", [
                'area_type' => $areaType,
                'area_id' => $areaId,
                'year' => $year,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * Display calculation results
     */
    private function displayResults(array $result, int $year): void
    {
        $this->info("========================================");
        $this->info(" Calculation Results");
        $this->info("========================================");
        $this->newLine();

        // Display individual factors
        if (isset($result['factors']) && is_array($result['factors'])) {
            $this->info("RUSLE Factors:");
            $this->newLine();

            $factors = [
                'r' => 'R-Factor (Rainfall Erosivity)',
                'k' => 'K-Factor (Soil Erodibility)',
                'ls' => 'LS-Factor (Topographic)',
                'c' => 'C-Factor (Cover Management)',
                'p' => 'P-Factor (Support Practice)'
            ];

            foreach ($factors as $key => $name) {
                if (isset($result['factors'][$key])) {
                    $factor = $result['factors'][$key];
                    $this->line("  {$name}:");
                    $this->line("    Mean:   " . number_format($factor['mean'], 3) . " {$factor['unit']}");
                    $this->line("    Min:    " . number_format($factor['min'], 3) . " {$factor['unit']}");
                    $this->line("    Max:    " . number_format($factor['max'], 3) . " {$factor['unit']}");
                    $this->line("    StdDev: " . number_format($factor['std_dev'], 3) . " {$factor['unit']}");
                    $this->line("    Description: {$factor['description']}");
                    $this->newLine();
                }
            }
        }

        // Display soil erosion if computed
        if (isset($result['soil_erosion']) && is_array($result['soil_erosion'])) {
            $erosion = $result['soil_erosion'];
            $this->info("Soil Erosion (A = R × K × LS × C × P):");
            $this->newLine();
            $this->line("  Mean:   " . number_format($erosion['mean'] ?? 0, 2) . " t/ha/yr");
            $this->line("  Min:    " . number_format($erosion['min'] ?? 0, 2) . " t/ha/yr");
            $this->line("  Max:    " . number_format($erosion['max'] ?? 0, 2) . " t/ha/yr");
            $this->line("  StdDev: " . number_format($erosion['std_dev'] ?? 0, 2) . " t/ha/yr");
            $this->newLine();
        }

        // Display metadata
        $this->info("Metadata:");
        $this->line("  Year: {$year}");
        $this->line("  Scale: " . ($result['scale'] ?? 'N/A') . "m");
        $this->newLine();
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
}

