<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\RegisterController;
use App\Http\Controllers\API\VictimReportController;
use App\Http\Controllers\API\CaseFollowUpTaskController;
use App\Http\Controllers\API\AppointmentController;
use App\Http\Controllers\API\OrganizationController;
use App\Http\Controllers\API\ServicePointController;
use App\Http\Controllers\API\ReportController;
use App\Http\Controllers\API\CaseActionController;

Route::controller(RegisterController::class)->group(function () {
    Route::post('register', 'register');
    Route::post('login', 'login');
});

Route::middleware('auth:sanctum')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Current logged-in user
    |--------------------------------------------------------------------------
    | Mobile victim dashboard must call this endpoint after login.
    | It returns only the authenticated user's own profile.
    |--------------------------------------------------------------------------
    */
    Route::get('me', [RegisterController::class, 'me']);

    /*
    |--------------------------------------------------------------------------
    | Users & Roles routes
    |--------------------------------------------------------------------------
    | Controller protects these routes so victims receive 403.
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
    | Victim Reports routes
    |--------------------------------------------------------------------------
    */
    Route::post('victim-reports', [VictimReportController::class, 'store']);
    Route::post('victim-reports/quick-emergency', [VictimReportController::class, 'quickEmergency']);
    Route::get('victim-reports', [VictimReportController::class, 'index']);

    /*
    |--------------------------------------------------------------------------
    | Case Follow-Up Task routes
    |--------------------------------------------------------------------------
    */
    Route::get('victim-reports/{report}/follow-up-tasks', [CaseFollowUpTaskController::class, 'index']);
    Route::post('victim-reports/{report}/follow-up-tasks', [CaseFollowUpTaskController::class, 'store']);

    Route::patch('case-follow-up-tasks/{task}', [CaseFollowUpTaskController::class, 'update']);
    Route::put('case-follow-up-tasks/{task}', [CaseFollowUpTaskController::class, 'update']);
    Route::delete('case-follow-up-tasks/{task}', [CaseFollowUpTaskController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Victim Case Actions routes
    |--------------------------------------------------------------------------
    */
    Route::post('victim-reports/{report}/withdraw', [CaseActionController::class, 'withdraw']);
    Route::post('victim-reports/{report}/close', [CaseActionController::class, 'close']);

    /*
    |--------------------------------------------------------------------------
    | Appointments routes
    |--------------------------------------------------------------------------
    */
    Route::get('appointments', [AppointmentController::class, 'index']);
    Route::post('appointments', [AppointmentController::class, 'store']);
    Route::get('appointments/{appointment}', [AppointmentController::class, 'show']);
    Route::patch('appointments/{appointment}', [AppointmentController::class, 'update']);
    Route::put('appointments/{appointment}', [AppointmentController::class, 'update']);
    Route::delete('appointments/{appointment}', [AppointmentController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Service Directory - Organizations routes
    |--------------------------------------------------------------------------
    */
    Route::get('organizations', [OrganizationController::class, 'index']);
    Route::post('organizations', [OrganizationController::class, 'store']);
    Route::get('organizations/{organization}', [OrganizationController::class, 'show']);
    Route::patch('organizations/{organization}', [OrganizationController::class, 'update']);
    Route::put('organizations/{organization}', [OrganizationController::class, 'update']);
    Route::delete('organizations/{organization}', [OrganizationController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Service Directory - Service Points routes
    |--------------------------------------------------------------------------
    */
    Route::get('service-points', [ServicePointController::class, 'index']);
    Route::post('service-points', [ServicePointController::class, 'store']);
    Route::get('service-points/{servicePoint}', [ServicePointController::class, 'show']);
    Route::patch('service-points/{servicePoint}', [ServicePointController::class, 'update']);
    Route::put('service-points/{servicePoint}', [ServicePointController::class, 'update']);
    Route::delete('service-points/{servicePoint}', [ServicePointController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Reports & Statistics routes
    |--------------------------------------------------------------------------
    */
    Route::get('reports/summary', [ReportController::class, 'summary']);

    /*
    |--------------------------------------------------------------------------
    | Case Status Update route
    |--------------------------------------------------------------------------
    */
    Route::patch('victim-reports/{id}/status', [VictimReportController::class, 'updateStatus']);
    Route::put('victim-reports/{id}/status', [VictimReportController::class, 'updateStatus']);

    /*
    |--------------------------------------------------------------------------
    | Single Victim Report route
    |--------------------------------------------------------------------------
    */
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