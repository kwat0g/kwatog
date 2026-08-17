<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Dashboard\Enums\RenderKind;
use App\Modules\Dashboard\Models\DashboardWidget;
use App\Modules\Dashboard\Models\KpiDefinition;
use App\Modules\Dashboard\Services\Analytics\ApprovalsWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\AssetWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\BudgetWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\CoreWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\CrmWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\KpiWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\LoanWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\ReturnWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\SupplyChainWidgetAnalytics;
use App\Modules\Dashboard\Services\KpiSnapshotService;
use Database\Seeders\DashboardRoleLayoutSeeder;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\KpiDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WidgetSeedIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DashboardWidgetSeeder::class);
    }

    /** Every key any provider claims to serve. */
    private function handledKeys(): Collection
    {
        return collect([
            app(CoreWidgetAnalytics::class),
            app(CrmWidgetAnalytics::class),
            app(AssetWidgetAnalytics::class),
            app(SupplyChainWidgetAnalytics::class),
            app(ReturnWidgetAnalytics::class),
            app(BudgetWidgetAnalytics::class),
            app(LoanWidgetAnalytics::class),
            app(KpiWidgetAnalytics::class),
            app(ApprovalsWidgetAnalytics::class),
        ])->flatMap(fn ($provider) => $provider->handles())->unique();
    }

    /**
     * A widget declaring a rich kind with no provider renders an empty tile —
     * worse than the scalar it replaced. The two lists must agree.
     */
    public function test_every_rich_widget_has_a_provider(): void
    {
        $rich = DashboardWidget::query()
            ->where('render_kind', '!=', RenderKind::Scalar->value)
            ->pluck('key');

        $orphans = $rich->diff($this->handledKeys())->values()->all();

        $this->assertSame([], $orphans, 'rich widgets with no analytics provider: '.implode(', ', $orphans));
    }

    /** Conversely, a provider for a key nobody seeds is dead code. */
    public function test_no_provider_handles_an_unseeded_key(): void
    {
        $orphans = $this->handledKeys()
            ->diff(DashboardWidget::query()->pluck('key'))
            ->values()
            ->all();

        $this->assertSame([], $orphans, 'providers serving unseeded keys: '.implode(', ', $orphans));
    }

    /**
     * Every provider-backed key must actually be seeded rich. A provider that
     * returns a breakdown for a widget still marked `scalar` never runs.
     */
    public function test_every_handled_key_is_seeded_rich(): void
    {
        $stillScalar = DashboardWidget::query()
            ->whereIn('key', $this->handledKeys()->all())
            ->where('render_kind', RenderKind::Scalar->value)
            ->pluck('key')
            ->values()
            ->all();

        $this->assertSame([], $stillScalar, 'handled but still scalar: '.implode(', ', $stillScalar));
    }

    /**
     * Widths must be a real 12-column layout, not the uniform `12` every row
     * carried while the SPA ignored the column entirely.
     */
    public function test_role_layouts_use_varied_widths(): void
    {
        $this->seed(DashboardRoleLayoutSeeder::class);

        $widths = DB::table('dashboard_layouts')
            ->where('owner_type', 'role')
            ->distinct()
            ->pluck('width');

        $this->assertGreaterThan(1, $widths->count(), 'every role row is still full-width');
        foreach ($widths as $width) {
            $this->assertContains((int) $width, [4, 6, 8, 12]);
        }
    }

    /** No role's row may overflow the 12-column grid. */
    public function test_no_role_row_exceeds_twelve_columns(): void
    {
        $this->seed(DashboardRoleLayoutSeeder::class);

        $overflows = DB::table('dashboard_layouts')
            ->where('owner_type', 'role')
            ->selectRaw('owner_id, position_y, SUM(width) AS total')
            ->groupBy('owner_id', 'position_y')
            ->havingRaw('SUM(width) > 12')
            ->get();

        $this->assertCount(0, $overflows, 'rows wider than the 12-column grid');
    }

    /**
     * A role default must only contain widgets that role actually qualifies for.
     *
     * The render-time strip (DashboardLayoutService::hydrateVisibleLayout) makes
     * a leaky default safe but not harmless: the widget silently vanishes, and
     * the role's dashboard quietly shrinks by one tile with nothing saying why.
     */
    public function test_no_role_default_contains_a_widget_that_role_cannot_see(): void
    {
        $this->seed(DashboardRoleLayoutSeeder::class);

        $permissionByKey = DashboardWidget::query()->pluck('permission', 'key');
        $leaks = [];

        foreach (Role::query()->get() as $role) {
            if ($role->slug === 'system_admin') {
                continue; // wildcard holder; nothing to leak
            }

            $held = $role->permissions()->pluck('permissions.slug')->all();
            $keys = DB::table('dashboard_layouts')
                ->where('owner_type', 'role')
                ->where('owner_id', $role->id)
                ->pluck('widget_key');

            foreach ($keys as $key) {
                $needed = $permissionByKey[$key] ?? null;
                if ($needed !== null && ! in_array($needed, $held, true)) {
                    $leaks[] = "{$role->slug} → {$key} (needs {$needed})";
                }
                if (! $permissionByKey->has($key)) {
                    $leaks[] = "{$role->slug} → {$key} (no such widget)";
                }
            }
        }

        $this->assertSame([], $leaks, "role defaults referencing invisible widgets:\n".implode("\n", $leaks));
    }

    /**
     * A widget with no `link_path` renders a tile with no way out of it. That
     * was the failure mode of the SPA-side WIDGET_LINKS map: nothing bound it
     * to this table, so a key added to the seeder silently lost its "Open →".
     */
    public function test_every_widget_declares_where_open_goes(): void
    {
        $unlinked = DashboardWidget::query()
            ->whereNull('link_path')
            ->orderBy('key')
            ->pluck('key')
            ->all();

        $this->assertSame([], $unlinked, 'widgets with no link_path: '.implode(', ', $unlinked));
    }

    /** Every link must be an in-app absolute path, not an external URL. */
    public function test_link_paths_are_in_app_absolute_paths(): void
    {
        foreach (DashboardWidget::query()->pluck('link_path', 'key') as $key => $path) {
            $this->assertMatchesRegularExpression('#^/[a-z0-9\-/]*$#', (string) $path, "widget {$key} has a non-app link");
        }
    }

    /**
     * The KPI widget list is hand-written (seeder order must not decide whether
     * KPI widgets exist), so it can drift from `kpi_definitions`. A KPI with no
     * widget is invisible to the five roles that land on the generic dashboard;
     * a widget with no KPI renders an empty tile forever.
     */
    public function test_kpi_widgets_and_kpi_definitions_agree(): void
    {
        $this->seed(KpiDefinitionSeeder::class);

        $defined = KpiDefinition::query()->pluck('code')->sort()->values()->all();
        $published = collect(DashboardWidgetSeeder::kpiCatalog())
            ->pluck('code')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($defined, $published, 'kpi_definitions and DashboardWidgetSeeder::kpiCatalog disagree');
    }

    /**
     * A KPI tile must be gated by exactly the grant its own scorecard enforces
     * (KpiSnapshotService::getScorecard → ::userCanSeeModule). A looser
     * permission here would publish a KPI on a dashboard that the page it links
     * to then refuses to show.
     */
    public function test_kpi_widgets_reuse_the_scorecard_permission(): void
    {
        foreach (DashboardWidgetSeeder::kpiCatalog() as $kpi) {
            $widget = DashboardWidget::query()
                ->where('key', DashboardWidgetSeeder::kpiWidgetKey($kpi['code']))
                ->firstOrFail();

            $this->assertSame(
                KpiSnapshotService::MODULE_PERMISSIONS[$kpi['module']] ?? null,
                $widget->permission,
                "kpi widget {$widget->key} does not match the scorecard boundary",
            );
        }
    }
}
