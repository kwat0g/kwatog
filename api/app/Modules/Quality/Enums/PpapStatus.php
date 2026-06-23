<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

enum PpapStatus: string
{
    case Draft       = 'draft';
    case Submitted   = 'submitted';
    case UnderReview = 'under_review';
    case Approved    = 'approved';
    case Rejected    = 'rejected';
    case Expired     = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft       => 'Draft',
            self::Submitted   => 'Submitted',
            self::UnderReview => 'Under Review',
            self::Approved    => 'Approved',
            self::Rejected    => 'Rejected',
            self::Expired     => 'Expired',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Expired], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
