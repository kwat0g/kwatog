<?php

declare(strict_types=1);

use App\Modules\B2B\Controllers\CustomerAuthController;
use App\Modules\B2B\Controllers\CustomerPortalController;
use App\Modules\B2B\Controllers\SupplierAuthController;
use App\Modules\B2B\Controllers\SupplierPortalController;
use App\Modules\B2B\Controllers\PortalAccessController;
use Illuminate\Support\Facades\Route;

/*
 * ADV10 — B2B Portals.
 * Supplier Portal + Customer Portal with separate auth guards.
 * Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.
 */

/* ─── Supplier Portal ─────────────────────────────────────────── */
Route::prefix('b2b/supplier')->group(function () {
    // Public — throttle:auth (5/min/ip|email) protects against credential
    // spraying. Logout shares the limiter to bound DoS on the token-revoke path.
    Route::post('login', [SupplierAuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('logout', [SupplierAuthController::class, 'logout'])->middleware('throttle:auth');
    Route::post('forgot-password', [SupplierAuthController::class, 'forgotPassword'])->middleware('throttle:auth');
    Route::post('reset-password', [SupplierAuthController::class, 'resetPassword'])->middleware('throttle:auth');

    // Authenticated
    Route::middleware(['auth:supplier_portal', 'portal:supplier_portal', 'feature:b2b_portals', \App\Modules\B2B\Middleware\B2BTenancyScopeMiddleware::class])->group(function () {
        Route::get('me', [SupplierAuthController::class, 'me']);
        Route::post('change-password', [SupplierAuthController::class, 'changePassword'])->middleware('throttle:sensitive');
        Route::middleware('portal.password.changed')->group(function (): void {
        Route::get('dashboard', [SupplierPortalController::class, 'dashboard']);
        Route::get('purchase-orders', [SupplierPortalController::class, 'purchaseOrders']);
        Route::get('purchase-orders/{purchaseOrder}', [SupplierPortalController::class, 'purchaseOrderShow']);
        Route::get('purchase-orders/{purchaseOrder}/pdf', [SupplierPortalController::class, 'poPdf']);
        Route::post('purchase-orders/{purchaseOrder}/acknowledge', [SupplierPortalController::class, 'acknowledgePo']);
        Route::post('purchase-orders/{purchaseOrder}/shipment-update', [SupplierPortalController::class, 'updateShipment']);
        Route::post('purchase-orders/{purchaseOrder}/shipping-documents', [SupplierPortalController::class, 'uploadShippingDocuments']);
        Route::get('purchase-orders/{purchaseOrder}/shipping-documents', [SupplierPortalController::class, 'shippingDocuments']);
        Route::get('purchase-orders/shipping-documents/options', [SupplierPortalController::class, 'shippingDocumentOptions']);
        Route::post('purchase-orders/{purchaseOrder}/submit-invoice', [SupplierPortalController::class, 'submitInvoice']);
        Route::get('shipping-documents/{id}/download', [SupplierPortalController::class, 'downloadShippingDocument']);
        Route::get('invoices', [SupplierPortalController::class, 'invoices']);
        Route::get('invoices/{invoice}', [SupplierPortalController::class, 'invoiceDetail']);
        Route::get('invoices/{invoice}/pdf', [SupplierPortalController::class, 'invoicePdf']);
        Route::get('deliveries', [SupplierPortalController::class, 'deliveries']);
        Route::get('statement-of-account', [SupplierPortalController::class, 'statementOfAccount']);
        Route::get('delivery-schedules', [SupplierPortalController::class, 'deliverySchedules']);
        Route::post('delivery-schedules', [SupplierPortalController::class, 'storeDeliverySchedule']);
        // PPAP submissions (read-only, scoped to this supplier).
        Route::get('ppap-submissions', [SupplierPortalController::class, 'ppapSubmissions']);
        });
    });
});

/* ─── Internal portal access administration ───────────────────── */
Route::middleware(['auth:sanctum', 'session.timeout', 'password.expired', 'feature:b2b_portals'])
    ->prefix('b2b/portal-access')
    ->group(function (): void {
        Route::post('customers/{customer}/invite', [PortalAccessController::class, 'inviteCustomer'])
            ->middleware('permission:accounting.customers.manage');
        Route::post('suppliers/{vendor}/invite', [PortalAccessController::class, 'inviteSupplier'])
            ->middleware('permission:accounting.vendors.manage');
    });

/* ─── Customer Portal ─────────────────────────────────────────── */
Route::prefix('b2b/customer')->group(function () {
    // Public — throttle:auth (5/min/ip|email) protects against credential
    // spraying. Logout shares the limiter to bound DoS on the token-revoke path.
    Route::post('login', [CustomerAuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('logout', [CustomerAuthController::class, 'logout'])->middleware('throttle:auth');
    Route::post('forgot-password', [CustomerAuthController::class, 'forgotPassword'])->middleware('throttle:auth');
    Route::post('reset-password', [CustomerAuthController::class, 'resetPassword'])->middleware('throttle:auth');

    // Authenticated
    Route::middleware(['auth:customer_portal', 'portal:customer_portal', 'feature:b2b_portals', \App\Modules\B2B\Middleware\B2BTenancyScopeMiddleware::class])->group(function () {
        Route::get('me', [CustomerAuthController::class, 'me']);
        Route::post('change-password', [CustomerAuthController::class, 'changePassword'])->middleware('throttle:sensitive');
        Route::middleware('portal.password.changed')->group(function (): void {
        Route::get('dashboard', [CustomerPortalController::class, 'dashboard']);
        Route::get('orders', [CustomerPortalController::class, 'salesOrders']);
        Route::get('orders/{salesOrder}', [CustomerPortalController::class, 'salesOrderShow']);
        Route::get('orders/{salesOrder}/chain', [CustomerPortalController::class, 'salesOrderChain']);
        Route::get('invoices', [CustomerPortalController::class, 'invoices']);
        Route::get('invoices/{invoice}', [CustomerPortalController::class, 'invoiceDetail']);
        Route::get('invoices/{invoice}/pdf', [CustomerPortalController::class, 'invoicePdf']);
        Route::get('deliveries', [CustomerPortalController::class, 'deliveries']);
        Route::get('deliveries/{delivery}', [CustomerPortalController::class, 'deliveryDetail']);
        Route::get('deliveries/{delivery}/proofs/{proof}/view', [CustomerPortalController::class, 'deliveryProof']);
        Route::get('complaints', [CustomerPortalController::class, 'complaints']);
        Route::get('complaints/options', [CustomerPortalController::class, 'complaintOptions']);
        Route::post('complaints', [CustomerPortalController::class, 'createComplaint']);
        Route::get('complaints/{complaint}/8d-report', [CustomerPortalController::class, 'complaint8dReport']);
        Route::get('statement-of-account', [CustomerPortalController::class, 'statementOfAccount']);
        Route::get('delivery-schedules', [CustomerPortalController::class, 'deliverySchedules']);
        Route::post('delivery-schedules', [CustomerPortalController::class, 'storeDeliverySchedule']);
        });
    });
});
