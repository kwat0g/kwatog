<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

enum PunchDirection: string
{
    case In = 'in';
    case Out = 'out';
}
