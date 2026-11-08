<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Services\OutboxService;
use App\Modules\Admin\Events\AdminRoleChanged;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one latest Admin Role refresh per role and database transaction. */
final class AdminRoleRealtimeService
{
    public function stage(Role $role, string $action): void
    {
        $version = $role->getAttribute('updated_at')?->format('Y-m-d\TH:i:s.uP')
            ?? now()->format('Y-m-d\TH:i:s.uP');
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(new AdminRoleChanged(
            roleHashId: (string) $role->hash_id,
            action: $action,
            version: $version,
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
        ), "admin-role-refresh:{$role->getKey()}:{$transactionId}");
    }
}
