<?php

declare(strict_types=1);

use App\Modules\Dashboard\Controllers\DashboardController;
use App\Modules\Dashboard\Controllers\DashboardLayoutController;
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

/*
 * Series R — Task R4 — role-defaulted dashboard layout endpoints.
 *
 * No `permission:` middleware on the layout endpoints themselves: every
 * authenticated user has a dashboard. Per-widget permission gating happens
 * inside DashboardLayoutService::getEffectiveLayout / ::listAvailableWidgets.
 */
Route::prefix('dashboard')
    ->middleware(['auth:sanctum', 'session.timeout', 'password.expired'])
    ->group(function (): void {
        Route::get('/widgets',       [DashboardLayoutController::class, 'widgets']);
        Route::get('/layout',        [DashboardLayoutController::class, 'show']);
        Route::put('/layout',        [DashboardLayoutController::class, 'save']);
        Route::post('/layout/reset', [DashboardLayoutController::class, 'reset']);
    });
