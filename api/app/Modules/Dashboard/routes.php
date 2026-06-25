<?php

declare(strict_types=1);

use App\Modules\Dashboard\Controllers\BadgeController;
use App\Modules\Dashboard\Controllers\CopqWidgetController;
use App\Modules\Dashboard\Controllers\DashboardController;
use App\Modules\Dashboard\Controllers\DashboardLayoutController;
use App\Modules\Accounting\Controllers\FinanceDashboardController;
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

    // D6, D7, D8 — New role-specific dashboards
    Route::get('/purchasing',     [DashboardController::class, 'purchasing'])
        ->middleware('permission:dashboard.purchasing.view');
    Route::get('/warehouse',      [DashboardController::class, 'warehouse'])
        ->middleware('permission:dashboard.warehouse.view');
    Route::get('/quality',        [DashboardController::class, 'quality'])
        ->middleware('permission:dashboard.quality.view');
    Route::get('/admin',          [DashboardController::class, 'admin'])
        ->middleware('permission:dashboard.admin.view');

    /*
     * Polish Task S2 — sidebar badge counts. Single endpoint that returns
     * every pending-work count for the current user. The service self-gates
     * per key by permission, so no extra middleware is needed beyond
     * authentication.
     */
    Route::get('/badges',         [BadgeController::class, 'index']);

    // COPQ widget — dedicated breakdown + trend chart
    Route::get('/copq-widget', [CopqWidgetController::class, 'index'])
        ->middleware('permission:dashboard.quality.view');

    // P4.3 — Finance dashboard unified under /dashboards prefix (canonical).
    // The old /dashboard/finance route in Accounting/routes.php is kept as a
    // temporary alias until the SPA is updated (see spa/src/api/accounting/dashboard.ts).
    Route::get('/finance',         [FinanceDashboardController::class, 'summary'])
        ->middleware('permission:accounting.dashboard.view');
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
