<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum InterviewOutcome: string
{
    case Pending = 'pending';
    case Passed  = 'passed';
    case Failed  = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Passed  => 'Passed',
            self::Failed  => 'Failed',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
