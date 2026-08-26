<?php

use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ComputerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SiteController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/password', [AuthController::class, 'updatePassword']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);

    Route::get('/dashboard', [DashboardController::class, 'show']);

    Route::get('/my/today', [ReportController::class, 'today']);
    Route::post('/my/reports', [ReportController::class, 'submit']);
    Route::get('/my/reports', [ReportController::class, 'mine']);
    Route::get('/my/computers', [ComputerController::class, 'mine']);

    Route::middleware('role:admin|manager,sanctum')->group(function () {
        Route::get('/employees/next-id', [EmployeeController::class, 'nextId']);
        Route::apiResource('employees', EmployeeController::class);
        Route::patch('/employees/{employee}/status', [EmployeeController::class, 'updateStatus']);
        Route::patch('/employees/{employee}/password', [EmployeeController::class, 'updatePassword']);

        Route::apiResource('computers', ComputerController::class)->except(['show']);
        Route::post('/computers/{computer}/assign', [ComputerController::class, 'assign']);
        Route::post('/computers/{computer}/unassign', [ComputerController::class, 'unassign']);

        Route::apiResource('sites', SiteController::class)->except(['show']);
        Route::post('/sites/{site}/keywords', [SiteController::class, 'storeKeyword']);
        Route::put('/keywords/{keyword}', [SiteController::class, 'updateKeyword']);
        Route::delete('/keywords/{keyword}', [SiteController::class, 'destroyKeyword']);

        Route::get('/assignments', [AssignmentController::class, 'index']);
        Route::post('/assignments', [AssignmentController::class, 'store']);
        Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy']);
        Route::post('/assignments/copy-yesterday', [AssignmentController::class, 'copyYesterday']);

        Route::get('/reports', [ReportController::class, 'index']);
    });
});
