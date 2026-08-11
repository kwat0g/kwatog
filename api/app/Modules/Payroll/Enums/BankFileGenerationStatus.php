<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum BankFileGenerationStatus: string
{
    case NotStarted = 'not_started';
    case Pending = 'pending';
    case ManualRequired = 'manual_required';
    case Generated = 'generated';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::Pending => 'Pending automatic generation',
            self::ManualRequired => 'Manual generation required',
            self::Generated => 'Generated',
        };
    }
}
