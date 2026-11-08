<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Events\PriceAgreementChanged;
use App\Modules\CRM\Models\PriceAgreement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages Price Agreement and customer-catalog refreshes from the model lifecycle. */
final class PriceAgreementRealtimeService
{
    public function stage(PriceAgreement $agreement, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $version = $agreement->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP');
        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(
            new PriceAgreementChanged(
                agreementHashId: (string) $agreement->hash_id,
                action: $action,
                version: $version,
                actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
            ),
            "price-agreement-refresh:{$agreement->getKey()}:{$transactionId}",
        );

        $customerIds = [(int) $agreement->customer_id];
        if ($action === 'updated') {
            $previousCustomerId = (int) $agreement->getRawOriginal('customer_id');
            if ($previousCustomerId > 0) {
                $customerIds[] = $previousCustomerId;
            }
        }

        app(ProductRealtimeService::class)->stageCustomerCatalogForCustomers(
            array_values(array_unique($customerIds)),
            $action,
            $version,
        );
    }
}
