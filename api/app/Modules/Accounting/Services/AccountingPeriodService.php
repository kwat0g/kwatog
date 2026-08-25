<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Enums\AccountingPeriodStatus;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * OGAMI-001 — GL period-close lock.
 *
 * Owns the lifecycle of `accounting_periods` rows and exposes the single
 * posting gate `assertPostingAllowed()` (the "PeriodGuard") consumed by every
 * GL-touching service (JournalEntry, Invoice, Bill, Payroll).
 *
 * Closing a month freezes posting/back-dating into it; reopening lifts the
 * freeze (status=reopened, which the guard treats as allowed).
 */
class AccountingPeriodService
{
    /**
     * Filtered, paginated list ordered by year/month desc.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $q = AccountingPeriod::query()->with(['closedBy:id,name,role_id', 'reopenedBy:id,name,role_id']);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['year'])) {
            $q->where('year', (int) $filters['year']);
        }

        return $q->orderByDesc('year')->orderByDesc('month')
            ->paginate(min((int) ($filters['per_page'] ?? 24), 100));
    }

    /**
     * Close a period. Idempotent-ish: closing an already-closed period is a
     * no-op; closing a reopened period re-locks it.
     */
    public function close(int $year, int $month, User $by): AccountingPeriod
    {
        $this->assertValidMonth($month);

        try {
            return $this->closeInTransaction($year, $month, $by);
        } catch (QueryException $e) {
            if (! $this->isDuplicatePeriodViolation($e)) {
                throw $e;
            }

            // PostgreSQL aborts the transaction that saw a 23505 statement
            // error. Recovery must therefore start after that transaction has
            // rolled back; issuing SELECT ... FOR UPDATE in the old closure
            // would only produce "current transaction is aborted".
            return DB::transaction(function () use ($year, $month, $by, $e): AccountingPeriod {
                $this->acquirePeriodLock($year, $month);
                $period = AccountingPeriod::query()
                    ->where('year', $year)
                    ->where('month', $month)
                    ->lockForUpdate()
                    ->first();

                if (! $period) {
                    // The unique violation came from this period's insert, so
                    // a missing winner means the database state changed again
                    // before recovery. Preserve the original database error.
                    throw $e;
                }

                if ($period->status === AccountingPeriodStatus::Closed) {
                    return $period;
                }

                $this->markClosed($period, $by);
                $period->save();

                return $period;
            });
        }
    }

    private function closeInTransaction(int $year, int $month, User $by): AccountingPeriod
    {
        return DB::transaction(function () use ($year, $month, $by): AccountingPeriod {
            // All period lifecycle writes and GL posts take the same
            // transaction-scoped advisory lock before reading the row. The
            // advisory lock also serializes a brand-new (row-less) month,
            // where SELECT ... FOR UPDATE cannot lock a missing row.
            $this->acquirePeriodLock($year, $month);
            $period = AccountingPeriod::query()
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if ($period !== null && $period->status === AccountingPeriodStatus::Closed) {
                return $period;
            }

            $period ??= new AccountingPeriod(['year' => $year, 'month' => $month]);
            $this->markClosed($period, $by);
            $period->save();

            return $period;
        });
    }

    private function markClosed(AccountingPeriod $period, User $by): void
    {
        $period->fill(['status' => AccountingPeriodStatus::Closed]);
        $period->closed_at = now();
        $period->closed_by = $by->id;
        // Clear stale reopen metadata on a re-close so the row reflects the
        // current (closed) state cleanly.
        $period->reopened_at = null;
        $period->reopened_by = null;
        $period->reopen_reason = null;
    }

    private function isDuplicatePeriodViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505';
    }

    /**
     * Reopen a closed period with an audit reason. Sets status=reopened and
     * records who/why. Posting into a reopened period is allowed again.
     *
     * (OGAMI-001): time-boxed relock — the `accounting:relock-periods` cron
     * automatically closes these after a defined window (48h default) to prevent
     * indefinite reopens.
     */
    public function reopen(int $year, int $month, User $by, string $reason): AccountingPeriod
    {
        $this->assertValidMonth($month);

        $reason = trim($reason);
        if ($reason === '') {
            throw new BusinessRuleException('A reason is required to reopen a closed period.');
        }

        return DB::transaction(function () use ($year, $month, $by, $reason) {
            $this->acquirePeriodLock($year, $month);

            // Lock-then-guard: re-read under lock so a concurrent close cannot
            // race a reopen's closed-status check.
            $period = AccountingPeriod::query()
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if (! $period) {
                throw new BusinessRuleException(sprintf('Period %04d-%02d does not exist; nothing to reopen.', $year, $month));
            }
            if ($period->status !== AccountingPeriodStatus::Closed) {
                throw new BusinessRuleException(sprintf('Only a closed period can be reopened (current status: %s).', $period->status->value));
            }

            $period->status        = AccountingPeriodStatus::Reopened;
            $period->reopened_at   = now();
            $period->reopened_by   = $by->id;
            $period->reopen_reason = $reason;
            $period->save();

            return $period;
        });
    }

    /**
     * THE PERIOD GUARD.
     *
     * Throws ClosedPeriodException when $date falls inside a period whose
     * status is `closed`. No row for the month → treated as OPEN (allow).
     * Reopened → allow.
     */
    public function assertPostingAllowed(Carbon|string $date): void
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);

        // This method is called from the create/post transactions of every GL
        // writer. Taking the same transaction-scoped lock as close() means a
        // post either gets in before a close, or waits and observes the closed
        // state; it cannot read OPEN and commit after close is authoritative.
        $this->acquirePeriodLock((int) $d->year, (int) $d->month);
        $period = AccountingPeriod::query()
            ->where('year', (int) $d->year)
            ->where('month', (int) $d->month)
            ->lockForUpdate()
            ->first();

        if ($period && $period->isClosed()) {
            throw new ClosedPeriodException(
                (int) $d->year,
                (int) $d->month,
                $d->toDateString(),
            );
        }
    }

    private function assertValidMonth(int $month): void
    {
        if ($month < 1 || $month > 12) {
            // Left as an unmapped RuntimeException: every HTTP caller validates
            // `month` with min:1|max:12 in its FormRequest, so reaching here
            // means an internal caller (cron, seeder, another service) passed a
            // bad month. That is a caller bug to fix, not a message to show an
            // operator, and a 422 would make it look like their input.
            throw new RuntimeException("Invalid month {$month}; expected 1-12.");
        }
    }

    /**
     * Auto-relock reopened periods that have been open longer than $hours.
     * Run by the scheduler to ensure periods aren't left reopened indefinitely.
     */
    public function relockStaleReopenedPeriods(int $hours = 48): int
    {
        $stalePeriods = AccountingPeriod::query()
            ->where('status', AccountingPeriodStatus::Reopened)
            ->whereNotNull('reopened_at')
            ->where('reopened_at', '<', now()->subHours($hours))
            ->get(['id', 'year', 'month']);

        $count = 0;
        foreach ($stalePeriods as $candidate) {
            $relocked = DB::transaction(function () use ($candidate, $hours): bool {
                // Acquire the advisory lock before the row lock, matching
                // close(), reopen(), and posting. Re-read the expected state
                // after both locks so a manual lifecycle write that won the
                // race is never overwritten by this stale snapshot.
                $this->acquirePeriodLock((int) $candidate->year, (int) $candidate->month);
                $period = AccountingPeriod::query()->lockForUpdate()->find($candidate->id);
                if (! $period || $period->status !== AccountingPeriodStatus::Reopened || $period->reopened_at === null) {
                    return false;
                }
                if ($period->reopened_at->gte(now()->subHours($hours))) {
                    return false;
                }

                $period->status = AccountingPeriodStatus::Closed;
                $period->closed_at = now();
                // The audit log actor is system for scheduler work. Preserve
                // closed_by as the last human close actor; it remains history,
                // while the fresh closed_at identifies this relock.
                $period->reopened_at = null;
                $period->reopened_by = null;
                $period->reopen_reason = null;
                $period->save();

                return true;
            });
            $count += $relocked ? 1 : 0;
        }

        return $count;
    }

    /**
     * Serialize all operations that can change or authorize a calendar month.
     * PostgreSQL advisory locks cover the missing-row case before a period is
     * first created; row locks below still protect an existing period's data.
     */
    private function acquirePeriodLock(int $year, int $month): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::select(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            [sprintf('accounting-period:%04d-%02d', $year, $month)],
        );
    }
}
