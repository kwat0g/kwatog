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

        return DB::transaction(function () use ($year, $month, $by) {
            // Lock-then-guard: lock the authoritative row so a concurrent
            // close/reopen cannot race past the closed-check. A brand-new
            // period races the unique (year, month) index instead; on a unique
            // violation the winner is re-read under lock and relocked.
            $period = AccountingPeriod::query()
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if ($period !== null && $period->status === AccountingPeriodStatus::Closed) {
                return $period;
            }

            $period ??= new AccountingPeriod(['year' => $year, 'month' => $month]);
            $period->fill(['year' => $year, 'month' => $month]);
            $period->status      = AccountingPeriodStatus::Closed;
            $period->closed_at   = now();
            $period->closed_by   = $by->id;
            // Clear stale reopen metadata on a re-close so the row reflects the
            // current (closed) state cleanly.
            $period->reopened_at   = null;
            $period->reopened_by   = null;
            $period->reopen_reason = null;

            try {
                $period->save();
            } catch (\Illuminate\Database\QueryException $e) {
                if (($e->errorInfo[0] ?? null) !== '23505') {
                    throw $e;
                }
                // Concurrent close won the insert; relock the winner.
                $period = AccountingPeriod::query()
                    ->where('year', $year)
                    ->where('month', $month)
                    ->lockForUpdate()
                    ->firstOrFail();
                $period->status = AccountingPeriodStatus::Closed;
                $period->save();
            }

            return $period;
        });
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

        $period = AccountingPeriod::forDate($d);

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
            ->get();

        $count = 0;
        foreach ($stalePeriods as $period) {
            $period->status = AccountingPeriodStatus::Closed;
            $period->closed_at = now();
            // We preserve the last closed_by since it was an automated systemic close,
            // or we could null it. Preserving the historical closer is fine.
            $period->reopened_at = null;
            $period->reopened_by = null;
            $period->reopen_reason = null;
            $period->save();
            $count++;
        }

        return $count;
    }
}
