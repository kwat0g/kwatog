<?php

declare(strict_types=1);

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Sprint 6 — Task 55. Reverb / private channel routes.
 *
 * The SPA subscribes via Laravel Echo with cookie-based auth (Sanctum
 * stateful — withCredentials: true). The /broadcasting/auth endpoint is
 * already wired by Laravel; the closures below decide who can subscribe
 * to which private channel.
 */

Broadcast::channel('production.dashboard', fn (User $user): bool =>
    $user->hasPermission('production.dashboard.view')
);

Broadcast::channel('production.wo.{hashId}', fn (User $user, string $hashId): bool =>
    $user->hasPermission('production.work_orders.view')
);

Broadcast::channel('production.machine.{hashId}', fn (User $user, string $hashId): bool =>
    $user->hasPermission('mrp.machines.view')
);
