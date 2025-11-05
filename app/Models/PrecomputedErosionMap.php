<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PrecomputedErosionMap extends Model
{
    protected $fillable = [
        'area_type',
        'area_id',
        'year',
        'status',
        'geotiff_path',
        'tiles_path',
        'statistics',
        'metadata',
        'computed_at',
        'error_message'
    ];

    protected $casts = [
        'statistics' => 'array',
        'metadata' => 'array',
        'computed_at' => 'datetime',
        'year' => 'integer',
        'area_id' => 'integer'
    ];

    /**
     * Check if the precomputed map is available and accessible
     */
    public function isAvailable(): bool
    {
        if ($this->status !== 'completed' || !$this->tiles_path) {
            return false;
        }

        // Check if tiles directory exists
        $tilesDir = storage_path("rusle-tiles/tiles/{$this->area_type}_{$this->area_id}/{$this->year}");
        return file_exists($tilesDir) && is_dir($tilesDir);
    }

    /**
     * Get the area (Region or District)
     */
    public function area()
    {
        if ($this->area_type === 'region') {
            return $this->belongsTo(Region::class, 'area_id');
        } else {
            return $this->belongsTo(District::class, 'area_id');
        }
    }

    /**
     * Scope: only completed maps
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope: only failed maps
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope: processing maps
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    /**
     * Scope: for a specific area
     */
    public function scopeForArea($query, string $areaType, int $areaId)
    {
        return $query->where('area_type', $areaType)
                    ->where('area_id', $areaId);
    }

    /**
     * Get tile URL pattern
     */
    public function getTileUrlAttribute(): string
    {
        // Generate URL pattern manually with placeholders
        $baseUrl = url('/api/erosion/tiles');
        return "{$baseUrl}/{$this->area_type}/{$this->area_id}/{$this->year}/{z}/{x}/{y}.png";
    }
}





