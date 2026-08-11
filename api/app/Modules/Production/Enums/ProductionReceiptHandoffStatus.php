<?php

declare(strict_types=1);

namespace App\Modules\Production\Enums;

enum ProductionReceiptHandoffStatus: string
{
    case NotStarted = 'not_started';
    case Generated = 'generated';
    case ManualRequired = 'manual_required';
    case NotRequired = 'not_required';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not attempted',
            self::Generated => 'Finished-goods receipt posted',
            self::ManualRequired => 'Inventory action required',
            self::NotRequired => 'No receipt required',
        };
    }
}
