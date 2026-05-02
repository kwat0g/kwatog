<?php

declare(strict_types=1);

use App\Modules\Production\Controllers\OeeController;
use App\Modules\Production\Controllers\WorkOrderController;
use Illuminate\Support\Facades\Route;

/*
 * Production module routes — Sprint 6 Tasks 51, 55–58.
 * Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.
 */

Route::middleware(['auth:sanctum', 'feature:production'])->prefix('production')->group(function () {

    /* ─── Work orders (Task 51) ─── */
    Route::get('/work-orders',                       [WorkOrderController::class, 'index']) ->middleware('permission:production.work_orders.view');
    Route::get('/work-orders/{workOrder}',           [WorkOrderController::class, 'show'])  ->middleware('permission:production.work_orders.view');
    Route::get('/work-orders/{workOrder}/chain',     [WorkOrderController::class, 'chain']) ->middleware('permission:production.work_orders.view');
    Route::post('/work-orders',                      [WorkOrderController::class, 'store']) ->middleware('permission:production.wo.create');
    Route::delete('/work-orders/{workOrder}',        [WorkOrderController::class, 'destroy'])->middleware('permission:production.wo.create');
    Route::post('/work-orders/{workOrder}/confirm',  [WorkOrderController::class, 'confirm'])->middleware('permission:production.wo.confirm');
    Route::post('/work-orders/{workOrder}/start',    [WorkOrderController::class, 'start'])  ->middleware('permission:production.work_orders.lifecycle');
    Route::post('/work-orders/{workOrder}/pause',    [WorkOrderController::class, 'pause'])  ->middleware('permission:production.work_orders.lifecycle');
    Route::post('/work-orders/{workOrder}/resume',   [WorkOrderController::class, 'resume']) ->middleware('permission:production.work_orders.lifecycle');
    Route::post('/work-orders/{workOrder}/complete', [WorkOrderController::class, 'complete'])->middleware('permission:production.work_orders.lifecycle');
    Route::post('/work-orders/{workOrder}/close',    [WorkOrderController::class, 'close'])  ->middleware('permission:production.work_orders.lifecycle');
    Route::post('/work-orders/{workOrder}/cancel',   [WorkOrderController::class, 'cancel']) ->middleware('permission:production.work_orders.lifecycle');

    /* ─── Output recording (Task 55) ─── */
    Route::get('/work-orders/{workOrder}/outputs',   [WorkOrderController::class, 'listOutputs'])->middleware('permission:production.work_orders.view');
    Route::post('/work-orders/{workOrder}/outputs',  [WorkOrderController::class, 'recordOutput'])->middleware('permission:production.wo.record');

    /* ─── OEE (Task 57) ─── */
    Route::get('/oee/machine/{machine}', [OeeController::class, 'forMachine'])->middleware('permission:production.dashboard.view');
    Route::get('/oee/today',             [OeeController::class, 'todayAll']) ->middleware('permission:production.dashboard.view');
});
