<?php

use App\Http\Controllers\ErosionController;
use App\Http\Controllers\Admin\DatasetController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Map', [
        'user' => auth()->user(),
        'regions' => \App\Models\Region::select('id', 'name_en', 'name_tj', 'code', 'area_km2', 'geometry')
            ->orderBy('name_en')->get(),
        'districts' => \App\Models\District::select('id', 'region_id', 'name_en', 'name_tj', 'code', 'area_km2', 'geometry')
            ->orderBy('name_en')->get(),
    ]);
});

// Admin routes
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Admin/Dashboard');
    });

    Route::get('/datasets/upload', function () {
        return Inertia::render('Admin/DatasetUpload');
    });

    Route::get('/usage-history', function () {
        return Inertia::render('Admin/UsageHistory');
    });
});
