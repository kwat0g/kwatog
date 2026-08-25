<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\Money;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\DisbursementProof;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Facades\Storage;

/**
 * Reconciles payroll cash owed with the proof files attached to a period.
 *
 * Proof uploads can represent a partial settlement, but the lifecycle only
 * reaches Disbursed after active, readable proof amounts exactly cover the
 * payable net total. This keeps the existing full-period state machine honest
 * while making the legacy partially_disbursed column observable during upload.
 */
final class DisbursementEvidenceService
{
    public function payableNetTotal(PayrollPeriod $period): string
    {
        $payrolls = Payroll::query()
            ->where('payroll_period_id', $period->id)
            ->get(['net_pay', 'error_message']);

        if ($payrolls->contains(fn (Payroll $payroll): bool => $payroll->hasError())) {
            throw new BusinessRuleException(
                'Disbursement evidence cannot be reconciled while one or more payroll rows have computation errors.',
            );
        }

        return $payrolls->reduce(
            static fn (string $total, Payroll $payroll): string => Money::add($total, (string) $payroll->net_pay),
            Money::zero(),
        );
    }

    public function activeProofTotal(PayrollPeriod $period): string
    {
        return $period->disbursementProofs()
            ->get(['disbursed_amount'])
            ->reduce(
                static fn (string $total, DisbursementProof $proof): string => Money::add(
                    $total,
                    (string) ($proof->disbursed_amount ?? Money::zero()),
                ),
                Money::zero(),
            );
    }

    public function assertCanUpload(PayrollPeriod $period, string $amount): void
    {
        if ($period->status !== PayrollPeriodStatus::Finalized) {
            throw new BusinessRuleException('Disbursement proofs can only be uploaded for finalized periods that are not yet closed.');
        }

        $amount = Money::round2($amount);
        if (Money::lte($amount, Money::zero())) {
            throw new BusinessRuleException('Disbursement proof amount must be greater than zero.');
        }

        $expected = $this->payableNetTotal($period);
        $projected = Money::add($this->activeProofTotal($period), $amount);
        if (Money::gt($projected, $expected)) {
            throw new BusinessRuleException(sprintf(
                'Disbursement proof total %s exceeds this period\'s payable net total %s.',
                $projected,
                $expected,
            ));
        }
    }

    /**
     * Keep the legacy status column aligned with the evidence currently held.
     * The terminal Disbursed value is written only by PayrollPeriodService.
     */
    public function syncStatus(PayrollPeriod $period): void
    {
        if ($period->status !== PayrollPeriodStatus::Finalized) {
            return;
        }

        $total = $this->activeProofTotal($period);
        $expected = $this->payableNetTotal($period);
        $status = Money::gt($total, Money::zero()) && Money::lt($total, $expected)
            ? 'partially_disbursed'
            : 'pending';

        if ((string) $period->disbursement_status !== $status) {
            $period->forceFill(['disbursement_status' => $status])->save();
        }
    }

    public function assertComplete(PayrollPeriod $period): void
    {
        if ($period->status !== PayrollPeriodStatus::Finalized) {
            throw new BusinessRuleException('Only finalized periods can be marked as disbursed.');
        }

        $proofs = $period->disbursementProofs()->get();
        if ($proofs->isEmpty()) {
            throw new BusinessRuleException('At least one disbursement proof must be uploaded before marking the period as disbursed.');
        }

        $total = Money::zero();
        foreach ($proofs as $proof) {
            if ($proof->disbursed_amount === null || Money::lte((string) $proof->disbursed_amount, Money::zero())) {
                throw new BusinessRuleException('Every active disbursement proof must state a positive disbursed amount.');
            }
            if (! Storage::disk('local')->exists((string) $proof->file_path)) {
                throw new BusinessRuleException(sprintf(
                    'Disbursement proof %s is missing from private storage and cannot support a disbursed status.',
                    $proof->file_name,
                ));
            }

            $total = Money::add($total, (string) $proof->disbursed_amount);
        }

        $expected = $this->payableNetTotal($period);
        if (Money::cmp($total, $expected) !== 0) {
            throw new BusinessRuleException(sprintf(
                'Disbursement evidence totals %s but this period\'s payable net total is %s. Upload the missing proof amount before closing the period.',
                $total,
                $expected,
            ));
        }
    }
}
