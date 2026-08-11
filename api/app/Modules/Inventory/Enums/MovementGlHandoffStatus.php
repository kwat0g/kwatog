<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum MovementGlHandoffStatus: string
{
    case NotStarted = 'not_started';
    case Generated = 'generated';
    case ManualRequired = 'manual_required';
    case NotRequired = 'not_required';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not attempted',
            self::Generated => 'Posted to GL',
            self::ManualRequired => 'Accounting review required',
            self::NotRequired => 'Not required',
        };
    }
}
