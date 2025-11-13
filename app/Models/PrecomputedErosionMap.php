<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrecomputedErosionMap extends Model
{
    protected $fillable = [
        'area_type',
        'area_id',
        'user_id',
        'year',
        'status',
        'geotiff_path',
        'tiles_path',
        'statistics',
        'metadata',
        'computed_at',
        'error_message',
        'config_hash',
        'config_snapshot',
    ];

    protected $casts = [
        'statistics' => 'array',
        'metadata' => 'array',
        'computed_at' => 'datetime',
        'year' => 'integer',
        'area_id' => 'integer',
        'user_id' => 'integer',
        'config_snapshot' => 'array',
    ];

    protected $appends = [
        'period_label',
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
        $period = $this->period_label;
        $tilesDir = storage_path("rusle-tiles/tiles/{$this->area_type}_{$this->area_id}/{$period}");
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function scopeForConfig($query, ?int $userId, string $configHash)
    {
        return $query->where('user_id', $userId)
            ->where('config_hash', $configHash);
    }

    /**
     * Get tile URL pattern
     */
    public function getTileUrlAttribute(): string
    {
        // Generate URL pattern manually with placeholders
        $baseUrl = url('/api/erosion/tiles');
        $period = $this->period_label;
        return "{$baseUrl}/{$this->area_type}/{$this->area_id}/{$period}/{z}/{x}/{y}.png";
    }

    public function getPeriodLabelAttribute(): string
    {
        $periodData = $this->metadata['period'] ?? null;

        if (is_array($periodData)) {
            if (!empty($periodData['label'])) {
                return (string) $periodData['label'];
            }

            $start = $periodData['start_year'] ?? $this->year;
            $end = $periodData['end_year'] ?? $this->year;

            if ($start && $end && $start !== $end) {
                return "{$start}-{$end}";
            }
        }

        return (string) $this->year;
    }
}





