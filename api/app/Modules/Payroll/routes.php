<?php

declare(strict_types=1);

// Payroll module routes.
// Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.

use App\Modules\Payroll\Controllers\BirAlphalistController;
use App\Modules\Payroll\Controllers\DisbursementProofController;
use App\Modules\Payroll\Controllers\GovernmentTableController;
use App\Modules\Payroll\Controllers\PayrollAdjustmentController;
use App\Modules\Payroll\Controllers\PayrollAnomalyController;
use App\Modules\Payroll\Controllers\PayrollController;
use App\Modules\Payroll\Controllers\StatutoryExportController;
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

// T1.8 — CSV import endpoint for HR / payroll users (separate gate from admin CRUD).
Route::middleware('auth:sanctum')->group(function () {
    Route::post('gov-tables/{agency}/import', [GovernmentTableController::class, 'import'])
        ->middleware('permission:payroll.gov_tables.manage');
});

// ─── Payroll periods + payrolls + adjustments + bank files ─────
// Each controller is registered conditionally so this file remains valid as
// the sprint is built up incrementally (Tasks 25/26/27).
if (class_exists(PayrollPeriodController::class)) {
    Route::middleware(['auth:sanctum', 'feature:payroll'])->group(function () {
        Route::prefix('payroll-periods')->group(function () {
            Route::get('/',                       [PayrollPeriodController::class, 'index'])->middleware('permission:payroll.view');
            Route::get('/pipeline',               [PayrollPeriodController::class, 'pipeline'])->middleware('permission:payroll.view');
            Route::post('/',                      [PayrollPeriodController::class, 'store'])->middleware('permission:payroll.periods.create');
            Route::post('/thirteenth-month',      [PayrollPeriodController::class, 'runThirteenthMonth'])->middleware('permission:payroll.thirteenth_month.run');
            Route::get('/{period}',               [PayrollPeriodController::class, 'show'])->middleware('permission:payroll.view');
            Route::post('/{period}/compute',      [PayrollPeriodController::class, 'compute'])->middleware('permission:payroll.periods.compute');
            Route::patch('/{period}/approve',     [PayrollPeriodController::class, 'approve'])->middleware('permission:payroll.periods.approve');
            Route::patch('/{period}/finalize',    [PayrollPeriodController::class, 'finalize'])->middleware('permission:payroll.periods.finalize');
            Route::get('/{period}/bank-file',     [PayrollPeriodController::class, 'bankFile'])->middleware('permission:payroll.periods.finalize');
            Route::get('/{period}/variance',      [PayrollPeriodController::class, 'variance'])->middleware('permission:payroll.view');
            // ADV1 — Disbursement proof (salary deposit slip / bank confirmation).
            Route::patch('/{period}/mark-disbursed', [PayrollPeriodController::class, 'markDisbursed'])->middleware('permission:payroll.periods.finalize');
            // H-8 — Admin escape hatch for periods stuck at Processing because
            // the payroll job worker crashed before its finally block could
            // reset status. POST (not PATCH) — recovery action with side
            // effects (audit log row).
            Route::post('/{period}/force-unlock',    [PayrollPeriodController::class, 'forceUnlock'])->middleware('permission:payroll.periods.force_unlock');
        });

        // ADV1 — Disbursement proof CRUD (linked to a period).
        if (class_exists(\App\Modules\Payroll\Controllers\DisbursementProofController::class)) {
            Route::prefix('payroll-periods/{period}/disbursement-proofs')->group(function () {
                Route::get('/',                         [DisbursementProofController::class, 'index'])->middleware('permission:payroll.view');
                Route::post('/',                        [DisbursementProofController::class, 'store'])->middleware('permission:payroll.periods.finalize');
                Route::get('/{proof}',                  [DisbursementProofController::class, 'show'])->middleware('permission:payroll.view');
                Route::delete('/{proof}',               [DisbursementProofController::class, 'destroy'])->middleware('permission:payroll.periods.finalize');
            });
        }

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

        // ─── Payroll anomaly flags (Task A9) ─────────────────────
        Route::prefix('payroll-periods/{period}/anomalies')->group(function () {
            Route::get('/', [PayrollAnomalyController::class, 'index'])
                ->middleware('permission:payroll.anomalies.review');
        });
        Route::patch('/payroll-anomalies/{flag}/resolve', [PayrollAnomalyController::class, 'resolve'])
            ->middleware('permission:payroll.anomalies.review');

        // ─── BIR 2316 Alphalist export (Task 6) ──────────────────
        Route::get('/payroll/bir-alphalist', [BirAlphalistController::class, 'download'])
            ->middleware('permission:payroll.view');

        // ─── Statutory remittance exports (OGAMI-102/103) ─────────
        Route::prefix('payroll/statutory')->middleware('permission:payroll.view')->group(function () {
            Route::get('/1601c', [StatutoryExportController::class, 'bir1601c']);
        });
    });
}
