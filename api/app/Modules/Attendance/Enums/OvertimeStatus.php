<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

enum OvertimeStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
