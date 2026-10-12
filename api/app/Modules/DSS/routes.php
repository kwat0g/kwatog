<?php

declare(strict_types=1);

use App\Modules\DSS\Controllers\RecommendationController;
use Illuminate\Support\Facades\Route;

/*
 * DSS Wave 0-3 — Decision Center API.
 * Auto-mounted under /api/v1 by App\Providers\ModuleServiceProvider.
 * Every read is scoped to detectors the caller may see (owner permissions +
 * advisory/assisted mode); dss.view is reachability, scoping is the gate.
 */
Route::middleware(['auth:sanctum', 'feature:dss'])->prefix('dss')->group(function () {
    Route::get('/recommendations', [RecommendationController::class, 'index'])
        ->middleware('permission:dss.view');
    Route::get('/recommendations/{recommendation}', [RecommendationController::class, 'show'])
        ->middleware('permission:dss.view');
    Route::post('/recommendations/{recommendation}/actions', [RecommendationController::class, 'act'])
        ->middleware('permission:dss.view');
    Route::post('/recommendations/{recommendation}/outcome', [RecommendationController::class, 'outcome'])
        ->middleware('permission:dss.view');
});
