<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Common\Services\SettingsService;
use App\Common\Support\CacheGeneration;
use App\Modules\Dashboard\Support\Analytics\MetricDefinition;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The analytics cache layer. Phase 0 F §R15: widget-data and the rich payloads
 * cache NOTHING, so every 30-60s poll re-runs the aggregation against
 * transactional tables. This is the shared answer — one key per
 * `metric + scope + filters`, one TTL per metric, one invalidation seam.
 *
 * Keys embed a unique generation token rather than relying on cache tags: tags need a
 * taggable store (Redis), while the test environment runs the array store, and
 * a cache layer that silently degrades to no-cache (or throws) in one
 * environment is worse than one that invalidates by version everywhere.
 * `flush()` rotates the token, which orphans every previous key for that metric;
 * the token itself makes invalidation explicit and O(1), while remaining safe
 * when old marker keys expire.
 *
 * Scope is part of the key on purpose: a company-wide reading and a
 * department-scoped reading are different payloads. Serving one under the
 * other's key is the classic analytics leak, and it is the reason `scopeId()`
 * exists on `DomainMetric` even for metrics that always answer company-wide.
 */
final class AnalyticsCache
{
    /** Fallback when the setting row is missing (fresh install, trimmed DB). */
    public const DEFAULT_TTL_SECONDS = 60;

    private const MAX_TTL_SECONDS = 86400;

    private const VERSION_TTL_SECONDS = 604800;

    private const FILL_LOCK_TTL_SECONDS = 30;

    private const FILL_LOCK_WAIT_SECONDS = 1;

    /** Sentinel metric slot for the fleet-wide version counter. */
    private const GLOBAL_CODE = '*';

    /** Default TTL per refresh cadence; a definition may override. */
    private const TTL_BY_REFRESH = [
        'live' => 60,
        'hourly' => 300,
        'daily' => 3600,
        'monthly' => 86400,
    ];

    private const VERSION_KEY = 'dash:an:ver';

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /** Seconds this metric's payloads live, resolved definition → setting → default. */
    public function ttl(MetricDefinition $definition): int
    {
        if ($definition->cacheTtlSeconds !== null) {
            $seconds = $definition->cacheTtlSeconds;
        } elseif ($definition->refresh !== 'live') {
            // Only `live` metrics honour the global setting; slower buckets
            // use their cadence default.
            $seconds = self::TTL_BY_REFRESH[$definition->refresh];
        } else {
            $configured = $this->settings->get('dashboard.analytics.cache_ttl_seconds', self::DEFAULT_TTL_SECONDS);
            $seconds = is_numeric($configured) ? (int) $configured : self::DEFAULT_TTL_SECONDS;
        }

        return min(self::MAX_TTL_SECONDS, max(1, $seconds));
    }

    public function version(string $code): string
    {
        $key = $this->versionKey($code);

        return CacheGeneration::currentDefault(
            $key, self::VERSION_TTL_SECONDS, 'dashboard.analytics.'.$code);
    }

    /**
     * The fleet-wide version. Part of EVERY key, which is what makes
     * `flush(null)` work: without it the global token would not be part of keys
     * no key reads, and "flush everything" would silently flush nothing.
     */
    public function globalVersion(): string
    {
        $key = $this->versionKey(self::GLOBAL_CODE);

        return CacheGeneration::currentDefault(
            $key, self::VERSION_TTL_SECONDS, 'dashboard.analytics.global');
    }

    /**
     * Explicit invalidation. Passing a code retires that metric's payloads;
     * passing null rotates the shared analytics generation (config/FY rollover).
     */
    public function flush(?string $code = null): void
    {
        $this->bump($this->versionKey($code ?? self::GLOBAL_CODE));
    }

    /** Retire every analytics snapshot after replacing the source database. */
    public function invalidateForDatabaseRestore(): bool
    {
        $key = $this->versionKey(self::GLOBAL_CODE);

        return CacheGeneration::rotateDefault(
            $key,
            self::VERSION_TTL_SECONDS,
            'dashboard.analytics.database_restore',
        );
    }

    /**
     * Cache key: metric + version + scope + filter fingerprint. Built from
     * primitives so a caller cannot accidentally share an entry between two
     * differently-scoped viewers.
     *
     * @param  array<string, mixed>  $filters
     */
    public function key(MetricDefinition $definition, string $scope, array $filters = []): string
    {
        return implode(':', [
            'dash',
            'an',
            $definition->code,
            'v'.$this->globalVersion().'.'.$this->version($definition->code),
            str_replace(':', '_', $scope),
            substr(hash('sha256', (string) json_encode($this->normalizeFilters($filters))), 0, 12),
        ]);
    }

    /**
     * Resolve and cache one metric payload.
     *
     * @param  array<string, mixed>  $filters
     * @param  callable(): mixed  $query
     * @return array{cached: bool, ttl: int, key: string, value: mixed}
     */
    public function remember(MetricDefinition $definition, string $scope, array $filters, callable $query): array
    {
        $key = $this->key($definition, $scope, $filters);
        $ttl = $this->ttl($definition);

        $sentinel = new \stdClass;
        $cached = $this->read($key, $sentinel, $definition->code);

        if ($cached !== $sentinel) {
            return ['cached' => true, 'ttl' => $ttl, 'key' => $key, 'value' => $cached];
        }

        // Several viewers may open the same dashboard when this shared key
        // expires. Use the non-evicting Redis lock connection configured for
        // the cache store, then recheck after acquisition before running SQL.
        try {
            $lock = Cache::lock(
                'dash:an:fill:'.config('app.env', 'production').':'.hash('sha256', $key),
                self::FILL_LOCK_TTL_SECONDS,
            );
        } catch (Throwable $exception) {
            Log::warning('Analytics cache fill lock unavailable; falling back to an unlocked fill.', [
                'metric' => $definition->code,
                'exception' => $exception::class,
            ]);

            return $this->fillWithoutLock($key, $ttl, $query, $definition->code);
        }

        try {
            $lock->block(self::FILL_LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            // Preserve request availability if the owner is unusually slow.
            // Do not cache this fallback: it must not race and overwrite the
            // owner's result after releasing the lock.
            $cached = $this->read($key, $sentinel, $definition->code);
            if ($cached !== $sentinel) {
                return ['cached' => true, 'ttl' => $ttl, 'key' => $key, 'value' => $cached];
            }

            return ['cached' => false, 'ttl' => $ttl, 'key' => $key, 'value' => $query()];
        } catch (Throwable $exception) {
            // A lock backend failure must not make an otherwise available
            // cache/query path unavailable; the versioned key remains safe.
            Log::warning('Analytics cache fill lock unavailable; falling back to an unlocked fill.', [
                'metric' => $definition->code,
                'exception' => $exception::class,
            ]);

            return $this->fillWithoutLock($key, $ttl, $query, $definition->code);
        }

        try {
            $cached = $this->read($key, $sentinel, $definition->code);
            if ($cached !== $sentinel) {
                return ['cached' => true, 'ttl' => $ttl, 'key' => $key, 'value' => $cached];
            }

            $value = $query();
            $this->write($key, $value, $ttl, $definition->code);

            return ['cached' => false, 'ttl' => $ttl, 'key' => $key, 'value' => $value];
        } finally {
            try {
                $lock->release();
            } catch (Throwable $exception) {
                Log::warning('Analytics cache fill lock release failed; its lease will expire.', [
                    'metric' => $definition->code,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    /**
     * @param  callable(): mixed  $query
     * @return array{cached: bool, ttl: int, key: string, value: mixed}
     */
    private function fillWithoutLock(string $key, int $ttl, callable $query, string $metric): array
    {
        $value = $query();
        $this->write($key, $value, $ttl, $metric);

        return ['cached' => false, 'ttl' => $ttl, 'key' => $key, 'value' => $value];
    }

    private function versionKey(string $code): string
    {
        // Namespaced by APP_ENV so a staging flush cannot retire production keys
        // when both share one Redis instance.
        return self::VERSION_KEY.':'.config('app.env', 'production').':'.$code;
    }

    private function bump(string $key): void
    {
        DB::afterCommit(static function () use ($key): void {
            // A failed write retires the old namespace; callback failures are
            // contained by CacheGeneration so outbox dispatch can continue.
            CacheGeneration::rotateDefault($key, self::VERSION_TTL_SECONDS, 'dashboard.analytics');
        });
    }

    private function read(string $key, mixed $default, string $metric): mixed
    {
        try {
            return Cache::get($key, $default);
        } catch (Throwable $exception) {
            $this->logCacheFailure('payload_read', $key, $metric, $exception);

            return $default;
        }
    }

    private function write(string $key, mixed $value, int $ttl, string $metric): void
    {
        try {
            Cache::put($key, $value, $ttl);
        } catch (Throwable $exception) {
            $this->logCacheFailure('payload_write', $key, $metric, $exception);
        }
    }

    private function logCacheFailure(string $operation, string $key, string $metric, Throwable $exception): void
    {
        Log::warning('Analytics cache operation failed; resolving from the source query.', [
            'operation' => $operation,
            'metric' => $metric,
            'key_hash' => hash('sha256', $key),
            'exception' => $exception::class,
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function normalizeFilters(array $filters): array
    {
        ksort($filters);

        return $filters;
    }
}
