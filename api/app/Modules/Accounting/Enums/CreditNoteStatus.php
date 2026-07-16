<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum CreditNoteStatus: string
{
    case Draft     = 'draft';     // editable, not yet posted to GL
    case Finalized = 'finalized'; // posted to GL, unapplied credit available
    case Applied   = 'applied';   // fully applied to invoices/bills
    case Void      = 'void';      // reversed

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Finalized => 'Finalized',
            self::Applied   => 'Applied',
            self::Void      => 'Void',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
