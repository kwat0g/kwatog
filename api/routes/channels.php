<?php

declare(strict_types=1);

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Reverb / private channel routes.
 *
 * The SPA subscribes via Laravel Echo with cookie-based auth (Sanctum
 * stateful — withCredentials: true). The /broadcasting/auth endpoint is
 * already wired by Laravel; the closures below decide who can subscribe
 * to which private channel.
 *
 * Sprint 6 channels (production), Sprint 8 channels (payroll, inventory,
 * maintenance, user notifications).
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

/* Sprint 8 — Task 78. New channels. */

// Payroll period progress — visible to anyone with payroll.view
Broadcast::channel('payroll.period.{hashId}', fn (User $user, string $hashId): bool =>
    $user->hasPermission('payroll.view')
);

// Inventory stock changes — useful for warehouse staff dashboards
Broadcast::channel('inventory.stock', fn (User $user): bool =>
    $user->hasPermission('inventory.stock_levels.view') || $user->hasPermission('inventory.view')
);

// Maintenance dashboard — open WO updates push live
Broadcast::channel('maintenance.dashboard', fn (User $user): bool =>
    $user->hasPermission('maintenance.view')
);

// Per-user notifications channel — backs the SPA bell + toast updates.
// Laravel's default convention is `App.Models.User.{id}` for notifiable
// listeners; we use the explicit `user.{id}` channel for clarity.
Broadcast::channel('user.{userId}', fn (User $user, int $userId): bool =>
    $user->id === $userId
);
