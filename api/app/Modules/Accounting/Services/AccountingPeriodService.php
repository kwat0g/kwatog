<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

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
            $period = AccountingPeriod::query()->firstOrNew([
                'year'  => $year,
                'month' => $month,
            ]);

            if ($period->exists && $period->status === AccountingPeriodStatus::Closed) {
                return $period;
            }

            $period->fill(['year' => $year, 'month' => $month]);
            $period->status      = AccountingPeriodStatus::Closed;
            $period->closed_at   = now();
            $period->closed_by   = $by->id;
            // Clear stale reopen metadata on a re-close so the row reflects the
            // current (closed) state cleanly.
            $period->reopened_at   = null;
            $period->reopened_by   = null;
            $period->reopen_reason = null;
            $period->save();

            return $period;
        });
    }

    /**
     * Reopen a closed period with an audit reason. Sets status=reopened and
     * records who/why. Posting into a reopened period is allowed again.
     *
     * TODO(OGAMI-001): time-boxed relock — auto-close a reopened period after
     * an admin-defined window (e.g. 48h) so a reopen can't be left open
     * indefinitely. Out of scope for the initial lock; tracked as a follow-up.
     */
    public function reopen(int $year, int $month, User $by, string $reason): AccountingPeriod
    {
        $this->assertValidMonth($month);

        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A reason is required to reopen a closed period.');
        }

        return DB::transaction(function () use ($year, $month, $by, $reason) {
            $period = AccountingPeriod::query()->where('year', $year)->where('month', $month)->first();

            if (! $period) {
                throw new RuntimeException(sprintf('Period %04d-%02d does not exist; nothing to reopen.', $year, $month));
            }
            if ($period->status !== AccountingPeriodStatus::Closed) {
                throw new RuntimeException(sprintf('Only a closed period can be reopened (current status: %s).', $period->status->value));
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
            throw new RuntimeException("Invalid month {$month}; expected 1-12.");
        }
    }
}
