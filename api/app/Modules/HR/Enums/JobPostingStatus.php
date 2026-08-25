<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

use App\Modules\HR\Support\RecruitmentPostingStateMachine;

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
        return RecruitmentPostingStateMachine::canTransition($this, $target);
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
