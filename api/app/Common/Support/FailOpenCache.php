<?php

declare(strict_types=1);

namespace App\Common\Support;

use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-through cache helper for non-authoritative snapshots.
 *
 * Cache outages must not hide the fresh source-of-truth result. A failed read
 * computes without caching; a failed write still returns the computed value.
 */
final class FailOpenCache
{
    public static function remember(
        Repository $repository,
        string $key,
        DateInterval|DateTimeInterface|int $ttlSeconds,
        callable $resolve,
        string $owner,
    ): mixed {
        $missing = new \stdClass();

        try {
            $cached = $repository->get($key, $missing);
        } catch (Throwable $exception) {
            self::logFailure('read', $key, $owner, $exception);

            return $resolve();
        }

        if ($cached !== $missing) {
            return $cached;
        }

        $value = $resolve();

        try {
            $repository->put($key, $value, $ttlSeconds);
        } catch (Throwable $exception) {
            self::logFailure('write', $key, $owner, $exception);
        }

        return $value;
    }

    private static function logFailure(string $operation, string $key, string $owner, Throwable $exception): void
    {
        Log::warning('Non-authoritative cache operation failed; using the source result.', [
            'operation' => $operation,
            'owner' => $owner,
            'key_hash' => hash('sha256', $key),
            'exception' => $exception::class,
        ]);
    }
}
