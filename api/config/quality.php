<?php

declare(strict_types=1);

return [
    /*
    | PPAP PO Gate — when true, PurchaseOrderService::approve() blocks approval
    | of a PO whose vendor+item has a registered PPAP that is NOT approved.
    | Default false = advisory only, no behavior change.
    */
    'ppap_gate_enabled' => env('QUALITY_PPAP_GATE', false),
];
