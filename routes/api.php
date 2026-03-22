<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\RegisterController;
use App\Http\Controllers\API\VictimReportController;

Route::controller(RegisterController::class)->group(function () {
    Route::post('register', 'register');
    Route::post('login', 'login');
});

Route::middleware('auth:sanctum')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Preferred routes
    |--------------------------------------------------------------------------
    */
    Route::post('victim-reports', [VictimReportController::class, 'store']);
    Route::post('victim-reports/quick-emergency', [VictimReportController::class, 'quickEmergency']);
    Route::get('victim-reports', [VictimReportController::class, 'index']);
    Route::get('victim-reports/{id}', [VictimReportController::class, 'show']);

    /*
    |--------------------------------------------------------------------------
    | Legacy compatibility routes
    |--------------------------------------------------------------------------
    | Keep these if some old frontend code still uses /reports
    */
    Route::post('reports', [VictimReportController::class, 'store']);
    Route::post('reports/quick-emergency', [VictimReportController::class, 'quickEmergency']);
    Route::get('reports', [VictimReportController::class, 'index']);
    Route::get('reports/{id}', [VictimReportController::class, 'show']);
});