<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Enums;

/** State of the Return Management → Quality inspection handoff. */
enum ReturnInspectionHandoffStatus: string
{
    case NotStarted = 'not_started';
    case Generated = 'generated';
    case ManualRequired = 'manual_required';
    case NotRequired = 'not_required';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not attempted',
            self::Generated => 'Inspection staged',
            self::ManualRequired => 'Quality review required',
            self::NotRequired => 'Not required',
        };
    }
}
