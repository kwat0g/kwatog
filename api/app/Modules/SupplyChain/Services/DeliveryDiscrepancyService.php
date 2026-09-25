<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Services\SystemUserResolver;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Services\ReturnCaseService;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Support\Str;

/** Compatibility entry point: all quantity disputes now use one case ledger. */
class DeliveryDiscrepancyService
{
    public function report(Delivery $delivery, int $customerId, int $portalUserId, array $data): Delivery
    {
        $portal = CustomerPortalUser::query()->where('customer_id', $customerId)->findOrFail($portalUserId);
        $system = app(SystemUserResolver::class);
        $system->impersonate(fn () => app(ReturnCaseService::class)->create([
            'source_kind' => 'delivery', 'source_id' => $delivery->hash_id,
            'request_key' => $data['request_key'] ?? (string) Str::uuid(),
            'description' => $data['rationale'], 'preferred_resolution' => 'advice',
            'lines' => array_map(fn ($line) => [
                'source_line_id' => $line['delivery_item_id'],
                'received_quantity' => $line['received_quantity'], 'defective_quantity' => '0',
            ], $data['lines']),
        ], $system->user(), $portal));
        return app(DeliveryService::class)->show($delivery);
    }

    public function reject(Delivery $delivery, string $reason, User $by): Delivery
    {
        $case = ReturnCase::query()->where('delivery_id', $delivery->id)
            ->whereNotIn('status', ['resolved', 'withdrawn', 'rejected'])->latest('id')->firstOrFail();
        app(ReturnCaseService::class)->act($case, ['action' => 'reject', 'message' => $reason], $by,
            ['type' => 'internal', 'id' => $by->id, 'name' => $by->name]);
        return app(DeliveryService::class)->show($delivery);
    }
}
