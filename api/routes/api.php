<?php

declare(strict_types=1);

use App\Common\Controllers\AlertController;
use App\Common\Controllers\ApprovalBoardController;
use App\Common\Controllers\CalendarController;
use App\Common\Controllers\ChainBottleneckController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

/*
 * Series C — Task C4. Broadcasting auth endpoint used by Reverb.
 *
 * spa/src/lib/echo.ts is configured with
 *   authEndpoint: '/api/v1/broadcasting/auth'
 * Laravel 11's default `Broadcast::routes()` registers at
 * `/broadcasting/auth` (no prefix), so without this explicit route every
 * private-channel subscription 404s silently and real-time updates fall
 * back to no-ops. We register the same controller under the API prefix
 * with auth:sanctum middleware so cookie-based session auth attaches.
 *
 * Both GET and POST are accepted — Pusher.js sends POST, but the
 * `/broadcasting/auth` Laravel default also accepts GET, so we mirror.
 */
Route::match(['get', 'post'], '/broadcasting/auth', [BroadcastController::class, 'authenticate'])
    ->middleware(['auth:sanctum']);

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

Route::get('/health', function () {
    // Phase 4 — deep healthcheck. Reports component-by-component so a load
    // balancer can route around partial failures and uptime monitors get
    // useful telemetry instead of a flat 200.
    $checks = [
        'app'   => true,
        'time'  => now()->toIso8601String(),
        'db'    => false,
        'redis' => false,
        'queue' => null,
    ];

    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $checks['db'] = true;
    } catch (\Throwable $e) {
        // db stays false
    }

    try {
        if (\Illuminate\Support\Facades\Redis::connection()->ping()) {
            $checks['redis'] = true;
        }
    } catch (\Throwable $e) {
        // redis stays false
    }

    try {
        $checks['queue'] = \Illuminate\Support\Facades\Queue::size('default');
    } catch (\Throwable $e) {
        // queue stays null
    }

    $healthy = $checks['app'] && $checks['db'] && $checks['redis'];
    return response()->json([
        'status'  => $healthy ? 'ok' : 'degraded',
        'service' => 'ogami-api',
        'checks'  => $checks,
    ], $healthy ? 200 : 503);
});

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

/* ─── Chain bottlenecks (Series C — Task C5) ─────────────────────── */
Route::middleware(['auth:sanctum'])->prefix('chain')->group(function () {
    Route::get('/bottlenecks', [ChainBottleneckController::class, 'index'])
        ->middleware('permission:dashboard.view_bottlenecks');
});

/* ─── Series F — Cross-module aggregator endpoints ───────────────── */

// Task F1 — Calendar (per-layer permissions enforced inside the service)
Route::middleware(['auth:sanctum'])
    ->get('/calendar/events', [CalendarController::class, 'index'])
    ->middleware('permission:calendar.view');

// Task F2 — Approvals Kanban board (read-only — mutations stay on per-entity controllers)
Route::middleware(['auth:sanctum'])
    ->get('/approvals/board', [ApprovalBoardController::class, 'index'])
    ->middleware('permission:approvals.board.view');
