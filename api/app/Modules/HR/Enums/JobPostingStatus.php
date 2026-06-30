<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum JobPostingStatus: string
{
    case Draft  = 'draft';
    case Open   = 'open';
    case Closed = 'closed';
    case Filled = 'filled';

    public function label(): string
    {
        return match ($this) {
            self::Draft  => 'Draft',
            self::Open   => 'Open',
            self::Closed => 'Closed',
            self::Filled => 'Filled',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft  => $target === self::Open,
            self::Open   => in_array($target, [self::Closed, self::Filled], true),
            self::Closed => $target === self::Open,
            self::Filled => false,
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
