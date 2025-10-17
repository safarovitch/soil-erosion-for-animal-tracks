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
        Schema::create('custom_datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type'); // 'rainfall', 'erosion', 'custom'
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('processed_path')->nullable(); // Path to processed COG tiles
            $table->json('metadata'); // GDAL metadata (bounds, resolution, CRS, etc.)
            $table->string('status')->default('uploading'); // uploading, processing, ready, failed
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_datasets');
    }
};
