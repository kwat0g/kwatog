<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum EmploymentChangeType: string
{
    case Hired           = 'hired';
    case Promoted        = 'promoted';
    case Transferred     = 'transferred';
    case SalaryAdjusted  = 'salary_adjusted';
    case Regularized     = 'regularized';
    case Separated       = 'separated';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
