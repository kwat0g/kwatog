<?php

declare(strict_types=1);

namespace App\Common\Support;

use RuntimeException;

final class E2EEnvironment
{
    public static function assertEnabled(): string
    {
        $database = (string) config('database.connections.pgsql.database');
        $token = (string) config('app.e2e.control_token');

        if (! config('app.e2e.enabled')
            || app()->environment() !== 'testing'
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.host') !== 'db'
            || $database !== 'ogami_e2e'
            || config('app.e2e.database') !== 'ogami_e2e'
            || config('app.e2e.compose_project') !== 'ogami-erp-e2e'
            || strlen($token) < 32) {
            throw new RuntimeException('E2E control is available only inside the isolated ogami_e2e test environment.');
        }

        return $database;
    }
}
