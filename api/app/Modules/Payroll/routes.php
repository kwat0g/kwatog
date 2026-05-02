<?php

declare(strict_types=1);

// Payroll module routes.
// Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.

use App\Modules\Payroll\Controllers\GovernmentTableController;
use App\Modules\Payroll\Controllers\PayrollAdjustmentController;
use App\Modules\Payroll\Controllers\PayrollController;
use App\Modules\Payroll\Controllers\PayrollPeriodController;
use Illuminate\Support\Facades\Route;

// ─── Government tables (admin-managed) ─────────────────────────
// Mounted under /api/v1/admin/* — Finance officers and System Admins use this
// regardless of whether the payroll module feature toggle is enabled.
Route::middleware('auth:sanctum')->prefix('admin/gov-tables')->group(function () {
    Route::get('/',                        [GovernmentTableController::class, 'index'])
        ->middleware('permission:admin.gov_tables.manage');
    Route::put('/{govTable}',              [GovernmentTableController::class, 'update'])
        ->middleware('permission:admin.gov_tables.manage');
    Route::patch('/{govTable}/deactivate', [GovernmentTableController::class, 'deactivate'])
        ->middleware('permission:admin.gov_tables.manage');
    Route::patch('/{govTable}/activate',   [GovernmentTableController::class, 'activate'])
        ->middleware('permission:admin.gov_tables.manage');
    Route::delete('/{govTable}',           [GovernmentTableController::class, 'destroy'])
        ->middleware('permission:admin.gov_tables.manage');
});

// ─── Payroll periods + payrolls + adjustments + bank files ─────
// Each controller is registered conditionally so this file remains valid as
// the sprint is built up incrementally (Tasks 25/26/27).
if (class_exists(PayrollPeriodController::class)) {
    Route::middleware(['auth:sanctum', 'feature:payroll'])->group(function () {
        Route::prefix('payroll-periods')->group(function () {
            Route::get('/',                       [PayrollPeriodController::class, 'index'])->middleware('permission:payroll.view');
            Route::post('/',                      [PayrollPeriodController::class, 'store'])->middleware('permission:payroll.periods.create');
            Route::post('/thirteenth-month',      [PayrollPeriodController::class, 'runThirteenthMonth'])->middleware('permission:payroll.thirteenth_month.run');
            Route::get('/{period}',               [PayrollPeriodController::class, 'show'])->middleware('permission:payroll.view');
            Route::post('/{period}/compute',      [PayrollPeriodController::class, 'compute'])->middleware('permission:payroll.periods.compute');
            Route::patch('/{period}/approve',     [PayrollPeriodController::class, 'approve'])->middleware('permission:payroll.periods.approve');
            Route::patch('/{period}/finalize',    [PayrollPeriodController::class, 'finalize'])->middleware('permission:payroll.periods.finalize');
            Route::get('/{period}/bank-file',     [PayrollPeriodController::class, 'bankFile'])->middleware('permission:payroll.periods.finalize');
        });

        Route::prefix('payrolls')->group(function () {
            Route::get('/',                       [PayrollController::class, 'index'])->middleware('permission:payroll.view');
            Route::get('/{payroll}',              [PayrollController::class, 'show'])->middleware('permission:payroll.view');
            Route::post('/{payroll}/recompute',   [PayrollController::class, 'recompute'])->middleware('permission:payroll.periods.compute');
            Route::get('/{payroll}/payslip',      [PayrollController::class, 'payslip'])->middleware('permission:payroll.view');
        });

        Route::prefix('payroll-adjustments')->group(function () {
            Route::get('/',                       [PayrollAdjustmentController::class, 'index'])->middleware('permission:payroll.view');
            Route::post('/',                      [PayrollAdjustmentController::class, 'store'])->middleware('permission:payroll.adjustments.create');
            Route::get('/{adjustment}',           [PayrollAdjustmentController::class, 'show'])->middleware('permission:payroll.view');
            Route::patch('/{adjustment}/approve', [PayrollAdjustmentController::class, 'approve'])->middleware('permission:payroll.adjustments.create');
            Route::patch('/{adjustment}/reject',  [PayrollAdjustmentController::class, 'reject'])->middleware('permission:payroll.adjustments.create');
        });
    });
}
