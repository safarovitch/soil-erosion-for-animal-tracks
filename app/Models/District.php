<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class District extends Model
{
    protected $fillable = [
        'region_id',
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
     * Get the region that owns the district.
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * Get the cached erosion data for the district.
     */
    public function erosionCache(): MorphMany
    {
        return $this->morphMany(ErosionCache::class, 'cacheable');
    }

    /**
     * Get the time series data for the district.
     */
    public function timeSeriesData(): MorphMany
    {
        return $this->morphMany(TimeSeriesData::class, 'area');
    }

    /**
     * Get the user queries for the district.
     */
    public function userQueries(): MorphMany
    {
        return $this->morphMany(UserQuery::class, 'queryable');
    }

    /**
     * Get the district by code.
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }

    /**
     * Get the district by English name.
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
     * Calculate bounding box for the district geometry
     */
    public function getBoundingBox(): ?array
    {
        $geometry = $this->getGeometryArray();
        
        if (!$geometry || !isset($geometry['coordinates'][0])) {
            return null;
        }
        
        $coords = $geometry['coordinates'][0];
        
        $minLon = $maxLon = $coords[0][0];
        $minLat = $maxLat = $coords[0][1];
        
        foreach ($coords as $coord) {
            $lon = $coord[0];
            $lat = $coord[1];
            
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
     * Get center point of the district
     */
    public function getCenterPoint(): ?array
    {
        $bbox = $this->getBoundingBox();
        
        if (!$bbox) {
            return null;
        }
        
        return [
            'lon' => ($bbox['west'] + $bbox['east']) / 2,
            'lat' => ($bbox['south'] + $bbox['north']) / 2,
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
                'region_id' => $this->region_id,
            ],
            'geometry' => $this->getGeometryArray(),
        ];
    }

}
