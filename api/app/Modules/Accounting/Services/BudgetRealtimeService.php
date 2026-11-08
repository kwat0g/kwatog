<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\OutboxService;
use App\Modules\Accounting\Events\BudgetChanged;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages transaction-coalesced budget refresh signals in the durable outbox. */
final class BudgetRealtimeService
{
    /** Status changes alter budget detail actions, so notify active record viewers too. */
    public function stageFiscalYearBudgets(int $fiscalYearId): void
    {
        Budget::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->get()
            ->each(fn (Budget $budget) => $this->stage($budget, 'updated'));
    }

    public function stage(Budget $budget, string $action): void
    {
        $transactionId = $this->transactionId();
        $actorHashId = $this->actorHashId(auth()->user() instanceof User ? (int) auth()->id() : null);

        app(OutboxService::class)->recordCoalesced(new BudgetChanged(
            budgetHashId: (string) $budget->hash_id,
            action: $action,
            version: $budget->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: $actorHashId,
        ), "budget-refresh:{$budget->getKey()}:{$transactionId}");
    }

    /** Refreshes budget lists and summaries for fiscal-year and sync changes. */
    public function stageDomainRefresh(string $action, ?string $version = null): void
    {
        $transactionId = $this->transactionId();
        $actorHashId = $this->actorHashId(auth()->user() instanceof User ? (int) auth()->id() : null);

        app(OutboxService::class)->recordCoalesced(new BudgetChanged(
            budgetHashId: null,
            action: $action,
            version: $version ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: $actorHashId,
        ), "budget-domain-refresh:{$transactionId}");
    }

    private function transactionId(): string
    {
        return DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
    }

    private function actorHashId(?int $actorId): ?string
    {
        if ($actorId === null) {
            return null;
        }

        $hashId = User::query()->find($actorId)?->hash_id;

        return is_string($hashId) ? $hashId : null;
    }
}
