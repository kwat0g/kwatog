<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

/** State of the CRM complaint → Quality NCR handoff. */
enum ComplaintNcrHandoffStatus: string
{
    case NotStarted = 'not_started';
    case Generated = 'generated';
    case ManualRequired = 'manual_required';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not attempted',
            self::Generated => 'NCR opened',
            self::ManualRequired => 'Quality review required',
        };
    }
}
