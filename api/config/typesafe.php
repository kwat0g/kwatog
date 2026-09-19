<?php

declare(strict_types=1);

return [
    // Never expose this key to the SPA or include it in command arguments.
    'api_key' => env('TYPESAFE_API_KEY'),
    'endpoint' => env('TYPESAFE_ENDPOINT', 'https://api.typesafe.ai/v1/systemone'),
    // Pin audits to a version so a later alias update cannot rewrite history.
    'model' => env('TYPESAFE_MODEL', 'jev-1.13.0'),
    'timeout_seconds' => (int) env('TYPESAFE_TIMEOUT_SECONDS', 30),
    'connect_timeout_seconds' => (int) env('TYPESAFE_CONNECT_TIMEOUT_SECONDS', 10),
    'max_attempts' => (int) env('TYPESAFE_MAX_ATTEMPTS', 3),
    'retry_delays_ms' => [250, 1000],
    'min_confidence' => (float) env('TYPESAFE_AUDIT_MIN_CONFIDENCE', 0.75),
    // Keep room below Jev's 64k request limit for question definitions.
    'max_request_bytes' => (int) env('TYPESAFE_MAX_REQUEST_BYTES', 200000),
];
