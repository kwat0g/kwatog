<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\OutboxService;
use App\Modules\Accounting\Events\AccountChanged;
use App\Modules\Accounting\Models\Account;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable, transaction-coalesced publisher for chart-of-accounts writes. */
final class AccountRealtimeService
{
    public function stage(Account $account, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();
        $actorHashId = $actor instanceof User ? $actor->hash_id : null;

        app(OutboxService::class)->recordCoalesced(new AccountChanged(
            accountHashId: (string) $account->hash_id,
            action: $action,
            version: $account->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: is_string($actorHashId) ? $actorHashId : null,
        ), "account-refresh:{$account->getKey()}:{$transactionId}");
    }
}
