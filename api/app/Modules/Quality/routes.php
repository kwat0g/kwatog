<?php

declare(strict_types=1);

use App\Modules\Quality\Controllers\InspectionSpecController;
use Illuminate\Support\Facades\Route;

/*
 * Quality module routes — Sprint 7 Tasks 59+.
 * Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.
 */

Route::middleware(['auth:sanctum', 'feature:quality'])->prefix('quality')->group(function () {

    /* ─── Inspection specs (Task 59) ─── */
    Route::get('/inspection-specs',                       [InspectionSpecController::class, 'index'])
        ->middleware('permission:quality.specs.view');
    Route::get('/inspection-specs/{inspectionSpec}',      [InspectionSpecController::class, 'show'])
        ->middleware('permission:quality.specs.view');
    Route::post('/inspection-specs',                      [InspectionSpecController::class, 'upsert'])
        ->middleware('permission:quality.specs.manage');
    Route::delete('/inspection-specs/{inspectionSpec}',   [InspectionSpecController::class, 'destroy'])
        ->middleware('permission:quality.specs.manage');

    Route::get('/products/{product}/inspection-spec',     [InspectionSpecController::class, 'forProduct'])
        ->middleware('permission:quality.specs.view');
});
