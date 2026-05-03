<?php

declare(strict_types=1);

use App\Modules\Attendance\Controllers\AttendanceController;
use App\Modules\Attendance\Controllers\HolidayController;
use App\Modules\Attendance\Controllers\OvertimeController;
use App\Modules\Attendance\Controllers\ShiftController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:attendance'])->prefix('attendance')->group(function () {
    // Shifts
    Route::get('/shifts',                  [ShiftController::class, 'index'])->middleware('permission:attendance.view');
    Route::post('/shifts',                 [ShiftController::class, 'store'])->middleware('permission:attendance.shifts.manage');
    Route::get('/shifts/{shift}',          [ShiftController::class, 'show'])->middleware('permission:attendance.view');
    Route::put('/shifts/{shift}',          [ShiftController::class, 'update'])->middleware('permission:attendance.shifts.manage');
    Route::delete('/shifts/{shift}',       [ShiftController::class, 'destroy'])->middleware('permission:attendance.shifts.manage');
    Route::post('/shifts/bulk-assign',     [ShiftController::class, 'bulkAssign'])->middleware('permission:attendance.shifts.manage');

    // Holidays
    Route::get('/holidays',                [HolidayController::class, 'index'])->middleware('permission:attendance.view');
    Route::post('/holidays',               [HolidayController::class, 'store'])->middleware('permission:attendance.holidays.manage');
    Route::get('/holidays/{holiday}',      [HolidayController::class, 'show'])->middleware('permission:attendance.view');
    Route::put('/holidays/{holiday}',      [HolidayController::class, 'update'])->middleware('permission:attendance.holidays.manage');
    Route::delete('/holidays/{holiday}',   [HolidayController::class, 'destroy'])->middleware('permission:attendance.holidays.manage');

    // Attendance
    Route::get('/attendances',                  [AttendanceController::class, 'index'])->middleware('permission:attendance.view');
    Route::post('/attendances',                 [AttendanceController::class, 'store'])->middleware('permission:attendance.edit');
    Route::get('/attendances/{attendance}',     [AttendanceController::class, 'show'])->middleware('permission:attendance.view');
    Route::put('/attendances/{attendance}',     [AttendanceController::class, 'update'])->middleware('permission:attendance.edit');
    Route::delete('/attendances/{attendance}',  [AttendanceController::class, 'destroy'])->middleware('permission:attendance.edit');
    Route::post('/attendances/import',          [AttendanceController::class, 'import'])->middleware('permission:attendance.import');

    // Overtime requests
    Route::get('/overtime-requests',                       [OvertimeController::class, 'index'])->middleware('permission:attendance.view');
    Route::post('/overtime-requests',                      [OvertimeController::class, 'store'])->middleware('permission:attendance.ot.create');
    Route::get('/overtime-requests/{overtime}',            [OvertimeController::class, 'show'])->middleware('permission:attendance.view');
    Route::patch('/overtime-requests/{overtime}/approve',  [OvertimeController::class, 'approve'])->middleware('permission:attendance.ot.approve');
    Route::patch('/overtime-requests/{overtime}/reject',   [OvertimeController::class, 'reject'])->middleware('permission:attendance.ot.approve');
});
