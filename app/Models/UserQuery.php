<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UserQuery extends Model
{
    protected $fillable = [
        'user_id',
        'session_id',
        'ip_address',
        'user_agent',
        'queryable_type',
        'queryable_id',
        'year',
        'period',
        'query_type',
        'parameters',
        'geometry',
        'processing_time',
    ];

    protected $casts = [
        'parameters' => 'array',
        'geometry' => 'array',
        'processing_time' => 'decimal:3',
    ];

    /**
     * Get the user that made the query.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent queryable model (region or district).
     */
    public function queryable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope for queries by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('query_type', $type);
    }

    /**
     * Scope for queries by user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for queries by session.
     */
    public function scopeBySession($query, string $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    /**
     * Scope for recent queries.
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
