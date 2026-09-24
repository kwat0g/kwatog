<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum WithholdingTaxType: string
{
    case None        = 'none';
    case Goods       = 'goods';
    case Services    = 'services';
    case Rentals     = 'rentals';
    case Professional = 'professional';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Goods => 'Goods (1%)',
            self::Services => 'Services (2%)',
            self::Rentals => 'Rentals (5%)',
            self::Professional => 'Professional Services (10%)',
        };
    }

    public function rate(): string
    {
        return match ($this) {
            self::None => '0.00',
            self::Goods => '0.01',
            self::Services => '0.02',
            self::Rentals => '0.05',
            self::Professional => '0.10',
        };
    }

    public function atc(): string
    {
        return match ($this) {
            self::None => '',
            self::Goods => 'WC158',
            self::Services => 'WC160',
            self::Rentals => 'WC100',
            self::Professional => 'WC010',
        };
    }
}
