<?php

declare(strict_types=1);

use App\Modules\Loans\Controllers\LoanController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:loans'])->prefix('loans')->group(function () {
    Route::get('/', [LoanController::class, 'index'])->middleware('permission:loans.view');
    Route::get('/types', [LoanController::class, 'types'])->middleware('permission:loans.create');
    Route::post('/', [LoanController::class, 'store'])->middleware('permission:loans.create');
    // Pure calculation used by the employee self-service form; it reads no
    // employee or loan record and therefore only requires authentication.
    Route::post('/preview-amortization', [LoanController::class, 'previewAmortization']);
    Route::post('/bulk-approve', [LoanController::class, 'bulkApprove'])->middleware('permission:loans.approve');
    Route::get('/limits/{employee}', [LoanController::class, 'limits'])->middleware('permission:loans.approve');
    Route::get('/{loan}', [LoanController::class, 'show'])->middleware('permission:loans.view');
    Route::patch('/{loan}/approve', [LoanController::class, 'approve'])->middleware('permission:loans.approve');
    Route::patch('/{loan}/reject', [LoanController::class, 'reject'])->middleware('permission:loans.approve');
    Route::patch('/{loan}/cancel', [LoanController::class, 'cancel'])->middleware('permission:loans.write_off');
});
