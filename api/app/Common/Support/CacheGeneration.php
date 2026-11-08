<?php

declare(strict_types=1);

namespace App\Common\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Resolves generation markers without reviving a payload namespace after marker loss. */
final class CacheGeneration
{
    private const DEFAULT_STORE = 'generations';

    /** Resolve the configured repository inside the same failure boundary. */
    public static function rotateDefault(
        string $key,
        int $ttlSeconds,
        string $owner,
        ?string $generation = null,
    ): bool {
        try {
            return self::rotate(Cache::store(self::DEFAULT_STORE), $key, $ttlSeconds, $owner, $generation);
        } catch (Throwable $exception) {
            Log::error('Cache generation repository could not be resolved.', [
                'owner' => $owner,
                'key_hash' => hash('sha256', $key),
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /** Read a generation marker from the durable metadata store, never the evictable payload cache. */
    public static function currentDefault(string $key, int $ttlSeconds, string $owner): string
    {
        try {
            return self::current(Cache::store(self::DEFAULT_STORE), $key, $ttlSeconds, $owner);
        } catch (Throwable $exception) {
            Log::warning('Cache generation repository could not be resolved; using an unshared namespace.', [
                'owner' => $owner,
                'key_hash' => hash('sha256', $key),
                'exception' => $exception::class,
            ]);

            return 'cache-unavailable-'.Str::uuid();
        }
    }

    /** Rotate a marker, retiring the old namespace if the write is rejected. */
    public static function rotate(
        Repository $cache,
        string $key,
        int $ttlSeconds,
        string $owner,
        ?string $generation = null,
    ): bool {
        $generation ??= (string) Str::uuid();

        $lastExceptionClass = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                if ($cache->put($key, $generation, $ttlSeconds)) {
                    return true;
                }
                $lastExceptionClass = null;
            } catch (Throwable $exception) {
                $lastExceptionClass = $exception::class;
            }

            if ($attempt === 1) {
                Log::warning('Cache generation write failed; retrying once before retiring the prior marker.', [
                    'owner' => $owner,
                    'key_hash' => hash('sha256', $key),
                    'exception' => $lastExceptionClass,
                ]);
            }
        }

        Log::warning('Cache generation write failed after retry; retiring the prior marker.', [
            'owner' => $owner,
            'key_hash' => hash('sha256', $key),
            'exception' => $lastExceptionClass,
        ]);

        try {
            // A missing marker causes current() to initialize a fresh UUID
            // namespace. This makes a failed rotation fail toward cache misses
            // instead of leaving the previous payload addressable.
            if ($cache->forget($key)) {
                return true;
            }

            // Some stores return false when the key was already absent. Treat
            // that as retired only after confirming there is no marker to
            // select an older payload namespace.
            if ($cache->get($key) === null) {
                return true;
            }

            Log::error('Cache generation fallback could not retire the prior marker.', [
                'owner' => $owner,
                'key_hash' => hash('sha256', $key),
            ]);
        } catch (Throwable $exception) {
            Log::error('Cache generation fallback failed to retire the prior marker.', [
                'owner' => $owner,
                'key_hash' => hash('sha256', $key),
                'exception' => $exception::class,
            ]);
        }

        return false;
    }

    public static function current(Repository $cache, string $key, int $ttlSeconds, string $owner): string
    {
        try {
            $version = $cache->get($key);
            if (is_string($version) && $version !== '') {
                return $version;
            }
            if (is_int($version)) {
                return (string) $version;
            }

            // `add` is atomic across PHP workers. Never fall back to a stable
            // legacy token, which could make an old payload current again.
            $candidate = (string) Str::uuid();
            $cache->add($key, $candidate, $ttlSeconds);
            $version = $cache->get($key);

            if (is_string($version) && $version !== '') {
                return $version;
            }
            if (is_int($version)) {
                return (string) $version;
            }

            return $candidate;
        } catch (Throwable $exception) {
            Log::warning('Cache generation read failed; using an unshared namespace.', [
                'owner' => $owner,
                'key_hash' => hash('sha256', $key),
                'exception' => $exception::class,
            ]);

            return 'cache-unavailable-'.Str::uuid();
        }
    }
}
