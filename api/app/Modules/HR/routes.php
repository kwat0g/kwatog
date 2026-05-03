<?php

declare(strict_types=1);

use App\Modules\HR\Controllers\DepartmentController;
use App\Modules\HR\Controllers\EmployeeController;
use App\Modules\HR\Controllers\PositionController;
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
        Route::get('/{employee}', [EmployeeController::class, 'show'])->middleware('permission:hr.employees.view');
        Route::put('/{employee}', [EmployeeController::class, 'update'])->middleware('permission:hr.employees.edit');
        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:hr.employees.delete');
        Route::patch('/{employee}/separate', [EmployeeController::class, 'separate'])->middleware('permission:hr.employees.separate');

        // Sprint 8 — Task 71: separation + clearance flow
        Route::post('/{employee}/separation', [\App\Modules\HR\Controllers\SeparationController::class, 'initiate'])
            ->middleware('permission:hr.separation.initiate');
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
