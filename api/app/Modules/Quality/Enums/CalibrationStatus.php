<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

enum CalibrationStatus: string
{
    case Active   = 'active';   // calibrated, not yet near due
    case Due      = 'due';      // within the warning window
    case Overdue  = 'overdue';  // past next_calibration_date
    case Retired  = 'retired';  // no longer in service

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
