<?php

declare(strict_types=1);

use App\Modules\Dashboard\Services\KpiSnapshotService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-gate six dashboard widgets on the DATASET read rather than on a page grant
 * or a self-service slug (DSS 01-matrix §D2 G-P2-1 / G-P2-2 / G-P2-4).
 *
 * Three defects, one cause: a widget's gate was chosen by which permission
 * happened to be nearby, not by who may read the data behind it.
 *
 *  - `production.kpi`, `machine.utilization`, `oee.gauges` were gated
 *    `production.dashboard.view` — a PAGE grant ("may open
 *    /production/dashboard") held by the plant manager and the admin wildcard
 *    only. The role that owns the production schedule, `ppc_head`, therefore had
 *    its own production widgets stripped from its dashboard (G-P2-2).
 *
 *  - `forecast.cash_flow` (a FINANCE projection) and `forecast.capacity` (a
 *    production one) were gated `forecasting.view`, held by six roles including
 *    purchasing_officer and warehouse_staff — buyers rendering finance's cash
 *    projection (G-P2-4).
 *
 *  - `forecast.attendance` was gated `attendance.view`, a self-service slug all
 *    17 roles hold, while the payload is an ORG-WIDE attendance/leave pipeline
 *    projection (G-P2-1's one real instance).
 *
 * The gate written here is the module's READ SET — the same
 * `KpiSnapshotService::moduleGates()` the `kpi.*` tiles use — rather than a
 * single dataset slug. Sourcing it from the service instead of spelling the
 * slugs out means this migration cannot disagree with the tiles, and it is what
 * keeps the executive reader (whose entire authority is
 * `dashboard.company.view`) on the company page built for them: a one-slug gate
 * is correct for the module's own holder and silently empty for them.
 *
 * A migration as well as a seeder edit, because `DashboardWidgetSeeder` only
 * reaches a database that re-runs seeders, and this project has already shipped
 * a grant that existed only in the seeder (2026_10_10_000500).
 *
 * Timestamp-named on purpose: `dashboard_widgets` rows are also written by
 * `2026_*` migrations (2026_10_02_000002, 2026_10_10_000500), and every `0NNN_`
 * file sorts before every `2026_*` file — so a numbered name would run first and
 * be overwritten by whoever wrote the row later.
 */
return new class extends Migration
{
    /**
     * key => [module whose read gates it, the gate this migration replaces].
     *
     * Both directions are explicit so `down()` restores exactly the previous
     * authority rather than guessing it.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const REGATES = [
        'production.kpi' => ['production', 'production.dashboard.view'],
        'machine.utilization' => ['production', 'production.dashboard.view'],
        'oee.gauges' => ['production', 'production.dashboard.view'],
        'forecast.cash_flow' => ['accounting', 'forecasting.view'],
        'forecast.capacity' => ['production', 'forecasting.view'],
        'forecast.attendance' => ['attendance', 'attendance.view'],
        // `forecast.revenue` was the last one still on a PAGE grant rather than
        // the dataset read: it is the revenue projection, and it was gated on
        // `accounting.dashboard.view` ("may open /dashboard/finance"), held by
        // finance_officer and the admin wildcard. Its sibling `forecast.cash_flow`
        // is above for exactly this reason, so leaving revenue behind would have
        // made "a projection is gated by the dataset's read" true of one finance
        // forecast and false of the other. §B puts it in the company page's
        // Demand-vs-supply family.
        'forecast.revenue' => ['accounting', 'accounting.dashboard.view'],
    ];

    public function up(): void
    {
        foreach (self::REGATES as $key => [$module, $before]) {
            $this->regate(
                $key,
                from: ['permission' => $before, 'permission_any' => null],
                to: ['permission' => null, 'permission_any' => KpiSnapshotService::moduleGates($module)],
            );
        }
    }

    public function down(): void
    {
        foreach (self::REGATES as $key => [$module, $before]) {
            $this->regate(
                $key,
                from: ['permission' => null, 'permission_any' => KpiSnapshotService::moduleGates($module)],
                to: ['permission' => $before, 'permission_any' => null],
            );
        }
    }

    /**
     * Moves one row, scoped by the state it is expected to be in.
     *
     * The `from` predicate is not decoration: if a gate has been changed by
     * something else since, this migration must not overwrite that decision.
     * A missing row — a widget this database never seeded — simply updates
     * nothing.
     *
     * @param  array{permission: ?string, permission_any: ?array}  $from
     * @param  array{permission: ?string, permission_any: ?array}  $to
     */
    private function regate(string $key, array $from, array $to): void
    {
        $query = DB::table('dashboard_widgets')->where('key', $key);

        $from['permission'] === null
            ? $query->whereNull('permission')
            : $query->where('permission', $from['permission']);

        $from['permission_any'] === null
            ? $query->whereNull('permission_any')
            : $query->whereJsonContains('permission_any', $from['permission_any']);

        $query->update([
            'permission' => $to['permission'],
            'permission_any' => $to['permission_any'] === null ? null : json_encode($to['permission_any']),
        ]);
    }
};
