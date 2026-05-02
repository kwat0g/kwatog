<?php

declare(strict_types=1);

use App\Modules\MRP\Controllers\BomController;
use App\Modules\MRP\Controllers\MachineController;
use App\Modules\MRP\Controllers\MoldController;
use App\Modules\MRP\Controllers\MrpPlanController;
use Illuminate\Support\Facades\Route;

/*
 * MRP module routes — Sprint 6 Tasks 49–53.
 * Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.
 */

Route::middleware(['auth:sanctum', 'feature:mrp'])->prefix('mrp')->group(function () {

    /* ─── Bills of materials (Task 49) ─── */
    Route::get('/boms',                   [BomController::class, 'index']) ->middleware('permission:mrp.boms.view');
    Route::get('/boms/{bom}',             [BomController::class, 'show'])  ->middleware('permission:mrp.boms.view');
    Route::post('/boms',                  [BomController::class, 'store']) ->middleware('permission:mrp.boms.manage');
    Route::put('/boms/{bom}',             [BomController::class, 'update'])->middleware('permission:mrp.boms.manage');
    Route::delete('/boms/{bom}',          [BomController::class, 'destroy'])->middleware('permission:mrp.boms.manage');

    Route::get('/products/{product}/bom', [BomController::class, 'forProduct'])->middleware('permission:mrp.boms.view');

    /* ─── Machines (Task 50) ─── */
    Route::get('/machines',           [MachineController::class, 'index']) ->middleware('permission:mrp.machines.view');
    Route::get('/machines/{machine}', [MachineController::class, 'show'])  ->middleware('permission:mrp.machines.view');
    Route::post('/machines',          [MachineController::class, 'store']) ->middleware('permission:production.machines.manage');
    Route::put('/machines/{machine}', [MachineController::class, 'update'])->middleware('permission:production.machines.manage');
    Route::delete('/machines/{machine}', [MachineController::class, 'destroy'])->middleware('permission:production.machines.manage');
    Route::patch('/machines/{machine}/transition-status', [MachineController::class, 'transitionStatus'])
        ->middleware('permission:production.machines.transition');

    /* ─── Molds (Task 50) ─── */
    Route::get('/molds',           [MoldController::class, 'index']) ->middleware('permission:mrp.molds.view');
    Route::get('/molds/{mold}',    [MoldController::class, 'show'])  ->middleware('permission:mrp.molds.view');
    Route::get('/molds/{mold}/history',          [MoldController::class, 'history']) ->middleware('permission:mrp.molds.view');
    Route::get('/products/{product}/molds',      [MoldController::class, 'byProduct'])->middleware('permission:mrp.molds.view');
    Route::post('/molds',          [MoldController::class, 'store']) ->middleware('permission:production.molds.manage');
    Route::put('/molds/{mold}',    [MoldController::class, 'update'])->middleware('permission:production.molds.manage');
    Route::delete('/molds/{mold}', [MoldController::class, 'destroy'])->middleware('permission:production.molds.manage');
    Route::post('/molds/{mold}/compatibility', [MoldController::class, 'syncCompatibility'])
        ->middleware('permission:production.molds.manage');

    /* ─── MRP plans (Task 52) ─── */
    Route::get('/plans',                    [MrpPlanController::class, 'index']) ->middleware('permission:mrp.plans.view');
    Route::get('/plans/{mrpPlan}',          [MrpPlanController::class, 'show']) ->middleware('permission:mrp.plans.view');
    Route::post('/plans/{mrpPlan}/rerun',   [MrpPlanController::class, 'rerun'])->middleware('permission:mrp.plans.run');
    Route::get('/sales-orders/{salesOrder}/mrp-plan', [MrpPlanController::class, 'forSalesOrder'])
        ->middleware('permission:mrp.plans.view');
});
