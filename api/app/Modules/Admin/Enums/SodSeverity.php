<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

enum SodSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
