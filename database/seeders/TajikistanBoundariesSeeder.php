<?php

namespace Database\Seeders;

use App\Models\Region;
use App\Models\District;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TajikistanBoundariesSeeder extends Seeder
{
    /**
     * District to Region mapping based on Tajikistan administrative structure
     */
    private array $districtRegionMapping = [
        // Sughd Region
        'Asht District' => 'TJ-SU',
        'Ayni District' => 'TJ-SU',
        'Ghafurov District' => 'TJ-SU',
        'Ghonchi District' => 'TJ-SU',
        'Isfara District' => 'TJ-SU',
        'Istaravshan District' => 'TJ-SU',
        'Jabbor Rasulov District' => 'TJ-SU',
        'Konibodom District' => 'TJ-SU',
        'Kuhistoni Mastchoh District' => 'TJ-SU',
        'Mastchoh District' => 'TJ-SU',
        'Panjakent District' => 'TJ-SU',
        'Spitamen District' => 'TJ-SU',
        'Shahriston District' => 'TJ-SU',
        'Zafarobod District' => 'TJ-SU',
        
        // Khatlon Region
        'Baljuvon District' => 'TJ-KT',
        'Bokhtar District' => 'TJ-KT',
        'Danghara District' => 'TJ-KT',
        'Dzhami District' => 'TJ-KT',
        'Farkhor District' => 'TJ-KT',
        'Hamadoni District' => 'TJ-KT',
        'Jilikul District' => 'TJ-KT',
        'Khovaling District' => 'TJ-KT',
        'Khuroson District' => 'TJ-KT',
        'Kulob District' => 'TJ-KT',
        'Muminobod District' => 'TJ-KT',
        'Norak District' => 'TJ-KT',
        'Panj District' => 'TJ-KT',
        'Qabodiyon District' => 'TJ-KT',
        'Qumsangir District' => 'TJ-KT',
        'Rumi District' => 'TJ-KT',
        'Shahrtuz District' => 'TJ-KT',
        'Shuro-obod District' => 'TJ-KT',
        'Temurmalik District' => 'TJ-KT',
        'Vakhsh District' => 'TJ-KT',
        "Vose' District" => 'TJ-KT',
        'Yovon District' => 'TJ-KT',
        'Nosiri Khusrav District' => 'TJ-KT',
        
        // Gorno-Badakhshan Autonomous Region (GBAO)
        'Darvoz District' => 'TJ-GB',
        'Ishkoshim District' => 'TJ-GB',
        'Murghob District' => 'TJ-GB',
        "Roshtqal'a District" => 'TJ-GB',
        'Rushon District' => 'TJ-GB',
        'Shughnon District' => 'TJ-GB',
        'Vanj District' => 'TJ-GB',
        
        // Districts of Republican Subordination (DRS)
        'Faizobod District' => 'TJ-RA',
        'Hisor District' => 'TJ-RA',
        'Jirgatol District' => 'TJ-RA',
        'Nurobod District' => 'TJ-RA',
        'Rasht District' => 'TJ-RA',
        'Roghun District' => 'TJ-RA',
        'Rudaki District' => 'TJ-RA',
        'Sarband District' => 'TJ-KT',
        'Sharinav District' => 'TJ-RA',
        'Tavildara District' => 'TJ-RA',
        'Tojikobod District' => 'TJ-RA',
        'Tursunzoda District' => 'TJ-RA',
        'Vahdat District' => 'TJ-RA',
        'Varzob District' => 'TJ-RA',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Log::info('Starting Tajikistan boundaries seeding...');
        
        // Clear existing data
        DB::table('districts')->delete();
        DB::table('regions')->delete();
        
        // Create regions first
        $this->createRegions();
        
        // Load districts from GeoJSON
        $this->loadDistrictsFromGeoJSON();
        
        Log::info('Tajikistan boundaries seeding completed');
    }
    
    /**
     * Create Tajikistan regions
     */
    private function createRegions(): void
    {
        $regions = [
            [
                'name_en' => 'Dushanbe',
                'name_tj' => 'Душанбе',
                'code' => 'TJ-DU',
                'area_km2' => 126.6,
                'geometry' => null, // Will be populated from district aggregation
            ],
            [
                'name_en' => 'Sughd',
                'name_tj' => 'Суғд',
                'code' => 'TJ-SU',
                'area_km2' => 25100,
                'geometry' => null,
            ],
            [
                'name_en' => 'Khatlon',
                'name_tj' => 'Хатлон',
                'code' => 'TJ-KT',
                'area_km2' => 24600,
                'geometry' => null,
            ],
            [
                'name_en' => 'Gorno-Badakhshan',
                'name_tj' => 'Вилояти Мухтори Кӯҳистони Бадахшон',
                'code' => 'TJ-GB',
                'area_km2' => 64400,
                'geometry' => null,
            ],
            [
                'name_en' => 'Districts of Republican Subordination',
                'name_tj' => 'Ноҳияҳои тобеи Марказ',
                'code' => 'TJ-RA',
                'area_km2' => 28600,
                'geometry' => null,
            ],
        ];

        foreach ($regions as $regionData) {
            $regionData['geometry'] = $regionData['geometry'] ? json_encode($regionData['geometry']) : null;
            Region::create($regionData);
            Log::info("Created region: {$regionData['name_en']}");
        }
    }
    
    /**
     * Load districts from GeoJSON file
     */
    private function loadDistrictsFromGeoJSON(): void
    {
        $geoJsonPath = storage_path('/storage/geoBoundaries-TJK-ADM2.geojson');
        
        if (!file_exists($geoJsonPath)) {
            Log::error("GeoJSON file not found at: {$geoJsonPath}");
            return;
        }
        
        $geoJsonContent = file_get_contents($geoJsonPath);
        $geoData = json_decode($geoJsonContent, true);
        
        if (!$geoData || !isset($geoData['features'])) {
            Log::error("Invalid GeoJSON format");
            return;
        }
        
        $districtCount = 0;
        $unmappedDistricts = [];
        
        foreach ($geoData['features'] as $feature) {
            $properties = $feature['properties'] ?? [];
            $geometry = $feature['geometry'] ?? null;
            
            $districtName = $properties['shapeName'] ?? 'Unknown';
            $shapeID = $properties['shapeID'] ?? '';
            
            // Find the region for this district
            $regionCode = $this->districtRegionMapping[$districtName] ?? null;
            
            if (!$regionCode) {
                $unmappedDistricts[] = $districtName;
                Log::warning("District not mapped to region: {$districtName}");
                // Try to continue with a default region or skip
                continue;
            }
            
            $region = Region::where('code', $regionCode)->first();
            
            if (!$region) {
                Log::error("Region not found for code: {$regionCode}");
                continue;
            }
            
            // Calculate area from geometry (approximate)
            $areaKm2 = $this->calculateAreaFromGeometry($geometry);
            
            // Generate a code for the district
            $districtCode = $this->generateDistrictCode($regionCode, $districtName, $shapeID);
            
            // Create district
            District::create([
                'region_id' => $region->id,
                'name_en' => $districtName,
                'name_tj' => $districtName, // Use same for now, can be updated later
                'code' => $districtCode,
                'geometry' => json_encode($geometry),
                'area_km2' => $areaKm2,
            ]);
            
            $districtCount++;
            Log::info("Created district: {$districtName} in region: {$region->name_en}");
        }
        
        Log::info("Loaded {$districtCount} districts from GeoJSON");
        
        if (!empty($unmappedDistricts)) {
            Log::warning("Unmapped districts: " . implode(', ', $unmappedDistricts));
        }
    }
    
    /**
     * Calculate approximate area from GeoJSON geometry in km²
     */
    private function calculateAreaFromGeometry(?array $geometry): float
    {
        if (!$geometry || !isset($geometry['coordinates'])) {
            return 0.0;
        }
        
        // Simple bounding box approximation
        // For more accurate calculation, would need proper geodesic area calculation
        $coords = $geometry['coordinates'][0] ?? [];
        
        if (empty($coords) || !is_array($coords[0]) || count($coords[0]) < 2) {
            return 0.0;
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
        
        // Approximate conversion: 1 degree ≈ 111 km at equator
        // This is a rough approximation, adjust for latitude
        $latFactor = cos(deg2rad(($minLat + $maxLat) / 2.0));
        $width = ($maxLon - $minLon) * 111.0 * $latFactor;
        $height = ($maxLat - $minLat) * 111.0;
        
        return round($width * $height, 2);
    }
    
    /**
     * Generate a unique district code
     */
    private function generateDistrictCode(string $regionCode, string $districtName, string $shapeID): string
    {
        // Try to create a meaningful code that fits in VARCHAR(10)
        // Format: TJ-XX-NNN where XX is region suffix and NNN is 3 char name/id
        
        // Get last 2 chars of region code (e.g., "SU" from "TJ-SU")
        $regionSuffix = substr($regionCode, -2);
        
        // Get first 3 letters of district name
        $namePart = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $districtName), 0, 3));
        
        // Format: TJ-SU-XYZ (10 chars max)
        return "TJ-{$regionSuffix}-{$namePart}";
    }
}
