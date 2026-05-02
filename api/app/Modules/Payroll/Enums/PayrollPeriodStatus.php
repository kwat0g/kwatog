<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PayrollPeriodStatus: string
{
    case Draft      = 'draft';
    case Processing = 'processing';
    case Approved   = 'approved';
    case Finalized  = 'finalized';

    public function label(): string
    {
        return match ($this) {
            self::Draft      => 'Draft',
            self::Processing => 'Processing',
            self::Approved   => 'Approved',
            self::Finalized  => 'Finalized',
        };
    }

    public function isLocked(): bool
    {
        return $this === self::Finalized;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
