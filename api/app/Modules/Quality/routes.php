<?php

declare(strict_types=1);

use App\Modules\Quality\Controllers\InspectionController;
use App\Modules\Quality\Controllers\InspectionSpecController;
use App\Modules\Quality\Controllers\NcrController;
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

    /* ─── Inspections (Task 60) ─── */
    Route::get('/inspections',                                  [InspectionController::class, 'index'])
        ->middleware('permission:quality.inspections.view');
    Route::get('/inspections/aql-preview',                      [InspectionController::class, 'aqlPreview'])
        ->middleware('permission:quality.inspections.view');
    Route::get('/inspections/{inspection}',                     [InspectionController::class, 'show'])
        ->middleware('permission:quality.inspections.view');
    Route::post('/inspections',                                 [InspectionController::class, 'store'])
        ->middleware('permission:quality.inspections.manage');
    Route::patch('/inspections/{inspection}/measurements',      [InspectionController::class, 'recordMeasurements'])
        ->middleware('permission:quality.inspections.manage');
    Route::post('/inspections/{inspection}/complete',           [InspectionController::class, 'complete'])
        ->middleware('permission:quality.inspections.manage');
    Route::post('/inspections/{inspection}/cancel',             [InspectionController::class, 'cancel'])
        ->middleware('permission:quality.inspections.manage');

    /* ─── NCRs (Task 61) ─── */
    Route::get('/ncrs',                                         [NcrController::class, 'index'])
        ->middleware('permission:quality.ncr.view');
    Route::get('/ncrs/{ncr}',                                   [NcrController::class, 'show'])
        ->middleware('permission:quality.ncr.view');
    Route::post('/ncrs',                                        [NcrController::class, 'store'])
        ->middleware('permission:quality.ncr.manage');
    Route::post('/ncrs/{ncr}/actions',                          [NcrController::class, 'addAction'])
        ->middleware('permission:quality.ncr.manage');
    Route::patch('/ncrs/{ncr}/disposition',                     [NcrController::class, 'setDisposition'])
        ->middleware('permission:quality.ncr.manage');
    Route::post('/ncrs/{ncr}/close',                            [NcrController::class, 'close'])
        ->middleware('permission:quality.ncr.manage');
    Route::post('/ncrs/{ncr}/cancel',                           [NcrController::class, 'cancel'])
        ->middleware('permission:quality.ncr.manage');
});
