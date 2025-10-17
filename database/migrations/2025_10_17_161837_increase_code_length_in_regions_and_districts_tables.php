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
        Schema::table('regions', function (Blueprint $table) {
            $table->string('code', 50)->change();
        });
        
        Schema::table('districts', function (Blueprint $table) {
            $table->string('code', 50)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->string('code', 10)->change();
        });
        
        Schema::table('districts', function (Blueprint $table) {
            $table->string('code', 10)->change();
        });
    }
};
