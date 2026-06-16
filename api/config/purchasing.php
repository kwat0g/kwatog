<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Urgent PR — Department Head skip limit (OGAMI-013)
    |--------------------------------------------------------------------------
    |
    | An "urgent" purchase request may skip the Department Head approval step.
    | This was previously an unconditional, value-blind bypass. The cap below
    | gates the skip behind a peso ceiling on the PR total:
    |
    |   - total <= urgent_skip_limit  -> the Dept Head step may be skipped.
    |   - total >  urgent_skip_limit  -> the skip is DENIED; full chain applies
    |                                     even when is_urgent = true.
    |
    | Set to '0' to disable urgent skipping entirely. The value is a decimal
    | string (pesos) to stay consistent with money handling elsewhere. The
    | default is intentionally permissive (large) so existing behavior is not
    | broken; tighten it via PURCHASING_URGENT_SKIP_LIMIT in production.
    |
    */
    'urgent_skip_limit' => env('PURCHASING_URGENT_SKIP_LIMIT', '50000'),
];
