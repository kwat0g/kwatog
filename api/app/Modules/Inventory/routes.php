<?php

declare(strict_types=1);

use App\Modules\Inventory\Controllers\GoodsReceiptNoteController;
use App\Modules\Inventory\Controllers\InventoryDashboardController;
use App\Modules\Inventory\Controllers\ItemCategoryController;
use App\Modules\Inventory\Controllers\ItemController;
use App\Modules\Inventory\Controllers\MaterialIssueSlipController;
use App\Modules\Inventory\Controllers\StockAdjustmentController;
use App\Modules\Inventory\Controllers\StockCardController;
use App\Modules\Inventory\Controllers\StockCountController;
use App\Modules\Inventory\Controllers\StockLevelController;
use App\Modules\Inventory\Controllers\StockMovementController;
use App\Modules\Inventory\Controllers\StockTransferController;
use App\Modules\Inventory\Controllers\TransferOrderController;
use App\Modules\Inventory\Controllers\WarehouseController;
use App\Modules\Inventory\Controllers\WarehouseMapController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:inventory'])->prefix('inventory')->group(function () {

    /* ─── Item categories ─── */
    Route::get('/item-categories',           [ItemCategoryController::class, 'index'])->middleware('permission:inventory.view');
    Route::get('/item-categories/tree',      [ItemCategoryController::class, 'tree']) ->middleware('permission:inventory.view');
    Route::post('/item-categories',          [ItemCategoryController::class, 'store'])->middleware('permission:inventory.items.manage');
    Route::put('/item-categories/{itemCategory}',    [ItemCategoryController::class, 'update'])->middleware('permission:inventory.items.manage');
    Route::delete('/item-categories/{itemCategory}', [ItemCategoryController::class, 'destroy'])->middleware('permission:inventory.items.manage');

    /* ─── Items ─── */
    Route::get('/items',           [ItemController::class, 'index'])->middleware('permission:inventory.view');
    Route::get('/items/{item}',    [ItemController::class, 'show']) ->middleware('permission:inventory.view');
    Route::post('/items',          [ItemController::class, 'store'])->middleware('permission:inventory.items.manage');
    Route::put('/items/{item}',    [ItemController::class, 'update'])->middleware('permission:inventory.items.manage');
    Route::delete('/items/{item}', [ItemController::class, 'destroy'])->middleware('permission:inventory.items.manage');

    /* ─── Series F / Task F3 — Stock Card ─── */
    Route::get('/items/{item}/stock-card', [StockCardController::class, 'show'])
        ->middleware('permission:inventory.view');

    /* ─── Warehouse / Zones / Locations ─── */
    Route::get('/warehouse',                 [WarehouseController::class, 'tree'])->middleware('permission:inventory.view');
    Route::get('/warehouses',                [WarehouseController::class, 'indexWarehouses'])->middleware('permission:inventory.view');
    Route::post('/warehouses',               [WarehouseController::class, 'storeWarehouse'])->middleware('permission:inventory.items.manage');
    Route::put('/warehouses/{warehouse}',    [WarehouseController::class, 'updateWarehouse'])->middleware('permission:inventory.items.manage');
    Route::delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroyWarehouse'])->middleware('permission:inventory.items.manage');

    Route::post('/zones',           [WarehouseController::class, 'storeZone'])->middleware('permission:inventory.items.manage');
    Route::put('/zones/{zone}',     [WarehouseController::class, 'updateZone'])->middleware('permission:inventory.items.manage');
    Route::delete('/zones/{zone}',  [WarehouseController::class, 'destroyZone'])->middleware('permission:inventory.items.manage');

    Route::post('/locations',                [WarehouseController::class, 'storeLocation'])->middleware('permission:inventory.items.manage');
    Route::put('/locations/{location}',      [WarehouseController::class, 'updateLocation'])->middleware('permission:inventory.items.manage');
    Route::delete('/locations/{location}',   [WarehouseController::class, 'destroyLocation'])->middleware('permission:inventory.items.manage');

    /* ─── Stock ─── */
    Route::get('/stock-levels',     [StockLevelController::class, 'index'])->middleware('permission:inventory.view');
    Route::get('/stock-movements',  [StockMovementController::class, 'index'])->middleware('permission:inventory.view');
    Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store'])->middleware('permission:inventory.adjust');
    Route::post('/stock-transfers',   [StockTransferController::class, 'store'])->middleware('permission:inventory.adjust');

    /* ─── ADV8 — WMS: Warehouse Map ─── */
    Route::get('/warehouse-map',          [WarehouseMapController::class, 'index'])->middleware('permission:inventory.view');
    Route::get('/warehouse-map/bins/{id}', [WarehouseMapController::class, 'binDetail'])->middleware('permission:inventory.view');

    /* ─── ADV8 — WMS: Stock Count ─── */
    Route::get('/stock-counts',               [StockCountController::class, 'index'])->middleware('permission:inventory.stock_count.view');
    Route::get('/stock-counts/{id}',           [StockCountController::class, 'show'])->middleware('permission:inventory.stock_count.view');
    Route::post('/stock-counts',               [StockCountController::class, 'store'])->middleware('permission:inventory.stock_count.manage');
    Route::post('/stock-counts/{id}/start',    [StockCountController::class, 'start'])->middleware('permission:inventory.stock_count.manage');
    Route::post('/stock-counts/items/{id}/count',  [StockCountController::class, 'recordCount'])->middleware('permission:inventory.stock_count.manage');
    Route::post('/stock-counts/items/{id}/approve', [StockCountController::class, 'approveVariance'])->middleware('permission:inventory.stock_count.manage');
    Route::post('/stock-counts/{id}/complete', [StockCountController::class, 'complete'])->middleware('permission:inventory.stock_count.manage');
    Route::delete('/stock-counts/{id}',        [StockCountController::class, 'cancel'])->middleware('permission:inventory.stock_count.manage');

    /* ─── ADV8 — WMS: Transfer Orders ─── */
    Route::get('/transfer-orders',          [TransferOrderController::class, 'index'])->middleware('permission:inventory.view');
    Route::get('/transfer-orders/{id}',     [TransferOrderController::class, 'show'])->middleware('permission:inventory.view');
    Route::post('/transfer-orders',          [TransferOrderController::class, 'store'])->middleware('permission:inventory.adjust');
    Route::post('/transfer-orders/{id}/execute', [TransferOrderController::class, 'execute'])->middleware('permission:inventory.adjust');
    Route::delete('/transfer-orders/{id}',   [TransferOrderController::class, 'cancel'])->middleware('permission:inventory.adjust');

    /* ─── ADV8 — WMS: Picking List ─── */
    Route::get('/picking-lists/mis/{id}', [WarehouseMapController::class, 'pickingList'])->middleware('permission:inventory.view');

    /* ─── GRN ─── */
    Route::get('/grn',                  [GoodsReceiptNoteController::class, 'index'])->middleware('permission:inventory.view');
    Route::get('/grn/{grn}',            [GoodsReceiptNoteController::class, 'show']) ->middleware('permission:inventory.view');
    Route::post('/grn',                 [GoodsReceiptNoteController::class, 'store'])->middleware('permission:inventory.grn.create');
    Route::patch('/grn/{grn}/accept',   [GoodsReceiptNoteController::class, 'accept'])->middleware('permission:inventory.grn.create');
    Route::patch('/grn/{grn}/reject',   [GoodsReceiptNoteController::class, 'reject'])->middleware('permission:inventory.grn.create');

    /* ─── CA2 — Single-screen receiving (GRN + QC + inventory in one call) ─── */
    Route::post('/receive-goods', [GoodsReceiptNoteController::class, 'receiveWithQc'])->middleware('permission:inventory.grn.create');

    /* ─── Material Issue ─── */
    Route::get('/material-issues',        [MaterialIssueSlipController::class, 'index'])->middleware('permission:inventory.view');
    Route::get('/material-issues/{materialIssueSlip}', [MaterialIssueSlipController::class, 'show'])->middleware('permission:inventory.view');
    Route::post('/material-issues',       [MaterialIssueSlipController::class, 'store'])->middleware('permission:inventory.issue.create');

    /* ─── Dashboard ─── */
    Route::get('/dashboard', [InventoryDashboardController::class, 'index'])->middleware('permission:inventory.view');
});
