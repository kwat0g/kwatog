<?php

declare(strict_types=1);

namespace App\Modules\B2B\Enums;

enum DeliveryScheduleStatus: string
{
    case Submitted    = 'submitted';
    case Acknowledged = 'acknowledged';
    case Rejected     = 'rejected';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Submitted    => 'Submitted',
            self::Acknowledged => 'Acknowledged',
            self::Rejected     => 'Rejected',
        };
    }
}
