<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PayrollGlHandoffStatus: string
{
    case NotStarted = 'not_started';
    case Pending = 'pending';
    case ManualRequired = 'manual_required';
    case Posted = 'posted';
    case NotRequired = 'not_required';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not attempted',
            self::Pending => 'Posting to GL',
            self::ManualRequired => 'Accounting review required',
            self::Posted => 'Posted to GL',
            self::NotRequired => 'Not required',
        };
    }
}
