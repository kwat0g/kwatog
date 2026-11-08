<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\EmployeeSkillChanged;
use App\Modules\HR\Models\EmployeeSkill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces employee-skill refreshes by employee and database transaction. */
final class EmployeeSkillRealtimeService
{
    public function stage(EmployeeSkill $record, string $action): void
    {
        $employeeId = (int) $record->employee_id;
        if ($employeeId <= 0) {
            return;
        }

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(new EmployeeSkillChanged(
            employeeHashId: (string) app('hashids')->encode($employeeId),
            action: $action,
            version: $record->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
        ), "employee-skill-refresh:{$employeeId}:{$transactionId}");
    }
}
