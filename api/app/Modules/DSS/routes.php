<?php

declare(strict_types=1);

use App\Modules\DSS\Controllers\DssAdminController;
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

/* ─── Admin console (W0-6): detector fleet + kill switch + parameters ─── */
Route::middleware(['auth:sanctum', 'feature:dss'])->prefix('dss/admin')->group(function () {
    Route::get('/detectors', [DssAdminController::class, 'detectors'])
        ->middleware('permission:dss.manage');
    Route::patch('/detectors/{id}', [DssAdminController::class, 'updateDetector'])
        ->middleware('permission:dss.manage');
    Route::post('/detectors/{id}/run', [DssAdminController::class, 'runDetector'])
        ->middleware('permission:dss.manage');
    Route::get('/engine', [DssAdminController::class, 'engine'])
        ->middleware('permission:dss.manage');
    Route::patch('/engine', [DssAdminController::class, 'updateEngine'])
        ->middleware('permission:dss.admin');
    Route::get('/parameters', [DssAdminController::class, 'parameters'])
        ->middleware('permission:dss.manage');
    Route::put('/parameters', [DssAdminController::class, 'setParameter'])
        ->middleware('permission:dss.manage');
});
