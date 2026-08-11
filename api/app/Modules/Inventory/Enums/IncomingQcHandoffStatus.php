<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/** State of the GRN → Quality incoming-QC handoff. */
enum IncomingQcHandoffStatus: string
{
    case NotStarted = 'not_started';
    case Generated = 'generated';
    case ManualRequired = 'manual_required';
    case NotRequired = 'not_required';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'QC trigger pending',
            self::Generated => 'Inspection staged',
            self::ManualRequired => 'Quality trigger needs attention',
            self::NotRequired => 'QC not required',
        };
    }
}
