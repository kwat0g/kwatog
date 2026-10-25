<?php

declare(strict_types=1);

namespace App\Modules\DSS\Verifiers;

use App\Modules\DSS\Contracts\RecommendationVerifierInterface;
use App\Modules\DSS\Contracts\VerificationResult;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\Production\Models\WorkOrder;
use Carbon\Carbon;

final class WorkOrderInterventionVerifier implements RecommendationVerifierInterface
{
    private const ACTIVE_STATUSES = ['confirmed', 'in_progress', 'paused'];

    public function verify(DssRecommendation $recommendation): VerificationResult
    {
        $context = $recommendation->verification_context;
        if ($recommendation->detector_id !== 'dss.work_order_intervention'
            || ! is_array($context)
            || ! is_int($context['source_id'] ?? null)
            || $context['source_id'] < 1
            || ! is_string($context['planned_end'] ?? null)
            || $context['planned_end'] === '') {
            return new VerificationResult(VerificationResult::UNAVAILABLE);
        }

        try {
            Carbon::parse($context['planned_end']);
            $workOrder = WorkOrder::query()->find($context['source_id']);
            if ($workOrder === null) {
                return new VerificationResult(VerificationResult::UNAVAILABLE);
            }

            $status = $workOrder->status->value;
            if (in_array($status, self::ACTIVE_STATUSES, true)
                && $workOrder->planned_end !== null
                && $workOrder->planned_end->isBefore(now())) {
                return new VerificationResult(VerificationResult::STILL_OPEN);
            }

            $proof = in_array($status, self::ACTIVE_STATUSES, true)
                ? 'Work order deadline is no longer overdue.'
                : 'Work order is no longer active.';

            return new VerificationResult(VerificationResult::VERIFIED_CLEAR, $proof, now());
        } catch (\Throwable) {
            return new VerificationResult(VerificationResult::UNAVAILABLE);
        }
    }
}
