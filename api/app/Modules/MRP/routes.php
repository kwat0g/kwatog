<?php

declare(strict_types=1);

use App\Modules\MRP\Controllers\BomController;
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
});
