<?php

declare(strict_types=1);

use App\Modules\CRM\Controllers\PriceAgreementController;
use App\Modules\CRM\Controllers\ProductController;
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
});
