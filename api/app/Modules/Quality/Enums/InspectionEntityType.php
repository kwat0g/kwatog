<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

/**
 * Sprint 7 — Task 60. Polymorphic target an inspection gates.
 *
 *   grn         — incoming inspection on a Goods Receipt Note
 *   work_order  — in-process inspection during a Work Order
 *   delivery    — outgoing inspection before a Delivery is released
 */
enum InspectionEntityType: string
{
    case Grn       = 'grn';
    case WorkOrder = 'work_order';
    case Delivery  = 'delivery';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
