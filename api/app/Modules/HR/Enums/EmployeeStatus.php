<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum EmployeeStatus: string
{
    case Active     = 'active';
    case OnLeave    = 'on_leave';
    case Suspended  = 'suspended';
    case Resigned   = 'resigned';
    case Terminated = 'terminated';
    case Retired    = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Active     => 'Active',
            self::OnLeave    => 'On leave',
            self::Suspended  => 'Suspended',
            self::Resigned   => 'Resigned',
            self::Terminated => 'Terminated',
            self::Retired    => 'Retired',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
