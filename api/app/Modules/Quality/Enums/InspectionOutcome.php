<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

enum InspectionOutcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';

    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
