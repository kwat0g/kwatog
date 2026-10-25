<?php

declare(strict_types=1);

namespace App\Modules\DSS\Verifiers;

use App\Modules\DSS\Contracts\RecommendationVerifierInterface;
use App\Modules\DSS\Contracts\VerificationResult;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Support\MasterHygieneScope;
use Throwable;

/** Rechecks the full current source query for one named master-data gap. */
final class MasterHygieneVerifier implements RecommendationVerifierInterface
{
    public function __construct(private readonly MasterHygieneScope $scope) {}

    public function verify(DssRecommendation $recommendation): VerificationResult
    {
        if ($recommendation->detector_id !== 'dss.master_hygiene') {
            return new VerificationResult(VerificationResult::UNAVAILABLE);
        }

        $context = $recommendation->verification_context;
        $gap = is_array($context) ? ($context['gap'] ?? null) : null;
        $gap ??= is_array($recommendation->rationale) ? ($recommendation->rationale['gap'] ?? null) : null;
        if (! is_string($gap) || ! isset($this->scope->definitions()[$gap])) {
            return new VerificationResult(VerificationResult::UNAVAILABLE);
        }
        if ($recommendation->decision_code !== null
            && $recommendation->decision_code !== "master_data_gap.{$gap}") {
            return new VerificationResult(VerificationResult::UNAVAILABLE);
        }

        try {
            if ($this->scope->query($gap)->exists()) {
                return new VerificationResult(VerificationResult::STILL_OPEN);
            }

            return new VerificationResult(
                VerificationResult::VERIFIED_CLEAR,
                'No current records match this master-data gap.',
                now(),
            );
        } catch (Throwable) {
            return new VerificationResult(VerificationResult::UNAVAILABLE);
        }
    }
}
