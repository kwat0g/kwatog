<?php

declare(strict_types=1);

use App\Modules\HR\Controllers\DepartmentController;
use App\Modules\HR\Controllers\EmployeeAccountController;
use App\Modules\HR\Controllers\EmployeeController;
use App\Modules\HR\Controllers\EmployeeOnboardingController;
use App\Modules\HR\Controllers\PositionController;
use App\Modules\HR\Controllers\ProfileUpdateReviewController;
use App\Modules\HR\Controllers\SelfServiceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:hr'])->prefix('hr')->group(function () {
    // Departments
    Route::prefix('departments')->group(function () {
        Route::get('/tree', [DepartmentController::class, 'tree'])->middleware('permission:hr.departments.view');
        Route::get('/', [DepartmentController::class, 'index'])->middleware('permission:hr.departments.view');
        Route::post('/', [DepartmentController::class, 'store'])->middleware('permission:hr.departments.manage');
        Route::get('/{department}', [DepartmentController::class, 'show'])->middleware('permission:hr.departments.view');
        Route::put('/{department}', [DepartmentController::class, 'update'])->middleware('permission:hr.departments.manage');
        Route::delete('/{department}', [DepartmentController::class, 'destroy'])->middleware('permission:hr.departments.manage');
    });

    // Positions
    Route::prefix('positions')->group(function () {
        Route::get('/', [PositionController::class, 'index'])->middleware('permission:hr.positions.view');
        Route::post('/', [PositionController::class, 'store'])->middleware('permission:hr.positions.manage');
        Route::get('/{position}', [PositionController::class, 'show'])->middleware('permission:hr.positions.view');
        Route::put('/{position}', [PositionController::class, 'update'])->middleware('permission:hr.positions.manage');
        Route::delete('/{position}', [PositionController::class, 'destroy'])->middleware('permission:hr.positions.manage');
    });

    // Employees
    Route::prefix('employees')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->middleware('permission:hr.employees.view');
        Route::post('/', [EmployeeController::class, 'store'])->middleware('permission:hr.employees.create');

        // U1 — bulk account provisioning (must come before {employee} segment).
        Route::post('/bulk-provision-accounts', [EmployeeAccountController::class, 'bulkProvision'])
            ->middleware('permission:hr.employees.provision_account');

        Route::get('/{employee}', [EmployeeController::class, 'show'])->middleware('permission:hr.employees.view');
        Route::put('/{employee}', [EmployeeController::class, 'update'])->middleware('permission:hr.employees.edit');
        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:hr.employees.delete');
        Route::patch('/{employee}/separate', [EmployeeController::class, 'separate'])->middleware('permission:hr.employees.separate');

        // U1 — system account lifecycle.
        Route::get('/{employee}/account-status',     [EmployeeAccountController::class, 'status'])
            ->middleware('permission:hr.employees.account_status');
        Route::post('/{employee}/provision-account', [EmployeeAccountController::class, 'provision'])
            ->middleware('permission:hr.employees.provision_account');
        Route::post('/{employee}/deactivate-account',[EmployeeAccountController::class, 'deactivate'])
            ->middleware('permission:hr.employees.deactivate_account');
        Route::patch('/{employee}/reset-password',   [EmployeeAccountController::class, 'resetPassword'])
            ->middleware('permission:hr.employees.reset_password');

        // U4 — onboarding workflow.
        Route::get('/{employee}/onboarding',           [EmployeeOnboardingController::class, 'show'])
            ->middleware('permission:hr.employees.view');
        Route::post('/{employee}/onboarding/recompute',[EmployeeOnboardingController::class, 'recompute'])
            ->middleware('permission:hr.employees.edit');

        // Sprint 8 — Task 71: separation + clearance flow
        Route::post('/{employee}/separation', [\App\Modules\HR\Controllers\SeparationController::class, 'initiate'])
            ->middleware('permission:hr.separation.initiate');
    });

    // U3 (HR side) — review queue for profile-update requests.
    Route::prefix('profile-update-requests')->group(function () {
        Route::get('/', [ProfileUpdateReviewController::class, 'index'])
            ->middleware('permission:hr.employees.view');
        Route::patch('/{profileUpdateRequest}/review', [ProfileUpdateReviewController::class, 'review'])
            ->middleware('permission:hr.employees.edit');
    });

    // U3 — Self-service portal (every employee). Auth-only; the controller
    // resolves the employee from the session and rejects cross-employee access.
    Route::prefix('self-service')->group(function () {
        Route::get('/home',                   [SelfServiceController::class, 'home']);
        Route::get('/loans',                  [SelfServiceController::class, 'loans']);
        Route::post('/loans',                 [SelfServiceController::class, 'applyLoan']);
        Route::get('/profile',                [SelfServiceController::class, 'profile']);
        Route::post('/profile/request-update',[SelfServiceController::class, 'requestProfileUpdate']);
        Route::get('/profile/update-requests',[SelfServiceController::class, 'profileUpdateRequests']);
    });

    // Sprint 8 — Task 71: clearance lifecycle
    Route::prefix('clearances')->group(function () {
        Route::get('/',                          [\App\Modules\HR\Controllers\SeparationController::class, 'index'])
            ->middleware('permission:hr.separation.view');
        Route::get('/{clearance}',               [\App\Modules\HR\Controllers\SeparationController::class, 'show'])
            ->middleware('permission:hr.separation.view');
        Route::patch('/{clearance}/items',       [\App\Modules\HR\Controllers\SeparationController::class, 'signItem'])
            ->middleware('permission:hr.clearance.sign');
        Route::post('/{clearance}/final-pay/compute', [\App\Modules\HR\Controllers\SeparationController::class, 'computeFinalPay'])
            ->middleware('permission:hr.separation.finalize');
        Route::patch('/{clearance}/finalize',    [\App\Modules\HR\Controllers\SeparationController::class, 'finalize'])
            ->middleware('permission:hr.separation.finalize');
    });
});
