<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

enum DeliveryInvoiceHandoffStatus: string
{
    case NotStarted = 'not_started';
    case Generated = 'generated';
    case ManualRequired = 'manual_required';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not attempted',
            self::Generated => 'Draft invoice created',
            self::ManualRequired => 'Finance action required',
        };
    }
}
