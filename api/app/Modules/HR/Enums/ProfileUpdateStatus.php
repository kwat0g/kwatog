<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum ProfileUpdateStatus: string
{
    case Pending = 'pending';
    case PendingFinance = 'pending_finance';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending HR',
            self::PendingFinance => 'Awaiting Finance',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
