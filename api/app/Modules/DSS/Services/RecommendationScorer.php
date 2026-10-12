<?php

declare(strict_types=1);

namespace App\Modules\DSS\Services;

use App\Modules\DSS\Contracts\DetectorEmission;

/**
 * Transparent priority formula (02-plan §2): urgency × impact × confidence,
 * adjusted by effort. Money-denominated impact where possible — until scale
 * normalisation exists (Wave 2), impact contributes presence-only: an emission
 * carrying any impact_estimate outranks an otherwise identical one without.
 *
 * All weights are `dss.scoring.*` parameters, never hardcoded at call sites.
 * A detector that presets priority_score owns it; the scorer keeps it.
 *
 * Urgency bands from expires_at (explicit assumption, tunable):
 * <=24h → 1.0, <=7d → 0.7, <=30d → 0.4, none/later → 0.5.
 */
class RecommendationScorer
{
    public function __construct(private readonly DssParameterService $params) {}

    public function score(DetectorEmission $emission): float
    {
        if ($emission->priorityScore !== null) {
            return round($emission->priorityScore, 4);
        }

        $severityWeights = $this->params->get('global', 'dss.scoring.severity_weights', [
            'critical' => 1.0, 'warning' => 0.6, 'info' => 0.3,
        ]);
        $severity = $severityWeights[$emission->severity] ?? 0.3;

        $confidence = $emission->confidence ?? 0.5;

        $urgency = $this->urgency($emission->expiresAt);

        $impact = $emission->impactEstimate !== null ? 1.0 : 0.5;

        return round($severity * $confidence * $urgency * $impact, 4);
    }

    private function urgency(?\DateTimeInterface $expiresAt): ?float
    {
        return $this->params->get('global', 'dss.scoring.urgency_override', null)
            ?? $this->urgencyFromExpiry($expiresAt);
    }

    private function urgencyFromExpiry(?\DateTimeInterface $expiresAt): float
    {
        if ($expiresAt === null) {
            return 0.5;
        }

        $hours = (time() - $expiresAt->getTimestamp()) / -3600;

        if ($hours <= 24) {
            return 1.0;
        }

        if ($hours <= 24 * 7) {
            return 0.7;
        }

        return $hours <= 24 * 30 ? 0.4 : 0.5;
    }
}
