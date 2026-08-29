<?php

declare(strict_types=1);

namespace App\Common\Support;

use RuntimeException;

/**
 * Boot-time guard against deploying with dev defaults in production. Catches
 * misconfigured deployments that would otherwise serve traffic with a
 * reversible HashID salt, verbose debug pages, or the placeholder APP_KEY.
 */
class ProductionAssertions
{
    public static function assertSafeOrFail(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $errors = [];

        if (config('app.debug')) {
            $errors[] = 'APP_DEBUG must be false in production.';
        }

        $hashSalt = (string) config('hashids.connections.main.salt');
        if ($hashSalt === '' || str_starts_with($hashSalt, 'change_me')) {
            $errors[] = 'HASHIDS_SALT must be set to a non-default value (config/hashids.php).';
        }

        $appKey = (string) config('app.key');
        if ($appKey === '' || $appKey === 'base64:' || str_starts_with($appKey, 'base64:dev')) {
            $errors[] = 'APP_KEY must be a real generated key (php artisan key:generate).';
        }

        $serverName = strtolower(trim((string) config('app.server_name', '')));
        if ($serverName === '' || in_array($serverName, ['localhost', '127.0.0.1', '::1', '_'], true)) {
            $errors[] = 'SERVER_NAME must identify the real production host.';
        }

        // The whole authentication model is a cookie the browser must never send
        // over plaintext HTTP. config/session.php defaults `secure` to true, but
        // every dev template ships SESSION_SECURE_COOKIE=false, so a deployment
        // seeded from one of those would silently serve the session cookie
        // without the Secure attribute — and nothing else in the stack would
        // notice. Assert it here alongside the other dev-default guards.
        if (config('session.secure') !== true) {
            $errors[] = 'SESSION_SECURE_COOKIE must be true in production so the session cookie is never sent over plaintext HTTP.';
        }

        if (! empty($errors)) {
            throw new RuntimeException('Production boot blocked: '.implode(' ', $errors));
        }
    }
}
