<?php

declare(strict_types=1);

use App\Common\Services\ChainBroadcaster;
use App\Common\Support\ApprovalSourceScope;
use App\Common\Support\ChainDefinitions;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Models\Machine;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Policies\PurchaseOrderAccessPolicy;
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
$canViewSubject = static function (User $user, string $hashId, string $permission, string $model): bool {
    if (! $user->hasPermission($permission)) {
        return false;
    }

    $id = HashIdFilter::decode($hashId, $model);
    if ($id === null) {
        return false;
    }

    return ApprovalSourceScope::hasScopeFor($model, $user)
        ? ApprovalSourceScope::visibleIds($model, [$id], $user) !== []
        : $model::query()->whereKey($id)->exists();
};

Broadcast::channel('production.dashboard', fn (User $user): bool => $user->hasPermission('production.dashboard.view')
);

Broadcast::channel('production.wo.{hashId}', fn (User $user, string $hashId): bool => $canViewSubject(
    $user,
    $hashId,
    'production.work_orders.view',
    WorkOrder::class,
)
);

Broadcast::channel('production.machine.{hashId}', fn (User $user, string $hashId): bool => $canViewSubject(
    $user,
    $hashId,
    'mrp.machines.view',
    Machine::class,
)
);

/* Sprint 8 — Task 78. New channels. */

// Payroll period progress contains company-wide run metadata.
Broadcast::channel('payroll.period.{hashId}', fn (User $user, string $hashId): bool => $canViewSubject(
    $user,
    $hashId,
    'payroll.periods.view',
    PayrollPeriod::class,
)
);

// Maintenance dashboard — open WO updates push live
Broadcast::channel('maintenance.dashboard', fn (User $user): bool => $user->hasPermission('maintenance.view')
);

// Per-user notifications channel — backs the SPA bell + toast updates.
// Laravel's default convention is `App.Models.User.{id}` for notifiable
// listeners; we use the explicit `user.{id}` channel for clarity.
Broadcast::channel('user.{userId}', fn (User $user, string $userId): bool => $user->hasPermission('notifications.view')
    && $user->id === User::tryDecodeHash($userId)
);

// Sidebar badge counts — any authenticated user may listen; the payload is
// empty and each client refetches only the keys it has permission to see.
Broadcast::channel('badges', fn (User $user): bool => true);

/* Series C — Task C4. Chain progress (sales_order/work_order/purchase_order/
 * delivery/grn). One private channel per entity instance. Permission is
 * derived from the entity-type slug. Hash IDs are passed verbatim — the
 * subscribing component is the only consumer that knows what they map to.
 */
Broadcast::channel(
    'chain.{entityType}.{hashId}',
    function (User $user, string $entityType, string $hashId) use ($canViewSubject): bool {
        if (! in_array($entityType, ChainDefinitions::allowedTypes(), true)) {
            return false;
        }

        if ($entityType === 'purchase_order') {
            if (! $user->hasPermission('purchasing.view') && ! $user->hasPermission('purchasing.po.approve')) {
                return false;
            }

            $id = PurchaseOrder::tryDecodeHash($hashId);
            $purchaseOrder = $id === null ? null : PurchaseOrder::query()->find($id);

            return $purchaseOrder !== null
                && app(PurchaseOrderAccessPolicy::class)->canView($user, $purchaseOrder);
        }

        $class = ChainBroadcaster::entityClassFor($entityType);

        return $class !== null && $canViewSubject(
            $user,
            $hashId,
            ChainDefinitions::viewPermission($entityType),
            $class,
        );
    }
);
