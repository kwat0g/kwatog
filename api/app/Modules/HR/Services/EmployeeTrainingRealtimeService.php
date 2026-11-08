<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\EmployeeTrainingChanged;
use App\Modules\HR\Models\EmployeeTraining;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces employee-training refresh signals within each record transaction. */
final class EmployeeTrainingRealtimeService
{
    public function stage(EmployeeTraining $record, string $action): void
    {
        $actor = auth()->user();
        $actorId = $actor instanceof User && is_string($actor->hash_id) ? $actor->hash_id : null;
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new EmployeeTrainingChanged(
            hashId: (string) $record->hash_id,
            action: $action,
            version: $record->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorId: $actorId,
        ), "employee-training-refresh:{$record->getKey()}:{$transactionId}");
    }
}
