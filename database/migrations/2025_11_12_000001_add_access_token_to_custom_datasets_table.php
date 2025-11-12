<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('custom_datasets', 'access_token')) {
            Schema::table('custom_datasets', function (Blueprint $table) {
                $table->string('access_token', 64)
                    ->nullable()
                    ->after('status');
            });
        }

        DB::table('custom_datasets')
            ->whereNull('access_token')
            ->lazyById()
            ->each(function ($dataset) {
                DB::table('custom_datasets')
                    ->where('id', $dataset->id)
                    ->update(['access_token' => Str::uuid()->toString()]);
            });

        DB::statement('ALTER TABLE custom_datasets DROP CONSTRAINT IF EXISTS custom_datasets_access_token_unique');

        Schema::table('custom_datasets', function (Blueprint $table) {
            $table->string('access_token', 64)->nullable(false)->change();
            $table->unique('access_token', 'custom_datasets_access_token_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_datasets', function (Blueprint $table) {
            $table->dropUnique(['access_token']);
            $table->dropColumn('access_token');
        });
    }
};


