<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum BudgetType: string
{
    case Operating = 'operating';
    case Capital = 'capital';
    case Project = 'project';
    case Department = 'department';

    public function label(): string
    {
        return match ($this) {
            self::Operating => 'Operating', self::Capital => 'Capital',
            self::Project => 'Project', self::Department => 'Department',
        };
    }
}
