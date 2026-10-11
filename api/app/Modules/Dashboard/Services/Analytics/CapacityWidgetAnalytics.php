<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\CapacityForecastService;

/** Capacity forecast as a utilisation-per-month trend. Read-only; the engine owns the math. */
final class CapacityWidgetAnalytics
{
    public function __construct(private readonly CapacityForecastService $capacity) {}

    /** @return list<string> */
    public function handles(): array
    {
        return ['forecast.capacity'];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        if ($key !== 'forecast.capacity') {
            return [];
        }

        $months = $this->capacity->forecast()['months'] ?? [];
        if ($months === []) {
            return [];
        }

        $points = array_map(fn (array $m): array => [
            'label' => sprintf('%04d-%02d', $m['year'], $m['month']),
            'value' => (float) $m['util_pct'],
        ], $months);
        $first = (float) $points[0]['value'];
        $last = (float) end($points)['value'];

        return [
            'points' => $points,
            'delta' => $first != 0.0 ? round((($last - $first) / abs($first)) * 100, 1) : null,
            'kind' => 'percent',
        ];
    }
}
