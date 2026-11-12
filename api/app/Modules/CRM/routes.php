<?php

declare(strict_types=1);

use App\Modules\Accounting\Controllers\CustomerController;
use App\Modules\CRM\Controllers\ComplaintController;
use App\Modules\CRM\Controllers\CustomerAgreementDocumentController;
use App\Modules\CRM\Controllers\CustomerAgreementSampleController;
use App\Modules\CRM\Controllers\CustomerProductAccessController;
use App\Modules\CRM\Controllers\CustomerWarrantyTermsController;
use App\Modules\CRM\Controllers\PriceAgreementController;
use App\Modules\CRM\Controllers\PriceNegotiationController;
use App\Modules\CRM\Controllers\ProductBlueprintController;
use App\Modules\CRM\Controllers\ProductController;
use App\Modules\CRM\Controllers\ProductImageController;
use App\Modules\CRM\Controllers\SalesOrderAdvanceController;
use App\Modules\CRM\Controllers\SalesOrderAgreementController;
use App\Modules\CRM\Controllers\SalesOrderController;
use App\Modules\CRM\Controllers\SalesOrderFulfillmentController;
use App\Modules\CRM\Controllers\SalesOrderResponseController;
use Illuminate\Support\Facades\Route;

/*
 * CRM module routes — Sprint 6 Tasks 47–48.
 * Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.
 */

Route::middleware(['auth:sanctum', 'feature:crm'])->prefix('crm')->group(function () {

    /* ─── Customers (delegated to Accounting CustomerController) ─── */
    Route::get('/customers', [CustomerController::class, 'index'])->middleware('permission:accounting.customers.view');
    Route::get('/customers/name-check', [CustomerController::class, 'checkName'])->middleware('permission:accounting.customers.manage');
    Route::get('/customers/product-order-limit-capability', [CustomerProductAccessController::class, 'orderLimitCapability'])
        ->middleware('permission:accounting.customers.manage');
    Route::get('/customers/sample-agreement', CustomerAgreementSampleController::class)
        ->middleware(['permission:accounting.customers.manage', 'throttle:sensitive']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:accounting.customers.view');
    Route::post('/customers', [CustomerController::class, 'store'])->middleware('permission:accounting.customers.manage');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:accounting.customers.manage');
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:accounting.customers.manage');
    Route::patch('/customers/{customer}/restore', [CustomerController::class, 'restore'])
        ->middleware('permission:accounting.customers.manage')
        ->withTrashed();
    Route::get('/customers/{customer}/agreement-documents', [CustomerAgreementDocumentController::class, 'index'])
        ->middleware('permission:accounting.customers.view');
    Route::post('/customers/{customer}/agreement-documents', [CustomerAgreementDocumentController::class, 'store'])
        ->middleware(['permission:accounting.customers.manage', 'throttle:sensitive']);
    Route::post('/customers/{customer}/agreement-documents/{document}/issue', [CustomerAgreementDocumentController::class, 'issue'])
        ->middleware(['permission:accounting.customers.manage', 'throttle:sensitive']);
    Route::get('/customers/{customer}/agreement-documents/{document}/download', [CustomerAgreementDocumentController::class, 'download'])
        ->middleware('permission:accounting.customers.view');
    Route::get('/customers/{customer}/warranty-terms', [CustomerWarrantyTermsController::class, 'index'])
        ->middleware('permission:accounting.customers.view');
    Route::post('/customers/{customer}/warranty-terms/{product}/options', [CustomerWarrantyTermsController::class, 'storeOption'])
        ->middleware('permission:accounting.customers.manage')->withTrashed();
    Route::put('/customers/{customer}/warranty-terms/{product}/options/{option}', [CustomerWarrantyTermsController::class, 'updateOption'])
        ->middleware('permission:accounting.customers.manage')->withTrashed();
    Route::delete('/customers/{customer}/warranty-terms/{product}/options/{option}', [CustomerWarrantyTermsController::class, 'destroyOption'])
        ->middleware('permission:accounting.customers.manage')->withTrashed();
    Route::put('/customers/{customer}/warranty-terms/{product}', [CustomerWarrantyTermsController::class, 'upsert'])
        ->middleware('permission:accounting.customers.manage')->withTrashed();
    Route::delete('/customers/{customer}/warranty-terms/{product}', [CustomerWarrantyTermsController::class, 'destroy'])
        ->middleware('permission:accounting.customers.manage')->withTrashed();
    Route::get('/customers/{customer}/product-access', [CustomerProductAccessController::class, 'index'])
        ->middleware('permission:accounting.customers.view');
    Route::put('/customers/{customer}/product-access', [CustomerProductAccessController::class, 'update'])
        ->middleware('permission:accounting.customers.manage');

    /* ─── Products ─── */
    Route::get('/products/options', [ProductController::class, 'options'])
        ->middleware('permission_any:crm.products.view,accounting.customers.view,production.molds.manage,quality.inspections.view,quality.inspections.manage,quality.ncr.manage,quality.specs.view,forecasting.view');
    Route::get('/products/customer-options', [ProductController::class, 'customerOptions'])
        ->middleware('permission:crm.products.customer_access.manage');
    Route::get('/products', [ProductController::class, 'index'])->middleware('permission:crm.products.view');
    Route::get('/products/{product}/image', [ProductImageController::class, 'show'])
        ->middleware('permission_any:crm.products.view,crm.products.manage,inventory.costs.approve,production.molds.manage,quality.inspections.view,quality.inspections.manage,quality.ncr.manage,quality.specs.view,forecasting.view')
        ->withTrashed();
    Route::post('/products/{product}/image', [ProductImageController::class, 'upload'])
        ->middleware('permission:crm.products.manage')
        ->withTrashed();
    Route::post('/products/{product}/images', [ProductImageController::class, 'uploadMany'])
        ->middleware('permission:crm.products.manage')->withTrashed();
    Route::patch('/products/{product}/images/{image}/primary', [ProductImageController::class, 'setPrimary'])
        ->middleware('permission:crm.products.manage')->withTrashed();
    Route::get('/products/{product}/images/{image}/file', [ProductImageController::class, 'showImage'])
        ->middleware('permission_any:crm.products.view,crm.products.manage,inventory.costs.approve,production.molds.manage,quality.inspections.view,quality.inspections.manage,quality.ncr.manage,quality.specs.view,forecasting.view')
        ->withTrashed();
    Route::delete('/products/{product}/images/{image}', [ProductImageController::class, 'destroy'])
        ->middleware('permission:crm.products.manage')->withTrashed();
    Route::get('/products/{product}/blueprints', [ProductBlueprintController::class, 'index'])
        ->middleware('permission_any:crm.products.view,crm.products.manage,inventory.costs.approve')->withTrashed();
    Route::post('/products/{product}/blueprints', [ProductBlueprintController::class, 'store'])
        ->middleware('permission:crm.products.manage')->withTrashed();
    Route::get('/products/{product}/blueprints/{blueprint}/file', [ProductBlueprintController::class, 'show'])
        ->middleware('permission_any:crm.products.view,crm.products.manage,inventory.costs.approve')->withTrashed();
    Route::delete('/products/{product}/blueprints/{blueprint}', [ProductBlueprintController::class, 'destroy'])
        ->middleware('permission:crm.products.manage')->withTrashed();
    Route::get('/products/{product}', [ProductController::class, 'show'])->middleware('permission_any:crm.products.view,crm.products.manage,inventory.costs.approve');
    Route::post('/products', [ProductController::class, 'store'])->middleware('permission:crm.products.manage');
    Route::put('/products/{product}/customer-access', [ProductController::class, 'updateCustomerAccess'])
        ->middleware('permission:crm.products.customer_access.manage');
    Route::put('/products/{product}/selling-prices', [ProductController::class, 'updateSellingPrices'])
        ->middleware('permission:crm.products.prices.manage');
    Route::put('/products/{product}', [ProductController::class, 'update'])->middleware('permission:crm.products.manage');
    Route::post('/products/{product}/financial-proposal', [ProductController::class, 'proposeFinancialChange'])->middleware('permission:crm.products.manage');
    Route::post('/products/{product}/financial-decision', [ProductController::class, 'decideFinancialChange'])->middleware('permission:inventory.costs.approve');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('permission:crm.products.manage');
    Route::patch('/products/{product}/restore', [ProductController::class, 'restore'])
        ->middleware('permission:crm.products.manage')
        ->withTrashed();

    /* ─── Price agreements ─── */
    Route::get('/price-requests', [PriceNegotiationController::class, 'index'])->middleware('permission_any:crm.price_agreements.view,crm.price_agreements.approve,crm.price_agreements.propose');
    Route::get('/price-requests/{priceRequest}', [PriceNegotiationController::class, 'show'])->middleware('permission_any:crm.price_agreements.view,crm.price_agreements.approve,crm.price_agreements.propose');
    Route::post('/price-requests', [PriceNegotiationController::class, 'store'])->middleware('permission:crm.price_agreements.propose');
    Route::put('/price-requests/{priceRequest}/offer', [PriceNegotiationController::class, 'offer'])->middleware('permission:crm.price_agreements.propose');
    Route::post('/price-requests/{priceRequest}/approve', [PriceNegotiationController::class, 'approve'])->middleware('permission:crm.price_agreements.approve');
    Route::post('/price-requests/{priceRequest}/reject', [PriceNegotiationController::class, 'reject'])->middleware('permission:crm.price_agreements.propose');
    Route::post('/price-requests/{priceRequest}/withdraw', [PriceNegotiationController::class, 'withdraw'])->middleware('permission_any:crm.price_agreements.propose,crm.price_agreements.approve');
    Route::get('/price-agreements', [PriceAgreementController::class, 'index'])->middleware('permission:crm.price_agreements.view');
    Route::get('/price-agreements/{priceAgreement}', [PriceAgreementController::class, 'show'])->middleware('permission:crm.price_agreements.view');
    Route::get('/price-agreements/{priceAgreement}/termination-preview', [PriceAgreementController::class, 'terminationPreview'])
        ->middleware('permission:crm.price_agreements.manage');
    Route::get('/price-agreements/{priceAgreement}/termination-history', [PriceAgreementController::class, 'terminationHistory'])
        ->middleware('permission:crm.price_agreements.view')
        ->withTrashed();
    Route::post('/price-agreements/{priceAgreement}/termination-requests', [PriceAgreementController::class, 'requestTermination'])
        ->middleware(['permission:crm.price_agreements.manage', 'throttle:sensitive']);
    Route::post('/price-agreements/{priceAgreement}/termination-requests/cancel', [PriceAgreementController::class, 'cancelTerminationRequest'])
        ->middleware(['permission:crm.price_agreements.manage', 'throttle:sensitive']);
    Route::post('/price-agreements/{priceAgreement}/replacement-proposals', [PriceNegotiationController::class, 'replacementProposal'])->middleware('permission:crm.price_agreements.propose');
    Route::post('/price-agreements', [PriceNegotiationController::class, 'store'])->middleware('permission:crm.price_agreements.propose');
    Route::put('/price-agreements/{priceAgreement}', [PriceAgreementController::class, 'update'])->middleware('permission:crm.price_agreements.manage');
    Route::delete('/price-agreements/{priceAgreement}', [PriceAgreementController::class, 'destroy'])->middleware('permission:crm.price_agreements.manage');
    Route::patch('/price-agreements/{priceAgreement}/restore', [PriceAgreementController::class, 'restore'])
        ->middleware('permission:crm.price_agreements.manage')
        ->withTrashed();

    Route::get('/customers/{customer}/price-agreements', [PriceAgreementController::class, 'forCustomer'])
        ->middleware('permission:crm.price_agreements.view');

    /* ─── Sales-order responses (customer negotiation) ─── */
    // Declared before the `/sales-orders/{salesOrder}` wildcard group so the
    // literal `sales-order-responses` segment can never be param-bound.
    Route::patch('/sales-order-responses/{response}/accept', [SalesOrderResponseController::class, 'accept'])
        ->middleware('permission_any:crm.sales_orders.confirm,crm.sales_orders.cancel');
    Route::patch('/sales-order-responses/{response}/reject', [SalesOrderResponseController::class, 'reject'])
        ->middleware('permission_any:crm.sales_orders.confirm,crm.sales_orders.cancel');

    /* ─── Sales orders (Task 48) ─── */
    Route::get('/sales-orders/{salesOrder}/agreements', [SalesOrderAgreementController::class, 'index'])->middleware('permission:crm.sales_orders.view');
    Route::post('/sales-orders/{salesOrder}/agreements', [SalesOrderAgreementController::class, 'publish'])->middleware(['permission:crm.sales_orders.update', 'throttle:sensitive']);
    Route::post('/sales-orders/{salesOrder}/agreements/{agreement}/acknowledge', [SalesOrderAgreementController::class, 'acknowledgeExternal'])->middleware(['permission:crm.sales_orders.confirm', 'throttle:sensitive']);
    Route::get('/sales-orders/{salesOrder}/agreements/{agreement}/file', [SalesOrderAgreementController::class, 'file'])->middleware('permission_any:crm.sales_orders.view,return_management.view');
    Route::get('/sales-orders/{salesOrder}/fulfillment', [SalesOrderFulfillmentController::class, 'show'])->middleware('permission_any:crm.sales_orders.view,production.wo.confirm,production.schedule.confirm');
    Route::put('/sales-orders/{salesOrder}/fulfillment', [SalesOrderFulfillmentController::class, 'update'])->middleware('permission_any:production.wo.confirm,production.schedule.confirm');
    Route::get('/sales-orders/options', [SalesOrderController::class, 'options'])->middleware('permission:crm.sales_orders.view');
    Route::get('/sales-orders', [SalesOrderController::class, 'index'])->middleware('permission:crm.sales_orders.view');
    Route::get('/sales-orders/{salesOrder}', [SalesOrderController::class, 'show'])->middleware('permission:crm.sales_orders.view');
    Route::get('/sales-orders/{salesOrder}/chain', [SalesOrderController::class, 'chain'])->middleware('permission:crm.sales_orders.view');
    Route::put('/sales-orders/{salesOrder}/advance-requirement', [SalesOrderAdvanceController::class, 'configure'])
        ->middleware('permission:crm.sales_orders.update');
    Route::get('/sales-orders/{salesOrder}/responses', [SalesOrderResponseController::class, 'index'])->middleware('permission:crm.sales_orders.view');
    Route::post('/sales-orders', [SalesOrderController::class, 'store'])->middleware('permission:crm.sales_orders.create');
    Route::put('/sales-orders/{salesOrder}', [SalesOrderController::class, 'update'])->middleware('permission:crm.sales_orders.update');
    Route::put('/sales-orders/{salesOrder}/review-payment-terms', [SalesOrderController::class, 'reviewCustomerPaymentTerms'])
        ->middleware('permission_any:crm.sales_orders.update,crm.sales_orders.confirm');
    Route::delete('/sales-orders/{salesOrder}', [SalesOrderController::class, 'destroy'])->middleware('permission:crm.sales_orders.delete');
    Route::patch('/sales-orders/{salesOrder}/restore', [SalesOrderController::class, 'restore'])
        ->withTrashed()
        ->middleware('permission:crm.sales_orders.delete');
    Route::post('/sales-orders/{salesOrder}/propose-delivery-date', [SalesOrderResponseController::class, 'proposeDeliveryDate'])->middleware('permission:crm.sales_orders.confirm');
    Route::post('/sales-orders/{salesOrder}/confirm', [SalesOrderController::class, 'confirm'])->middleware('permission:crm.sales_orders.confirm');
    Route::get('/sales-order-advances', [SalesOrderAdvanceController::class, 'index'])->middleware('permission:accounting.invoices.collect');
    Route::get('/sales-order-advances/{advance}/proof', [SalesOrderAdvanceController::class, 'downloadProof'])->middleware('permission:accounting.invoices.collect');
    Route::post('/sales-order-advances/{advance}/verify', [SalesOrderAdvanceController::class, 'verify'])->middleware(['permission:accounting.invoices.collect', 'throttle:sensitive']);
    Route::post('/sales-order-advances/{advance}/reject', [SalesOrderAdvanceController::class, 'reject'])->middleware(['permission:accounting.invoices.collect', 'throttle:sensitive']);
    Route::post('/sales-order-advances/{advance}/waive', [SalesOrderAdvanceController::class, 'waive'])->middleware(['permission:accounting.invoices.collect', 'throttle:sensitive']);
    Route::post('/sales-order-advances/{advance}/refund', [SalesOrderAdvanceController::class, 'refund'])->middleware(['permission:accounting.invoices.collect', 'throttle:sensitive']);
    Route::post('/sales-orders/{salesOrder}/request-customer-confirmation', [SalesOrderController::class, 'requestCustomerConfirmation'])->middleware('permission:crm.sales_orders.confirm');
    Route::post('/sales-orders/{salesOrder}/cancel', [SalesOrderController::class, 'cancel'])->middleware('permission:crm.sales_orders.cancel');

    /* ─── Customer complaints + 8D (Task 68) ─── */
    Route::get('/complaints/options', [ComplaintController::class, 'options'])
        ->middleware('permission:crm.complaints.view');
    Route::get('/complaints', [ComplaintController::class, 'index'])
        ->middleware('permission:crm.complaints.view');
    Route::get('/complaints/{complaint}', [ComplaintController::class, 'show'])
        ->middleware('permission:crm.complaints.view');
    Route::post('/complaints', [ComplaintController::class, 'store'])
        ->middleware('permission:crm.complaints.manage');
    Route::post('/complaints/{complaint}/retry-ncr', [ComplaintController::class, 'retryNcr'])
        ->middleware('permission:crm.complaints.manage');
    Route::patch('/complaints/{complaint}/8d', [ComplaintController::class, 'update8D'])
        ->middleware('permission:crm.complaints.manage');
    Route::post('/complaints/{complaint}/8d/finalize', [ComplaintController::class, 'finalize8D'])
        ->middleware('permission:crm.complaints.manage');
    Route::post('/complaints/{complaint}/resolve', [ComplaintController::class, 'resolve'])
        ->middleware('permission:crm.complaints.manage');
    Route::post('/complaints/{complaint}/close', [ComplaintController::class, 'close'])
        ->middleware('permission:crm.complaints.manage');
    Route::get('/complaints/{complaint}/8d/pdf', [ComplaintController::class, 'pdf'])
        ->middleware('permission:crm.complaints.view');

});
