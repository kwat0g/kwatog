<?php

declare(strict_types=1);

namespace App\Modules\DSS\Contracts;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class VerificationResult
{
    public const STILL_OPEN = 'still_open';
    public const VERIFIED_CLEAR = 'verified_clear';
    public const UNAVAILABLE = 'unavailable';

    public ?DateTimeImmutable $observedAt;

    public function __construct(
        public string $status,
        public ?string $proof = null,
        ?DateTimeInterface $observedAt = null,
    ) {
        if (! in_array($status, [self::STILL_OPEN, self::VERIFIED_CLEAR, self::UNAVAILABLE], true)) {
            throw new InvalidArgumentException('Verification status is invalid.');
        }

        if ($status === self::VERIFIED_CLEAR) {
            if (! is_string($proof) || trim($proof) === '' || strlen($proof) > 500
                || preg_match('/[0-9\\\\]|\b(?:WorkOrder|NonConformanceReport|WorkOrderInterventionVerifier|NcrSlaVerifier)\b/', $proof)) {
                throw new InvalidArgumentException('Verified clearance requires bounded proof without identifiers.');
            }
            if (! $observedAt instanceof DateTimeInterface) {
                throw new InvalidArgumentException('Verified clearance requires an authoritative observation time.');
            }
            $this->observedAt = DateTimeImmutable::createFromInterface($observedAt);

            return;
        }

        if ($proof !== null || $observedAt !== null) {
            throw new InvalidArgumentException('Only verified clearance may carry resolution evidence.');
        }
        $this->observedAt = null;
    }
}
