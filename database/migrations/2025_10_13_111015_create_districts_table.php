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
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained()->onDelete('cascade');
            $table->string('name_en');
            $table->string('name_tj'); // Tajik name
            $table->string('code', 10)->unique(); // Administrative code
            $table->text('geometry')->nullable(); // Store as JSON string for SQLite compatibility
            $table->decimal('area_km2', 10, 2)->nullable(); // Area in square kilometers
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('districts');
    }
};
