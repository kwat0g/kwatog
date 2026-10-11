<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\Analytics\Domains\DomainMetric;
use App\Modules\Dashboard\Support\Analytics\AnalyticsPeriod;
use App\Modules\Dashboard\Support\Analytics\MetricDefinition;
use App\Modules\Dashboard\Support\Analytics\MetricRegistry;

/**
 * The analytics layer's front door: the dictionary it publishes and the
 * envelope it answers with.
 *
 * One envelope for every metric — `data` carries the payload, `meta` carries
 * period, generated_at, cache_ttl, scope, sources and caveats. Phase 2 asked
 * for exactly that shape because the alternative already happened once: each
 * bespoke dashboard invented its own payload and the SPA had to know eight
 * shapes. The metric's definition travels WITH its value, so "explain this
 * number" needs no second lookup and the tile can show its own caveats.
 */
final class AnalyticsService
{
    public function __construct(
        private readonly MetricRegistry $registry,
        private readonly AnalyticsCache $cache,
    ) {}

    /**
     * The dictionary, filtered to the metrics this caller may read.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function catalog(User $user, ?string $module = null): array
    {
        $visible = $this->registry->visibleTo($user);

        if ($module !== null && $module !== '') {
            $visible = array_filter(
                $visible,
                static fn (MetricDefinition $definition): bool => $definition->module === $module,
            );
        }

        return [
            'data' => array_values(array_map(
                static fn (MetricDefinition $definition): array => $definition->toArray(),
                $visible,
            )),
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'count' => count($visible),
                'module' => $module,
                'fingerprint' => $this->registry->fingerprint(),
                'document' => 'docs/dashboards/METRICS.md',
            ],
        ];
    }

    /**
     * Resolve one metric for one viewer over one period.
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function resolve(string $code, User $user, AnalyticsPeriod $period, array $filters = []): array
    {
        $definition = $this->require($code);
        $result = $this->domain($definition)->resolve($user, $period, $filters);

        return [
            'data' => [
                'definition' => $definition->toArray(),
                'value' => $result['value'],
            ],
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'metric' => $definition->code,
                'period' => $period->toMeta(),
                'cache_ttl' => $result['ttl'],
                'cached' => $result['cached'],
                'cache_key' => $result['cache_key'],
                'scope' => $result['scope'],
                'module' => $definition->module,
                'refresh' => $definition->refresh,
                'sources' => $definition->sources,
                'indexes' => $definition->indexes,
                'caveats' => $definition->caveats,
            ],
        ];
    }

    /** Explicit invalidation seam (also used by tests and admin tooling). */
    public function flush(?string $code = null): void
    {
        $this->cache->flush($code);
    }

    public function definition(string $code): ?MetricDefinition
    {
        return $this->registry->get($code);
    }

    /** True when the metric exists AND the caller may read it. */
    public function readableBy(string $code, User $user): bool
    {
        $definition = $this->registry->get($code);

        return $definition !== null && $user->hasPermission($definition->permission);
    }

    private function require(string $code): MetricDefinition
    {
        $definition = $this->registry->get($code);

        if ($definition === null) {
            throw new \RuntimeException("Unknown metric [{$code}].");
        }

        return $definition;
    }

    private function domain(MetricDefinition $definition): DomainMetric
    {
        /** @var DomainMetric $metric */
        $metric = app($definition->queryClass);

        return $metric;
    }
}
