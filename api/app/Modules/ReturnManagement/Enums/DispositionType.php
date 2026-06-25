<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Enums;

enum DispositionType: string
{
    case Scrap             = 'scrap';
    case Rework            = 'rework';
    case Restock           = 'restock';
    case ReturnToSupplier  = 'return_to_supplier';

    public function label(): string
    {
        return match ($this) {
            self::Scrap            => 'Scrap',
            self::Rework           => 'Rework',
            self::Restock          => 'Restock',
            self::ReturnToSupplier => 'Return to Supplier',
        };
    }
}
