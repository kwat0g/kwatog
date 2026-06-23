<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

/**
 * CAPA effectiveness verdict for a corrective/preventive action (IATF 16949 §10.2.1).
 */
enum EffectivenessStatus: string
{
    case PendingVerification = 'pending_verification';
    case Effective           = 'effective';
    case Ineffective         = 'ineffective';
    case NotApplicable       = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::PendingVerification => 'Pending Verification',
            self::Effective           => 'Effective',
            self::Ineffective         => 'Ineffective',
            self::NotApplicable       => 'Not Applicable',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
