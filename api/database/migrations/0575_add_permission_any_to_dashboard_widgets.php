<?php

declare(strict_types=1);

use App\Modules\Dashboard\Services\KpiSnapshotService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guarding a widget with ANY of several grants (DSS 01-matrix §D4 #3, G-P3-2).
 *
 * `permission` holds one slug, which was enough until a single dataset needed
 * more than one qualifying audience. The `kpi.*` tiles are the case that broke
 * it: the module's own dashboard holder must see them, and so must a
 * company-wide reader — and the scorecard endpoint those tiles mirror already
 * answers to a slug SET (`KpiSnapshotService::MODULE_PERMISSIONS`). Gating the
 * tile on one slug while the scorecard accepted several would let a tile appear
 * on a dashboard while the page it links to omits the same KPI.
 *
 * `permission_any` carries the set; `permission` stays for every single-grant
 * widget, and a populated `permission_any` takes precedence — the ONE rule
 * lives in `DashboardLayoutService::canSeeWidget()`. Nullable with no default,
 * so "no set" is distinguishable from "an empty set".
 *
 * Numbered name on purpose: this adds a column to a table created by an earlier
 * `0NNN_` migration and depends on nothing a `2026_*` migration introduces, so
 * the migrator's `0NNN_`-sorts-first rule cannot put it before a dependency.
 *
 * No `hasColumn` guard — an added column that silently does not exist is
 * indistinguishable from a healthy `migrate:fresh` (the failure mode CLAUDE.md
 * records three times), so a missing dependency must throw here instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table): void {
            $table->jsonb('permission_any')->nullable()->after('permission');
        });

        // Backfill, so applying migrations alone leaves the registry CORRECT.
        //
        // Without this, migrating a deployed database and not re-running
        // DashboardWidgetSeeder would leave every `kpi.*` row with
        // `permission = null` and `permission_any = null` — and a null pair
        // means UNGATED to DashboardLayoutService, so every role would gain
        // every KPI tile. A seeder re-run is not a step a migration may assume
        // (this project has shipped grants that existed only in the seeder, see
        // 2026_10_10_000500).
        //
        // `permission_any` is replaced wholesale, and `permission` cleared, so
        // the row carries one gate rather than two that can disagree. Rows are
        // matched by their `kpi.` key prefix, the same rule the seeder and
        // KpiWidgetAnalytics use. On a fresh database there are no rows yet and
        // this is a no-op; the seeder writes the same values afterwards.
        DB::table('dashboard_widgets')
            ->where('key', 'like', 'kpi.%')
            ->orderBy('id')
            ->get(['id', 'module'])
            ->each(function (object $widget): void {
                $gates = KpiSnapshotService::moduleGates((string) $widget->module);

                if ($gates === null) {
                    return;
                }

                DB::table('dashboard_widgets')->where('id', $widget->id)->update([
                    'permission' => null,
                    'permission_any' => json_encode($gates),
                ]);
            });
    }

    public function down(): void
    {
        // Restore the single-slug gate before dropping the column, or a
        // rollback leaves each `kpi.*` row ungated (see up()).
        DB::table('dashboard_widgets')
            ->where('key', 'like', 'kpi.%')
            ->orderBy('id')
            ->get(['id', 'module'])
            ->each(function (object $widget): void {
                $gates = KpiSnapshotService::moduleGates((string) $widget->module);

                DB::table('dashboard_widgets')->where('id', $widget->id)->update([
                    'permission' => $gates[0] ?? null,
                ]);
            });

        Schema::table('dashboard_widgets', function (Blueprint $table): void {
            $table->dropColumn('permission_any');
        });
    }
};
