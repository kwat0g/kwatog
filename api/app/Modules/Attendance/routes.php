<?php

declare(strict_types=1);

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
});
