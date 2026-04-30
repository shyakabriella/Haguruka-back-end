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
    | Users & Roles routes
    |--------------------------------------------------------------------------
    | These methods are inside RegisterController.
    |--------------------------------------------------------------------------
    */
    Route::get('roles', [RegisterController::class, 'roles']);

    Route::get('users', [RegisterController::class, 'users']);
    Route::post('users', [RegisterController::class, 'storeUser']);
    Route::get('users/{id}', [RegisterController::class, 'showUser']);
    Route::patch('users/{id}', [RegisterController::class, 'updateUser']);
    Route::put('users/{id}', [RegisterController::class, 'updateUser']);
    Route::delete('users/{id}', [RegisterController::class, 'deleteUser']);

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
    */
    Route::post('reports', [VictimReportController::class, 'store']);
    Route::post('reports/quick-emergency', [VictimReportController::class, 'quickEmergency']);
    Route::get('reports', [VictimReportController::class, 'index']);
    Route::get('reports/{id}', [VictimReportController::class, 'show']);
});