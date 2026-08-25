<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * The publication boundary for employee payroll outputs.
 *
 * Payroll rows remain historical records after a run is voided, but they are
 * not public documents. Keeping the status/error predicate here prevents the
 * payslip, certificate, vault, and email paths from drifting apart.
 */
final class PayrollPublicationPolicy
{
    /** @var list<string> */
    private const PUBLISHABLE_STATUSES = [
        PayrollPeriodStatus::Finalized->value,
        PayrollPeriodStatus::Disbursed->value,
    ];

    public function isPeriodPublishable(?PayrollPeriod $period): bool
    {
        if ($period === null) {
            return false;
        }

        $status = $period->status instanceof PayrollPeriodStatus
            ? $period->status->value
            : (string) $period->status;

        return in_array($status, self::PUBLISHABLE_STATUSES, true);
    }

    public function isPayrollPublishable(Payroll $payroll): bool
    {
        $payroll->loadMissing('period');

        return ! $payroll->hasError()
            && $this->isPeriodPublishable($payroll->period);
    }

    /**
     * Apply the same predicate to a query without loading rows into memory.
     * Empty error strings are treated as no error for legacy rows.
     *
     * @param Builder<Payroll> $query
     * @return Builder<Payroll>
     */
    public function scopePublishable(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $errors): void {
                $errors
                    ->whereNull('error_message')
                    ->orWhere('error_message', '');
            })
            ->whereHas('period', function (Builder $periods): void {
                $periods->whereIn('status', self::PUBLISHABLE_STATUSES);
            });
    }

    public function assertPayrollPublishable(Payroll $payroll): void
    {
        if (! $this->isPayrollPublishable($payroll)) {
            throw new BusinessRuleException(
                'This payroll output is not available until the period is finalized and is not voided or in error.',
            );
        }
    }
}
