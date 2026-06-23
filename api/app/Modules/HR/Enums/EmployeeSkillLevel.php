<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum EmployeeSkillLevel: string
{
    case Novice    = 'novice';
    case Competent = 'competent';
    case Proficient = 'proficient';
    case Expert    = 'expert';
    case Trainer   = 'trainer';
}
