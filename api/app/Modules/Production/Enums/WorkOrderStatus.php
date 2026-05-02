<?php

declare(strict_types=1);

namespace App\Modules\Production\Enums;

enum WorkOrderStatus: string
{
    case Planned    = 'planned';
    case Confirmed  = 'confirmed';
    case InProgress = 'in_progress';
    case Paused     = 'paused';
    case Completed  = 'completed';
    case Closed     = 'closed';
    case Cancelled  = 'cancelled';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Planned    => 'Planned',
            self::Confirmed  => 'Confirmed',
            self::InProgress => 'In Progress',
            self::Paused     => 'Paused',
            self::Completed  => 'Completed',
            self::Closed     => 'Closed',
            self::Cancelled  => 'Cancelled',
        };
    }
}
