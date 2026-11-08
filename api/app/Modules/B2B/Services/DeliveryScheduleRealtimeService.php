<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Services\OutboxService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Events\B2bDeliveryScheduleChanged;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one coalesced refresh signal for a changed delivery schedule. */
final class DeliveryScheduleRealtimeService
{
    public function stage(DeliverySchedule $schedule, string $action): void
    {
        $customer = $schedule->customer_id === null
            ? null
            : Customer::query()->find((int) $schedule->customer_id, ['id']);
        $vendor = $schedule->vendor_id === null
            ? null
            : Vendor::query()->find((int) $schedule->vendor_id, ['id']);
        $customerHashId = $customer === null ? null : (string) $customer->hash_id;
        $vendorHashId = $vendor === null ? null : (string) $vendor->hash_id;

        $actor = auth()->user();
        $actorHashId = $actor instanceof User || $actor instanceof CustomerPortalUser || $actor instanceof SupplierPortalUser
            ? (string) $actor->hash_id
            : null;
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new B2bDeliveryScheduleChanged(
                hashId: (string) $schedule->hash_id,
                action: $action,
                version: $schedule->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
                customerHashId: $customerHashId,
                vendorHashId: $vendorHashId,
                actorHashId: $actorHashId,
            ),
            "delivery-schedule-refresh:{$schedule->getKey()}:{$transactionId}",
        );
    }
}
