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

        if (! empty($errors)) {
            throw new RuntimeException('Production boot blocked: '.implode(' ', $errors));
        }
    }
}
