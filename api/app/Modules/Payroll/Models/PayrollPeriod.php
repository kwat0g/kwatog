<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected static function newFactory(): \Database\Factories\PayrollPeriodFactory
    {
        return \Database\Factories\PayrollPeriodFactory::new();
    }

    protected $fillable = [
        'period_start',
        'period_end',
        'payroll_date',
        'is_first_half',
        'is_thirteenth_month',
        'created_by',
        'is_auto_created',
        'auto_created_at',
        // Scope filters — which slice of the workforce this run pays.
        // Empty/null on both = company-wide (the historical behaviour).
        'scope_employment_types',
        'scope_department_ids',
        'scope_pay_types',
        'scope_label',
    ];

    protected $casts = [
        'period_start'        => 'date',
        'period_end'          => 'date',
        'payroll_date'        => 'date',
        'is_first_half'       => 'boolean',
        'is_thirteenth_month' => 'boolean',
        'scope_employment_types' => 'array',
        'scope_department_ids'   => 'array',
        'scope_pay_types'        => 'array',
        'status'              => PayrollPeriodStatus::class,
        'disbursement_status' => 'string',
        'disbursed_at'        => 'datetime',
        'is_auto_created'     => 'boolean',
        'auto_created_at'     => 'datetime',
        'voided_at'           => 'datetime',
        'approved_at'         => 'datetime',
        'finalized_at'        => 'datetime',
        // Was the only timestamp on this model left uncast, so it came back as
        // a raw string: the resource's optional()->toIso8601String() silently
        // yielded null and any date comparison on it blew up.
        'processing_started_at' => 'datetime',
    ];

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }

    public function bankFileRecords(): HasMany
    {
        return $this->hasMany(BankFileRecord::class);
    }

    public function disbursementProofs(): HasMany
    {
        return $this->hasMany(DisbursementProof::class, 'payroll_period_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    // REC-04 — maker-checker attribution. computer() is the HR user who clicked
    // Compute; approver()/finalizer() are the checker(s) who signed off. These
    // let the resource surface WHO did each step for the audit trail.
    public function computer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'computed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function scopeNotFinalized(Builder $q): Builder
    {
        return $q->where('status', '!=', PayrollPeriodStatus::Finalized->value);
    }

    public function scopeForYear(Builder $q, int $year): Builder
    {
        return $q->whereYear('period_start', $year);
    }

    /**
     * Delegate to the enum so there is ONE definition of "locked". This method
     * previously only checked Finalized while the enum also counted Disbursed,
     * so a disbursed period read as unlocked and could be recomputed after the
     * money had already left the bank.
     */
    public function isLocked(): bool
    {
        return $this->status?->isLocked() ?? false;
    }

    public function label(): string
    {
        $start = $this->period_start?->format('M j');
        $end   = $this->period_end?->format('M j, Y');
        $half  = $this->is_thirteenth_month ? '13th Month' : ($this->is_first_half ? '1st half' : '2nd half');
        $label = "{$start}–{$end} · {$half}";

        // A scoped run must be identifiable at a glance: two periods can now
        // share the same dates and half, and only the scope tells them apart.
        $scope = $this->scopeLabel();

        return $scope === null ? $label : "{$label} · {$scope}";
    }

    /**
     * Human-readable scope, or null for a company-wide run.
     *
     * Prefers the operator-supplied scope_label so HR can name a run
     * ("Plant contractuals") rather than read a filter dump.
     */
    public function scopeLabel(): ?string
    {
        if ($this->isCompanyWide()) {
            return null;
        }
        if (is_string($this->scope_label) && trim($this->scope_label) !== '') {
            return trim($this->scope_label);
        }

        $parts = [];
        foreach ((array) $this->scope_employment_types as $type) {
            $parts[] = \App\Modules\HR\Enums\EmploymentType::tryFrom((string) $type)?->label() ?? (string) $type;
        }
        foreach ((array) $this->scope_pay_types as $type) {
            $parts[] = \App\Modules\HR\Enums\PayType::tryFrom((string) $type)?->label() ?? (string) $type;
        }
        $deptCount = count((array) $this->scope_department_ids);
        if ($deptCount > 0) {
            $names = \App\Modules\HR\Models\Department::query()
                ->whereIn('id', (array) $this->scope_department_ids)
                ->orderBy('name')
                ->pluck('name')
                ->all();
            $parts[] = $deptCount <= 2
                ? implode(' + ', $names)
                : $deptCount.' departments';
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * Does this period pay every active employee (no scope filters)?
     *
     * Company-wide is the historical behaviour and stays the default, so an
     * unscoped period keeps behaving exactly as it did before scoping existed.
     */
    public function isCompanyWide(): bool
    {
        return empty($this->scope_employment_types)
            && empty($this->scope_department_ids)
            && empty($this->scope_pay_types);
    }

    /**
     * The pay cycle this period belongs to: year, month and half.
     *
     * This is the double-pay unit of account. Two periods in the same cycle may
     * coexist (that is the point of scoping), but no EMPLOYEE may appear in more
     * than one of them — enforced by payroll_cycle_claims, keyed on this value.
     *
     * The half is derived from period_start, NEVER from the is_first_half
     * column. That column is operator input, and trusting it was exploitable:
     * label a Nov 16–30 period "1st half" and a Nov 1–15 period "2nd half" and
     * their keys invert, so the guard read two different cycles and paid the
     * same employee twice for November. A cycle is a fact about the calendar
     * window, so it is read off the window.
     *
     * Keyed on year+month rather than the exact dates, so two periods covering
     * one cutoff with slightly different windows (1–15 vs 2–15, e.g. after a
     * manual correction) still collide as they should.
     *
     * Format (≤ 20 chars, matching the column):
     *   2026-04-H1   first half of April 2026
     *   2026-04-H2   second half
     *   2026-13TH    the year's 13th-month run
     */
    public function cycleKey(): string
    {
        $start = $this->startDate();

        if ($this->is_thirteenth_month) {
            return $start->format('Y').'-13TH';
        }

        return $start->format('Y-m').(self::deriveIsFirstHalf($start) ? '-H1' : '-H2');
    }

    /**
     * Which half of the month does a cutoff starting on this date belong to?
     *
     * Day 1–15 → first half; 16 onward → second. The ONE definition, shared by
     * cycleKey() and by period creation, so a period's stored flag can never
     * disagree with the cycle it actually claims.
     */
    public static function deriveIsFirstHalf(\DateTimeInterface|string $periodStart): bool
    {
        $date = $periodStart instanceof \DateTimeInterface
            ? \Illuminate\Support\Carbon::instance(\DateTime::createFromInterface($periodStart))
            : \Illuminate\Support\Carbon::parse($periodStart);

        return (int) $date->format('j') <= 15;
    }

    private function startDate(): \Illuminate\Support\Carbon
    {
        return $this->period_start instanceof \DateTimeInterface
            ? \Illuminate\Support\Carbon::instance(\DateTime::createFromInterface($this->period_start))
            : \Illuminate\Support\Carbon::parse((string) $this->period_start);
    }

    public function cycleClaims(): HasMany
    {
        return $this->hasMany(PayrollCycleClaim::class, 'payroll_period_id');
    }
}
