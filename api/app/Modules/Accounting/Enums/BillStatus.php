<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum BillStatus: string
{
    // 2026-08-08 — auto-created supplier bills (GRN accepted → draft bill)
    // live in this state until accounting reviews and posts them. Nothing in
    // open-bill/aging/AP queries matches it, so drafts never leak into
    // payables or the supplier portal.
    case Draft     = 'draft';
    case Unpaid    = 'unpaid';
    case Partial   = 'partial';
    case Paid      = 'paid';
    case Cancelled = 'cancelled';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Unpaid => 'Unpaid',
            self::Partial => 'Partially paid',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }
}
