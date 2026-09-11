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

    /**
     * Statuses whose accepted quantity may be billed.
     *
     * A partially-accepted receipt already moved real stock and posted its
     * GRNI journal, so it carries a payable exactly like a fully-accepted one.
     * This is the single predicate for every "is this GRN billable?" check —
     * never re-list the statuses at a call site.
     *
     * @return array<int, self>
     */
    public static function billable(): array
    {
        return [self::Accepted, self::PartialAccepted];
    }

    /** @return array<int, string> */
    public static function billableValues(): array
    {
        return array_map(fn (self $c) => $c->value, self::billable());
    }

    public function isBillable(): bool
    {
        return in_array($this, self::billable(), true);
    }
}
