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
        Schema::create('precomputed_erosion_maps', function (Blueprint $table) {
            $table->id();
            $table->string('area_type');  // 'region' or 'district'
            $table->unsignedBigInteger('area_id');
            $table->year('year');
            $table->string('status')->default('pending');  // pending, processing, completed, failed
            $table->text('geotiff_path')->nullable();
            $table->text('tiles_path')->nullable();
            $table->json('statistics')->nullable();  // mean, min, max, std_dev
            $table->json('metadata')->nullable();  // bbox, cell_count, task_id, etc.
            $table->timestamp('computed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->unique(['area_type', 'area_id', 'year']);
            $table->index(['status', 'computed_at']);
            $table->index('area_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('precomputed_erosion_maps');
    }
};
