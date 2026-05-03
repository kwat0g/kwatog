<?php

declare(strict_types=1);

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
});
