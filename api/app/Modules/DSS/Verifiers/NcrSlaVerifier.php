<?php

declare(strict_types=1);

namespace App\Modules\DSS\Verifiers;

use App\Modules\DSS\Contracts\RecommendationVerifierInterface;
use App\Modules\DSS\Contracts\VerificationResult;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrSlaService;
use Carbon\Carbon;

final class NcrSlaVerifier implements RecommendationVerifierInterface
{
    public function __construct(private readonly NcrSlaService $ncrSla) {}

    public function verify(DssRecommendation $recommendation): VerificationResult
    {
        $context = $recommendation->verification_context;
        if ($recommendation->detector_id !== 'dss.quality_ncr_sla'
            || ! is_array($context)
            || ! is_int($context['source_id'] ?? null)
            || $context['source_id'] < 1
            || ! is_string($context['severity'] ?? null)
            || ! is_int($context['sla_hours'] ?? null)
            || ! is_string($context['source_clock'] ?? null)
            || $context['source_clock'] === ''
            || ($context['source_clock_rule'] ?? null) !== 'COALESCE(last_escalated_at, created_at)') {
            return new VerificationResult(VerificationResult::UNAVAILABLE);
        }

        $severity = NcrSeverity::tryFrom($context['severity']);
        if ($severity === null || $context['sla_hours'] < 1 || $context['sla_hours'] > 87600) {
            return new VerificationResult(VerificationResult::UNAVAILABLE);
        }

        try {
            $emittedClock = Carbon::parse($context['source_clock']);
            if ($emittedClock->isFuture()) {
                return new VerificationResult(VerificationResult::UNAVAILABLE);
            }
            $currentSlaHours = $this->ncrSla->hoursFor($severity);
            if ($currentSlaHours !== $context['sla_hours']) {
                return new VerificationResult(VerificationResult::UNAVAILABLE);
            }

            $ncr = NonConformanceReport::query()->find($context['source_id']);
            if ($ncr === null) {
                return new VerificationResult(VerificationResult::UNAVAILABLE);
            }

            $currentSeverity = $ncr->severity instanceof NcrSeverity
                ? $ncr->severity
                : NcrSeverity::tryFrom((string) $ncr->severity);
            if ($currentSeverity !== $severity) {
                return new VerificationResult(VerificationResult::UNAVAILABLE);
            }

            $status = $ncr->status->value;
            if (! in_array($status, ['open', 'in_progress'], true)) {
                return new VerificationResult(
                    VerificationResult::VERIFIED_CLEAR,
                    'NCR is no longer open.',
                    now(),
                );
            }

            if ($ncr->actions()->where('action_type', NcrActionType::Corrective->value)->exists()) {
                return new VerificationResult(
                    VerificationResult::VERIFIED_CLEAR,
                    'NCR has a recorded corrective action.',
                    now(),
                );
            }

            $clock = $this->ncrSla->clockStart($ncr);
            if ($clock === null || $clock->isFuture()) {
                return new VerificationResult(VerificationResult::UNAVAILABLE);
            }
            $dueAt = $this->ncrSla->dueAt($ncr);
            if ($dueAt !== null && $dueAt->lte(now())) {
                return new VerificationResult(VerificationResult::STILL_OPEN);
            }

            return new VerificationResult(
                VerificationResult::VERIFIED_CLEAR,
                'NCR response is within its configured SLA.',
                now(),
            );
        } catch (\Throwable) {
            return new VerificationResult(VerificationResult::UNAVAILABLE);
        }
    }
}
