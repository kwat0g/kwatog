<?php

declare(strict_types=1);

return [
    /*
    | Budget Enforcement Mode — 'off' (default, advisory), 'warn' (log only),
    | 'block' (hard-block at 100%+). Set via BUDGETING_ENFORCEMENT_MODE.
    */
    'enforcement_mode' => env('BUDGETING_ENFORCEMENT_MODE', 'off'),
];
