<?php

declare(strict_types=1);

use App\Modules\Edge\Controllers\EdgeConditionController;
use App\Modules\Edge\Controllers\EdgeDeviceAdminController;
use App\Modules\Edge\Controllers\EdgeHealthController;
use App\Modules\Edge\Controllers\EdgeMeasurementController;
use App\Modules\Edge\Controllers\EdgeOutputController;
use App\Modules\Edge\Controllers\EdgeScanController;
use App\Modules\Edge\Middleware\StampEdgeLastSeen;
use Illuminate\Support\Facades\Route;

/* ─── Admin CRUD + token issuance ───────────────────────────── */
Route::prefix('admin/edge-devices')
    ->middleware(['auth:sanctum', 'session.timeout', 'password.expired',
                  'permission:admin.edge_devices.manage'])
    ->group(function (): void {
        Route::get('/',                          [EdgeDeviceAdminController::class, 'index']);
        Route::post('/',                         [EdgeDeviceAdminController::class, 'store']);
        Route::get('{edgeDevice}',               [EdgeDeviceAdminController::class, 'show']);
        Route::patch('{edgeDevice}',             [EdgeDeviceAdminController::class, 'update']);
        Route::patch('{edgeDevice}/deactivate',  [EdgeDeviceAdminController::class, 'deactivate']);
        Route::post('{edgeDevice}/tokens',       [EdgeDeviceAdminController::class, 'issueToken']);
        Route::delete('{edgeDevice}/tokens',     [EdgeDeviceAdminController::class, 'revokeTokens']);
    });

/* ─── Edge device API (per-device bearer token) ─────────────── */
Route::prefix('edge/v1')
    ->middleware(['auth:edge_device', StampEdgeLastSeen::class, 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/health', [EdgeHealthController::class, 'ping']);
        Route::post('/scan', [EdgeScanController::class, 'resolve'])
            ->middleware('ability:edge:scan');
        Route::post('/output', [EdgeOutputController::class, 'ingest'])
            ->middleware('ability:edge:output');
        Route::post('/condition', [EdgeConditionController::class, 'ingest'])
            ->middleware('ability:edge:condition');
        Route::post('/measurement', [EdgeMeasurementController::class, 'ingest'])
            ->middleware('ability:edge:measurement');
    });
