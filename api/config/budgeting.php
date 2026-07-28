<?php

declare(strict_types=1);

return [
    /*
    | Budget Enforcement Mode — 'off' (disabled), 'warn' (advisory with
    | Finance acknowledgment at 100%+), 'block' (hard-block at 100%+).
    | Set via BUDGETING_ENFORCEMENT_MODE.
    */
    'enforcement_mode' => env('BUDGETING_ENFORCEMENT_MODE', 'warn'),
];
