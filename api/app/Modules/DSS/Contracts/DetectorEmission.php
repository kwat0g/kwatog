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
        public ?array $ownerPermissions = null,
        public ?array $ownerRoleSlugs = null,
        public ?string $decisionCode = null,
        public ?string $policyVersion = null,
        public ?array $sourceReferences = null,
        public ?array $verificationContext = null,
        public ?\DateTimeInterface $sourceObservedAt = null,
        public ?\DateTimeInterface $businessDueAt = null,
        public ?string $impactCurrency = null,
        public ?string $impactMetric = null,
    ) {
        self::validateSourceReferences($sourceReferences);
    }

    private static function validateSourceReferences(?array $references): void
    {
        if ($references === null) {
            return;
        }
        if (! array_is_list($references) || count($references) > 5) {
            throw new \InvalidArgumentException('Detector source references may contain at most five entries.');
        }

        foreach ($references as $reference) {
            if (! is_array($reference) || count($reference) !== 4
                || array_diff(['source_kind', 'public_id', 'display_reference', 'destination'], array_keys($reference)) !== []) {
                throw new \InvalidArgumentException('Detector source references must use the supported public shape.');
            }

            $kind = $reference['source_kind'] ?? null;
            $id = $reference['public_id'] ?? null;
            $display = $reference['display_reference'] ?? null;
            $destination = $reference['destination'] ?? null;
            if (! is_string($kind) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $kind)
                || ! is_string($id) || strlen($id) > 100 || ! preg_match('/^[A-Za-z0-9_-]+$/D', $id) || ctype_digit($id)
                || ! is_string($display) || $display === '' || strlen($display) > 120 || trim($display) !== $display
                || ! is_string($destination) || strlen($destination) > 255
                || ! preg_match('~^/(?:[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*)?$~D', $destination)
                || str_contains($display, '\\'))
            {
                throw new \InvalidArgumentException('Detector source references contain an invalid or private value.');
            }
        }
    }
}
