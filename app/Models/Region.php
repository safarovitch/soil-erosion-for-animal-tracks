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

}
