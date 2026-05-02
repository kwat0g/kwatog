<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

enum AttendanceStatus: string
{
    case Present  = 'present';
    case Absent   = 'absent';
    case Late     = 'late';
    case Halfday  = 'halfday';
    case OnLeave  = 'on_leave';
    case Holiday  = 'holiday';
    case RestDay  = 'rest_day';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
