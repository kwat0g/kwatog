<?php

declare(strict_types=1);

use App\Modules\Maintenance\Controllers\DowntimeAnalyticsController;
use App\Modules\Maintenance\Controllers\MaintenanceScheduleController;
use App\Modules\Maintenance\Controllers\MaintenanceWorkOrderController;
use Illuminate\Support\Facades\Route;
use App\Modules\Maintenance\Middleware\CheckMaintenanceWorkAccess;

/*
 * Maintenance module routes — Sprint 8 Task 69.
 * Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.
 */

Route::middleware(['auth:sanctum', 'feature:maintenance'])->prefix('maintenance')->group(function () {
    Route::get('/target-options', [MaintenanceWorkOrderController::class, 'targetOptions'])
        ->middleware('permission:maintenance.wo.create');
    Route::get('/spare-part-options', [MaintenanceWorkOrderController::class, 'sparePartOptions'])
        ->middleware(CheckMaintenanceWorkAccess::class.':record');
    Route::get('/spare-part-stock', [MaintenanceWorkOrderController::class, 'sparePartStock'])
        ->middleware(CheckMaintenanceWorkAccess::class.':record');

    /* ── Schedules ─────────────────────────────────────────── */
    Route::get('/schedules/options',          [MaintenanceScheduleController::class, 'options'])
        ->middleware('permission:maintenance.view');
    Route::get('/schedules/target-options', [MaintenanceWorkOrderController::class, 'targetOptions'])
        ->middleware('permission:maintenance.schedules.manage');
    Route::get('/schedules',                 [MaintenanceScheduleController::class, 'index'])
        ->middleware('permission:maintenance.view');
    Route::get('/schedules/{schedule}',      [MaintenanceScheduleController::class, 'show'])
        ->middleware('permission:maintenance.view');
    Route::get('/schedules/{schedule}/window-suggestions', [MaintenanceScheduleController::class, 'windowSuggestions'])
        ->middleware('permission:maintenance.schedules.manage');
    Route::post('/schedules',                [MaintenanceScheduleController::class, 'store'])
        ->middleware('permission:maintenance.schedules.manage');
    Route::put('/schedules/{schedule}',      [MaintenanceScheduleController::class, 'update'])
        ->middleware('permission:maintenance.schedules.manage');
    Route::delete('/schedules/{schedule}',   [MaintenanceScheduleController::class, 'destroy'])
        ->middleware('permission:maintenance.schedules.manage');
    Route::patch('/schedules/{schedule}/restore', [MaintenanceScheduleController::class, 'restore'])
        ->middleware('permission:maintenance.schedules.manage')
        ->withTrashed();

    /* ── Work orders ───────────────────────────────────────── */
    Route::get('/work-orders/options',                 [MaintenanceWorkOrderController::class, 'options'])
        ->middleware(CheckMaintenanceWorkAccess::class.':read');
    Route::get('/work-orders/assignees',               [MaintenanceWorkOrderController::class, 'assignees'])
        ->middleware('permission:maintenance.wo.assign');
    Route::get('/work-orders',                          [MaintenanceWorkOrderController::class, 'index'])
        ->middleware(CheckMaintenanceWorkAccess::class.':read');
    Route::get('/work-orders/{workOrder}/assignee-suggestions', [MaintenanceWorkOrderController::class, 'assigneeSuggestions'])
        ->middleware('permission:maintenance.wo.assign');
    Route::get('/work-orders/{workOrder}',              [MaintenanceWorkOrderController::class, 'show'])
        ->middleware(CheckMaintenanceWorkAccess::class.':read');
    Route::post('/work-orders/{workOrder}/window', [MaintenanceWorkOrderController::class, 'confirmWindow'])
        ->middleware(['permission:maintenance.schedules.manage', 'permission:maintenance.wo.assign']);
    Route::post('/work-orders',                         [MaintenanceWorkOrderController::class, 'store'])
        ->middleware('permission:maintenance.wo.create');
    Route::patch('/work-orders/{workOrder}/assign',     [MaintenanceWorkOrderController::class, 'assign'])
        ->middleware('permission:maintenance.wo.assign');
    Route::patch('/work-orders/{workOrder}/start',      [MaintenanceWorkOrderController::class, 'start'])
        ->middleware('permission_any:maintenance.wo.complete,maintenance.wo.assign');
    Route::patch('/work-orders/{workOrder}/complete',   [MaintenanceWorkOrderController::class, 'complete'])
        ->middleware('permission_any:maintenance.wo.complete,maintenance.wo.assign');
    Route::post('/work-orders/{workOrder}/submit-repair', [MaintenanceWorkOrderController::class, 'submitRepair'])
        ->middleware('permission_any:maintenance.wo.complete,maintenance.wo.assign,maintenance.work.assigned');
    Route::post('/work-orders/{workOrder}/reconcile-parts', [MaintenanceWorkOrderController::class, 'reconcileParts'])
        ->middleware(CheckMaintenanceWorkAccess::class.':reconcile');
    Route::post('/work-orders/{workOrder}/verify', [MaintenanceWorkOrderController::class, 'verifyRepair'])
        ->middleware('permission:maintenance.wo.verify');
    Route::patch('/work-orders/{workOrder}/cancel',     [MaintenanceWorkOrderController::class, 'cancel'])
        ->middleware('permission_any:maintenance.wo.complete,maintenance.wo.assign');
    Route::post('/work-orders/{workOrder}/logs',        [MaintenanceWorkOrderController::class, 'addLog'])
        ->middleware(CheckMaintenanceWorkAccess::class.':record');
    Route::post('/work-orders/{workOrder}/work-records', [MaintenanceWorkOrderController::class, 'recordWork'])
        ->middleware(CheckMaintenanceWorkAccess::class.':record');
    Route::post('/work-orders/{workOrder}/spare-parts', [MaintenanceWorkOrderController::class, 'recordSparePart'])
        ->middleware(CheckMaintenanceWorkAccess::class.':record');
    Route::post('/work-orders/{workOrder}/part-requests/{partRequest}/withdraw', [MaintenanceWorkOrderController::class, 'withdrawPartRequest'])
        ->middleware(CheckMaintenanceWorkAccess::class.':record');
    Route::post('/work-orders/{workOrder}/spare-parts/{usage}/returns', [MaintenanceWorkOrderController::class, 'returnSparePart'])
        ->middleware(CheckMaintenanceWorkAccess::class.':record');

    /*
     * Condition readings / machine-health — HIDDEN 2026-08-08 (scope cut).
     *
     * The system has no IoT/edge connection to machines, so "live" machine
     * health is impossible; the feature was a manual phone-rounds flow with a
     * threshold-breach → auto-corrective-WO automation. Per the scope-cut
     * decision it is fully hidden (desktop + mobile entry + these routes).
     *
     * Re-enable: restore the import above, uncomment the block below, and
     * restore the SPA routes (maintenanceRoutes.tsx, maintenanceMobileRoutes.tsx)
     * + the sidebar/layout entries. The controller/service/model are intact;
     * also restore tests/Feature/Maintenance/ConditionReadingHashIdTest.php
     * (the retained hash-id/service contract regression).
     *
     * Route::get('/condition-readings/options', [MachineConditionReadingController::class, 'options'])
     *     ->middleware('permission:maintenance.view');
     * Route::get('/condition-readings', [MachineConditionReadingController::class, 'index'])
     *     ->middleware('permission:maintenance.view');
     * Route::post('/condition-readings', [MachineConditionReadingController::class, 'store'])
     *     ->middleware('permission:maintenance.wo.create');
     * Route::get('/condition-readings/trend', [MachineConditionReadingController::class, 'trend'])
     *     ->middleware('permission:maintenance.view');
     * Route::get('/condition-readings/health-snapshot', [MachineConditionReadingController::class, 'healthSnapshot'])
     *     ->middleware('permission:maintenance.view');
     * Route::get('/condition-readings/{reading}', [MachineConditionReadingController::class, 'show'])
     *     ->middleware('permission:maintenance.view');
     */

    /* ── Downtime analytics ──────────────────────────────────── */
    Route::get('/downtime-analytics/summary', [DowntimeAnalyticsController::class, 'summary'])
        ->middleware('permission:maintenance.view');
    Route::get('/downtime-analytics/policy', [DowntimeAnalyticsController::class, 'policy'])
        ->middleware('permission:maintenance.view');
    Route::get('/downtime-analytics/daily-trend', [DowntimeAnalyticsController::class, 'dailyTrend'])
        ->middleware('permission:maintenance.view');
    Route::get('/downtime-analytics/top-machines', [DowntimeAnalyticsController::class, 'topMachines'])
        ->middleware('permission:maintenance.view');
    Route::get('/downtime-analytics/all-machines', [DowntimeAnalyticsController::class, 'allMachines'])
        ->middleware('permission:maintenance.view');
    // L-39 Pareto.
    Route::get('/downtime-analytics/pareto', [DowntimeAnalyticsController::class, 'pareto'])
        ->middleware('permission:maintenance.view');
});
