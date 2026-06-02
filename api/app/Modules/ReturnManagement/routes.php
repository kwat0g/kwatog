<?php

declare(strict_types=1);

use App\Modules\ReturnManagement\Controllers\ReturnRequestController;
use Illuminate\Support\Facades\Route;

/*
 * Return Management (RMA) routes — ADV12.
 * Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.
 */

Route::middleware(['auth:sanctum'])->prefix('return-management')->group(function () {

    /* ─── Return requests (RMA) ─── */
    Route::get('/return-requests',                    [ReturnRequestController::class, 'index'])  ->middleware('permission:return_management.view');
    Route::get('/return-requests/{returnRequest}',     [ReturnRequestController::class, 'show'])   ->middleware('permission:return_management.view');
    Route::post('/return-requests',                    [ReturnRequestController::class, 'store'])  ->middleware('permission:return_management.manage');

    // Workflow actions
    Route::post('/return-requests/{returnRequest}/submit',   [ReturnRequestController::class, 'submit'])  ->middleware('permission:return_management.manage');
    Route::post('/return-requests/{returnRequest}/approve',  [ReturnRequestController::class, 'approve']) ->middleware('permission:return_management.manage');
    Route::post('/return-requests/{returnRequest}/receive',  [ReturnRequestController::class, 'receive']) ->middleware('permission:return_management.manage');
    Route::post('/return-requests/{returnRequest}/inspect',  [ReturnRequestController::class, 'inspect']) ->middleware('permission:return_management.manage');
    Route::post('/return-requests/{returnRequest}/complete', [ReturnRequestController::class, 'complete'])->middleware('permission:return_management.manage');
    Route::post('/return-requests/{returnRequest}/reject',   [ReturnRequestController::class, 'reject'])  ->middleware('permission:return_management.manage');
    Route::post('/return-requests/{returnRequest}/cancel',   [ReturnRequestController::class, 'cancel'])  ->middleware('permission:return_management.manage');
});
