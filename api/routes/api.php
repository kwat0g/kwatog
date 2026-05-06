<?php

declare(strict_types=1);

use App\Common\Controllers\AlertController;
use App\Common\Controllers\ChainController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (mounted at /api/v1)
|--------------------------------------------------------------------------
|
| Per-module routes live in app/Modules/<Module>/routes.php and are
| auto-mounted by App\Providers\ModuleServiceProvider during boot.
|
| Cross-module / utility routes belong here.
|
*/

Route::get('/health', fn () => response()->json([
    'status'   => 'ok',
    'service'  => 'ogami-api',
    'time'     => now()->toIso8601String(),
]));

/* ─── Alerts (Task A2) — cross-module so registered here ─────────── */
Route::middleware(['auth:sanctum'])->prefix('alerts')->group(function () {
    Route::get('/',                  [AlertController::class, 'index'])
        ->middleware('permission:alerts.view');
    Route::get('/unread-count',      [AlertController::class, 'unreadCount'])
        ->middleware('permission:alerts.view');
    Route::patch('/{alert}/dismiss', [AlertController::class, 'dismiss'])
        ->middleware('permission:alerts.dismiss');
    Route::patch('/{alert}/read',    [AlertController::class, 'markRead'])
        ->middleware('permission:alerts.view');
});

/* ─── WS-D.1 Chain registry — definitions endpoint ───────────────────── */
Route::middleware(['auth:sanctum'])->prefix('chains')->group(function () {
    Route::get('/',                    [ChainController::class, 'index']);
    Route::get('/{key}/definition',    [ChainController::class, 'definition'])
        ->where('key', '[a-z_]+');
});
