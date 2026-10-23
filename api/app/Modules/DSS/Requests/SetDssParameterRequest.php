<?php

declare(strict_types=1);

namespace App\Modules\DSS\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Modules\DSS\Services\DetectorRegistry;

class SetDssParameterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('dss.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $detectorId = $this->input('detector_id');
        $key = $this->input('key');

        return [
            'detector_id' => ['required', 'string', 'max:80', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== 'global' && (! is_string($value) || ! app(DetectorRegistry::class)->has($value))) {
                    $fail('Choose a registered DSS detector or global settings.');
                }
            }],
            'key' => ['required', 'string', 'max:100'],
            'value' => ['present', function (string $attribute, mixed $value, \Closure $fail) use ($detectorId, $key): void {
                if (! is_string($key)) {
                    $fail('Choose a supported DSS parameter.');

                    return;
                }

                $globalTypes = [
                    'dss.runner.default_limit' => 'limit',
                    'dss.runner.time_budget_ms' => 'budget',
                    'dss.scoring.urgency_override' => 'unit_interval_or_null',
                    'dss.scoring.severity_weights' => 'severity_weights',
                ];

                if ($detectorId === 'global') {
                    $type = $globalTypes[$key] ?? null;
                    if ($type === null || ! $this->validValue($value, $type)) {
                        $fail('This global DSS parameter or value is not supported.');
                    }

                    return;
                }

                if (! is_string($detectorId) || ! app(DetectorRegistry::class)->has($detectorId)) {
                    return;
                }

                $defaults = app(DetectorRegistry::class)->make($detectorId)->defaultParams();
                if (! array_key_exists($key, $defaults) || ! $this->matchesDefaultType($key, $value, $defaults[$key])) {
                    $fail('This detector DSS parameter or value is not supported.');
                }
            }],
        ];
    }

    private function validValue(mixed $value, string $type): bool
    {
        return match ($type) {
            'limit' => is_int($value) && $value >= 1 && $value <= 10000,
            'budget' => is_int($value) && $value >= 1 && $value <= 300000,
            'unit_interval_or_null' => $value === null || (is_numeric($value) && (float) $value >= 0 && (float) $value <= 1),
            'severity_weights' => is_array($value)
                && count($value) === 3
                && array_diff(array_keys($value), ['critical', 'warning', 'info']) === []
                && array_diff(['critical', 'warning', 'info'], array_keys($value)) === []
                && collect($value)->every(fn ($weight): bool => is_numeric($weight) && $weight >= 0 && $weight <= 1),
            default => false,
        };
    }

    private function matchesDefaultType(string $key, mixed $value, mixed $default): bool
    {
        return match (true) {
            is_bool($default) => is_bool($value),
            is_int($default) => is_int($value) && match ($key) {
                'max_evidence_per_gap' => $value >= 1 && $value <= 50,
                'critical_count' => $value >= 1 && $value <= 100000,
                default => $value >= 0 && $value <= 300000,
            },
            is_float($default) => is_numeric($value) && is_finite((float) $value),
            is_string($default) => is_string($value) && mb_strlen($value) <= 255,
            is_array($default) => is_array($value),
            default => false,
        };
    }
}
