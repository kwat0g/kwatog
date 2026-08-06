<?php

declare(strict_types=1);

use App\Modules\Attendance\Controllers\AttendanceController;
use App\Modules\Attendance\Controllers\HolidayController;
use App\Modules\Attendance\Controllers\OvertimeController;
use App\Modules\Attendance\Controllers\ShiftController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:attendance'])->prefix('attendance')->group(function () {
    // Shifts
    Route::get('/shifts', [ShiftController::class, 'index'])->middleware('permission_any:attendance.edit,attendance.shifts.manage');
    Route::post('/shifts', [ShiftController::class, 'store'])->middleware('permission:attendance.shifts.manage');
    Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->middleware('permission_any:attendance.edit,attendance.shifts.manage');
    Route::put('/shifts/{shift}', [ShiftController::class, 'update'])->middleware('permission:attendance.shifts.manage');
    Route::delete('/shifts/{shift}', [ShiftController::class, 'destroy'])->middleware('permission:attendance.shifts.manage');
    Route::patch('/shifts/{shift}/restore', [ShiftController::class, 'restore'])->middleware('permission:attendance.shifts.manage');
    Route::post('/shifts/bulk-assign', [ShiftController::class, 'bulkAssign'])->middleware('permission:attendance.shifts.manage');
    Route::post('/shifts/assign-employee/{employee}', [ShiftController::class, 'assignEmployee'])->middleware('permission:attendance.shifts.manage');
    Route::get('/shifts/current/{employee}', [ShiftController::class, 'currentEmployeeShift'])->middleware('permission_any:attendance.edit,attendance.shifts.manage');

    // Holidays
    Route::get('/holidays', [HolidayController::class, 'index'])->middleware('permission_any:attendance.edit,attendance.holidays.manage');
    Route::get('/holidays/options', [HolidayController::class, 'options'])->middleware('permission_any:attendance.edit,attendance.holidays.manage');
    Route::post('/holidays', [HolidayController::class, 'store'])->middleware('permission:attendance.holidays.manage');
    Route::get('/holidays/{holiday}', [HolidayController::class, 'show'])->middleware('permission_any:attendance.edit,attendance.holidays.manage');
    Route::put('/holidays/{holiday}', [HolidayController::class, 'update'])->middleware('permission:attendance.holidays.manage');
    Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy'])->middleware('permission:attendance.holidays.manage');
    Route::patch('/holidays/{holiday}/restore', [HolidayController::class, 'restore'])->middleware('permission:attendance.holidays.manage');

    // Attendance
    Route::get('/attendances/options', [AttendanceController::class, 'options'])->middleware('permission:attendance.view');
    Route::get('/attendances', [AttendanceController::class, 'index'])->middleware('permission:attendance.view');
    Route::post('/attendances', [AttendanceController::class, 'store'])->middleware('permission:attendance.edit');
    Route::get('/attendances/{attendance}', [AttendanceController::class, 'show'])->middleware('permission:attendance.view');
    Route::put('/attendances/{attendance}', [AttendanceController::class, 'update'])->middleware('permission:attendance.edit');
    Route::delete('/attendances/{attendance}', [AttendanceController::class, 'destroy'])->middleware('permission:attendance.edit');
    Route::patch('/attendances/{attendance}/restore', [AttendanceController::class, 'restore'])->middleware('permission:attendance.edit');
    Route::post('/attendances/import', [AttendanceController::class, 'import'])->middleware('permission:attendance.import');

    // Overtime requests
    Route::get('/overtime-requests', [OvertimeController::class, 'index'])->middleware('permission:attendance.view');
    Route::post('/overtime-requests', [OvertimeController::class, 'store'])->middleware('permission:attendance.ot.create');
    // Literal segment MUST precede the {overtime} binding below.
    Route::get('/overtime-requests/options', [OvertimeController::class, 'options'])->middleware('permission:attendance.view');
    Route::get('/overtime-requests/{overtime}', [OvertimeController::class, 'show'])->middleware('permission:attendance.view');
    Route::patch('/overtime-requests/{overtime}/approve', [OvertimeController::class, 'approve'])->middleware('permission:attendance.ot.approve');
    Route::patch('/overtime-requests/{overtime}/reject', [OvertimeController::class, 'reject'])->middleware('permission:attendance.ot.approve');
    Route::delete('/overtime-requests/{overtime}', [OvertimeController::class, 'cancel'])->middleware('permission:attendance.view');
    Route::post('/overtime-requests/bulk-approve', [OvertimeController::class, 'bulkApprove'])->middleware('permission:attendance.ot.approve');
});
