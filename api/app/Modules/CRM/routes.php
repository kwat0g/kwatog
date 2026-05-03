<?php

declare(strict_types=1);

use App\Modules\CRM\Controllers\ComplaintController;
use App\Modules\CRM\Controllers\PriceAgreementController;
use App\Modules\CRM\Controllers\ProductController;
use App\Modules\CRM\Controllers\SalesOrderController;
use Illuminate\Support\Facades\Route;

/*
 * CRM module routes — Sprint 6 Tasks 47–48.
 * Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.
 */

Route::middleware(['auth:sanctum', 'feature:crm'])->prefix('crm')->group(function () {

    /* ─── Products ─── */
    Route::get('/products',           [ProductController::class, 'index']) ->middleware('permission:crm.products.view');
    Route::get('/products/{product}', [ProductController::class, 'show']) ->middleware('permission:crm.products.view');
    Route::post('/products',          [ProductController::class, 'store']) ->middleware('permission:crm.products.manage');
    Route::put('/products/{product}', [ProductController::class, 'update'])->middleware('permission:crm.products.manage');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('permission:crm.products.manage');

    /* ─── Price agreements ─── */
    Route::get('/price-agreements',                    [PriceAgreementController::class, 'index']) ->middleware('permission:crm.price_agreements.view');
    Route::get('/price-agreements/{priceAgreement}',   [PriceAgreementController::class, 'show']) ->middleware('permission:crm.price_agreements.view');
    Route::post('/price-agreements',                   [PriceAgreementController::class, 'store']) ->middleware('permission:crm.price_agreements.manage');
    Route::put('/price-agreements/{priceAgreement}',   [PriceAgreementController::class, 'update'])->middleware('permission:crm.price_agreements.manage');
    Route::delete('/price-agreements/{priceAgreement}', [PriceAgreementController::class, 'destroy'])->middleware('permission:crm.price_agreements.manage');

    Route::get('/customers/{customer}/price-agreements', [PriceAgreementController::class, 'forCustomer'])
        ->middleware('permission:crm.price_agreements.view');

    /* ─── Sales orders (Task 48) ─── */
    Route::get('/sales-orders',                    [SalesOrderController::class, 'index']) ->middleware('permission:crm.sales_orders.view');
    Route::get('/sales-orders/{salesOrder}',       [SalesOrderController::class, 'show'])  ->middleware('permission:crm.sales_orders.view');
    Route::get('/sales-orders/{salesOrder}/chain', [SalesOrderController::class, 'chain']) ->middleware('permission:crm.sales_orders.view');
    Route::post('/sales-orders',                   [SalesOrderController::class, 'store']) ->middleware('permission:crm.sales_orders.create');
    Route::put('/sales-orders/{salesOrder}',       [SalesOrderController::class, 'update'])->middleware('permission:crm.sales_orders.update');
    Route::delete('/sales-orders/{salesOrder}',    [SalesOrderController::class, 'destroy'])->middleware('permission:crm.sales_orders.delete');
    Route::post('/sales-orders/{salesOrder}/confirm', [SalesOrderController::class, 'confirm'])->middleware('permission:crm.sales_orders.confirm');
    Route::post('/sales-orders/{salesOrder}/cancel',  [SalesOrderController::class, 'cancel']) ->middleware('permission:crm.sales_orders.cancel');

    /* ─── Customer complaints + 8D (Task 68) ─── */
    Route::get('/complaints',                              [ComplaintController::class, 'index'])
        ->middleware('permission:crm.complaints.manage');
    Route::get('/complaints/{complaint}',                  [ComplaintController::class, 'show'])
        ->middleware('permission:crm.complaints.manage');
    Route::post('/complaints',                             [ComplaintController::class, 'store'])
        ->middleware('permission:crm.complaints.manage');
    Route::patch('/complaints/{complaint}/8d',             [ComplaintController::class, 'update8D'])
        ->middleware('permission:crm.complaints.manage');
    Route::post('/complaints/{complaint}/8d/finalize',     [ComplaintController::class, 'finalize8D'])
        ->middleware('permission:crm.complaints.manage');
    Route::post('/complaints/{complaint}/resolve',         [ComplaintController::class, 'resolve'])
        ->middleware('permission:crm.complaints.manage');
    Route::post('/complaints/{complaint}/close',           [ComplaintController::class, 'close'])
        ->middleware('permission:crm.complaints.manage');
    Route::get('/complaints/{complaint}/8d/pdf',           [ComplaintController::class, 'pdf'])
        ->middleware('permission:crm.complaints.manage');
});
