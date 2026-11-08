<?php

declare(strict_types=1);

namespace App\Modules\Loans\Services;

use App\Common\Services\OutboxService;
use App\Common\Models\ApprovalRecord;
use App\Modules\Auth\Models\User;
use App\Modules\Loans\Events\EmployeeLoanChanged;
use App\Modules\Loans\Models\EmployeeLoan;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces every write to one loan aggregate into one durable transaction ping. */
final class EmployeeLoanRealtimeService
{
    /**
     * Approval steps are part of the loan representation even when the loan
     * status is still pending. Advance the aggregate timestamp quietly so its
     * version changes without recursively firing a parent observer.
     */
    public function stageApprovalChange(EmployeeLoan $loan, ApprovalRecord $record, string $action): void
    {
        $updatedAt = now();
        EmployeeLoan::query()->whereKey($loan->getKey())->update(['updated_at' => $updatedAt]);
        $loan->setAttribute('updated_at', $updatedAt);

        $this->stage($loan, $action, $record->acted_at ?? $record->created_at);
    }

    public function stage(EmployeeLoan $loan, string $action, ?DateTimeInterface $sourceVersion = null): void
    {
        $loan->loadMissing('employee.user');
        $owner = $loan->employee?->user;
        $actor = Auth::user();
        $version = $loan->updated_at;

        if ($sourceVersion !== null && ($version === null || $sourceVersion > $version)) {
            $version = $sourceVersion;
        }

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new EmployeeLoanChanged(
                loanHashId: (string) $loan->hash_id,
                ownerUserHashId: $owner instanceof User ? (string) $owner->hash_id : null,
                action: $action,
                version: $version?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
                eventId: null,
                timestamp: now()->toISOString(),
            ),
            'employee-loan-refresh:'.$loan->getKey().':'.$transactionId,
        );
    }
}
