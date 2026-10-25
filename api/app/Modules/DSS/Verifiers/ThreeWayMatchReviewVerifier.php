<?php

declare(strict_types=1);

namespace App\Modules\DSS\Verifiers;

use App\Modules\Accounting\Models\Bill;
use App\Modules\DSS\Contracts\RecommendationVerifierInterface;
use App\Modules\DSS\Contracts\VerificationResult;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\Purchasing\Services\ThreeWayMatchService;

/** Rechecks the current PO, bill, and receipt match without writing business state. */
final class ThreeWayMatchReviewVerifier implements RecommendationVerifierInterface
{
    public function __construct(private readonly ThreeWayMatchService $matcher) {}

    public function verify(DssRecommendation $recommendation): VerificationResult
    {
        $context = $recommendation->verification_context;
        if ($recommendation->detector_id !== 'dss.three_way_match_review'
            || ! is_array($context)
            || ! is_int($context['source_id'] ?? null)
            || $context['source_id'] < 1) {
            return new VerificationResult(VerificationResult::UNAVAILABLE);
        }

        try {
            $bill = Bill::query()->with('items')->find($context['source_id']);
            if ($bill === null || ! is_array($bill->three_way_match_snapshot)
                || ! is_string($bill->three_way_match_snapshot['overall_status'] ?? null)) {
                return new VerificationResult(VerificationResult::UNAVAILABLE);
            }

            if ($bill->items->isEmpty()) {
                return new VerificationResult(VerificationResult::UNAVAILABLE);
            }
            foreach ($bill->items as $item) {
                if (! is_numeric($item->item_id) || (int) $item->item_id < 1) {
                    return new VerificationResult(VerificationResult::UNAVAILABLE);
                }
            }

            // Posting or an authorized override deliberately removes bill from this review queue.
            if ($bill->status->value !== 'draft' || $bill->purchase_order_id === null || $bill->three_way_overridden === true) {
                return $this->clear('The bill is no longer eligible for blocked match review.');
            }

            $match = $this->matcher->matchForBill($bill);
            if ($match === null) {
                return new VerificationResult(VerificationResult::UNAVAILABLE);
            }
            if ($match->overallStatus === 'blocked') {
                return new VerificationResult(VerificationResult::STILL_OPEN);
            }
            if (! in_array($match->overallStatus, ['matched', 'has_variances'], true)) {
                return new VerificationResult(VerificationResult::UNAVAILABLE);
            }

            return $this->clear('Current purchase order, bill, and receipt match is no longer blocked.');
        } catch (\Throwable) {
            return new VerificationResult(VerificationResult::UNAVAILABLE);
        }
    }

    private function clear(string $proof): VerificationResult
    {
        return new VerificationResult(VerificationResult::VERIFIED_CLEAR, $proof, now());
    }
}
