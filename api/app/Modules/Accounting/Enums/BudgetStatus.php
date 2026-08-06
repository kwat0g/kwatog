<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum BudgetStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Active = 'active';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Active => 'Active',
            self::Closed => 'Closed',
        };
    }
}
