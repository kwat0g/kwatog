<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum LeadStatus: string
{
    case New          = 'new';
    case Contacted    = 'contacted';
    case Qualified    = 'qualified';
    case Disqualified = 'disqualified';
    case Converted    = 'converted';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::New          => 'New',
            self::Contacted    => 'Contacted',
            self::Qualified    => 'Qualified',
            self::Disqualified => 'Disqualified',
            self::Converted    => 'Converted',
        };
    }
}
