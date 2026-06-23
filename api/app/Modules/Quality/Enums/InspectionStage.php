<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

/**
 * Sprint 7 — Task 60. Inspection stage in the IATF 16949 quality flow.
 *
 *   incoming           — raw material check at GRN (gates GRN acceptance)
 *   in_process         — inline check during a work order
 *   outgoing           — finished-good batch check (gates delivery, AQL 0.65 L-II)
 *   supplier_return    — returned goods from a customer (supplier return inspection)
 *   customer_return    — returned goods to a supplier (customer return inspection)
 */
enum InspectionStage: string
{
    case Incoming        = 'incoming';
    case InProcess       = 'in_process';
    case Outgoing        = 'outgoing';
    case SupplierReturn  = 'supplier_return';
    case CustomerReturn  = 'customer_return';

    public function label(): string
    {
        return match ($this) {
            self::Incoming        => 'Incoming',
            self::InProcess       => 'In-process',
            self::Outgoing        => 'Outgoing',
            self::SupplierReturn  => 'Supplier Return',
            self::CustomerReturn  => 'Customer Return',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
