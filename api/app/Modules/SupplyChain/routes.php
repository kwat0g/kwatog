<?php

declare(strict_types=1);

use App\Modules\SupplyChain\Controllers\ShipmentController;
use Illuminate\Support\Facades\Route;

/*
 * Supply Chain module routes — Sprint 7 Task 65+.
 * Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.
 */

Route::middleware(['auth:sanctum', 'feature:supply_chain'])->prefix('supply-chain')->group(function () {

    /* ─── Shipments (Task 65) ─── */
    Route::get('/shipments',                                [ShipmentController::class, 'index'])
        ->middleware('permission:supply_chain.view');
    Route::get('/shipments/{shipment}',                     [ShipmentController::class, 'show'])
        ->middleware('permission:supply_chain.view');
    Route::post('/shipments',                               [ShipmentController::class, 'store'])
        ->middleware('permission:supply_chain.shipments.manage');
    Route::patch('/shipments/{shipment}/status',            [ShipmentController::class, 'updateStatus'])
        ->middleware('permission:supply_chain.shipments.manage');
    Route::patch('/shipments/{shipment}',                   [ShipmentController::class, 'updateMeta'])
        ->middleware('permission:supply_chain.shipments.manage');
    Route::delete('/shipments/{shipment}',                  [ShipmentController::class, 'destroy'])
        ->middleware('permission:supply_chain.shipments.manage');

    /* ─── Shipment documents ─── */
    Route::post('/shipments/{shipment}/documents',          [ShipmentController::class, 'uploadDocument'])
        ->middleware('permission:supply_chain.shipments.manage');
    Route::delete('/shipment-documents/{document}',         [ShipmentController::class, 'destroyDocument'])
        ->middleware('permission:supply_chain.shipments.manage');
});
