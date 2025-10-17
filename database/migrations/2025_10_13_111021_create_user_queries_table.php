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
        Schema::create('user_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null'); // Nullable for anonymous users
            $table->string('session_id')->nullable(); // For tracking anonymous users
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->morphs('queryable'); // Polymorphic relationship (region_id or district_id)
            $table->integer('year')->nullable();
            $table->string('period')->nullable();
            $table->string('query_type'); // 'erosion_map', 'time_series', 'drawing_analysis'
            $table->json('parameters')->nullable(); // Query parameters
            $table->json('geometry')->nullable(); // User-drawn geometry (if applicable)
            $table->decimal('processing_time', 8, 3)->nullable(); // Processing time in seconds
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['session_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_queries');
    }
};
