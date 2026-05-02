<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum PaymentMethod: string
{
    case Cash         = 'cash';
    case Check        = 'check';
    case BankTransfer = 'bank_transfer';
    case Online       = 'online';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash         => 'Cash',
            self::Check        => 'Check',
            self::BankTransfer => 'Bank Transfer',
            self::Online       => 'Online',
        };
    }
}
