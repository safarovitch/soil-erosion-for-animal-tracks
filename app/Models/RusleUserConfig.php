<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RusleUserConfig extends Model
{
    protected $fillable = [
        'user_id',
        'overrides',
        'defaults_version',
        'last_synced_at',
    ];

    protected $casts = [
        'overrides' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function effective(array $defaults): array
    {
        return static::mergeRecursiveDistinct($defaults, $this->overrides ?? []);
    }

    public static function mergeRecursiveDistinct(array $defaults, ?array $overrides): array
    {
        if (empty($overrides)) {
            return $defaults;
        }

        $merged = $defaults;

        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = self::mergeRecursiveDistinct($merged[$key], $value);
            } elseif ($value === null) {
                unset($merged[$key]);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
