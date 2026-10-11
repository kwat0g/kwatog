<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Enums\RenderKind;
use App\Modules\Dashboard\Models\DashboardWidget;
use App\Modules\Dashboard\Models\KpiDefinition;
use App\Modules\Dashboard\Services\Analytics\ApprovalsWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\AssetWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\AttendanceWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\BudgetWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\CapacityWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\CashFlowWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\CoreWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\CrmWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\KpiWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\LoanWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\ReturnWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\SupplyChainWidgetAnalytics;
use App\Modules\Dashboard\Services\DashboardLayoutService;
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
            app(CashFlowWidgetAnalytics::class),
            app(CapacityWidgetAnalytics::class),
            app(AttendanceWidgetAnalytics::class),
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
        // A widget's gate is EITHER one slug or any-of a set, so a leak check
        // that only reads `permission` would score every `kpi.*` tile as
        // ungated and quietly stop guarding them.
        $anyByKey = DashboardWidget::query()
            ->get()
            ->mapWithKeys(fn (DashboardWidget $w): array => [$w->key => $w->permission_any ?? []]);
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
                if (! $permissionByKey->has($key)) {
                    $leaks[] = "{$role->slug} → {$key} (no such widget)";
                    continue;
                }

                $anyOf = $anyByKey[$key] ?? [];

                if ($anyOf !== []) {
                    if (array_intersect($anyOf, $held) === []) {
                        $leaks[] = "{$role->slug} → {$key} (needs any of ".implode(', ', $anyOf).')';
                    }

                    continue;
                }

                $needed = $permissionByKey[$key];
                if ($needed !== null && ! in_array($needed, $held, true)) {
                    $leaks[] = "{$role->slug} → {$key} (needs {$needed})";
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

    /** Every link must be an in-app absolute path, not an external URL. KPI tiles carry an in-dashboard `#kpi-{code}` anchor. */
    public function test_link_paths_are_in_app_absolute_paths(): void
    {
        foreach (DashboardWidget::query()->pluck('link_path', 'key') as $key => $path) {
            $this->assertMatchesRegularExpression('~^/[a-z0-9\-/]*(#[a-z0-9\-_]+)?$~', (string) $path, "widget {$key} has a non-app link");
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
     * A KPI tile must be gated by exactly the grant set its own scorecard
     * enforces (KpiSnapshotService::getScorecard → ::userCanSeeModule). A looser
     * gate here would publish a KPI on a dashboard that the page it links to
     * then refuses to show.
     */
    public function test_kpi_widgets_reuse_the_scorecard_permission(): void
    {
        foreach (DashboardWidgetSeeder::kpiCatalog() as $kpi) {
            $widget = DashboardWidget::query()
                ->where('key', DashboardWidgetSeeder::kpiWidgetKey($kpi['code']))
                ->firstOrFail();

            $this->assertSame(
                KpiSnapshotService::moduleGates($kpi['module']),
                $widget->permission_any,
                "kpi widget {$widget->key} does not match the scorecard boundary",
            );
            // The set IS the gate. A single slug left alongside it would be a
            // second rule that can disagree with the scorecard.
            $this->assertNull($widget->permission);
        }
    }

    /**
     * A gate naming a slug no role can hold hides its widget from every viewer
     * forever: the row is seeded, the SPA registry knows it, the picker offers
     * it, and it never renders. Silent, so it needs an assertion rather than a
     * reader noticing.
     */
    public function test_every_widget_gate_slug_exists_in_the_permission_catalog(): void
    {
        $catalog = DB::table('permissions')->pluck('slug')->all();
        $missing = [];

        foreach (DashboardWidget::query()->get() as $widget) {
            $anyOf = $widget->permission_any ?? [];
            $slugs = $anyOf !== []
                ? $anyOf
                : ($widget->permission === null ? [] : [$widget->permission]);

            foreach ($slugs as $slug) {
                if (! in_array($slug, $catalog, true)) {
                    $missing[] = "{$widget->key} → {$slug}";
                }
            }
        }

        $this->assertSame([], $missing, "widget gates naming unknown permissions:\n".implode("\n", $missing));
    }

    /**
     * A widget is gated by who may read its DATA — not by a page grant or a
     * self-service slug that happened to be nearby (01-matrix §D2 G-P2-1/2/4).
     *
     * Three shape errors are pinned here, each fixed in
     * 2026_10_11_110000_regate_dashboard_widgets_on_dataset_reads:
     *
     *  - production tiles on `production.dashboard.view`, a PAGE grant held by
     *    production_manager and the admin wildcard, so the role that owns the
     *    production schedule had its own tiles stripped from its dashboard;
     *  - a finance projection and a production projection on `forecasting.view`,
     *    held by six roles including purchasing_officer and warehouse_staff —
     *    buyers rendering finance's cash forecast;
     *  - the org-wide attendance/leave projection on `attendance.view`, a
     *    self-service slug every role holds.
     */
    public function test_widget_gates_follow_the_dataset_read(): void
    {
        $expected = [
            'production.kpi' => 'production',
            'machine.utilization' => 'production',
            'oee.gauges' => 'production',
            'forecast.cash_flow' => 'accounting',
            'forecast.capacity' => 'production',
            'forecast.attendance' => 'attendance',
            'forecast.revenue' => 'accounting',
        ];

        foreach ($expected as $key => $module) {
            $widget = DashboardWidget::query()->where('key', $key)->firstOrFail();

            $this->assertSame(
                KpiSnapshotService::moduleGates($module),
                $widget->permission_any,
                "widget {$key} is gated by the wrong dataset read",
            );
            // The set IS the gate. A single slug left alongside it would be a
            // second rule that can disagree with the tiles over the same data.
            $this->assertNull($widget->permission, "widget {$key} carries a second gate");
        }
    }

    /**
     * …and the gates produce that audience when the real path reads them.
     *
     * The column check above would still pass if `canSeeWidget` ignored
     * `permission`, so the audience is asserted through
     * `listAvailableWidgets` — the method the picker and the layout endpoint
     * both use. Both directions matter: the schedule owner must GAIN its
     * production tiles, and the buyer and the floor must LOSE the finance one.
     */
    public function test_regated_widgets_reach_the_intended_audience(): void
    {
        $service = app(DashboardLayoutService::class);

        $visible = fn (string $role): array => array_column(
            $service->listAvailableWidgets($this->userWithRole($role)),
            'key',
        );

        $ppc = $visible('ppc_head');
        foreach (['production.kpi', 'machine.utilization', 'oee.gauges', 'forecast.capacity'] as $key) {
            $this->assertContains($key, $ppc, "ppc_head cannot see {$key}");
        }

        foreach (['purchasing_officer', 'warehouse_staff'] as $role) {
            foreach (['forecast.cash_flow', 'forecast.capacity', 'production.kpi', 'oee.gauges'] as $key) {
                $this->assertNotContains($key, $visible($role), "{$role} can see {$key}");
            }
        }

        // The company-wide reader holds ONE slug — `dashboard.company.view` —
        // and it is what makes their landing page work. A gate written as a
        // single module slug would pass every assertion above and leave the
        // executive's finance family drawing empty, which is the silent half of
        // this defect.
        $executive = $visible('vice_president');
        foreach (['forecast.cash_flow', 'forecast.capacity', 'forecast.revenue', 'production.kpi', 'oee.gauges'] as $key) {
            $this->assertContains($key, $executive, "vice_president cannot see {$key}");
        }

        $this->assertContains('forecast.cash_flow', $visible('finance_officer'));

        $this->assertContains('forecast.attendance', $visible('hr_officer'));
        $this->assertContains('forecast.attendance', $visible('vice_president'));
        $this->assertNotContains('forecast.attendance', $visible('driver'));
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'email' => 'widget+'.substr(uniqid(), -8).'@t.test',
        ]);
    }
}
