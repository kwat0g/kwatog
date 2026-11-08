<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Events\PriceRequestChanged;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\PriceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces every Eloquent PriceRequest write into one after-commit refresh per row/transaction. */
final class PriceRequestRealtimeService
{
    public function stage(PriceRequest $request, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $customerHashId = (string) Customer::withTrashed()->findOrFail($request->customer_id)->hash_id;
        $version = (string) $request->version;
        $actorHashId = $this->actorHashId($request);
        $agreementHashId = $request->agreement_id === null
            ? null
            : PriceAgreement::query()->find($request->agreement_id)?->hash_id;

        app(OutboxService::class)->recordCoalesced(
            new PriceRequestChanged(
                requestHashId: (string) $request->hash_id,
                customerHashId: $customerHashId,
                action: $action,
                version: $version,
                actorHashId: $actorHashId,
                agreementHashId: $agreementHashId,
            ),
            "price-request-refresh:{$request->getKey()}:{$transactionId}",
        );
    }

    private function actorHashId(PriceRequest $request): ?string
    {
        $history = $request->history ?? [];
        $lastActor = collect($history)->last()['actor'] ?? null;
        if (is_string($lastActor) && $lastActor !== '') {
            return $lastActor;
        }

        if ($request->requested_by !== null) {
            $portalActor = CustomerPortalUser::query()->find($request->requested_by);

            return $portalActor?->hash_id;
        }

        $actor = auth()->user();

        return $actor instanceof User || $actor instanceof CustomerPortalUser
            ? (string) $actor->hash_id
            : null;
    }
}
