<?php

declare(strict_types=1);

use App\Modules\Accounting\Controllers\AccountController;
use App\Modules\Accounting\Controllers\AccountingPeriodController;
use App\Modules\Accounting\Controllers\BillController;
use App\Modules\Accounting\Controllers\BudgetController;
use App\Modules\Accounting\Controllers\BudgetTransferController;
use App\Modules\Accounting\Controllers\CreditNoteController;
use App\Modules\Accounting\Controllers\CurrencyController;
use App\Modules\Accounting\Controllers\CustomerController;
use App\Modules\Accounting\Controllers\FinanceDashboardController;
use App\Modules\Accounting\Controllers\FinancialStatementController;
use App\Modules\Accounting\Controllers\InvoiceController;
use App\Modules\Accounting\Controllers\JournalEntryController;
use App\Modules\Accounting\Controllers\OpeningBalanceController;
use App\Modules\Accounting\Controllers\PdfController;
use App\Modules\Accounting\Controllers\VendorController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:accounting'])->group(function () {

    /* ─── Chart of Accounts ──────────────────────────── */
    Route::prefix('accounts')->group(function () {
        Route::get('/',           [AccountController::class, 'index'])     ->middleware('permission:accounting.coa.view');
        Route::get('/tree',       [AccountController::class, 'tree'])      ->middleware('permission:accounting.coa.view');
        Route::get('/{account}',  [AccountController::class, 'show'])      ->middleware('permission:accounting.coa.view');
        Route::post('/',          [AccountController::class, 'store'])     ->middleware('permission:accounting.coa.manage');
        Route::put('/{account}',  [AccountController::class, 'update'])    ->middleware('permission:accounting.coa.manage');
        Route::delete('/{account}',[AccountController::class, 'deactivate'])->middleware('permission:accounting.coa.deactivate');
    });

    /* ─── Accounting Periods (OGAMI-001 close lock) ──── */
    Route::prefix('accounting/periods')->group(function () {
        Route::get('/',         [AccountingPeriodController::class, 'index'])  ->middleware('permission:accounting.periods.view');
        Route::post('/close',   [AccountingPeriodController::class, 'close'])  ->middleware('permission:accounting.periods.manage');
        Route::post('/reopen',  [AccountingPeriodController::class, 'reopen']) ->middleware('permission:accounting.periods.manage');
    });

    /* ─── Journal Entries ────────────────────────────── */
    Route::prefix('journal-entries')->group(function () {
        Route::get('/',                       [JournalEntryController::class, 'index']) ->middleware('permission:accounting.journal.view');
        Route::get('/{journalEntry}',         [JournalEntryController::class, 'show'])  ->middleware('permission:accounting.journal.view');
        Route::post('/',                      [JournalEntryController::class, 'store']) ->middleware('permission:accounting.journal.create');
        Route::put('/{journalEntry}',         [JournalEntryController::class, 'update'])->middleware('permission:accounting.journal.create');
        Route::delete('/{journalEntry}',      [JournalEntryController::class, 'destroy'])->middleware('permission:accounting.journal.create');
        Route::patch('/{journalEntry}/post',  [JournalEntryController::class, 'post'])  ->middleware('permission:accounting.journal.post');
        Route::post('/{journalEntry}/reverse',[JournalEntryController::class, 'reverse'])->middleware('permission:accounting.journal.reverse');
        Route::get('/{journalEntry}/pdf',     [PdfController::class, 'journalEntry'])   ->middleware('permission:accounting.journal.view');
    });

    /* ─── REC-05 — go-live opening balances + TB reconciliation ─── */
    Route::prefix('accounting/opening-balances')->group(function () {
        Route::post('/gl',       [OpeningBalanceController::class, 'postGl'])   ->middleware('permission:accounting.opening_balance.manage');
        Route::post('/stock',    [OpeningBalanceController::class, 'postStock'])->middleware('permission:accounting.opening_balance.manage');
        // TB match is a reconciliation report — statements-view is enough.
        Route::post('/tb-match', [OpeningBalanceController::class, 'tbMatch'])  ->middleware('permission:accounting.statements.view');
    });

    /* ─── REC-13 — Credit notes (AR + AP) ────────────── */
    Route::prefix('accounting/credit-notes')->group(function () {
        Route::get('/',                  [CreditNoteController::class, 'index'])   ->middleware('permission:accounting.credit_notes.view');
        Route::get('/{creditNote}',      [CreditNoteController::class, 'show'])    ->middleware('permission:accounting.credit_notes.view');
        Route::post('/',                 [CreditNoteController::class, 'store'])   ->middleware('permission:accounting.credit_notes.manage');
        Route::post('/{creditNote}/finalize', [CreditNoteController::class, 'finalize'])->middleware('permission:accounting.credit_notes.manage');
        Route::post('/{creditNote}/apply',    [CreditNoteController::class, 'apply'])   ->middleware('permission:accounting.credit_notes.manage');
    });

    /* ─── REC-12 — multi-currency (FX rates + JPY parent-pack translation) ─── */
    Route::prefix('accounting/currency')->group(function () {
        Route::get('/fx-rates',  [CurrencyController::class, 'listRates']) ->middleware('permission:accounting.statements.view');
        Route::post('/fx-rates', [CurrencyController::class, 'storeRate']) ->middleware('permission:accounting.fx.manage');
        // Translated parent-pack statements — read-side, statements-view is enough.
        Route::get('/trial-balance',    [CurrencyController::class, 'trialBalance'])    ->middleware('permission:accounting.statements.view');
        Route::get('/income-statement', [CurrencyController::class, 'incomeStatement']) ->middleware('permission:accounting.statements.view');
        Route::get('/balance-sheet',    [CurrencyController::class, 'balanceSheet'])    ->middleware('permission:accounting.statements.view');
    });

    /* ─── Vendors + Bills + Bill Payments ────────────── */
    Route::prefix('vendors')->group(function () {
        Route::get('/',          [VendorController::class, 'index'])  ->middleware('permission:accounting.vendors.view');
        Route::get('/{vendor}',  [VendorController::class, 'show'])   ->middleware('permission:accounting.vendors.view');
        Route::post('/',         [VendorController::class, 'store'])  ->middleware('permission:accounting.vendors.manage');
        Route::put('/{vendor}',  [VendorController::class, 'update']) ->middleware('permission:accounting.vendors.manage');
        Route::delete('/{vendor}',[VendorController::class, 'destroy'])->middleware('permission:accounting.vendors.manage');
    });
    Route::prefix('bills')->group(function () {
        Route::get('/',          [BillController::class, 'index']) ->middleware('permission:accounting.bills.view');
        Route::get('/{bill}',    [BillController::class, 'show'])  ->middleware('permission:accounting.bills.view');
        Route::post('/',         [BillController::class, 'store']) ->middleware('permission:accounting.bills.create');
        Route::patch('/{bill}/cancel',  [BillController::class, 'cancel']) ->middleware('permission:accounting.bills.update');
        Route::post('/{bill}/payments', [BillController::class, 'recordPayment'])->middleware('permission:accounting.bills.pay');
        Route::get('/{bill}/pdf',[PdfController::class,  'bill'])  ->middleware('permission:accounting.bills.view');
    });

    /* ─── Customers + Invoices + Collections ─────────── */
    Route::prefix('customers')->group(function () {
        Route::get('/',           [CustomerController::class, 'index'])  ->middleware('permission:accounting.customers.view');
        Route::get('/{customer}', [CustomerController::class, 'show'])   ->middleware('permission:accounting.customers.view');
        Route::post('/',          [CustomerController::class, 'store'])  ->middleware('permission:accounting.customers.manage');
        Route::put('/{customer}', [CustomerController::class, 'update']) ->middleware('permission:accounting.customers.manage');
        Route::delete('/{customer}',[CustomerController::class, 'destroy'])->middleware('permission:accounting.customers.manage');
        Route::get('/{customer}/statement-of-account', [CustomerController::class, 'statementOfAccount'])
            ->middleware('permission:accounting.invoices.view');
    });
    Route::prefix('invoices')->group(function () {
        Route::get('/',           [InvoiceController::class, 'index']) ->middleware('permission:accounting.invoices.view');
        Route::get('/{invoice}',  [InvoiceController::class, 'show'])  ->middleware('permission:accounting.invoices.view');
        Route::post('/',          [InvoiceController::class, 'store']) ->middleware('permission:accounting.invoices.create');
        Route::put('/{invoice}',  [InvoiceController::class, 'update'])->middleware('permission:accounting.invoices.update');
        Route::patch('/{invoice}/finalize', [InvoiceController::class, 'finalize'])->middleware('permission:accounting.invoices.create');
        Route::patch('/{invoice}/cancel',   [InvoiceController::class, 'cancel'])  ->middleware('permission:accounting.invoices.update');
        Route::post('/{invoice}/collections',[InvoiceController::class, 'recordCollection'])->middleware('permission:accounting.invoices.collect');
        Route::get('/{invoice}/pdf', [PdfController::class, 'invoice'])->middleware('permission:accounting.invoices.view');
    });

    /* ─── Financial Statements + Dashboard ───────────── */
    Route::prefix('accounting/statements')->group(function () {
        Route::get('/trial-balance',     [FinancialStatementController::class, 'trialBalance'])    ->middleware('permission:accounting.statements.view');
        Route::get('/income-statement',  [FinancialStatementController::class, 'incomeStatement']) ->middleware('permission:accounting.statements.view');
        Route::get('/balance-sheet',     [FinancialStatementController::class, 'balanceSheet'])    ->middleware('permission:accounting.statements.view');
        Route::get('/trial-balance/pdf', [PdfController::class, 'trialBalance'])    ->middleware('permission:accounting.statements.view');
        Route::get('/income-statement/pdf',[PdfController::class, 'incomeStatement'])->middleware('permission:accounting.statements.view');
        Route::get('/balance-sheet/pdf', [PdfController::class, 'balanceSheet'])    ->middleware('permission:accounting.statements.view');
        // REC-15 — AR/AP aging reports (JSON + ?format=csv). Finance report,
        // gated with the statements permission (CFO monthly deliverable).
        Route::get('/ar-aging', [FinancialStatementController::class, 'arAging']) ->middleware('permission:accounting.statements.view');
        Route::get('/ap-aging', [FinancialStatementController::class, 'apAging']) ->middleware('permission:accounting.statements.view');
    });

    Route::get('/dashboard/finance', [FinanceDashboardController::class, 'summary'])
        ->middleware('permission:accounting.dashboard.view');

    /* ─── Budgeting (ADV9) ────────────────────────────── */
    Route::middleware(['feature:budgeting'])->group(function () {
        Route::prefix('budgets')->group(function () {
        Route::get('/',                               [BudgetController::class, 'index'])        ->middleware('permission:budgeting.view');
        Route::get('/fiscal-years',                   [BudgetController::class, 'fiscalYears'])  ->middleware('permission:budgeting.view');
        Route::get('/overview',                       [BudgetController::class, 'overview'])     ->middleware('permission:budgeting.view');
        Route::get('/budget-vs-actual',               [BudgetController::class, 'budgetVsActual'])->middleware('permission:budgeting.view');
        Route::get('/check-availability',             [BudgetController::class, 'checkAvailability'])->middleware('permission:budgeting.view');
        Route::get('/{budget}',                       [BudgetController::class, 'show'])         ->middleware('permission:budgeting.view');
        Route::post('/',                              [BudgetController::class, 'store'])        ->middleware('permission:budgeting.manage');
        Route::put('/{budget}',                       [BudgetController::class, 'update'])       ->middleware('permission:budgeting.manage');
        Route::post('/{budget}/submit',               [BudgetController::class, 'submit'])       ->middleware('permission:budgeting.manage');
        Route::post('/{budget}/approve',              [BudgetController::class, 'approve'])      ->middleware('permission:budgeting.approve');
        Route::post('/{budget}/close',                [BudgetController::class, 'close'])        ->middleware('permission:budgeting.manage');
        // L-26 Budget revisions.
        Route::get('/{budget}/revisions',                       [BudgetController::class, 'listRevisions'])    ->middleware('permission:budgeting.view');
        Route::post('/{budget}/revisions',                      [BudgetController::class, 'storeRevision'])    ->middleware('permission:budgeting.manage');
        Route::post('/{budget}/revisions/{revision}/approve',   [BudgetController::class, 'approveRevision'])  ->middleware('permission:budgeting.approve');
    });

    Route::prefix('budget-transfers')->group(function () {
        Route::get('/',                               [BudgetTransferController::class, 'index'])  ->middleware('permission:budgeting.view');
        Route::get('/{transfer}',                     [BudgetTransferController::class, 'show'])    ->middleware('permission:budgeting.view');
        Route::post('/',                              [BudgetTransferController::class, 'store'])   ->middleware('permission:budgeting.manage');
        Route::post('/{transfer}/approve',            [BudgetTransferController::class, 'approve']) ->middleware('permission:budgeting.approve');
        Route::post('/{transfer}/reject',             [BudgetTransferController::class, 'reject'])  ->middleware('permission:budgeting.approve');
    });
    });
});
