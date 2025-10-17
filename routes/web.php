<?php

use App\Http\Controllers\ErosionController;
use App\Http\Controllers\Admin\DatasetController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    // Load all regions and districts with their geometries
    $regions = \App\Models\Region::select('id', 'name_en', 'name_tj', 'code', 'area_km2', 'geometry')
        ->orderBy('name_en')
        ->get()
        ->map(function ($region) {
            return [
                'id' => $region->id,
                'name' => $region->name_en,
                'name_en' => $region->name_en,
                'name_tj' => $region->name_tj,
                'code' => $region->code,
                'area_km2' => $region->area_km2,
                'geometry' => $region->getGeometryArray(),
                'center' => $region->getCenterPoint(),
                'bbox' => $region->getBoundingBox(),
                'district_count' => $region->districts()->count(),
            ];
        });
    
    $districts = \App\Models\District::with('region')
        ->select('id', 'region_id', 'name_en', 'name_tj', 'code', 'area_km2', 'geometry')
        ->orderBy('name_en')
        ->get()
        ->map(function ($district) {
            return [
                'id' => $district->id,
                'region_id' => $district->region_id,
                'region_name' => $district->region->name_en ?? null,
                'region_code' => $district->region->code ?? null,
                'name' => $district->name_en,
                'name_en' => $district->name_en,
                'name_tj' => $district->name_tj,
                'code' => $district->code,
                'area_km2' => $district->area_km2,
                'geometry' => $district->getGeometryArray(),
                'center' => $district->getCenterPoint(),
                'bbox' => $district->getBoundingBox(),
            ];
        });

    return Inertia::render('Map', [
        'user' => auth()->user(),
        'regions' => $regions,
        'districts' => $districts,
        'initialData' => [
            'totalRegions' => $regions->count(),
            'totalDistricts' => $districts->count(),
            'appName' => config('app.name', 'Soil Erosion Watch'),
        ],
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
