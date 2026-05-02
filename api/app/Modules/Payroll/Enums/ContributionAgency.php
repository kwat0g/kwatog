<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum ContributionAgency: string
{
    case Sss        = 'sss';
    case Philhealth = 'philhealth';
    case Pagibig    = 'pagibig';
    case Bir        = 'bir';

    public function label(): string
    {
        return match ($this) {
            self::Sss        => 'SSS',
            self::Philhealth => 'PhilHealth',
            self::Pagibig    => 'Pag-IBIG',
            self::Bir        => 'BIR',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
