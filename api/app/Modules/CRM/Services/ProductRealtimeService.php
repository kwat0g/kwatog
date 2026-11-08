<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Events\CustomerCatalogChanged;
use App\Modules\CRM\Events\ProductChanged;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Services\AssignedWorkOrderRefreshService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages private Product and customer-catalog refresh signals. */
final class ProductRealtimeService
{
    private const MAX_CUSTOMERS_PER_EVENT = 100;

    public function stage(Product $product, string $action, ?User $actor = null): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $productHashId = (string) $product->hash_id;
        $version = $product->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP');
        $actor ??= auth()->user();

        app(OutboxService::class)->recordCoalesced(
            new ProductChanged(
                productHashId: $productHashId,
                action: $action,
                version: $version,
                actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
            ),
            "product-refresh:{$product->getKey()}:{$transactionId}",
        );

        app(AssignedWorkOrderRefreshService::class)->stageForProduct((int) $product->getKey());

        if (! app(SettingsService::class)->requiredBool('modules.b2b_portals')) {
            return;
        }

        $customerIds = CustomerPortalUser::query()
            ->where('is_active', true)
            ->where('must_change_password', false)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<=', now()))
            ->whereHas('customer', fn ($query) => $query->where('is_active', true))
            ->distinct()
            ->pluck('customer_id');
        if ($customerIds->isEmpty()) {
            return;
        }

        $customerHashIds = Customer::query()
            ->active()
            ->whereIn('id', $customerIds)
            ->get(['id'])
            ->map(static fn (Customer $customer): string => (string) $customer->hash_id)
            ->sort()
            ->values()
            ->all();

        foreach (array_chunk($customerHashIds, self::MAX_CUSTOMERS_PER_EVENT) as $chunk) {
            $audience = hash('sha256', implode(',', $chunk));
            app(OutboxService::class)->recordCoalesced(
                new CustomerCatalogChanged(
                    customerHashIds: $chunk,
                    action: $action,
                    version: $version,
                ),
                "customer-catalog-refresh:{$audience}:{$transactionId}",
            );
        }
    }

    /** @param list<int> $customerIds */
    public function stageCustomerCatalogForCustomers(array $customerIds, string $action, string $version): void
    {
        if (! app(SettingsService::class)->requiredBool('modules.b2b_portals')) {
            return;
        }

        $customerIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $customerIds),
            static fn (int $id): bool => $id > 0,
        )));
        sort($customerIds);
        if ($customerIds === []) {
            return;
        }

        $eligibleIds = CustomerPortalUser::query()
            ->whereIn('customer_id', $customerIds)
            ->where('is_active', true)
            ->where('must_change_password', false)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<=', now()))
            ->whereHas('customer', fn ($query) => $query->where('is_active', true))
            ->distinct()
            ->pluck('customer_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($eligibleIds === []) {
            return;
        }

        $customerHashIds = Customer::query()
            ->active()
            ->whereIn('id', $eligibleIds)
            ->get(['id'])
            ->map(static fn (Customer $customer): string => (string) $customer->hash_id)
            ->sort()
            ->values()
            ->all();
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        foreach (array_chunk($customerHashIds, self::MAX_CUSTOMERS_PER_EVENT) as $chunk) {
            $audience = hash('sha256', implode(',', $chunk));
            app(OutboxService::class)->recordCoalesced(
                new CustomerCatalogChanged(
                    customerHashIds: $chunk,
                    action: $action,
                    version: $version,
                ),
                "customer-catalog-refresh:{$audience}:{$transactionId}",
            );
        }
    }
}
