<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Enums;

enum DispositionType: string
{
    case Scrap             = 'scrap';
    case Rework            = 'rework';
    case Restock           = 'restock';
    case ReturnToSupplier  = 'return_to_supplier';
    case NoReturn          = 'no_return';

    public function label(): string
    {
        return match ($this) {
            self::Scrap            => 'Scrap',
            self::Rework           => 'Rework',
            self::Restock          => 'Restock',
            self::ReturnToSupplier => 'Return to Supplier',
            self::NoReturn         => 'No goods returned',
        };
    }

    /**
     * The legal policy matrix is shared by the API and the SPA options.
     * Supplier scrap/rework is intentionally not offered until a supplier
     * quarantine/rework ledger exists; allowing it would produce a terminal
     * RMA with no stock or accounting effect.
     *
     * @return list<self>
     */
    public static function allowedFor(ReturnRequestType $type, bool $financeOnly = false): array
    {
        if ($financeOnly) {
            return [self::NoReturn];
        }

        return $type === ReturnRequestType::CustomerReturn
            ? [self::Scrap, self::Rework, self::Restock, self::NoReturn]
            : [self::ReturnToSupplier, self::NoReturn];
    }
}
