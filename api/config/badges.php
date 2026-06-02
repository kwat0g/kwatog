<?php

declare(strict_types=1);

return [
    /*
     | Severity thresholds for sidebar badge counts.
     | count >  danger  → 'danger'
     | count >  warning → 'warning'
     | otherwise        → 'neutral'
     */
    'severity' => [
        'danger'  => (int) env('BADGE_SEVERITY_DANGER', 20),
        'warning' => (int) env('BADGE_SEVERITY_WARNING', 0),
    ],

    // Per-user badge cache TTL (seconds). Real-time bumps invalidate sooner.
    'cache_ttl' => (int) env('BADGE_CACHE_TTL', 30),
];
