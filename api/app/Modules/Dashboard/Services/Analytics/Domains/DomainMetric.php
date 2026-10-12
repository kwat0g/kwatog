<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics\Domains;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\Analytics\AnalyticsCache;
use App\Modules\Dashboard\Support\Analytics\AnalyticsPeriod;
use App\Modules\Dashboard\Support\Analytics\MetricDefinition;
use App\Modules\Dashboard\Support\Analytics\MetricRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/**
 * A domain analytics query object. Subclasses implement SQL only.
 *
 * Everything that made the widget providers drift lives one level up: the gate
 * comes from the registry (one permission, permission-derived, never a role
 * name), scope is declared by overriding `scopeId()`, and the payload is cached
 * per metric + scope + filters. A subclass therefore cannot forget a gate or
 * invent its own cache key — it writes a query and returns a shape.
 *
 * `resolve()` re-checks the gate even though the HTTP layer already did. The
 * check is cheap and it is what makes the service safe for non-HTTP callers
 * (scheduled commands, exports, future slide-over previews): a caller that may
 * not read a metric cannot run its query, so a refused read costs a permission
 * lookup rather than a table scan.
 */
abstract class DomainMetric
{
    public function __construct(
        protected readonly MetricRegistry $registry,
        protected readonly AnalyticsCache $cache,
    ) {}

    /** The registry code this class answers, e.g. `returns.pipeline`. */
    abstract public function code(): string;

    /**
     * The payload. SQL aggregation only — never load rows and loop.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    abstract protected function build(User $user, AnalyticsPeriod $period, array $filters): array;

    public function definition(): MetricDefinition
    {
        $definition = $this->registry->get($this->code());

        if ($definition === null) {
            throw new RuntimeException(
                "Domain metric [{$this->code()}] is not registered in MetricRegistry — a metric that no "
                .'dictionary entry describes cannot be gated, cached or documented.'
            );
        }

        return $definition;
    }

    /**
     * The department whose data this caller sees, or null for company-wide.
     * Metrics that are department-scoped override this with `WidgetScope`.
     */
    public function scopeId(User $user): ?int
    {
        return null;
    }

    public function scopeLabel(User $user): string
    {
        $departmentId = $this->scopeId($user);

        return $departmentId === null ? 'company' : 'department:'.$departmentId;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{cached: bool, ttl: int, scope: string, cache_key: string, value: mixed}
     */
    final public function resolve(User $user, AnalyticsPeriod $period, array $filters = []): array
    {
        $definition = $this->definition();

        if (! $user->hasPermission($definition->permission)) {
            throw new AuthorizationException(
                "You may not read the metric [{$definition->code}]."
            );
        }

        $scope = $this->scopeLabel($user);
        $filters = $this->withPeriod($period, $filters);

        $result = $this->cache->remember(
            definition: $definition,
            scope: $scope,
            filters: $filters,
            query: fn (): array => $this->build($user, $period, $filters),
        );

        return [
            'cached' => $result['cached'],
            'ttl' => $result['ttl'],
            'scope' => $scope,
            'cache_key' => $result['key'],
            'value' => $result['value'],
        ];
    }

    /**
     * The window is part of the cache identity even when the caller did not pass
     * it as a filter, so two periods never share an entry.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function withPeriod(AnalyticsPeriod $period, array $filters): array
    {
        return [
            'from' => $period->from->toDateString(),
            'to' => $period->to->toDateString(),
            'filters' => $filters,
        ];
    }
}
