<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Enums\KpiUnit;
use App\Modules\Dashboard\Models\KpiDefinition;
use App\Modules\Dashboard\Models\KpiSnapshot;
use Database\Seeders\DashboardWidgetSeeder;

/**
 * KPI scorecard analytics — one trend per definition.
 *
 * The data was already there: `kpi_snapshots` holds a monthly actual, the
 * target in force that month, the previous month and a computed status, for up
 * to 24 months. Nothing in the widget registry could address it, so the five
 * roles that land on the generic dashboard saw no KPI at all while the seven
 * bespoke pages hard-coded a KpiStrip.
 *
 * Read-only, and the permission is NOT decided here — `dashboard_widgets.permission`
 * (seeded from KpiSnapshotService::MODULE_PERMISSIONS) is the gate, applied by
 * DashboardLayoutService before this ever runs.
 *
 * Returns the trend shape plus two optional fields the other trend providers
 * don't set: `target` and `status`. A KPI without them is just a line, and the
 * whole point of a scorecard KPI is whether it is meeting its target.
 */
final class KpiWidgetAnalytics
{
    /** Months of history a KPI tile carries. Enough to read a direction. */
    private const WINDOW = 12;

    /** @return list<string> */
    public function handles(): array
    {
        return array_map(
            fn (array $kpi): string => DashboardWidgetSeeder::kpiWidgetKey($kpi['code']),
            DashboardWidgetSeeder::kpiCatalog(),
        );
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        if (! str_starts_with($key, 'kpi.')) {
            return [];
        }

        $definition = KpiDefinition::query()
            ->where('code', substr($key, 4))
            ->first();

        // A widget whose definition was deactivated or never seeded degrades to
        // the scalar path rather than drawing an empty axis.
        if (! $definition) {
            return [];
        }

        $snapshots = KpiSnapshot::query()
            ->where('definition_id', $definition->id)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->limit(self::WINDOW)
            ->get()
            ->sortBy(fn (KpiSnapshot $s): string => sprintf('%04d-%02d', $s->period_year, $s->period_month))
            ->values();

        if ($snapshots->isEmpty()) {
            return [];
        }

        $points = $snapshots->map(fn (KpiSnapshot $s): array => [
            'label' => sprintf('%04d-%02d', $s->period_year, $s->period_month),
            'value' => (float) $s->actual_value,
        ])->all();

        /** @var KpiSnapshot $latest */
        $latest = $snapshots->last();

        return [
            'points' => $points,
            'delta'  => $this->delta($latest),
            'kind'   => $this->kind($definition->unit),
            // The target on the latest snapshot, not the definition's current
            // one: a target raised this quarter must not retroactively rescore
            // the months measured against the old one.
            'target' => $latest->target_value === null ? null : (float) $latest->target_value,
            'status' => $latest->status?->value,
        ];
    }

    /**
     * Month-over-month change as a percentage, signed in RAW terms.
     *
     * Deliberately not flipped for `lower_is_better` KPIs: the SPA colours the
     * delta by sign, and a DPPM that fell is a fall. Inverting here would show
     * a green "+" on a number that went down and read as an increase.
     */
    private function delta(KpiSnapshot $latest): ?float
    {
        $previous = $latest->previous_value === null ? null : (float) $latest->previous_value;

        if ($previous === null || $previous === 0.0) {
            return null;
        }

        return round((((float) $latest->actual_value - $previous) / abs($previous)) * 100, 1);
    }

    /** KPI units map onto the widget value kinds the SPA already formats. */
    private function kind(KpiUnit $unit): string
    {
        return match ($unit) {
            KpiUnit::Percentage => 'percent',
            KpiUnit::Currency => 'currency',
            KpiUnit::Count => 'count',
            // Days and ratios are both fractional readings; 'decimal' keeps the
            // two decimal places a 6.2 turnover needs and 'count' would drop.
            KpiUnit::Days, KpiUnit::Ratio => 'decimal',
        };
    }
}
