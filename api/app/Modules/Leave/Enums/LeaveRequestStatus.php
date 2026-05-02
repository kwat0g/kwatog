<?php

declare(strict_types=1);

namespace App\Modules\Leave\Enums;

enum LeaveRequestStatus: string
{
    case PendingDept = 'pending_dept';
    case PendingHr   = 'pending_hr';
    case Approved    = 'approved';
    case Rejected    = 'rejected';
    case Cancelled   = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PendingDept => 'Pending department head',
            self::PendingHr   => 'Pending HR approval',
            self::Approved    => 'Approved',
            self::Rejected    => 'Rejected',
            self::Cancelled   => 'Cancelled',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
