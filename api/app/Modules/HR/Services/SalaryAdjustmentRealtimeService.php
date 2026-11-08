<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\SalaryAdjustmentChanged;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\SalaryAdjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces salary-adjustment status and metadata changes per transaction. */
final class SalaryAdjustmentRealtimeService
{
    /**
     * Approval steps are part of the salary-adjustment queue representation
     * even when the parent status remains pending. Advance its aggregate
     * version without recursively firing the parent observer.
     */
    public function stageApprovalChange(SalaryAdjustment $adjustment, string $action): void
    {
        $current = SalaryAdjustment::query()->lockForUpdate()->findOrFail($adjustment->getKey());
        $updatedAt = now()->startOfSecond();
        if ($current->updated_at !== null && ! $updatedAt->greaterThan($current->updated_at)) {
            $updatedAt = $current->updated_at->copy()->startOfSecond()->addSecond();
        }

        SalaryAdjustment::query()->whereKey($adjustment->getKey())->update(['updated_at' => $updatedAt]);
        $adjustment->setAttribute('updated_at', $updatedAt);

        $this->stage($adjustment, $action);
    }

    public function stage(SalaryAdjustment $adjustment, string $action): void
    {
        $employee = Employee::query()->whereKey($adjustment->employee_id)->first(['id']);
        $related = $employee === null ? [] : [[
            'entity_type' => 'employee',
            'hash_id' => (string) $employee->hash_id,
        ]];
        $actor = auth()->user();
        $actorId = $actor instanceof User ? (string) $actor->hash_id : null;
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new SalaryAdjustmentChanged(
            hashId: (string) $adjustment->hash_id,
            action: $action,
            version: $adjustment->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            related: $related,
            actorId: $actorId,
        ), "salary-adjustment-refresh:{$adjustment->getKey()}:{$transactionId}");
    }
}
