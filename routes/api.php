<?php

use App\Http\Controllers\Api\AppSettingController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ComputerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\SalaryController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\WorkSessionController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/password', [AuthController::class, 'updatePassword']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);

    Route::get('/dashboard', [DashboardController::class, 'show']);

    Route::get('/app-settings', [AppSettingController::class, 'show']);

    Route::get('/my/today', [ReportController::class, 'today']);
    Route::post('/my/reports', [ReportController::class, 'submit']);
    Route::get('/my/reports', [ReportController::class, 'mine']);
    Route::get('/my/computers', [ComputerController::class, 'mine']);
    Route::get('/my/work-session', [WorkSessionController::class, 'show']);
    Route::post('/my/work-session/start', [WorkSessionController::class, 'start']);
    Route::post('/my/work-session/complete', [WorkSessionController::class, 'complete']);
    Route::get('/holidays', [HolidayController::class, 'index']);
    Route::get('/calendar', [LeaveController::class, 'calendar']);
    Route::get('/my/leaves', [LeaveController::class, 'mine']);
    Route::post('/my/leaves', [LeaveController::class, 'store']);
    Route::delete('/my/leaves/{leave}', [LeaveController::class, 'destroy']);
    Route::get('/my/earnings', [SalaryController::class, 'mine']);

    Route::middleware('role:admin|manager,sanctum')->group(function () {
        Route::get('/employees/next-id', [EmployeeController::class, 'nextId']);
        Route::apiResource('employees', EmployeeController::class);
        Route::patch('/employees/{employee}/status', [EmployeeController::class, 'updateStatus']);
        Route::patch('/employees/{employee}/password', [EmployeeController::class, 'updatePassword']);

        Route::get('/computers/next-number', [ComputerController::class, 'nextNumber']);
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
        Route::put('/app-settings', [AppSettingController::class, 'update']);

        Route::post('/holidays', [HolidayController::class, 'store']);
        Route::put('/holidays/{holiday}', [HolidayController::class, 'update']);
        Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy']);

        Route::get('/leaves', [LeaveController::class, 'index']);
        Route::patch('/leaves/{leave}', [LeaveController::class, 'review']);

        Route::get('/salary', [SalaryController::class, 'index']);
    });
});
