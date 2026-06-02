<?php

declare(strict_types=1);

use App\Modules\B2B\Controllers\CustomerAuthController;
use App\Modules\B2B\Controllers\CustomerPortalController;
use App\Modules\B2B\Controllers\SupplierAuthController;
use App\Modules\B2B\Controllers\SupplierPortalController;
use Illuminate\Support\Facades\Route;

/*
 * ADV10 — B2B Portals.
 * Supplier Portal + Customer Portal with separate auth guards.
 * Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.
 */

/* ─── Supplier Portal ─────────────────────────────────────────── */
Route::prefix('b2b/supplier')->group(function () {
    // Public
    Route::post('login',  [SupplierAuthController::class, 'login']);
    Route::post('logout', [SupplierAuthController::class, 'logout']);

    // Authenticated
    Route::middleware('auth:supplier_portal')->group(function () {
        Route::get('me',                                [SupplierAuthController::class, 'me']);
        Route::get('dashboard',                          [SupplierPortalController::class, 'dashboard']);
        Route::get('purchase-orders',                    [SupplierPortalController::class, 'purchaseOrders']);
        Route::get('purchase-orders/{purchaseOrder}',    [SupplierPortalController::class, 'purchaseOrderShow']);
        Route::get('purchase-orders/{purchaseOrder}/pdf', [SupplierPortalController::class, 'poPdf']);
        Route::post('purchase-orders/{purchaseOrder}/acknowledge',  [SupplierPortalController::class, 'acknowledgePo']);
        Route::post('purchase-orders/{purchaseOrder}/shipment-update', [SupplierPortalController::class, 'updateShipment']);
        Route::post('purchase-orders/{purchaseOrder}/shipping-documents', [SupplierPortalController::class, 'uploadShippingDocuments']);
        Route::get('purchase-orders/{purchaseOrder}/shipping-documents', [SupplierPortalController::class, 'shippingDocuments']);
        Route::post('purchase-orders/{purchaseOrder}/submit-invoice', [SupplierPortalController::class, 'submitInvoice']);
        Route::get('shipping-documents/{id}/download',   [SupplierPortalController::class, 'downloadShippingDocument']);
        Route::get('invoices',                           [SupplierPortalController::class, 'invoices']);
        Route::get('invoices/{invoice}',                 [SupplierPortalController::class, 'invoiceDetail']);
        Route::get('invoices/{invoice}/pdf',              [SupplierPortalController::class, 'invoicePdf']);
        Route::get('deliveries',                         [SupplierPortalController::class, 'deliveries']);
        Route::get('statement-of-account',               [SupplierPortalController::class, 'statementOfAccount']);
        Route::get('delivery-schedules',                 [SupplierPortalController::class, 'deliverySchedules']);
        Route::post('delivery-schedules',                [SupplierPortalController::class, 'storeDeliverySchedule']);
    });
});

/* ─── Customer Portal ─────────────────────────────────────────── */
Route::prefix('b2b/customer')->group(function () {
    // Public
    Route::post('login',  [CustomerAuthController::class, 'login']);
    Route::post('logout', [CustomerAuthController::class, 'logout']);

    // Authenticated
    Route::middleware('auth:customer_portal')->group(function () {
        Route::get('me',                                [CustomerAuthController::class, 'me']);
        Route::get('dashboard',                          [CustomerPortalController::class, 'dashboard']);
        Route::get('orders',                             [CustomerPortalController::class, 'salesOrders']);
        Route::get('orders/{salesOrder}',                [CustomerPortalController::class, 'salesOrderShow']);
        Route::get('orders/{salesOrder}/chain',          [CustomerPortalController::class, 'salesOrderChain']);
        Route::get('invoices',                           [CustomerPortalController::class, 'invoices']);
        Route::get('invoices/{invoice}',                 [CustomerPortalController::class, 'invoiceDetail']);
        Route::get('invoices/{invoice}/pdf',              [CustomerPortalController::class, 'invoicePdf']);
        Route::get('deliveries',                         [CustomerPortalController::class, 'deliveries']);
        Route::get('deliveries/{delivery}',              [CustomerPortalController::class, 'deliveryDetail']);
        Route::get('complaints',                         [CustomerPortalController::class, 'complaints']);
        Route::post('complaints',                        [CustomerPortalController::class, 'createComplaint']);
        Route::get('complaints/{complaint}/8d-report',   [CustomerPortalController::class, 'complaint8dReport']);
        Route::get('statement-of-account',               [CustomerPortalController::class, 'statementOfAccount']);
        Route::get('delivery-schedules',                 [CustomerPortalController::class, 'deliverySchedules']);
        Route::post('delivery-schedules',                [CustomerPortalController::class, 'storeDeliverySchedule']);
    });
});
