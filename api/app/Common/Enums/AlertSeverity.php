<?php

declare(strict_types=1);

namespace App\Common\Enums;

enum AlertSeverity: string
{
    case Critical = 'critical';
    case Warning  = 'warning';
    case Info     = 'info';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
