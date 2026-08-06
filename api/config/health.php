<?php

declare(strict_types=1);

return [
    /*
     * Optional shared secret that unlocks the detailed per-component `checks`
     * payload of GET /api/v1/health (via X-Health-Token header or ?token=).
     *
     * When empty, the checks are returned to everyone (legacy behavior).
     * Set it in production to keep internal topology (db/redis/queue state)
     * private while load balancers / uptime monitors supply the token.
     */
    'detail_token' => (string) env('HEALTH_DETAIL_TOKEN', ''),
];
