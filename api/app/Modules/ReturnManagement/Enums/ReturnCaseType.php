<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Enums;

enum ReturnCaseType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Supplier => 'Supplier',
        };
    }
}
