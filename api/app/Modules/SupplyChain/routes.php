<?php

declare(strict_types=1);

use App\Modules\SupplyChain\Controllers\DeliveryController;
use App\Modules\SupplyChain\Controllers\DeliveryProofController;
use App\Modules\SupplyChain\Controllers\DriverDeliveryController;
use App\Modules\SupplyChain\Controllers\ImpexDocumentController;
use App\Modules\SupplyChain\Controllers\ShipmentController;
use App\Modules\SupplyChain\Controllers\VehicleController;
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

    /* ─── OGAMI-104 — Landed cost calculation ─── */
    Route::post('/shipments/{shipment}/calculate-landed-cost', [ShipmentController::class, 'calculateLandedCost'])
        ->middleware('permission:supply_chain.shipments.manage');

    /* ─── ImpEx document PDF generation (packing list + commercial invoice) ─── */
    Route::get('/shipments/{shipment}/packing-list',       [ImpexDocumentController::class, 'packingList'])
        ->middleware('permission:supply_chain.view');
    Route::get('/shipments/{shipment}/commercial-invoice',  [ImpexDocumentController::class, 'commercialInvoice'])
        ->middleware('permission:supply_chain.view');

    /* ─── Shipment documents ─── */
    Route::post('/shipments/{shipment}/documents',          [ShipmentController::class, 'uploadDocument'])
        ->middleware('permission:supply_chain.shipments.manage');
    Route::get('/shipment-documents/{document}/download',   [ShipmentController::class, 'downloadDocument'])
        ->middleware('permission:supply_chain.view');
    Route::delete('/shipment-documents/{document}',         [ShipmentController::class, 'destroyDocument'])
        ->middleware('permission:supply_chain.shipments.manage');

    /* ─── Containers (multi-container shipment tracking) ─── */
    Route::get('/shipments/{shipment}/containers',              [\App\Modules\SupplyChain\Controllers\ContainerController::class, 'index'])
        ->middleware('permission:supply_chain.view');
    Route::post('/shipments/{shipment}/containers',             [\App\Modules\SupplyChain\Controllers\ContainerController::class, 'store'])
        ->middleware('permission:supply_chain.shipments.manage');
    Route::get('/containers/{container}',                        [\App\Modules\SupplyChain\Controllers\ContainerController::class, 'show'])
        ->middleware('permission:supply_chain.view');
    Route::put('/containers/{container}',                        [\App\Modules\SupplyChain\Controllers\ContainerController::class, 'update'])
        ->middleware('permission:supply_chain.shipments.manage');
    Route::delete('/containers/{container}',                     [\App\Modules\SupplyChain\Controllers\ContainerController::class, 'destroy'])
        ->middleware('permission:supply_chain.shipments.manage');

    /* ─── Vehicles (Task 66) ─── */
    Route::get('/vehicles',                                 [VehicleController::class, 'index'])
        ->middleware('permission:supply_chain.view');
    Route::post('/vehicles',                                [VehicleController::class, 'store'])
        ->middleware('permission:supply_chain.fleet.manage');
    Route::patch('/vehicles/{vehicle}',                     [VehicleController::class, 'update'])
        ->middleware('permission:supply_chain.fleet.manage');
    Route::delete('/vehicles/{vehicle}',                    [VehicleController::class, 'destroy'])
        ->middleware('permission:supply_chain.fleet.manage');

    /* ─── Deliveries (Task 66) ─── */
    Route::get('/deliveries',                               [DeliveryController::class, 'index'])
        ->middleware('permission:supply_chain.view');
    Route::get('/deliveries/{delivery}',                    [DeliveryController::class, 'show'])
        ->middleware('permission:supply_chain.view');
    Route::post('/deliveries',                              [DeliveryController::class, 'store'])
        ->middleware('permission:supply_chain.deliveries.create');
    Route::patch('/deliveries/{delivery}/status',           [DeliveryController::class, 'updateStatus'])
        ->middleware('permission:supply_chain.deliveries.create');
    Route::post('/deliveries/{delivery}/receipt',           [DeliveryController::class, 'uploadReceipt'])
        ->middleware('permission:supply_chain.deliveries.create');
    Route::get('/deliveries/{delivery}/receipt-photo',      [DeliveryController::class, 'receiptPhoto'])
        ->middleware('permission:supply_chain.view');
    Route::post('/deliveries/{delivery}/confirm',           [DeliveryController::class, 'confirm'])
        ->middleware('permission:supply_chain.deliveries.confirm');
    Route::delete('/deliveries/{delivery}',                 [DeliveryController::class, 'destroy'])
        ->middleware('permission:supply_chain.deliveries.create');

    /* ─── ADV7 — Proof of Delivery (multi-file) ─── */
    Route::get('/deliveries/{delivery}/proofs',                       [DeliveryProofController::class, 'index'])
        ->middleware('permission:supply_chain.view');
    Route::post('/deliveries/{delivery}/proofs',                      [DeliveryProofController::class, 'store'])
        ->middleware('permission:supply_chain.deliveries.create');
    Route::get('/deliveries/{delivery}/proofs/{proof}/view',          [DeliveryProofController::class, 'view'])
        ->middleware('permission:supply_chain.view');
    Route::delete('/deliveries/{delivery}/proofs/{proof}',            [DeliveryProofController::class, 'destroy'])
        ->middleware('permission:supply_chain.deliveries.create');
});

/* ─── Driver self-service surface (T2.5) ──────────────────────── */
Route::prefix('driver')
    ->middleware(['auth:sanctum', 'session.timeout'])
    ->group(function (): void {
        Route::get('/deliveries',                       [DriverDeliveryController::class, 'index']);
        Route::get('/deliveries/{delivery}',            [DriverDeliveryController::class, 'show']);
        Route::patch('/deliveries/{delivery}/status',   [DriverDeliveryController::class, 'updateStatus']);
        Route::post('/deliveries/{delivery}/receipt',   [DriverDeliveryController::class, 'uploadReceipt']);
    });
