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
        Schema::create('erosion_caches', function (Blueprint $table) {
            $table->id();
            $table->morphs('cacheable'); // Polymorphic relationship (region_id or district_id)
            $table->integer('year');
            $table->string('period'); // 'annual', 'seasonal', etc.
            $table->string('cache_key')->unique(); // Unique identifier for cache entry
            $table->json('data'); // Cached computation results
            $table->string('tile_url')->nullable(); // URL to pre-generated tiles
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['cacheable_type', 'cacheable_id', 'year', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('erosion_caches');
    }
};
