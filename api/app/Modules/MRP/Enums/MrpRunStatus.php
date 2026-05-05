<?php

declare(strict_types=1);

namespace App\Modules\MRP\Enums;

enum MrpRunStatus: string
{
    case Running   = 'running';
    case Completed = 'completed';
    case Failed    = 'failed';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
