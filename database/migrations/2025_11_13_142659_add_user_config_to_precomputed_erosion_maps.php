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
            $table->dropUnique('precomputed_erosion_maps_area_type_area_id_year_unique');

            $table->foreignId('user_id')
                ->nullable()
                ->after('area_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('config_hash', 64)
                ->default('default')
                ->after('user_id');

            $table->json('config_snapshot')
                ->nullable()
                ->after('config_hash');

            $table->index('user_id');
            $table->index('config_hash');
            $table->unique(
                ['area_type', 'area_id', 'year', 'user_id', 'config_hash'],
                'precomputed_maps_area_year_user_hash_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('precomputed_erosion_maps', function (Blueprint $table) {
            $table->dropUnique('precomputed_maps_area_year_user_hash_unique');
            $table->dropIndex(['user_id']);
            $table->dropIndex(['config_hash']);

            $table->dropColumn(['config_snapshot', 'config_hash', 'user_id']);

            $table->unique(['area_type', 'area_id', 'year']);
        });
    }
};
