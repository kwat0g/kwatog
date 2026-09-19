<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

enum DeliveryCocHandoffStatus: string
{
    case NotStarted = 'not_started';
    case Generated = 'generated';
    case NotRequired = 'not_required';
    case ManualRequired = 'manual_required';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not attempted',
            self::Generated => 'Certificate attached',
            self::NotRequired => 'No passed inspection linked',
            self::ManualRequired => 'Quality action required',
        };
    }
}
