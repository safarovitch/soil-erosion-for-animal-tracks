<?php

use App\Http\Controllers\ErosionController;
use App\Http\Controllers\Admin\DatasetController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Authentication routes
Route::post('/login', function (Request $request) {
    $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    if (!Auth::attempt($request->only('email', 'password'))) {
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    $user = Auth::user();
    $token = $user->createToken('api-token')->plainTextToken;

    return response()->json([
        'user' => $user,
        'token' => $token,
    ]);
});

Route::post('/logout', function (Request $request) {
    $request->user()->currentAccessToken()->delete();
    return response()->json(['message' => 'Logged out successfully']);
})->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| Public API Routes
|--------------------------------------------------------------------------
*/

// Erosion data endpoints
Route::middleware('web')->prefix('erosion')->group(function () {
    Route::post('/compute', [ErosionController::class, 'compute']);
    Route::get('/cached', [ErosionController::class, 'getCached']);
    Route::post('/timeseries', [ErosionController::class, 'getTimeSeries']);
    Route::post('/analyze-geometry', [ErosionController::class, 'analyzeGeometry']);

    // Geographic data
    Route::get('/regions', [ErosionController::class, 'getRegions']);
    Route::get('/districts', [ErosionController::class, 'getDistricts']);
});

// Custom datasets (public access)
Route::get('/datasets', [DatasetController::class, 'getAvailable']);
Route::get('/datasets/{dataset}/tiles/{z}/{x}/{y}', [DatasetController::class, 'serveTiles'])
    ->name('api.datasets.tiles');

/*
|--------------------------------------------------------------------------
| Admin API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    // Dataset management
    Route::apiResource('datasets', DatasetController::class);
    Route::post('/datasets/upload', [DatasetController::class, 'upload']);

    // Analytics and stats
    Route::get('/stats', [\App\Http\Controllers\Admin\AnalyticsController::class, 'getStats']);
    Route::get('/recent-activity', [\App\Http\Controllers\Admin\AnalyticsController::class, 'getRecentActivity']);
    Route::get('/system-status', [\App\Http\Controllers\Admin\AnalyticsController::class, 'getSystemStatus']);
    Route::get('/usage-stats', [\App\Http\Controllers\Admin\AnalyticsController::class, 'getUsageStats']);
    Route::get('/usage-history', [\App\Http\Controllers\Admin\AnalyticsController::class, 'getUsageHistory']);
    Route::post('/clear-cache', [\App\Http\Controllers\Admin\AnalyticsController::class, 'clearCache']);
    Route::get('/export-usage', [\App\Http\Controllers\Admin\AnalyticsController::class, 'exportUsage']);
});
