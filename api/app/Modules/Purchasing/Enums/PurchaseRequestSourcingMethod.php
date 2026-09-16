<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

enum PurchaseRequestSourcingMethod: string
{
    case DirectPo = 'direct_po';
    case Rfq = 'rfq';

    public function label(): string
    {
        return match ($this) {
            self::DirectPo => 'Direct PO',
            self::Rfq => 'Competitive RFQ',
        };
    }
}
