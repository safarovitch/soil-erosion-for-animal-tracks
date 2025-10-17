<?php

namespace Database\Seeders;

use App\Models\Region;
use App\Models\District;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TajikistanBoundariesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * NOTE: This seeder creates sample boundary data for testing.
     * For production use, replace the sample geometries with actual
     * GeoJSON data from official Tajikistan administrative boundaries.
     *
     * To load real boundary data:
     * 1. Obtain GeoJSON files for Tajikistan regions and districts
     * 2. Convert the GeoJSON to PostGIS geometry format
     * 3. Replace the sample data below with real coordinates
     */
    public function run(): void
    {
        // Tajikistan regions (viloyat level)
        $regions = [
            [
                'name_en' => 'Dushanbe',
                'name_tj' => 'Душанбе',
                'code' => 'TJ-DU',
                'area_km2' => 126.6,
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[68.5, 38.5], [68.7, 38.5], [68.7, 38.7], [68.5, 38.7], [68.5, 38.5]]]
                ]
            ],
            [
                'name_en' => 'Sughd',
                'name_tj' => 'Суғд',
                'code' => 'TJ-SU',
                'area_km2' => 25100,
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[68.0, 39.0], [70.0, 39.0], [70.0, 40.5], [68.0, 40.5], [68.0, 39.0]]]
                ]
            ],
            [
                'name_en' => 'Khatlon',
                'name_tj' => 'Хатлон',
                'code' => 'TJ-KT',
                'area_km2' => 24600,
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[68.0, 37.0], [70.0, 37.0], [70.0, 39.0], [68.0, 39.0], [68.0, 37.0]]]
                ]
            ],
            [
                'name_en' => 'Gorno-Badakhshan',
                'name_tj' => 'Вилояти Мухтори Кӯҳистони Бадахшон',
                'code' => 'TJ-GB',
                'area_km2' => 64400,
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[71.0, 37.0], [75.0, 37.0], [75.0, 40.0], [71.0, 40.0], [71.0, 37.0]]]
                ]
            ],
            [
                'name_en' => 'Districts of Republican Subordination',
                'name_tj' => 'Ноҳияҳои тобеи ҷумҳурӣ',
                'code' => 'TJ-RA',
                'area_km2' => 28600,
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[68.0, 38.0], [71.0, 38.0], [71.0, 39.0], [68.0, 39.0], [68.0, 38.0]]]
                ]
            ],
        ];

        foreach ($regions as $regionData) {
            // Store geometry as JSON string for SQLite compatibility
            $regionData['geometry'] = json_encode($regionData['geometry']);
            Region::create($regionData);
        }

        // Sample districts (nohiya level) for Sughd region
        $sughdRegion = Region::where('code', 'TJ-SU')->first();
        if ($sughdRegion) {
            $sughdDistricts = [
                [
                    'region_id' => $sughdRegion->id,
                    'name_en' => 'Khujand',
                    'name_tj' => 'Хуҷанд',
                    'code' => 'TJ-SU-KH',
                    'area_km2' => 2600,
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[69.5, 40.0], [69.7, 40.0], [69.7, 40.2], [69.5, 40.2], [69.5, 40.0]]]
                    ]
                ],
                [
                    'region_id' => $sughdRegion->id,
                    'name_en' => 'Isfara',
                    'name_tj' => 'Исфара',
                    'code' => 'TJ-SU-IS',
                    'area_km2' => 832,
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[70.5, 39.8], [70.7, 39.8], [70.7, 40.0], [70.5, 40.0], [70.5, 39.8]]]
                    ]
                ],
                [
                    'region_id' => $sughdRegion->id,
                    'name_en' => 'Istaravshan',
                    'name_tj' => 'Истаравшан',
                    'code' => 'TJ-SU-IT',
                    'area_km2' => 1830,
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[69.0, 39.5], [69.2, 39.5], [69.2, 39.7], [69.0, 39.7], [69.0, 39.5]]]
                    ]
                ],
            ];

            foreach ($sughdDistricts as $districtData) {
                // Store geometry as JSON string for SQLite compatibility
                $districtData['geometry'] = json_encode($districtData['geometry']);
                District::create($districtData);
            }
        }

        // Sample districts for Khatlon region
        $khatlonRegion = Region::where('code', 'TJ-KT')->first();
        if ($khatlonRegion) {
            $khatlonDistricts = [
                [
                    'region_id' => $khatlonRegion->id,
                    'name_en' => 'Bokhtar',
                    'name_tj' => 'Бохтар',
                    'code' => 'TJ-KT-BK',
                    'area_km2' => 2600,
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[68.5, 37.5], [68.7, 37.5], [68.7, 37.7], [68.5, 37.7], [68.5, 37.5]]]
                    ]
                ],
                [
                    'region_id' => $khatlonRegion->id,
                    'name_en' => 'Kulob',
                    'name_tj' => 'Кӯлоб',
                    'code' => 'TJ-KT-KL',
                    'area_km2' => 1200,
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[69.5, 37.8], [69.7, 37.8], [69.7, 38.0], [69.5, 38.0], [69.5, 37.8]]]
                    ]
                ],
                [
                    'region_id' => $khatlonRegion->id,
                    'name_en' => 'Vose',
                    'name_tj' => 'Восеъ',
                    'code' => 'TJ-KT-VS',
                    'area_km2' => 900,
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[69.0, 37.0], [69.2, 37.0], [69.2, 37.2], [69.0, 37.2], [69.0, 37.0]]]
                    ]
                ],
            ];

            foreach ($khatlonDistricts as $districtData) {
                // Store geometry as JSON string for SQLite compatibility
                $districtData['geometry'] = json_encode($districtData['geometry']);
                District::create($districtData);
            }
        }

        // Sample districts for Gorno-Badakhshan
        $gbRegion = Region::where('code', 'TJ-GB')->first();
        if ($gbRegion) {
            $gbDistricts = [
                [
                    'region_id' => $gbRegion->id,
                    'name_en' => 'Khorog',
                    'name_tj' => 'Хоруғ',
                    'code' => 'TJ-GB-KH',
                    'area_km2' => 8700,
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[71.5, 37.5], [71.7, 37.5], [71.7, 37.7], [71.5, 37.7], [71.5, 37.5]]]
                    ]
                ],
                [
                    'region_id' => $gbRegion->id,
                    'name_en' => 'Murghob',
                    'name_tj' => 'Мурғоб',
                    'code' => 'TJ-GB-MU',
                    'area_km2' => 38500,
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[72.0, 38.0], [72.2, 38.0], [72.2, 38.2], [72.0, 38.2], [72.0, 38.0]]]
                    ]
                ],
            ];

            foreach ($gbDistricts as $districtData) {
                // Store geometry as JSON string for SQLite compatibility
                $districtData['geometry'] = json_encode($districtData['geometry']);
                District::create($districtData);
            }
        }
    }

    /**
     * Convert GeoJSON geometry to PostGIS WKT format.
     */
    private function convertGeoJsonToPostGis(array $geoJson): string
    {
        $type = $geoJson['type'];
        $coordinates = $geoJson['coordinates'];

        switch ($type) {
            case 'Polygon':
                $wkt = 'POLYGON((';
                foreach ($coordinates[0] as $index => $coord) {
                    if ($index > 0) $wkt .= ', ';
                    $wkt .= $coord[0] . ' ' . $coord[1];
                }
                $wkt .= '))';
                return $wkt;

            case 'MultiPolygon':
                $wkt = 'MULTIPOLYGON(';
                foreach ($coordinates as $polygonIndex => $polygon) {
                    if ($polygonIndex > 0) $wkt .= ', ';
                    $wkt .= '((';
                    foreach ($polygon[0] as $coordIndex => $coord) {
                        if ($coordIndex > 0) $wkt .= ', ';
                        $wkt .= $coord[0] . ' ' . $coord[1];
                    }
                    $wkt .= '))';
                }
                $wkt .= ')';
                return $wkt;

            default:
                throw new \InvalidArgumentException("Unsupported geometry type: {$type}");
        }
    }
}
