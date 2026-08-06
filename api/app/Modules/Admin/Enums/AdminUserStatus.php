<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

enum AdminUserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Locked = 'locked';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
