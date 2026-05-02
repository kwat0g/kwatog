<?php

declare(strict_types=1);

namespace App\Modules\MRP\Enums;

enum MachineStatus: string
{
    case Running     = 'running';
    case Idle        = 'idle';
    case Maintenance = 'maintenance';
    case Breakdown   = 'breakdown';
    case Offline     = 'offline';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Running     => 'Running',
            self::Idle        => 'Idle',
            self::Maintenance => 'Maintenance',
            self::Breakdown   => 'Breakdown',
            self::Offline     => 'Offline',
        };
    }
}
