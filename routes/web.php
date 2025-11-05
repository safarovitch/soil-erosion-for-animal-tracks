<?php

use App\Http\Controllers\ErosionController;
use App\Http\Controllers\Admin\DatasetController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [HomeController::class, 'index']);

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

// Telescope (development only)
if (app()->environment('local', 'development', 'staging')) {
    Route::get('/telescope', function () {
        return redirect('/telescope/requests');
    });
}
