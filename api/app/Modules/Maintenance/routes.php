<?php

declare(strict_types=1);

use App\Modules\Maintenance\Controllers\DowntimeAnalyticsController;
use App\Modules\Maintenance\Controllers\MachineConditionReadingController;
use App\Modules\Maintenance\Controllers\MaintenanceScheduleController;
use App\Modules\Maintenance\Controllers\MaintenanceWorkOrderController;
use Illuminate\Support\Facades\Route;

/*
 * Maintenance module routes — Sprint 8 Task 69.
 * Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.
 */

Route::middleware(['auth:sanctum', 'feature:maintenance'])->prefix('maintenance')->group(function () {

    /* ── Schedules ─────────────────────────────────────────── */
    Route::get('/schedules',                 [MaintenanceScheduleController::class, 'index'])
        ->middleware('permission:maintenance.view');
    Route::get('/schedules/{schedule}',      [MaintenanceScheduleController::class, 'show'])
        ->middleware('permission:maintenance.view');
    Route::post('/schedules',                [MaintenanceScheduleController::class, 'store'])
        ->middleware('permission:maintenance.schedules.manage');
    Route::put('/schedules/{schedule}',      [MaintenanceScheduleController::class, 'update'])
        ->middleware('permission:maintenance.schedules.manage');
    Route::delete('/schedules/{schedule}',   [MaintenanceScheduleController::class, 'destroy'])
        ->middleware('permission:maintenance.schedules.manage');

    /* ── Work orders ───────────────────────────────────────── */
    Route::get('/work-orders',                          [MaintenanceWorkOrderController::class, 'index'])
        ->middleware('permission:maintenance.view');
    Route::get('/work-orders/{workOrder}',              [MaintenanceWorkOrderController::class, 'show'])
        ->middleware('permission:maintenance.view');
    Route::post('/work-orders',                         [MaintenanceWorkOrderController::class, 'store'])
        ->middleware('permission:maintenance.wo.create');
    Route::patch('/work-orders/{workOrder}/assign',     [MaintenanceWorkOrderController::class, 'assign'])
        ->middleware('permission:maintenance.wo.assign');
    Route::patch('/work-orders/{workOrder}/start',      [MaintenanceWorkOrderController::class, 'start'])
        ->middleware('permission:maintenance.wo.complete');
    Route::patch('/work-orders/{workOrder}/complete',   [MaintenanceWorkOrderController::class, 'complete'])
        ->middleware('permission:maintenance.wo.complete');
    Route::patch('/work-orders/{workOrder}/cancel',     [MaintenanceWorkOrderController::class, 'cancel'])
        ->middleware('permission:maintenance.wo.complete');
    Route::post('/work-orders/{workOrder}/logs',        [MaintenanceWorkOrderController::class, 'addLog'])
        ->middleware('permission:maintenance.wo.complete');
    Route::post('/work-orders/{workOrder}/spare-parts', [MaintenanceWorkOrderController::class, 'recordSparePart'])
        ->middleware('permission:maintenance.wo.complete');

    /* ── Condition readings (predictive maintenance) ─────────── */
    // Non-parameterised routes must come BEFORE the {reading} wildcard.
    Route::get('/condition-readings', [MachineConditionReadingController::class, 'index'])
        ->middleware('permission:maintenance.view');
    Route::post('/condition-readings', [MachineConditionReadingController::class, 'store'])
        ->middleware('permission:maintenance.wo.create');
    Route::get('/condition-readings/trend', [MachineConditionReadingController::class, 'trend'])
        ->middleware('permission:maintenance.view');
    Route::get('/condition-readings/health-snapshot', [MachineConditionReadingController::class, 'healthSnapshot'])
        ->middleware('permission:maintenance.view');
    Route::get('/condition-readings/{reading}', [MachineConditionReadingController::class, 'show'])
        ->middleware('permission:maintenance.view');

    /* ── Downtime analytics ──────────────────────────────────── */
    Route::get('/downtime-analytics/summary', [DowntimeAnalyticsController::class, 'summary'])
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
