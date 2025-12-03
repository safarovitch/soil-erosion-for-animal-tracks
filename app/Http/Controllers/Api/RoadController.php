<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class RoadController extends Controller
{
    /**
     * Path to the roads GeoJSON file
     */
    protected string $roadsFilePath = 'public/TJ_planet_roadsurface_lines.geojson';

    /**
     * Get all roads with optional filtering
     */
    public function index(Request $request)
    {
        try {
            $roads = $this->loadRoads();
            
            // Apply filters
            $highwayType = $request->input('highway_type');
            $surfaceType = $request->input('surface_type');
            $rwClass = $request->input('rw_class');
            
            $features = $roads['features'];
            
            if ($highwayType && $highwayType !== 'all') {
                $features = array_filter($features, function ($feature) use ($highwayType) {
                    return ($feature['properties']['osm_tags_highway'] ?? '') === $highwayType;
                });
            }
            
            if ($surfaceType && $surfaceType !== 'all') {
                $features = array_filter($features, function ($feature) use ($surfaceType) {
                    $surface = $feature['properties']['osm_tags_surface'] ?? '';
                    $surfaceClass = $feature['properties']['OSM_surface_class'] ?? '';
                    return $surface === $surfaceType || $surfaceClass === $surfaceType;
                });
            }
            
            if ($rwClass && $rwClass !== 'all') {
                $features = array_filter($features, function ($feature) use ($rwClass) {
                    return ($feature['properties']['rw_class'] ?? '') == $rwClass;
                });
            }
            
            return response()->json([
                'type' => 'FeatureCollection',
                'features' => array_values($features),
                'metadata' => [
                    'total_count' => count($features),
                    'filters_applied' => [
                        'highway_type' => $highwayType,
                        'surface_type' => $surfaceType,
                        'rw_class' => $rwClass,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load roads data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available filter options
     */
    public function filters()
    {
        try {
            $roads = $this->loadRoads();
            
            $highwayTypes = [];
            $surfaceTypes = [];
            $rwClasses = [];
            
            foreach ($roads['features'] as $feature) {
                $props = $feature['properties'] ?? [];
                
                if (!empty($props['osm_tags_highway'])) {
                    $highwayTypes[$props['osm_tags_highway']] = true;
                }
                if (!empty($props['osm_tags_surface'])) {
                    $surfaceTypes[$props['osm_tags_surface']] = true;
                }
                if (!empty($props['OSM_surface_class'])) {
                    $surfaceTypes[$props['OSM_surface_class']] = true;
                }
                if (isset($props['rw_class'])) {
                    $rwClasses[(string)$props['rw_class']] = true;
                }
            }
            
            return response()->json([
                'highway_types' => array_keys($highwayTypes),
                'surface_types' => array_keys($surfaceTypes),
                'rw_classes' => array_keys($rwClasses),
                'buffer_distances' => [50, 100, 200, 500],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load filter options',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get buffered roads as polygon geometry for erosion calculation
     */
    public function buffered(Request $request)
    {
        try {
            $request->validate([
                'buffer_distance' => 'required|numeric|min:10|max:1000',
                'highway_type' => 'nullable|string',
                'surface_type' => 'nullable|string',
                'rw_class' => 'nullable|string',
            ]);
            
            $bufferDistance = $request->input('buffer_distance', 100);
            $highwayType = $request->input('highway_type');
            $surfaceType = $request->input('surface_type');
            $rwClass = $request->input('rw_class');
            
            $roads = $this->loadRoads();
            $features = $roads['features'];
            
            // Apply filters
            if ($highwayType && $highwayType !== 'all') {
                $features = array_filter($features, function ($feature) use ($highwayType) {
                    return ($feature['properties']['osm_tags_highway'] ?? '') === $highwayType;
                });
            }
            
            if ($surfaceType && $surfaceType !== 'all') {
                $features = array_filter($features, function ($feature) use ($surfaceType) {
                    $surface = $feature['properties']['osm_tags_surface'] ?? '';
                    $surfaceClass = $feature['properties']['OSM_surface_class'] ?? '';
                    return $surface === $surfaceType || $surfaceClass === $surfaceType;
                });
            }
            
            if ($rwClass && $rwClass !== 'all') {
                $features = array_filter($features, function ($feature) use ($rwClass) {
                    return ($feature['properties']['rw_class'] ?? '') == $rwClass;
                });
            }
            
            $features = array_values($features);
            
            if (empty($features)) {
                return response()->json([
                    'error' => 'No roads match the selected filters'
                ], 400);
            }
            
            // Limit the number of roads to prevent timeout
            $maxRoads = 500;
            if (count($features) > $maxRoads) {
                return response()->json([
                    'error' => 'Too many roads selected (' . count($features) . '). Please apply filters to select fewer than ' . $maxRoads . ' roads.',
                    'road_count' => count($features),
                    'max_allowed' => $maxRoads
                ], 400);
            }
            
            // Buffer the LineStrings to create polygons
            $bufferedFeatures = [];
            foreach ($features as $feature) {
                $buffered = $this->bufferLineString($feature, $bufferDistance);
                if ($buffered) {
                    $bufferedFeatures[] = $buffered;
                }
            }
            
            // Merge all buffered polygons into a single MultiPolygon
            $mergedGeometry = $this->mergePolygons($bufferedFeatures);
            
            return response()->json([
                'type' => 'Feature',
                'geometry' => $mergedGeometry,
                'properties' => [
                    'buffer_distance_m' => $bufferDistance,
                    'road_count' => count($features),
                    'filters' => [
                        'highway_type' => $highwayType,
                        'surface_type' => $surfaceType,
                        'rw_class' => $rwClass,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to buffer roads',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Load roads from GeoJSON file with caching
     */
    protected function loadRoads(): array
    {
        return Cache::remember('roads_geojson', 3600, function () {
            $path = storage_path('app/' . $this->roadsFilePath);
            
            if (!file_exists($path)) {
                throw new \Exception("Roads GeoJSON file not found at: {$path}");
            }
            
            $content = file_get_contents($path);
            $data = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Invalid JSON in roads file: " . json_last_error_msg());
            }
            
            return $data;
        });
    }

    /**
     * Buffer a LineString to create a Polygon
     * Uses a simple perpendicular offset approach
     */
    protected function bufferLineString(array $feature, float $bufferMeters): ?array
    {
        $geometry = $feature['geometry'] ?? null;
        if (!$geometry || $geometry['type'] !== 'LineString') {
            return null;
        }
        
        $coords = $geometry['coordinates'] ?? [];
        if (count($coords) < 2) {
            return null;
        }
        
        // Convert buffer from meters to approximate degrees
        // At ~38°N (Tajikistan), 1° lat ≈ 111km, 1° lon ≈ 87km
        $bufferLat = $bufferMeters / 111000;
        $bufferLon = $bufferMeters / 87000;
        
        // Create a simple buffer by offsetting the line on both sides
        $leftSide = [];
        $rightSide = [];
        
        for ($i = 0; $i < count($coords); $i++) {
            $lon = $coords[$i][0];
            $lat = $coords[$i][1];
            
            // Calculate perpendicular direction
            if ($i === 0) {
                $dx = $coords[1][0] - $coords[0][0];
                $dy = $coords[1][1] - $coords[0][1];
            } elseif ($i === count($coords) - 1) {
                $dx = $coords[$i][0] - $coords[$i - 1][0];
                $dy = $coords[$i][1] - $coords[$i - 1][1];
            } else {
                $dx = $coords[$i + 1][0] - $coords[$i - 1][0];
                $dy = $coords[$i + 1][1] - $coords[$i - 1][1];
            }
            
            // Normalize and get perpendicular
            $len = sqrt($dx * $dx + $dy * $dy);
            if ($len > 0) {
                $perpX = -$dy / $len;
                $perpY = $dx / $len;
            } else {
                $perpX = 0;
                $perpY = 1;
            }
            
            // Offset points
            $leftSide[] = [
                $lon + $perpX * $bufferLon,
                $lat + $perpY * $bufferLat
            ];
            $rightSide[] = [
                $lon - $perpX * $bufferLon,
                $lat - $perpY * $bufferLat
            ];
        }
        
        // Create polygon by combining left side, reversed right side, and closing
        $polygonCoords = array_merge(
            $leftSide,
            array_reverse($rightSide),
            [$leftSide[0]] // Close the polygon
        );
        
        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [$polygonCoords]
            ],
            'properties' => $feature['properties'] ?? []
        ];
    }

    /**
     * Merge multiple polygons into a MultiPolygon
     */
    protected function mergePolygons(array $features): array
    {
        $polygons = [];
        
        foreach ($features as $feature) {
            $geom = $feature['geometry'] ?? null;
            if (!$geom) continue;
            
            if ($geom['type'] === 'Polygon') {
                $polygons[] = $geom['coordinates'];
            } elseif ($geom['type'] === 'MultiPolygon') {
                foreach ($geom['coordinates'] as $poly) {
                    $polygons[] = $poly;
                }
            }
        }
        
        if (count($polygons) === 1) {
            return [
                'type' => 'Polygon',
                'coordinates' => $polygons[0]
            ];
        }
        
        return [
            'type' => 'MultiPolygon',
            'coordinates' => $polygons
        ];
    }
}

