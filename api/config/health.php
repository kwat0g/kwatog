<?php

declare(strict_types=1);

return [
    /*
     * Shared secret that unlocks the detailed per-component `checks` payload
     * of GET /api/v1/health via the X-Health-Token header.
     *
     * Empty is fail-closed: public health remains minimal and never exposes
     * internal topology (db/redis/queue state). Query-string tokens are not
     * supported because URLs can be retained in logs and referrers.
     */
    'detail_token' => (string) env('HEALTH_DETAIL_TOKEN', ''),
];
