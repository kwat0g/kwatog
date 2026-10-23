<?php

declare(strict_types=1);

namespace App\Modules\DSS\Enums;

enum RecommendationSeverity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';

    /** Sort weight: critical surfaces first. */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Warning => 1,
            self::Info => 2,
        };
    }
}
