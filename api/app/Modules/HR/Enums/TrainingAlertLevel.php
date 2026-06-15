<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum TrainingAlertLevel: string
{
    case T30     = 't30';
    case T14     = 't14';
    case T7      = 't7';
    case Expired = 'expired';

    /** Ordinal severity — higher = more severe. */
    public function ordinal(): int
    {
        return match ($this) {
            self::T30     => 1,
            self::T14     => 2,
            self::T7      => 3,
            self::Expired => 4,
        };
    }
}
