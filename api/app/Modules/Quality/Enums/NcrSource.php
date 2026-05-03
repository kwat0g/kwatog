<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

/**
 * Sprint 7 — Task 61. NCR origin path.
 */
enum NcrSource: string
{
    case InspectionFail     = 'inspection_fail';
    case CustomerComplaint  = 'customer_complaint';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
