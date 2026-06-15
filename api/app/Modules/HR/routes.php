<?php

declare(strict_types=1);

use App\Modules\HR\Controllers\DepartmentController;
use App\Modules\HR\Controllers\EmployeeAccountController;
use App\Modules\HR\Controllers\EmployeeController;
use App\Modules\HR\Controllers\EmployeeDirectoryController;
use App\Modules\HR\Controllers\EmployeeOnboardingController;
use App\Modules\HR\Controllers\PositionController;
use App\Modules\HR\Controllers\ProfileUpdateReviewController;
use App\Modules\HR\Controllers\SelfServiceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:hr'])->prefix('hr')->group(function () {
    // Series F / Task F5 — Employee directory + org chart.
    Route::get('/directory',           [EmployeeDirectoryController::class, 'index'])
        ->middleware('permission:hr.directory.view');
    Route::get('/directory/org-chart', [EmployeeDirectoryController::class, 'orgChart'])
        ->middleware('permission:hr.directory.view');

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

    // T3.4.A — Employee training records (admin assign / complete / cancel).
    Route::get('/employees/{employee}/trainings',  [\App\Modules\HR\Controllers\EmployeeTrainingController::class, 'index'])
        ->middleware('permission:hr.employees.trainings.view');
    Route::post('/employees/{employee}/trainings', [\App\Modules\HR\Controllers\EmployeeTrainingController::class, 'store'])
        ->middleware('permission:hr.employees.trainings.manage');
    Route::patch('/employee-trainings/{record}/complete', [\App\Modules\HR\Controllers\EmployeeTrainingController::class, 'complete'])
        ->middleware('permission:hr.employees.trainings.manage');
    Route::patch('/employee-trainings/{record}/cancel',   [\App\Modules\HR\Controllers\EmployeeTrainingController::class, 'cancel'])
        ->middleware('permission:hr.employees.trainings.manage');

    // T3.4.A — Training catalog (admin CRUD).
    Route::prefix('trainings')->group(function () {
        Route::get('/',              [\App\Modules\HR\Controllers\TrainingController::class, 'index'])
            ->middleware('permission:hr.trainings.view');
        Route::post('/',             [\App\Modules\HR\Controllers\TrainingController::class, 'store'])
            ->middleware('permission:hr.trainings.manage');
        Route::get('/{training}',    [\App\Modules\HR\Controllers\TrainingController::class, 'show'])
            ->middleware('permission:hr.trainings.view');
        Route::patch('/{training}',  [\App\Modules\HR\Controllers\TrainingController::class, 'update'])
            ->middleware('permission:hr.trainings.manage');
        Route::delete('/{training}', [\App\Modules\HR\Controllers\TrainingController::class, 'destroy'])
            ->middleware('permission:hr.trainings.manage');
    });

    // U3 (HR side) — review queue for profile-update requests.
    Route::prefix('profile-update-requests')->group(function () {
        Route::get('/', [ProfileUpdateReviewController::class, 'index'])
            ->middleware('permission:hr.employees.view');
        Route::patch('/{profileUpdateRequest}/review', [ProfileUpdateReviewController::class, 'review'])
            ->middleware('permission:hr.employees.edit');
        // Task SS2 — Finance leg for bank-account changes (dual approval).
        Route::patch('/{profileUpdateRequest}/finance-review', [ProfileUpdateReviewController::class, 'financeReview'])
            ->middleware('permission:hr.profile_updates.finance_review');
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

        // Task SS1 — overtime requests (scoped to the session employee).
        Route::get('/overtime',               [SelfServiceController::class, 'overtime']);
        Route::post('/overtime',              [SelfServiceController::class, 'applyOvertime']);
        Route::delete('/overtime/{id}',       [SelfServiceController::class, 'cancelOvertime']);

        // Task SS3 — employee document downloads (auto-generated certificates).
        Route::get('/documents',                              [SelfServiceController::class, 'documents']);
        Route::get('/documents/employment-certificate',       [SelfServiceController::class, 'employmentCertificate']);
        Route::get('/documents/contributions/{type}',         [SelfServiceController::class, 'contributionCertificate']);
        Route::get('/documents/bir-2316',                     [SelfServiceController::class, 'bir2316']);
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
