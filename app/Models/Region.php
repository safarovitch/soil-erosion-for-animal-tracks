<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Region extends Model
{
    protected $fillable = [
        'name_en',
        'name_tj',
        'code',
        'geometry',
        'area_km2',
    ];

    protected $casts = [
        'geometry' => 'array', // For JSON geometry data
        'area_km2' => 'decimal:2',
    ];

    /**
     * Get the districts for the region.
     */
    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }

    /**
     * Get the cached erosion data for the region.
     */
    public function erosionCache(): MorphMany
    {
        return $this->morphMany(ErosionCache::class, 'cacheable');
    }

    /**
     * Get the time series data for the region.
     */
    public function timeSeriesData(): MorphMany
    {
        return $this->morphMany(TimeSeriesData::class, 'area');
    }

    /**
     * Get the user queries for the region.
     */
    public function userQueries(): MorphMany
    {
        return $this->morphMany(UserQuery::class, 'queryable');
    }

    /**
     * Get the region by code.
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }

    /**
     * Get the region by English name.
     */
    public static function findByName(string $name): ?self
    {
        return static::where('name_en', $name)->first();
    }
    
    /**
     * Get geometry as GeoJSON array (decoded from JSON string)
     */
    public function getGeometryArray(): ?array
    {
        if (!$this->geometry) {
            return null;
        }
        
        return is_array($this->geometry) ? $this->geometry : json_decode($this->geometry, true);
    }
    
    /**
     * Calculate bounding box for the region geometry
     */
    public function getBoundingBox(): ?array
    {
        $geometry = $this->getGeometryArray();
        
        if (!$geometry || !isset($geometry['coordinates'])) {
            return null;
        }
        
        // Handle different geometry types
        $coords = [];
        if ($geometry['type'] === 'Polygon') {
            $coords = $geometry['coordinates'][0] ?? [];
        } elseif ($geometry['type'] === 'MultiPolygon') {
            // Flatten all polygons
            foreach ($geometry['coordinates'] as $polygon) {
                if (isset($polygon[0])) {
                    $coords = array_merge($coords, $polygon[0]);
                }
            }
        } else {
            return null;
        }
        
        if (empty($coords) || !isset($coords[0][0], $coords[0][1])) {
            return null;
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
        
        return [
            'west' => $minLon,
            'south' => $minLat,
            'east' => $maxLon,
            'north' => $maxLat,
        ];
    }
    
    /**
     * Get center point of the region [lon, lat]
     */
    public function getCenterPoint(): ?array
    {
        $bbox = $this->getBoundingBox();
        
        if (!$bbox || !is_array($bbox)) {
            return null;
        }
        
        // Ensure all bbox values are present and numeric
        if (!isset($bbox['west'], $bbox['east'], $bbox['south'], $bbox['north'])) {
            return null;
        }
        
        // Ensure they're numeric (not arrays)
        if (!is_numeric($bbox['west']) || !is_numeric($bbox['east']) || 
            !is_numeric($bbox['south']) || !is_numeric($bbox['north'])) {
            return null;
        }
        
        // Return [lon, lat] array
        return [
            ((float)$bbox['west'] + (float)$bbox['east']) / 2,
            ((float)$bbox['south'] + (float)$bbox['north']) / 2,
        ];
    }
    
    /**
     * Export geometry as GeoJSON Feature
     */
    public function toGeoJSONFeature(): array
    {
        return [
            'type' => 'Feature',
            'properties' => [
                'id' => $this->id,
                'name' => $this->name_en,
                'name_tj' => $this->name_tj,
                'code' => $this->code,
                'area_km2' => $this->area_km2,
            ],
            'geometry' => $this->getGeometryArray(),
        ];
    }

}
