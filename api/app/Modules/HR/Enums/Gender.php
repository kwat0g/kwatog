<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum Gender: string
{
    case Male   = 'male';
    case Female = 'female';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
