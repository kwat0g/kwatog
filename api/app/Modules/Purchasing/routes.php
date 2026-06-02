<?php

declare(strict_types=1);

use App\Modules\Purchasing\Controllers\ApprovedSupplierController;
use App\Modules\Purchasing\Controllers\ProcurementChainController;
use App\Modules\Purchasing\Controllers\PurchaseOrderController;
use App\Modules\Purchasing\Controllers\PurchaseRequestController;
use App\Modules\Purchasing\Controllers\PurchaseRequestTemplateController;
use App\Modules\Purchasing\Controllers\SupplierPerformanceController;
use App\Modules\Purchasing\Controllers\ThreeWayMatchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:purchasing'])->prefix('purchasing')->group(function () {

    /* ─── ADV5 — Procurement Chain overview ─── */
    Route::get('/chain', [ProcurementChainController::class, 'index'])->middleware('permission:purchasing.view');


    /* ─── Purchase Requests ─── */
    Route::get('/purchase-requests',       [PurchaseRequestController::class, 'index'])->middleware('permission:purchasing.view');
    // Static routes (no {purchaseRequest} param) must come BEFORE the wildcard.
    Route::get('/purchase-requests/pending-count', [PurchaseRequestController::class, 'pendingCount'])->middleware('permission:purchasing.pr.approve');
    Route::post('/purchase-requests/bulk-approve', [PurchaseRequestController::class, 'bulkApprove'])->middleware('permission:purchasing.pr.approve');
    Route::get('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'show'])->middleware('permission:purchasing.view');
    // Sprint P9 — printable PR with 4-tier approval signature block.
    Route::get('/purchase-requests/{purchaseRequest}/pdf', [PurchaseRequestController::class, 'printPdf'])->middleware('permission:purchasing.view');
    Route::post('/purchase-requests',      [PurchaseRequestController::class, 'store'])->middleware('permission:purchasing.pr.create');
    Route::put('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'update'])->middleware('permission:purchasing.pr.create');
    Route::delete('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'destroy'])->middleware('permission:purchasing.pr.create');

    Route::patch('/purchase-requests/{purchaseRequest}/submit',  [PurchaseRequestController::class, 'submit'])->middleware('permission:purchasing.pr.create');
    Route::patch('/purchase-requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve'])->middleware('permission:purchasing.pr.approve');
    Route::patch('/purchase-requests/{purchaseRequest}/reject',  [PurchaseRequestController::class, 'reject'])->middleware('permission:purchasing.pr.approve');
    Route::patch('/purchase-requests/{purchaseRequest}/cancel',  [PurchaseRequestController::class, 'cancel'])->middleware('permission:purchasing.pr.create');
    Route::post('/purchase-requests/{purchaseRequest}/convert',  [PurchaseRequestController::class, 'convert'])->middleware('permission:purchasing.po.create');

    /* ─── PR Templates ─── */
    Route::get('/pr-templates',                     [PurchaseRequestTemplateController::class, 'index'])->middleware('permission:purchasing.view');
    Route::get('/pr-templates/active',              [PurchaseRequestTemplateController::class, 'active'])->middleware('permission:purchasing.view');
    Route::get('/pr-templates/{template}',           [PurchaseRequestTemplateController::class, 'show'])->middleware('permission:purchasing.view');
    Route::post('/pr-templates',                    [PurchaseRequestTemplateController::class, 'store'])->middleware('permission:purchasing.pr.create');
    Route::put('/pr-templates/{template}',           [PurchaseRequestTemplateController::class, 'update'])->middleware('permission:purchasing.pr.create');
    Route::delete('/pr-templates/{template}',        [PurchaseRequestTemplateController::class, 'destroy'])->middleware('permission:purchasing.pr.create');

    /* ─── Purchase Orders ─── */
    Route::get('/purchase-orders',       [PurchaseOrderController::class, 'index'])->middleware('permission:purchasing.view');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchasing.view');
    Route::post('/purchase-orders',      [PurchaseOrderController::class, 'store'])->middleware('permission:purchasing.po.create');
    Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchasing.po.create');
    Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->middleware('permission:purchasing.po.create');
    Route::patch('/purchase-orders/{purchaseOrder}/submit',  [PurchaseOrderController::class, 'submit'])->middleware('permission:purchasing.po.create');
    Route::patch('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:purchasing.po.approve');
    Route::patch('/purchase-orders/{purchaseOrder}/reject',  [PurchaseOrderController::class, 'reject'])->middleware('permission:purchasing.po.approve');
    Route::patch('/purchase-orders/{purchaseOrder}/send',    [PurchaseOrderController::class, 'send'])->middleware('permission:purchasing.po.send');
    Route::patch('/purchase-orders/{purchaseOrder}/cancel',  [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchasing.po.create');
    Route::patch('/purchase-orders/{purchaseOrder}/close',   [PurchaseOrderController::class, 'close'])->middleware('permission:purchasing.po.create');
    Route::get('/purchase-orders/{purchaseOrder}/pdf',       [PurchaseOrderController::class, 'pdf'])->middleware('permission:purchasing.view');

    /* ─── Approved Suppliers ─── */
    Route::get('/approved-suppliers',       [ApprovedSupplierController::class, 'index'])->middleware('permission:purchasing.view');
    Route::post('/approved-suppliers',      [ApprovedSupplierController::class, 'store'])->middleware('permission:purchasing.po.create');
    Route::put('/approved-suppliers/{approvedSupplier}', [ApprovedSupplierController::class, 'update'])->middleware('permission:purchasing.po.create');
    Route::delete('/approved-suppliers/{approvedSupplier}', [ApprovedSupplierController::class, 'destroy'])->middleware('permission:purchasing.po.create');

    /* ─── 3-way match ─── */
    Route::get('/three-way-match/{bill}',   [ThreeWayMatchController::class, 'show'])->middleware('permission:accounting.bills.view');

    /* ─── Series F / Task F4 — Supplier performance dashboard ─── */
    Route::get('/vendors/{vendor}/performance',
        [SupplierPerformanceController::class, 'show'])
        ->middleware('permission:purchasing.suppliers.performance.view');
    Route::post('/vendors/{vendor}/performance/recompute',
        [SupplierPerformanceController::class, 'recompute'])
        ->middleware('permission:purchasing.suppliers.performance.recompute');
});
