<?php

declare(strict_types=1);

use App\Modules\Loans\Controllers\LoanController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:loans'])->prefix('loans')->group(function () {
    Route::get('/',                                  [LoanController::class, 'index'])->middleware('permission:loans.view');
    Route::post('/',                                 [LoanController::class, 'store'])->middleware('permission:loans.create');
    Route::post('/preview-amortization',             [LoanController::class, 'previewAmortization'])->middleware('permission:loans.create');
    Route::get('/limits/{employee}',                 [LoanController::class, 'limits'])->middleware('permission:loans.view');
    Route::get('/{loan}',                            [LoanController::class, 'show'])->middleware('permission:loans.view');
    Route::patch('/{loan}/approve',                  [LoanController::class, 'approve'])->middleware('permission:loans.approve');
    Route::patch('/{loan}/reject',                   [LoanController::class, 'reject'])->middleware('permission:loans.approve');
    Route::patch('/{loan}/cancel',                   [LoanController::class, 'cancel'])->middleware('permission:loans.approve');
});
