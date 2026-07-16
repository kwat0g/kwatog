<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum CreditNoteType: string
{
    case Customer = 'customer'; // AR — reduces what a customer owes
    case Supplier = 'supplier'; // AP — reduces what we owe a vendor

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer Credit',
            self::Supplier => 'Supplier Credit',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
