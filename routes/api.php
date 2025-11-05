<?php

use App\Http\Controllers\ErosionController;
use App\Http\Controllers\ErosionTileController;
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
Route::middleware('api')->prefix('erosion')->group(function () {
    Route::post('/compute', [ErosionController::class, 'compute']);
    Route::get('/cached', [ErosionController::class, 'getCached']);
    Route::post('/timeseries', [ErosionController::class, 'getTimeSeries']);
    Route::post('/analyze-geometry', [ErosionController::class, 'analyzeGeometry']);

    // Geographic data
    Route::get('/regions', [ErosionController::class, 'getRegions']);
    Route::get('/districts', [ErosionController::class, 'getDistricts']);
    Route::get('/districts/geojson', [ErosionController::class, 'getDistrictsGeoJSON']);

    // RUSLE factor layers
    Route::post('/layers/r-factor', [ErosionController::class, 'getRFactorLayer']);
    Route::post('/layers/k-factor', [ErosionController::class, 'getKFactorLayer']);
    Route::post('/layers/ls-factor', [ErosionController::class, 'getLSFactorLayer']);
    Route::post('/layers/c-factor', [ErosionController::class, 'getCFactorLayer']);
    Route::post('/layers/p-factor', [ErosionController::class, 'getPFactorLayer']);
    Route::post('/layers/rainfall-slope', [ErosionController::class, 'getRainfallSlope']);
    Route::post('/layers/rainfall-cv', [ErosionController::class, 'getRainfallCV']);

    // Detailed grid data
    Route::post('/detailed-grid', [ErosionController::class, 'getDetailedGrid']);
    
    // Available years
    Route::any('/available-years', [ErosionController::class, 'getAvailableYears']);
});

// Erosion Tile System (Precomputed Maps)
Route::prefix('erosion')->group(function () {
    // Serve map tiles
    Route::get('/tiles/{area_type}/{area_id}/{year}/{z}/{x}/{y}.png', 
        [ErosionTileController::class, 'serveTile']
    )->name('erosion.tiles');

    // Check availability / queue computation
    Route::post('/check-availability', 
        [ErosionTileController::class, 'checkAvailability']
    );

    // Task status
    Route::get('/task-status/{taskId}', 
        [ErosionTileController::class, 'taskStatus']
    );

    // Callback from Python service when task starts
    Route::post('/task-started', 
        [ErosionTileController::class, 'taskStarted']
    );

    // Callback from Python service when task completes
    Route::post('/task-complete', 
        [ErosionTileController::class, 'taskComplete']
    );
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

    // Erosion tile precomputation (admin only)
    Route::post('/erosion/precompute-all', 
        [ErosionTileController::class, 'precomputeAll']
    );

    // Analytics and stats
    Route::get('/stats', [\App\Http\Controllers\Admin\AnalyticsController::class, 'getStats']);
    Route::get('/recent-activity', [\App\Http\Controllers\Admin\AnalyticsController::class, 'getRecentActivity']);
    Route::get('/system-status', [\App\Http\Controllers\Admin\AnalyticsController::class, 'getSystemStatus']);
    Route::get('/usage-stats', [\App\Http\Controllers\Admin\AnalyticsController::class, 'getUsageStats']);
    Route::get('/usage-history', [\App\Http\Controllers\Admin\AnalyticsController::class, 'getUsageHistory']);
    Route::post('/clear-cache', [\App\Http\Controllers\Admin\AnalyticsController::class, 'clearCache']);
    Route::get('/export-usage', [\App\Http\Controllers\Admin\AnalyticsController::class, 'exportUsage']);
});
