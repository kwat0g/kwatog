<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum ReviewStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Acknowledged = 'acknowledged';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
