<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Requests;

use App\Modules\Dashboard\Support\Analytics\AnalyticsPeriod;
use App\Modules\Dashboard\Support\Analytics\MetricDefinition;
use App\Modules\Dashboard\Support\Analytics\MetricRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One metric, one viewer, one window.
 *
 * Authorization resolves the route's metric through the registry and asks for
 * that metric's OWN gate — the metric's permission is the only thing that
 * decides, so a new metric cannot ship without a gate and no role name appears
 * anywhere. An unknown metric is a 404 (it does not exist), a known metric the
 * caller may not read is a 403 (it exists, you may not have it): the same
 * distinction the rest of the API makes.
 */
class ShowMetricRequest extends FormRequest
{
    public function authorize(): bool
    {
        $definition = $this->definition();

        if ($definition === null) {
            abort(404, 'Unknown metric.');
        }

        return $this->user()?->hasPermission($definition->permission) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(AnalyticsPeriod::PRESETS)],
            // A custom window needs BOTH ends; `AnalyticsPeriod` also refuses a
            // half-open window for non-HTTP callers, but the HTTP path must
            // answer 422 rather than let that refusal become a 500.
            'from' => ['nullable', 'date', 'required_if:period,custom'],
            'to' => ['nullable', 'date', 'required_if:period,custom', 'after_or_equal:from'],
            // Only filters a registered metric actually consumes are allowed
            // through; an unknown filter would silently change the cache key
            // while changing no query.
            'machine_id' => ['nullable', 'integer', 'min:1', 'exists:machines,id'],
        ];
    }

    public function definition(): ?MetricDefinition
    {
        /** @var MetricRegistry $registry */
        $registry = app(MetricRegistry::class);

        return $registry->get((string) $this->route('metric'));
    }

    public function period(): AnalyticsPeriod
    {
        return AnalyticsPeriod::fromInput($this->validated());
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $validated = $this->validated();

        return array_filter(
            ['machine_id' => $validated['machine_id'] ?? null],
            static fn ($value): bool => $value !== null,
        );
    }
}
