<?php

declare(strict_types=1);

use App\Modules\Quality\Controllers\AnalyticsController;
use App\Modules\Quality\Controllers\CalibrationController;
use App\Modules\Quality\Controllers\CopqController;
use App\Modules\Quality\Controllers\DocumentAcknowledgmentController;
use App\Modules\Quality\Controllers\DocumentController;
use App\Modules\Quality\Controllers\InspectionController;
use App\Modules\Quality\Controllers\InspectionSpecController;
use App\Modules\Quality\Controllers\NcrController;
use App\Modules\Quality\Controllers\EffectivenessController;
use App\Modules\Quality\Controllers\NcrTemplateController;
use App\Modules\Quality\Controllers\PpapController;
use App\Modules\Quality\Controllers\ShipmentLotController;
use App\Modules\Quality\Controllers\SpcController;
use App\Modules\Quality\Controllers\TraceabilityController;
use Illuminate\Support\Facades\Route;

/*
 * Quality module routes — Sprint 7 Tasks 59+.
 * Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.
 */

Route::middleware(['auth:sanctum', 'feature:quality'])->prefix('quality')->group(function () {

    /* ─── OGAMI-016 — IATF calibration register ─── */
    Route::get('/calibration',                          [CalibrationController::class, 'index'])
        ->middleware('permission:quality.calibration.view');
    Route::post('/calibration',                         [CalibrationController::class, 'store'])
        ->middleware('permission:quality.calibration.manage');
    Route::get('/calibration/{calibrationRecord}',      [CalibrationController::class, 'show'])
        ->middleware('permission:quality.calibration.view');
    Route::patch('/calibration/{calibrationRecord}',    [CalibrationController::class, 'update'])
        ->middleware('permission:quality.calibration.manage');
    Route::post('/calibration/{calibrationRecord}/record', [CalibrationController::class, 'recordCalibration'])
        ->middleware('permission:quality.calibration.manage');

    /* ─── Inspection specs (Task 59) ─── */
    Route::get('/inspection-specs',                       [InspectionSpecController::class, 'index'])
        ->middleware('permission:quality.specs.view');
    Route::get('/inspection-specs/{inspectionSpec}',      [InspectionSpecController::class, 'show'])
        ->middleware('permission:quality.specs.view');
    Route::post('/inspection-specs',                      [InspectionSpecController::class, 'upsert'])
        ->middleware('permission:quality.specs.manage');
    Route::delete('/inspection-specs/{inspectionSpec}',   [InspectionSpecController::class, 'destroy'])
        ->middleware('permission:quality.specs.manage');
    Route::get('/inspection-specs/{inspectionSpec}/spc', [InspectionSpecController::class, 'spcData'])
        ->middleware('permission:quality.specs.view');

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
    Route::get('/inspections/{inspection}/coc',                 [InspectionController::class, 'coc'])
        ->middleware('permission:quality.inspections.view');

    /* ─── ADV7 — NCR templates ─── */
    Route::get('/ncr-templates/active',                        [NcrTemplateController::class, 'active'])
        ->middleware('permission:quality.ncr.view');
    Route::get('/ncr-templates',                                [NcrTemplateController::class, 'index'])
        ->middleware('permission:quality.ncr.view');
    Route::get('/ncr-templates/{ncrTemplate}',                  [NcrTemplateController::class, 'show'])
        ->middleware('permission:quality.ncr.view');
    Route::post('/ncr-templates',                               [NcrTemplateController::class, 'store'])
        ->middleware('permission:quality.ncr.manage');
    Route::patch('/ncr-templates/{ncrTemplate}',                 [NcrTemplateController::class, 'update'])
        ->middleware('permission:quality.ncr.manage');
    Route::delete('/ncr-templates/{ncrTemplate}',               [NcrTemplateController::class, 'destroy'])
        ->middleware('permission:quality.ncr.manage');

    /* ─── NCRs (Task 61) ─── */
    Route::get('/ncrs',                                         [NcrController::class, 'index'])
        ->middleware('permission:quality.ncr.view');

    // Series F / Task F6 — Bulk close NCRs (must precede /ncrs/{ncr}).
    Route::post('/ncrs/bulk-close',                             [NcrController::class, 'bulkClose'])
        ->middleware('permission:quality.ncr.manage');

    // CAPA effectiveness — literal segment, must precede /ncrs/{ncr}.
    Route::get('/ncrs/effectiveness/due',                       [EffectivenessController::class, 'dueIndex'])
        ->middleware('permission:quality.ncr.view');

    Route::get('/ncrs/{ncr}',                                   [NcrController::class, 'show'])
        ->middleware('permission:quality.ncr.view');
    Route::post('/ncrs',                                        [NcrController::class, 'store'])
        ->middleware('permission:quality.ncr.manage');
    Route::post('/ncrs/{ncr}/actions',                          [NcrController::class, 'addAction'])
        ->middleware('permission:quality.ncr.manage');
    // CAPA — record an effectiveness verdict on a corrective/preventive action.
    Route::patch('/ncrs/{ncr}/actions/{action}/verify',        [EffectivenessController::class, 'verify'])
        ->middleware('permission:quality.ncr.manage');
    Route::patch('/ncrs/{ncr}/disposition',                     [NcrController::class, 'setDisposition'])
        ->middleware('permission:quality.ncr.manage');
    Route::post('/ncrs/{ncr}/close',                            [NcrController::class, 'close'])
        ->middleware('permission:quality.ncr.manage');
    Route::post('/ncrs/{ncr}/cancel',                           [NcrController::class, 'cancel'])
        ->middleware('permission:quality.ncr.manage');

    /* ─── Analytics (Task 63) ─── */
    Route::get('/analytics/defect-pareto',                      [AnalyticsController::class, 'defectPareto'])
        ->middleware('permission:quality.view');
    Route::get('/analytics/defect-pareto/drill',                [AnalyticsController::class, 'paretoDrillDown'])
        ->middleware('permission:quality.view');

    /* ─── T3.6.B — COPQ rollup trend ─── */
    Route::get('/copq/trend',                                   [CopqController::class, 'trend'])
        ->middleware('permission:quality.copq.view');
    Route::get('/copq/summary',                                 [CopqController::class, 'summary'])
        ->middleware('permission:quality.copq.view');
    Route::get('/copq/by-product',                              [CopqController::class, 'byProduct'])
        ->middleware('permission:quality.copq.view');
    Route::get('/copq/by-supplier',                             [CopqController::class, 'bySupplier'])
        ->middleware('permission:quality.copq.view');

    /* ─── ADV3 — IATF 16949 traceability (batch + lot search, shipment lots) ─── */
    Route::get('/traceability/search', [TraceabilityController::class, 'search'])
        ->middleware('permission:quality.inspections.view');
    Route::get('/traceability/recall-simulation', [TraceabilityController::class, 'recallSimulation'])
        ->middleware('permission:quality.inspections.view');

    Route::get('/traceability/deliveries/{delivery}/shipment-lot',
        [ShipmentLotController::class, 'showForDelivery'])
        ->middleware('permission:quality.inspections.view');
    Route::post('/traceability/deliveries/{delivery}/shipment-lot',
        [ShipmentLotController::class, 'createForDelivery'])
        ->middleware('permission:quality.inspections.manage');
    Route::get('/traceability/shipment-lots/{shipmentLot}',
        [ShipmentLotController::class, 'show'])
        ->middleware('permission:quality.inspections.view');

    /* ─── T3.5 — Controlled documents (admin) ─── */
    Route::get('/documents',                                  [DocumentController::class, 'index'])
        ->middleware('permission:quality.documents.view');
    Route::post('/documents',                                 [DocumentController::class, 'store'])
        ->middleware('permission:quality.documents.manage');
    Route::get('/documents/{document}',                       [DocumentController::class, 'show'])
        ->middleware('permission:quality.documents.view');
    Route::patch('/documents/{document}',                     [DocumentController::class, 'update'])
        ->middleware('permission:quality.documents.manage');
    Route::post('/documents/{document}/revisions',            [DocumentController::class, 'publishRevision'])
        ->middleware('permission:quality.documents.manage');
    Route::post('/documents/{document}/mark-reviewed',        [DocumentController::class, 'markReviewed'])
        ->middleware('permission:quality.documents.manage');

    /* ─── PPAP & APQP tracking (IATF 16949) ─── */
    Route::get('/ppap',                       [PpapController::class, 'index'])  ->middleware('permission:quality.ppap.view');
    Route::post('/ppap',                      [PpapController::class, 'store'])  ->middleware('permission:quality.ppap.manage');
    Route::get('/ppap/{ppap}',                [PpapController::class, 'show'])   ->middleware('permission:quality.ppap.view');
    Route::put('/ppap/{ppap}',                [PpapController::class, 'update']) ->middleware('permission:quality.ppap.manage');
    Route::patch('/ppap/{ppap}/submit',       [PpapController::class, 'submit']) ->middleware('permission:quality.ppap.manage');
    Route::patch('/ppap/{ppap}/review',       [PpapController::class, 'review']) ->middleware('permission:quality.ppap.manage');
    Route::patch('/ppap/{ppap}/approve',      [PpapController::class, 'approve'])->middleware('permission:quality.ppap.manage');
    Route::patch('/ppap/{ppap}/reject',       [PpapController::class, 'reject']) ->middleware('permission:quality.ppap.manage');
    Route::patch('/ppap/{ppap}/elements/{element}', [PpapController::class, 'updateElement'])->middleware('permission:quality.ppap.manage');

    /* ─── SPC — Statistical Process Control ─── */
    Route::get('/spc/charts',                      [SpcController::class, 'index'])           ->middleware('permission:quality.spc.view');
    Route::post('/spc/charts',                     [SpcController::class, 'store'])           ->middleware('permission:quality.spc.manage');
    Route::get('/spc/charts/{chart}',              [SpcController::class, 'show'])            ->middleware('permission:quality.spc.view');
    Route::get('/spc/charts/{chart}/data',         [SpcController::class, 'data'])            ->middleware('permission:quality.spc.view');
    Route::post('/spc/charts/{chart}/recalculate', [SpcController::class, 'recalculate'])     ->middleware('permission:quality.spc.manage');
    Route::post('/spc/capability',                 [SpcController::class, 'capability'])      ->middleware('permission:quality.spc.view');
    Route::get('/spc/alerts',                      [SpcController::class, 'alerts'])          ->middleware('permission:quality.spc.view');
    Route::post('/spc/alerts/{alert}/acknowledge', [SpcController::class, 'acknowledgeAlert'])->middleware('permission:quality.spc.manage');
});

/* ─── T3.5.C — Self-service document acknowledgments ─── */
Route::middleware(['auth:sanctum'])->prefix('self-service/documents')->group(function () {
    Route::get('/pending',                  [DocumentAcknowledgmentController::class, 'pending']);
    Route::post('/{revision}/acknowledge',  [DocumentAcknowledgmentController::class, 'acknowledge']);
});
