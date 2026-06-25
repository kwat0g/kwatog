<?php

declare(strict_types=1);

use App\Modules\Accounting\Controllers\CustomerController;
use App\Modules\CRM\Controllers\CommissionController;
use App\Modules\CRM\Controllers\ComplaintController;
use App\Modules\CRM\Controllers\LeadController;
use App\Modules\CRM\Controllers\OpportunityController;
use App\Modules\CRM\Controllers\PriceAgreementController;
use App\Modules\CRM\Controllers\ProductController;
use App\Modules\CRM\Controllers\QuoteController;
use App\Modules\CRM\Controllers\SalesOrderController;
use Illuminate\Support\Facades\Route;

/*
 * CRM module routes — Sprint 6 Tasks 47–48.
 * Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.
 */

Route::middleware(['auth:sanctum', 'feature:crm'])->prefix('crm')->group(function () {

    /* ─── Customers (delegated to Accounting CustomerController) ─── */
    Route::get('/customers',             [CustomerController::class, 'index'])  ->middleware('permission:accounting.customers.view');
    Route::get('/customers/{customer}',  [CustomerController::class, 'show'])   ->middleware('permission:accounting.customers.view');
    Route::post('/customers',            [CustomerController::class, 'store'])  ->middleware('permission:accounting.customers.manage');
    Route::put('/customers/{customer}',  [CustomerController::class, 'update']) ->middleware('permission:accounting.customers.manage');
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:accounting.customers.manage');

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

    /* ─── Leads (Sales Pipeline) ─── */
    Route::get('/leads',                          [LeadController::class, 'index'])       ->middleware('permission:crm.leads.view');
    Route::get('/leads/{lead}',                   [LeadController::class, 'show'])        ->middleware('permission:crm.leads.view');
    Route::post('/leads',                         [LeadController::class, 'store'])       ->middleware('permission:crm.leads.manage');
    Route::put('/leads/{lead}',                   [LeadController::class, 'update'])      ->middleware('permission:crm.leads.manage');
    Route::patch('/leads/{lead}/qualify',         [LeadController::class, 'qualify'])     ->middleware('permission:crm.leads.manage');
    Route::patch('/leads/{lead}/disqualify',      [LeadController::class, 'disqualify'])  ->middleware('permission:crm.leads.manage');
    Route::post('/leads/{lead}/convert',          [LeadController::class, 'convert'])     ->middleware('permission:crm.leads.manage');

    /* ─── Opportunities (Sales Pipeline) ─── */
    Route::get('/opportunities',                                [OpportunityController::class, 'index'])       ->middleware('permission:crm.opportunities.view');
    Route::get('/opportunities/{opportunity}',                  [OpportunityController::class, 'show'])        ->middleware('permission:crm.opportunities.view');
    Route::post('/opportunities',                               [OpportunityController::class, 'store'])       ->middleware('permission:crm.opportunities.manage');
    Route::put('/opportunities/{opportunity}',                  [OpportunityController::class, 'update'])      ->middleware('permission:crm.opportunities.manage');
    Route::patch('/opportunities/{opportunity}/advance',        [OpportunityController::class, 'advance'])     ->middleware('permission:crm.opportunities.manage');
    Route::patch('/opportunities/{opportunity}/win',            [OpportunityController::class, 'win'])         ->middleware('permission:crm.opportunities.manage');
    Route::patch('/opportunities/{opportunity}/lose',           [OpportunityController::class, 'lose'])        ->middleware('permission:crm.opportunities.manage');
    Route::post('/opportunities/{opportunity}/create-quote',    [OpportunityController::class, 'createQuote']) ->middleware('permission:crm.quotes.manage');

    /* ─── Quotes (Sales Pipeline) ─── */
    Route::get('/quotes',                     [QuoteController::class, 'index'])       ->middleware('permission:crm.quotes.view');
    Route::get('/quotes/{quote}',             [QuoteController::class, 'show'])        ->middleware('permission:crm.quotes.view');
    Route::post('/quotes',                    [QuoteController::class, 'store'])       ->middleware('permission:crm.quotes.manage');
    Route::put('/quotes/{quote}',             [QuoteController::class, 'update'])      ->middleware('permission:crm.quotes.manage');
    Route::patch('/quotes/{quote}/send',      [QuoteController::class, 'send'])        ->middleware('permission:crm.quotes.manage');
    Route::patch('/quotes/{quote}/accept',    [QuoteController::class, 'accept'])      ->middleware('permission:crm.quotes.manage');
    Route::patch('/quotes/{quote}/reject',    [QuoteController::class, 'reject'])      ->middleware('permission:crm.quotes.manage');
    Route::post('/quotes/{quote}/convert',    [QuoteController::class, 'convert'])     ->middleware('permission:crm.quotes.manage');

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

    /* ─── Commission tracking ─── */
    Route::prefix('commissions')->group(function () {
        Route::get('/',           [CommissionController::class, 'index'])->middleware('permission:crm.commissions.view');
        Route::get('/rates',      [CommissionController::class, 'rates'])->middleware('permission:crm.commissions.view');
        Route::post('/rates',     [CommissionController::class, 'setRate'])->middleware('permission:crm.commissions.manage');
        Route::post('/{earning}/approve', [CommissionController::class, 'approve'])->middleware('permission:crm.commissions.manage');
        Route::post('/batch-paid', [CommissionController::class, 'batchPaid'])->middleware('permission:crm.commissions.manage');
    });
});
