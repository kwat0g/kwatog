<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

/**
 * Sprint 7 — Task 61. Final disposition of non-conforming material.
 *
 *   scrap                 → write off; on outgoing-QC NCR auto-create replacement WO
 *   rework               → repair to spec
 *   use_as_is            → concession (records but ships anyway, with customer sign-off)
 *   return_to_supplier   → ship back; auto-notify Purchasing
 */
enum NcrDisposition: string
{
    case Scrap            = 'scrap';
    case Rework           = 'rework';
    case UseAsIs          = 'use_as_is';
    case ReturnToSupplier = 'return_to_supplier';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
