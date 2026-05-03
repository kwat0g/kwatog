<?php

declare(strict_types=1);

use App\Modules\Assets\Controllers\AssetController;
use App\Modules\Assets\Controllers\AssetDepreciationController;
use Illuminate\Support\Facades\Route;

/*
 * Assets module routes — Sprint 8 Task 70.
 * Mounted automatically under /api/v1 by App\Providers\ModuleServiceProvider.
 */

Route::middleware(['auth:sanctum', 'feature:assets'])->prefix('assets')->group(function () {
    Route::get('/',                 [AssetController::class, 'index'])->middleware('permission:assets.view');
    Route::post('/',                [AssetController::class, 'store'])->middleware('permission:assets.create');
    Route::get('/{asset}',          [AssetController::class, 'show'])->middleware('permission:assets.view');
    Route::put('/{asset}',          [AssetController::class, 'update'])->middleware('permission:assets.update');
    Route::delete('/{asset}',       [AssetController::class, 'destroy'])->middleware('permission:assets.delete');
    Route::post('/{asset}/dispose', [AssetController::class, 'dispose'])->middleware('permission:assets.dispose');
    Route::get('/{asset}/qr',       [AssetController::class, 'qrPayload'])->middleware('permission:assets.view');
});

Route::middleware(['auth:sanctum', 'feature:assets'])->prefix('asset-depreciations')->group(function () {
    Route::get('/',     [AssetDepreciationController::class, 'index'])->middleware('permission:assets.depreciation.view');
    Route::post('/run', [AssetDepreciationController::class, 'runMonth'])->middleware('permission:assets.depreciation.run');
});
