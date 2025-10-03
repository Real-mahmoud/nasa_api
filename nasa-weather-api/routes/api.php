<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WeatherController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->middleware('throttle:60,1')->group(function () {
    // Main query endpoint
    Route::post('/query', [WeatherController::class, 'query']);

    // Download results as CSV (GET with query params)
    Route::get('/download', [WeatherController::class, 'downloadCsv']);

    // Seasonal forecast endpoint (NASA-only projection)
    Route::post('/forecast', [WeatherController::class, 'forecast']);

    // Metadata endpoints
    Route::get('/variables', [WeatherController::class, 'variables']);
    Route::get('/thresholds', [WeatherController::class, 'thresholds']);

    // Health check
    Route::get('/health', function () {
        return response()->json(['status' => 'ok']);
    });
});