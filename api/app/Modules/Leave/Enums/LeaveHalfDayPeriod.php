<?php

declare(strict_types=1);

namespace App\Modules\Leave\Enums;

enum LeaveHalfDayPeriod: string
{
    case FullDay = 'none';
    case Morning = 'am';
    case Afternoon = 'pm';

    public function label(): string
    {
        return match ($this) {
            self::FullDay => 'Full day',
            self::Morning => 'Morning (AM half-day)',
            self::Afternoon => 'Afternoon (PM half-day)',
        };
    }
}
