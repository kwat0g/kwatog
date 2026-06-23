<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

enum PpapElementStatus: string
{
    case Pending       = 'pending';
    case Submitted     = 'submitted';
    case Accepted      = 'accepted';
    case Rejected      = 'rejected';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Pending       => 'Pending',
            self::Submitted     => 'Submitted',
            self::Accepted      => 'Accepted',
            self::Rejected      => 'Rejected',
            self::NotApplicable => 'Not Applicable',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
