<?php

declare(strict_types=1);

use App\Modules\Forecasting\Controllers\DemandForecastController;
use App\Modules\Forecasting\Controllers\ForecastMrpController;
use App\Modules\Forecasting\Controllers\StockOutProjectionController;
use Illuminate\Support\Facades\Route;

/*
 * ADV11 — Demand & Sales Forecasting routes.
 * Auto-mounted under /api/v1 by App\Providers\ModuleServiceProvider.
 */

Route::middleware(['auth:sanctum'])->prefix('forecasting')->group(function () {

    /* ─── Demand forecasts ─── */
    Route::get('/demand-forecasts',
        [DemandForecastController::class, 'index'])
        ->middleware('permission:forecasting.view');
    Route::get('/demand-forecasts/historical',
        [DemandForecastController::class, 'historical'])
        ->middleware('permission:forecasting.view');
    Route::post('/demand-forecasts/recompute',
        [DemandForecastController::class, 'recompute'])
        ->middleware('permission:forecasting.manage');
    Route::post('/demand-forecasts/manual',
        [DemandForecastController::class, 'storeManual'])
        ->middleware('permission:forecasting.manage');

    /* ─── Forecast accuracy (MAPE) ─── */
    Route::get('/accuracy',
        [DemandForecastController::class, 'accuracy'])
        ->middleware('permission:forecasting.view');

    /* ─── Stock-out projection ─── */
    Route::get('/stock-out',
        [StockOutProjectionController::class, 'index'])
        ->middleware('permission:forecasting.view');

    /* ─── Forecast-driven MRP projection (forward material plan) ─── */
    Route::get('/mrp-projection',
        [ForecastMrpController::class, 'project'])
        ->middleware('permission:forecasting.view');
});
