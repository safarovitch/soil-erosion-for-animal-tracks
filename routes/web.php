<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [HomeController::class, 'index']);

Route::middleware('guest')->group(function () {
    Route::get('/admin/login', [AdminAuthController::class, 'create'])->name('admin.login');
    Route::get('/admin/login', [AdminAuthController::class, 'create'])->name('login');
    Route::post('/admin/login', [AdminAuthController::class, 'store'])->name('admin.login.submit');
});

Route::post('/admin/logout', [AdminAuthController::class, 'destroy'])
    ->middleware('auth')
    ->name('admin.logout');

// Admin routes
Route::middleware(['auth', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Admin/Dashboard');
    })->name('admin.dashboard');

    Route::get('/datasets/upload', function () {
        return Inertia::render('Admin/DatasetUpload');
    });

    Route::get('/usage-history', function () {
        return Inertia::render('Admin/UsageHistory');
    });

    Route::get('/rusle-config', function () {
        return Inertia::render('Admin/RusleConfig');
    });
});

// Telescope (development only)
if (app()->environment('local', 'development', 'staging')) {
    Route::get('/telescope', function () {
        return redirect('/telescope/requests');
    });
}
