<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum QuoteStatus: string
{
    case Draft     = 'draft';
    case Sent      = 'sent';
    case Accepted  = 'accepted';
    case Rejected  = 'rejected';
    case Expired   = 'expired';
    case Converted = 'converted';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Sent      => 'Sent',
            self::Accepted  => 'Accepted',
            self::Rejected  => 'Rejected',
            self::Expired   => 'Expired',
            self::Converted => 'Converted',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
