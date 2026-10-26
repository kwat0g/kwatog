<?php

declare(strict_types=1);

use App\Common\Services\SettingsService;
use App\Common\Support\ChainDefinitions;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Policies\PurchaseOrderAccessPolicy;
use App\Modules\Purchasing\Policies\PurchaseRequestAccessPolicy;
use App\Modules\SupplyChain\Models\Delivery;
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
Broadcast::channel('production.dashboard', fn (User $user): bool => $user->hasPermission('production.dashboard.view')
);

Broadcast::channel('production.wo.{hashId}', fn (User $user, string $hashId): bool => $user->hasPermission('production.work_orders.view')
);

Broadcast::channel('production.machine.{hashId}', fn (User $user, string $hashId): bool => $user->hasPermission('mrp.machines.view')
);

/* Sprint 8 — Task 78. New channels. */

// Payroll period progress contains company-wide run metadata.
Broadcast::channel('payroll.period.{hashId}', fn (User $user, string $hashId): bool => $user->hasPermission('payroll.periods.view')
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

/* Series C — Task C4. Chain progress. The channel follows the same module,
 * permission, and row checks as the entity's detail route. Hash IDs are passed
 * verbatim by the SPA.
 */
Broadcast::channel(
    'chain.{entityType}.{hashId}',
    function (User $user, string $entityType, string $hashId): bool {
        if (! in_array($entityType, ChainDefinitions::allowedTypes(), true)) {
            return false;
        }

        [$feature, $model, $permissionGroups] = match ($entityType) {
            'sales_order' => ['crm', SalesOrder::class, [['crm.sales_orders.view']]],
            'work_order' => ['production', WorkOrder::class, [['production.view', 'production.work_orders.view']]],
            'purchase_request' => ['purchasing', PurchaseRequest::class, [['purchasing.view']]],
            'purchase_order' => ['purchasing', PurchaseOrder::class, [['purchasing.view'], ['purchasing.po.approve']]],
            'delivery' => ['supply_chain', Delivery::class, [['supply_chain.view'], ['supply_chain.deliveries.view']]],
            'grn' => ['inventory', GoodsReceiptNote::class, [['inventory.view']]],
            'bill' => ['accounting', Bill::class, [['accounting.bills.view']]],
            'invoice' => ['accounting', Invoice::class, [['accounting.invoices.view']]],
            default => [null, null, []],
        };

        if ($feature === null || ! app(SettingsService::class)->requiredBool("modules.{$feature}")) {
            return false;
        }

        $hasRoutePermission = collect($permissionGroups)->contains(
            fn (array $group): bool => collect($group)->every(fn (string $permission): bool => $user->hasPermission($permission)),
        );
        if (! $hasRoutePermission) {
            return false;
        }

        $id = $model::tryDecodeHash($hashId);
        $record = $id === null ? null : $model::query()->find($id);
        if ($record === null) {
            return false;
        }

        return match ($entityType) {
            'purchase_request' => app(PurchaseRequestAccessPolicy::class)->canView($user, $record),
            'purchase_order' => app(PurchaseOrderAccessPolicy::class)->canView($user, $record),
            default => true,
        };
    }
);
