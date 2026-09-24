<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

/** How a supplier quotation states VAT. */
enum VatTreatment: string
{
    case Exclusive = 'exclusive';
    case Inclusive = 'inclusive';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Exclusive => 'VAT exclusive',
            self::Inclusive => 'VAT inclusive',
            self::None => 'No VAT',
        };
    }
}
