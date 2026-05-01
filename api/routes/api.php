<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (mounted at /api/v1)
|--------------------------------------------------------------------------
|
| Per-module routes live in app/Modules/<Module>/routes.php and are
| auto-mounted by App\Providers\ModuleServiceProvider during boot.
|
| Only cross-module / utility routes belong here.
|
*/

Route::get('/health', fn () => response()->json([
    'status'   => 'ok',
    'service'  => 'ogami-api',
    'time'     => now()->toIso8601String(),
]));
