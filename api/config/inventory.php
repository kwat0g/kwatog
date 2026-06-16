<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| OGAMI-012 — Inventory module configuration
|--------------------------------------------------------------------------
*/

return [

    /*
     | Value-threshold approval gate for manual stock adjustments.
     |
     | When the absolute peso value of an adjustment (|quantity * unit_cost|)
     | EXCEEDS this threshold, the adjustment is created in a `pending` state
     | and must be approved (StockAdjustmentService::approve) before the stock
     | movement posts. A value of '0' (the default) disables the gate entirely
     | — every adjustment applies immediately, preserving legacy behavior.
     |
     | Kept as a decimal STRING for bcmath comparison (never float money).
     */
    'adjustment_approval_threshold' => env('INVENTORY_ADJUSTMENT_APPROVAL_THRESHOLD', '0'),

];
