<?php

declare(strict_types=1);

namespace App\Modules\DSS\Contracts;

/**
 * One proposed recommendation from a detector run. Immutable value object —
 * the runner (W0-2) turns these into `dss_recommendations` rows via the dedupe
 * invariant. Every field maps 1:1 to an explainability answer: what (type +
 * subject), why (rationale: inputs/rule/threshold/calculation/evidence links),
 * how confident, how urgent (severity + expires_at), what it's worth
 * (impact_estimate, always labeled estimate), what next (action: target route +
 * prefilled payload through EXISTING workflows).
 */
final readonly class DetectorEmission
{
    public function __construct(
        public string $type,
        public string $severity,
        public string $dedupeKey,
        public array $rationale,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        public ?float $priorityScore = null,
        public ?string $impactEstimate = null,
        public ?string $impactUnit = null,
        public ?float $confidence = null,
        public string $dataSufficiency = 'sufficient',
        public ?array $action = null,
        public ?\DateTimeInterface $expiresAt = null,
    ) {}
}
