<?php

declare(strict_types=1);

namespace App\Modules\DSS\Verifiers;

use App\Modules\Accounting\Models\Bill;
use App\Modules\DSS\Contracts\RecommendationVerifierInterface;
use App\Modules\DSS\Contracts\VerificationResult;
use App\Modules\DSS\Models\DssRecommendation;

/** shortcut: checks saved bill state only; upgrade after current PO/GRN matching can be rechecked safely. */
final class ThreeWayMatchReviewVerifier implements RecommendationVerifierInterface
{
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
            $bill = Bill::query()->find($context['source_id']);
            if ($bill === null || ! is_array($bill->three_way_match_snapshot)
                || ! is_string($bill->three_way_match_snapshot['overall_status'] ?? null)) {
                return new VerificationResult(VerificationResult::UNAVAILABLE);
            }

            if ($bill->status->value === 'draft'
                && $bill->purchase_order_id !== null
                && $bill->has_variances === true
                && $bill->three_way_overridden === false
                && $bill->three_way_match_snapshot['overall_status'] === 'blocked') {
                return new VerificationResult(VerificationResult::STILL_OPEN);
            }

            return new VerificationResult(
                VerificationResult::VERIFIED_CLEAR,
                'The saved bill review state no longer meets the blocked draft condition.',
                now(),
            );
        } catch (\Throwable) {
            return new VerificationResult(VerificationResult::UNAVAILABLE);
        }
    }
}
