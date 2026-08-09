<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum GrnStatus: string
{
    case Draft          = 'draft';
    case PendingQc      = 'pending_qc';
    case Accepted       = 'accepted';
    case PartialAccepted = 'partial_accepted';
    case Rejected       = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft          => 'Draft',
            self::PendingQc      => 'Pending QC',
            self::Accepted       => 'Accepted',
            self::PartialAccepted => 'Partially Accepted',
            self::Rejected       => 'Rejected',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
