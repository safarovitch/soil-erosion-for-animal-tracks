<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ErosionCache extends Model
{
    protected $table = 'erosion_caches';

    protected $fillable = [
        'cacheable_type',
        'cacheable_id',
        'year',
        'period',
        'cache_key',
        'data',
        'tile_url',
        'expires_at',
    ];

    protected $casts = [
        'data' => 'array',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the parent cacheable model (region or district).
     */
    public function cacheable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Check if the cache entry is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Create a cache key for the given parameters.
     */
    public static function generateCacheKey(string $type, int $id, int $startYear, int $endYear, string $period): string
    {
        return "erosion_{$type}_{$id}_{$startYear}_{$endYear}_{$period}";
    }

    /**
     * Find cache entry by parameters.
     */
    public static function findByParameters(string $type, int $id, int $startYear, int $endYear, string $period): ?self
    {
        $cacheKey = static::generateCacheKey($type, $id, $startYear, $endYear, $period);

        return static::where('cacheable_type', $type)
            ->where('cacheable_id', $id)
            ->where('cache_key', $cacheKey)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    /**
     * Scope for non-expired cache entries.
     */
    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }
}
