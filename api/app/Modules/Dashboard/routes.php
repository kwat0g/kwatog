<?php

declare(strict_types=1);

use App\Modules\Dashboard\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
 * Dashboard module routes — Sprint 8 Tasks 72 + 73.
 * Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.
 */

Route::middleware('auth:sanctum')->prefix('dashboards')->group(function () {
    Route::get('/plant-manager', [DashboardController::class, 'plantManager'])
        ->middleware('permission:dashboard.plant_manager.view');
    Route::get('/hr',             [DashboardController::class, 'hr'])
        ->middleware('permission:dashboard.hr.view');
    Route::get('/ppc',            [DashboardController::class, 'ppc'])
        ->middleware('permission:dashboard.ppc.view');
    Route::get('/accounting',     [DashboardController::class, 'accounting'])
        ->middleware('permission:dashboard.accounting.view');

    // Self-service: server scopes to auth.user.employee_id; no permission gate
    // beyond authentication needed (every authenticated user can see their own).
    Route::get('/employee',       [DashboardController::class, 'employee']);
});
