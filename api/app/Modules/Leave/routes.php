<?php

declare(strict_types=1);

use App\Modules\Leave\Controllers\LeaveBalanceController;
use App\Modules\Leave\Controllers\LeaveRequestController;
use App\Modules\Leave\Controllers\LeaveTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:leave'])->prefix('leaves')->group(function () {
    // Leave types
    Route::get('/types',                 [LeaveTypeController::class, 'index'])->middleware('permission:leave.view');
    Route::post('/types',                [LeaveTypeController::class, 'store'])->middleware('permission:leave.types.manage');
    Route::get('/types/{leaveType}',     [LeaveTypeController::class, 'show'])->middleware('permission:leave.view');
    Route::put('/types/{leaveType}',     [LeaveTypeController::class, 'update'])->middleware('permission:leave.types.manage');
    Route::delete('/types/{leaveType}',  [LeaveTypeController::class, 'destroy'])->middleware('permission:leave.types.manage');

    // Balances
    Route::get('/balances/me',                [LeaveBalanceController::class, 'me'])->middleware('permission:leave.view');
    Route::get('/balances/{employee}',        [LeaveBalanceController::class, 'forEmployee'])->middleware('permission:leave.view');

    // Requests
    Route::get('/requests',                                      [LeaveRequestController::class, 'index'])->middleware('permission:leave.view');
    Route::post('/requests',                                     [LeaveRequestController::class, 'store'])->middleware('permission:leave.create');
    Route::get('/requests/{leaveRequest}',                       [LeaveRequestController::class, 'show'])->middleware('permission:leave.view');
    Route::patch('/requests/{leaveRequest}/approve-dept',        [LeaveRequestController::class, 'approveDept'])->middleware('permission:leave.approve_dept');
    Route::patch('/requests/{leaveRequest}/approve-hr',          [LeaveRequestController::class, 'approveHR'])->middleware('permission:leave.approve_hr');
    Route::patch('/requests/{leaveRequest}/reject',              [LeaveRequestController::class, 'reject'])->middleware('permission:leave.approve_dept');
    Route::patch('/requests/{leaveRequest}/cancel',              [LeaveRequestController::class, 'cancel'])->middleware('permission:leave.create');
});
