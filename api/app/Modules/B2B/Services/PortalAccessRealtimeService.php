<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Services\OutboxService;
use App\Modules\B2B\Events\PortalAccountAccessRevoked;
use App\Modules\B2B\Events\PortalAccessChanged;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces portal-access directory refreshes within a database transaction. */
final class PortalAccessRealtimeService
{
    public function changed(): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new PortalAccessChanged(),
            "b2b-portal-access-refresh:{$transactionId}",
        );
    }

    public function revoked(CustomerPortalUser|SupplierPortalUser $user): void
    {
        $realm = $user instanceof CustomerPortalUser ? 'customer' : 'supplier';
        $userHashId = (string) $user->hash_id;
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new PortalAccountAccessRevoked($realm, $userHashId),
            "b2b-portal-account-revoked:{$realm}:{$userHashId}:{$transactionId}",
        );
    }
}
