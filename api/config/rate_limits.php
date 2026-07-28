<?php

declare(strict_types=1);

return [
    // A React ERP page legitimately fans out into dashboard, notification,
    // badge, and table queries. Guests remain conservative while signed-in
    // users have room for a fast defense walkthrough.
    'api_guest_per_minute' => (int) env('API_GUEST_RATE_LIMIT', 60),
    'api_authenticated_per_minute' => (int) env('API_AUTHENTICATED_RATE_LIMIT', 300),
];
