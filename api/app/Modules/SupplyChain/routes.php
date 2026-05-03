<?php

declare(strict_types=1);

use App\Modules\SupplyChain\Controllers\DeliveryController;
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

    /* ─── Shipment documents ─── */
    Route::post('/shipments/{shipment}/documents',          [ShipmentController::class, 'uploadDocument'])
        ->middleware('permission:supply_chain.shipments.manage');
    Route::delete('/shipment-documents/{document}',         [ShipmentController::class, 'destroyDocument'])
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
    Route::post('/deliveries/{delivery}/confirm',           [DeliveryController::class, 'confirm'])
        ->middleware('permission:supply_chain.deliveries.confirm');
    Route::delete('/deliveries/{delivery}',                 [DeliveryController::class, 'destroy'])
        ->middleware('permission:supply_chain.deliveries.create');
});
