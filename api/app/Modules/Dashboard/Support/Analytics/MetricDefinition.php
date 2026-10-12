<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Support\Analytics;

use InvalidArgumentException;

/**
 * One row of the metric dictionary, as data.
 *
 * Phase 2 requires a dictionary whose entries are checkable: definition,
 * formula, source tables/columns, refresh cadence, gate. Putting that next to
 * the query means the doc and the code cannot disagree — `MetricRegistry` owns
 * the rows, the domain class owns the SQL, and `metrics:doc` renders the
 * dictionary from this object, so the Markdown is generated rather than
 * maintained by hand.
 *
 * `aggregation` is prose because the formula lives in SQL that is already
 * reviewable; `sources` and `indexes` are machine-checkable and are asserted
 * against the live schema by `AnalyticsLayerTest`.
 */
final readonly class MetricDefinition
{
    /**
     * @param  string        $code         stable identifier, `module.metric` (also the API route segment)
     * @param  string        $label        human name for a header/tooltip
     * @param  string        $module       owning module slug (hr, production, returns, ...)
     * @param  string        $unit         percent|currency|count|hours|days|ratio
     * @param  string        $direction    higher_better|lower_better|neutral
     * @param  string        $renderKind   scalar|trend|breakdown|table|gauge (matches Dashboard\Enums\RenderKind)
     * @param  string        $permission   the ONE gate; the registry never re-expresses module row scope
     * @param  string        $queryClass   domain analytics class that answers this metric
     * @param  string        $aggregation  prose description of the aggregation (mirrors the SQL)
     * @param  list<string>  $sources      every `table.column` the query touches
     * @param  list<string>  $indexes      indexes the query relies on (must exist — asserted in tests)
     * @param  string        $refresh      live|hourly|daily|monthly (drives the default TTL)
     * @param  list<string>  $caveats      known correctness limits, stated on the tile itself
     * @param  int|null      $cacheTtlSeconds  explicit override; null = refresh bucket / global setting
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $module,
        public string $unit,
        public string $direction,
        public string $renderKind,
        public string $permission,
        public string $queryClass,
        public string $aggregation,
        public array $sources,
        public array $indexes,
        public string $refresh,
        public array $caveats = [],
        public ?int $cacheTtlSeconds = null,
    ) {
        if (trim($this->code) === '' || ! str_contains($this->code, '.')) {
            throw new InvalidArgumentException("Metric code [{$this->code}] must be `module.metric`.");
        }

        if ($this->sources === []) {
            throw new InvalidArgumentException("Metric [{$this->code}] must declare at least one source column.");
        }

        if (in_array($this->refresh, ['live', 'hourly', 'daily', 'monthly'], true) === false) {
            throw new InvalidArgumentException("Metric [{$this->code}] has unknown refresh [{$this->refresh}].");
        }

        if ($this->cacheTtlSeconds !== null && $this->cacheTtlSeconds < 1) {
            throw new InvalidArgumentException("Metric [{$this->code}] cache TTL must be positive.");
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'module' => $this->module,
            'unit' => $this->unit,
            'direction' => $this->direction,
            'render_kind' => $this->renderKind,
            'permission' => $this->permission,
            'aggregation' => $this->aggregation,
            'sources' => $this->sources,
            'indexes' => $this->indexes,
            'refresh' => $this->refresh,
            'caveats' => $this->caveats,
            'cache_ttl_seconds' => $this->cacheTtlSeconds,
        ];
    }

    /**
     * Stable hash over the definition's content — the dictionary's drift
     * fingerprint, printed in the generated doc header so a reader can tell at a
     * glance whether the doc matches the code it was rendered from.
     */
    public function fingerprint(): string
    {
        $payload = $this->toArray();
        $payload['sources'] = $this->sources;
        $payload['indexes'] = $this->indexes;
        $payload['caveats'] = $this->caveats;
        ksort($payload);

        return substr(hash('sha256', (string) json_encode($payload)), 0, 16);
    }
}
