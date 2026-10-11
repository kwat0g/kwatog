<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Common\Services\SettingsService;
use App\Modules\Dashboard\Support\Analytics\MetricDefinition;
use Illuminate\Support\Facades\Cache;

/**
 * The analytics cache layer. Phase 0 F §R15: widget-data and the rich payloads
 * cache NOTHING, so every 30-60s poll re-runs the aggregation against
 * transactional tables. This is the shared answer — one key per
 * `metric + scope + filters`, one TTL per metric, one invalidation seam.
 *
 * Keys embed a VERSION counter rather than relying on cache tags: tags need a
 * taggable store (Redis), while the test environment runs the array store, and
 * a cache layer that silently degrades to no-cache (or throws) in one
 * environment is worse than one that invalidates by version everywhere.
 * `flush()` bumps the counter, which orphans every previous key for that metric;
 * the counter itself is what makes the invalidation explicit and O(1).
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
            return $definition->cacheTtlSeconds;
        }

        // Only `live` metrics honour the global setting; the slower buckets are
        // intrinsic to the cadence and must not be shortened by a dashboard knob.
        if ($definition->refresh !== 'live') {
            return self::TTL_BY_REFRESH[$definition->refresh];
        }

        $configured = $this->settings->get('dashboard.analytics.cache_ttl_seconds', self::DEFAULT_TTL_SECONDS);
        $seconds = is_numeric($configured) ? (int) $configured : self::DEFAULT_TTL_SECONDS;

        return max(1, $seconds);
    }

    public function version(string $code): int
    {
        return (int) Cache::get($this->versionKey($code), 1);
    }

    /**
     * The fleet-wide version. Part of EVERY key, which is what makes
     * `flush(null)` work: without it the global bump would increment a counter
     * no key reads, and "flush everything" would silently flush nothing.
     */
    public function globalVersion(): int
    {
        return (int) Cache::get($this->versionKey(self::GLOBAL_CODE), 1);
    }

    /**
     * Explicit invalidation. Passing a code retires that metric's payloads;
     * passing null retires every analytics payload (config/FY rollover).
     */
    public function flush(?string $code = null): void
    {
        $this->bump($this->versionKey($code ?? self::GLOBAL_CODE));
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
     * @param  callable(): mixed      $query
     * @return array{cached: bool, ttl: int, key: string, value: mixed}
     */
    public function remember(MetricDefinition $definition, string $scope, array $filters, callable $query): array
    {
        $key = $this->key($definition, $scope, $filters);
        $ttl = $this->ttl($definition);

        $sentinel = new \stdClass;
        $cached = Cache::get($key, $sentinel);

        if ($cached !== $sentinel) {
            return ['cached' => true, 'ttl' => $ttl, 'key' => $key, 'value' => $cached];
        }

        $value = $query();
        Cache::put($key, $value, $ttl);

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
        if (Cache::has($key)) {
            Cache::increment($key);

            return;
        }

        Cache::forever($key, 2); // 1 is the implicit default the readers assume.
    }

    /** @param array<string, mixed> $filters */
    private function normalizeFilters(array $filters): array
    {
        ksort($filters);

        return $filters;
    }
}
