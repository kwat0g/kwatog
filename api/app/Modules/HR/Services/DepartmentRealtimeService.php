<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\OutboxService;
use App\Modules\HR\Events\DepartmentChanged;
use App\Modules\HR\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable, transaction-coalesced publisher for department mutations. */
final class DepartmentRealtimeService
{
    public function stage(Department $department, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new DepartmentChanged(
            hashId: (string) $department->hash_id,
            action: $action,
            version: $department->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            timestamp: now()->toISOString(),
        ), "department-refresh:{$department->getKey()}:{$transactionId}");
    }
}
