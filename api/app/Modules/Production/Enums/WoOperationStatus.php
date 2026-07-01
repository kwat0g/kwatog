<?php

declare(strict_types=1);

namespace App\Modules\Production\Enums;

enum WoOperationStatus: string
{
    case Pending    = 'pending';
    case Setup      = 'setup';
    case InProgress = 'in_progress';
    case Paused     = 'paused';
    case Completed  = 'completed';
    case Skipped    = 'skipped';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'Pending',
            self::Setup      => 'Setup',
            self::InProgress => 'In Progress',
            self::Paused     => 'Paused',
            self::Completed  => 'Completed',
            self::Skipped    => 'Skipped',
        };
    }
}
