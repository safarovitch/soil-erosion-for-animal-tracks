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

}
