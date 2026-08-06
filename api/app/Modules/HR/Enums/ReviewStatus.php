<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum ReviewStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Acknowledged = 'acknowledged';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In progress',
            self::Submitted => 'Submitted',
            self::Acknowledged => 'Acknowledged',
        };
    }
}
