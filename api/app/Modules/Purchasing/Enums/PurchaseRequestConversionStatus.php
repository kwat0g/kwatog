<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

enum PurchaseRequestConversionStatus: string
{
    case NotStarted = 'not_started';
    case Pending = 'pending';
    case ManualRequired = 'manual_required';
    case Partial = 'partial';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::Pending => 'Pending automatic conversion',
            self::ManualRequired => 'Manual conversion required',
            self::Partial => 'Partially converted',
            self::Converted => 'Converted to PO',
        };
    }
}
