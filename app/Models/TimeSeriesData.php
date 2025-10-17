<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TimeSeriesData extends Model
{
    protected $fillable = [
        'area_type',
        'area_id',
        'year',
        'period',
        'mean_erosion_rate',
        'max_erosion_rate',
        'min_erosion_rate',
        'total_area_ha',
        'erosion_prone_area_ha',
        'bare_soil_frequency',
        'sustainability_factor',
        'monthly_data',
    ];

    protected $casts = [
        'mean_erosion_rate' => 'decimal:3',
        'max_erosion_rate' => 'decimal:3',
        'min_erosion_rate' => 'decimal:3',
        'total_area_ha' => 'decimal:3',
        'erosion_prone_area_ha' => 'decimal:3',
        'bare_soil_frequency' => 'decimal:2',
        'sustainability_factor' => 'decimal:3',
        'monthly_data' => 'array',
    ];

    /**
     * Get the parent area model (region or district).
     */
    public function area(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope for data by year range.
     */
    public function scopeByYearRange($query, int $startYear, int $endYear)
    {
        return $query->whereBetween('year', [$startYear, $endYear]);
    }

    /**
     * Scope for data by period.
     */
    public function scopeByPeriod($query, string $period)
    {
        return $query->where('period', $period);
    }

    /**
     * Get time series data for an area.
     */
    public static function getTimeSeriesForArea(string $areaType, int $areaId, int $startYear = 2016, int $endYear = 2024): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('area_type', $areaType)
            ->where('area_id', $areaId)
            ->byYearRange($startYear, $endYear)
            ->byPeriod('annual')
            ->orderBy('year')
            ->get();
    }

    /**
     * Calculate the erosion trend over time.
     */
    public function calculateTrend(): float
    {
        $data = static::where('area_type', $this->area_type)
            ->where('area_id', $this->area_id)
            ->byPeriod('annual')
            ->orderBy('year')
            ->pluck('mean_erosion_rate')
            ->toArray();

        if (count($data) < 2) {
            return 0;
        }

        // Simple linear regression slope
        $n = count($data);
        $x = range(1, $n);
        $y = $data;

        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = 0;
        $sumXX = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $y[$i];
            $sumXX += $x[$i] * $x[$i];
        }

        return ($n * $sumXY - $sumX * $sumY) / ($n * $sumXX - $sumX * $sumX);
    }
}
