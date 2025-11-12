<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomDataset extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'type',
        'original_filename',
        'file_path',
        'processed_path',
        'metadata',
        'status',
        'access_token',
        'processed_at',
    ];

    protected $hidden = [
        'file_path',
        'processed_path',
        'access_token',
    ];

    protected $casts = [
        'metadata' => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * Get the user that uploaded the dataset.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the full path to the original file.
     */
    public function getFullPathAttribute(): string
    {
        return storage_path('app/' . $this->file_path);
    }

    /**
     * Get the full path to the processed tiles.
     */
    public function getProcessedFullPathAttribute(): ?string
    {
        return $this->processed_path ? storage_path('app/' . $this->processed_path) : null;
    }

    /**
     * Check if the dataset is ready for use.
     */
    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->processed_path !== null;
    }

    /**
     * Get the tile URL for serving tiles.
     */
    public function getTileUrlAttribute(): string
    {
        return route('api.datasets.tiles', ['dataset' => $this->id]);
    }

    /**
     * Scope for ready datasets.
     */
    public function scopeReady($query)
    {
        return $query->where('status', 'ready');
    }

    /**
     * Scope for datasets by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
