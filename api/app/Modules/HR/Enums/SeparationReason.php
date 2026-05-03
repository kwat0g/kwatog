<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum SeparationReason: string
{
    case Resigned       = 'resigned';
    case Terminated     = 'terminated';
    case Retired        = 'retired';
    case EndOfContract  = 'end_of_contract';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** Map to the resulting EmployeeStatus on finalization. */
    public function toEmployeeStatus(): string
    {
        return match ($this) {
            self::Resigned, self::EndOfContract => 'resigned',
            self::Terminated                    => 'terminated',
            self::Retired                       => 'retired',
        };
    }
}
