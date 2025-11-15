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
        Schema::table('precomputed_erosion_maps', function (Blueprint $table) {
            $table->dropUnique('precomputed_maps_area_year_user_hash_unique');

            $table->string('geometry_hash', 64)
                ->default('')
                ->after('config_hash');

            $table->json('geometry_snapshot')
                ->nullable()
                ->after('geometry_hash');

            $table->unique(
                ['area_type', 'area_id', 'year', 'user_id', 'config_hash', 'geometry_hash'],
                'precomputed_maps_area_year_user_hash_geometry_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('precomputed_erosion_maps', function (Blueprint $table) {
            $table->dropUnique('precomputed_maps_area_year_user_hash_geometry_unique');
            $table->dropColumn(['geometry_hash', 'geometry_snapshot']);

            $table->unique(
                ['area_type', 'area_id', 'year', 'user_id', 'config_hash'],
                'precomputed_maps_area_year_user_hash_unique'
            );
        });
    }
};

