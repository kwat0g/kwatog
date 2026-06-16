<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

/**
 * OGAMI-008 — BIR VAT classification of a Sales Invoice.
 *
 *   vatable    — standard 12% output VAT applies on the subtotal
 *   zero_rated — 0% rated sale (e.g. export / PEZA) — no VAT, but VATable base
 *   vat_exempt — exempt sale — no VAT charged
 */
enum VatClassification: string
{
    case Vatable    = 'vatable';
    case ZeroRated  = 'zero_rated';
    case VatExempt  = 'vat_exempt';

    public function label(): string
    {
        return match ($this) {
            self::Vatable   => 'VATable',
            self::ZeroRated => 'Zero-Rated',
            self::VatExempt => 'VAT-Exempt',
        };
    }

    /** Whether 12% output VAT should be computed for this classification. */
    public function chargesVat(): bool
    {
        return $this === self::Vatable;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
