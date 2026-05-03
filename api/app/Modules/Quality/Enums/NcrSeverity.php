<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

/** Sprint 7 — Task 61. NCR severity scale. */
enum NcrSeverity: string
{
    case Low      = 'low';
    case Medium   = 'medium';
    case High     = 'high';
    case Critical = 'critical';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
