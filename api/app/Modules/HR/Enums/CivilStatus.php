<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum CivilStatus: string
{
    case Single    = 'single';
    case Married   = 'married';
    case Widowed   = 'widowed';
    case Separated = 'separated';
    case Divorced  = 'divorced';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
