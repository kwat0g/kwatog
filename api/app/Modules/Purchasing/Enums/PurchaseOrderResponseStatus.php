<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

/**
 * Lifecycle of a supplier response row.
 *
 * `pending`  — awaiting purchasing's decision.
 * `accepted` — purchasing accepted the reply (an `accept` reply resolves to
 *              this immediately at creation; a `propose`/`decline` reply
 *              moves here only through the internal resolve endpoint).
 * `rejected` — purchasing sent it back; the PO returns to `sent` so the
 *              supplier may respond again.
 * `superseded` — a newer pending response replaced this one; kept for the
 *              audit trail.
 */
enum PurchaseOrderResponseStatus: string
{
    case Pending      = 'pending';
    case Accepted     = 'accepted';
    case Rejected     = 'rejected';
    case Superseded   = 'superseded';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'Pending',
            self::Accepted   => 'Accepted',
            self::Rejected   => 'Rejected',
            self::Superseded => 'Superseded',
        };
    }
}
