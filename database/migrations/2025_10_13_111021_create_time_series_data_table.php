<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('time_series_data', function (Blueprint $table) {
            $table->id();
            $table->morphs('area'); // Polymorphic relationship (region_id or district_id)
            $table->integer('year');
            $table->string('period'); // 'annual', 'monthly', 'seasonal'
            $table->decimal('mean_erosion_rate', 10, 3); // Mean erosion rate in t/ha/year
            $table->decimal('max_erosion_rate', 10, 3); // Maximum erosion rate
            $table->decimal('min_erosion_rate', 10, 3); // Minimum erosion rate
            $table->decimal('total_area_ha', 15, 3); // Total area in hectares
            $table->decimal('erosion_prone_area_ha', 15, 3); // Erosion-prone area in hectares
            $table->decimal('bare_soil_frequency', 5, 2); // Average bare soil frequency (%)
            $table->decimal('sustainability_factor', 5, 3); // Average sustainability factor
            $table->json('monthly_data')->nullable(); // Monthly breakdown if available
            $table->timestamps();

            $table->unique(['area_type', 'area_id', 'year', 'period']);
            $table->index(['area_type', 'area_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_series_data');
    }
};
