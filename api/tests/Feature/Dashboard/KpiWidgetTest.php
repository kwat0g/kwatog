<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Models\DashboardLayout;
use App\Modules\Dashboard\Models\KpiDefinition;
use App\Modules\Dashboard\Models\KpiSnapshot;
use App\Modules\Dashboard\Services\DashboardLayoutService;
use App\Modules\Dashboard\Services\DashboardWidgetDataService;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\KpiDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scorecard KPIs, as registry widgets.
 *
 * `kpi_definitions` + `kpi_snapshots` held targets, thresholds, directions and
 * up to 24 months of history, and none of it was addressable from the widget
 * registry — only from a KpiStrip hard-coded onto the seven bespoke dashboard
 * pages. The five roles that land on `/dashboard/default` could not see a
 * single KPI. These tests pin the two properties that make the fix safe:
 * the tile is gated by the SAME grant as the scorecard, and it degrades to a
 * scalar rather than an empty chart when nothing has been computed.
 */
class KpiWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(KpiDefinitionSeeder::class);
        $this->seed(DashboardWidgetSeeder::class);
    }

    private function actingAsRole(string $slug): User
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'email' => 'kpi+'.substr(uniqid(), -8).'@t.test',
        ]);
        $this->actingAs($user);

        return $user;
    }

    /** @param array<int, array{year:int,month:int,actual:float,previous:?float,status:string,target?:float}> $periods */
    private function snapshots(string $code, array $periods): KpiDefinition
    {
        $definition = KpiDefinition::query()->where('code', $code)->firstOrFail();

        foreach ($periods as $p) {
            KpiSnapshot::create([
                'definition_id' => $definition->id,
                'period_year' => $p['year'],
                'period_month' => $p['month'],
                'actual_value' => $p['actual'],
                'target_value' => $p['target'] ?? 85.0,
                'previous_value' => $p['previous'],
                'trend' => 'flat',
                'status' => $p['status'],
                'computed_at' => now(),
            ]);
        }

        return $definition;
    }

    private function putOnLayout(User $user, string $widgetKey): void
    {
        DashboardLayout::create([
            'owner_type' => DashboardLayout::OWNER_USER,
            'owner_id' => $user->id,
            'widget_key' => $widgetKey,
            'position_x' => 0,
            'position_y' => 0,
            'width' => 4,
            'height' => 4,
        ]);
    }

    public function test_every_kpi_definition_is_published_as_a_widget(): void
    {
        foreach (KpiDefinition::query()->pluck('code') as $code) {
            $this->assertDatabaseHas('dashboard_widgets', [
                'key' => DashboardWidgetSeeder::kpiWidgetKey($code),
                'render_kind' => 'trend',
            ]);
        }
    }

    /**
     * The whole point of deriving the gate from the permission: production_manager
     * holds `production.dashboard.view` and therefore reaches the OEE tile
     * without any code naming that role.
     */
    public function test_a_role_reaches_a_kpi_widget_through_its_permission(): void
    {
        $user = $this->actingAsRole('production_manager');

        $available = collect(app(DashboardLayoutService::class)->listAvailableWidgets($user))
            ->pluck('key');

        $this->assertTrue($available->contains('kpi.oee'));
        // Same user, a KPI whose module gate it does not hold.
        $this->assertFalse($available->contains('kpi.supplier_quality'));
    }

    public function test_a_role_without_the_scorecard_grant_cannot_pick_the_kpi(): void
    {
        $user = $this->actingAsRole('employee');

        $available = collect(app(DashboardLayoutService::class)->listAvailableWidgets($user))
            ->pluck('key');

        $this->assertFalse($available->contains('kpi.oee'));
        $this->assertFalse($available->contains('kpi.ar_aging_60d'));
    }

    /**
     * Permission, not role name: a brand-new role holding only the production
     * dashboard grant gets the OEE tile. Nothing in the seeder or the provider
     * mentions this slug.
     */
    public function test_a_new_role_gets_kpi_widgets_it_qualifies_for(): void
    {
        $role = Role::create(['name' => 'Line Analyst', 'slug' => 'line_analyst', 'is_system' => false]);
        $role->permissions()->sync([
            Permission::query()->where('slug', 'production.dashboard.view')->value('id'),
        ]);
        $user = $this->actingAsRole('line_analyst');

        $available = collect(app(DashboardLayoutService::class)->listAvailableWidgets($user))
            ->pluck('key');

        $this->assertTrue($available->contains('kpi.oee'));
        $this->assertTrue($available->contains('kpi.wo_completion_rate'));
        $this->assertFalse($available->contains('kpi.dppm'));
    }

    public function test_rich_payload_carries_target_and_status_with_chronological_points(): void
    {
        $this->snapshots('oee', [
            ['year' => 2026, 'month' => 6, 'actual' => 78.0, 'previous' => null, 'status' => 'warning'],
            ['year' => 2026, 'month' => 7, 'actual' => 82.5, 'previous' => 78.0, 'status' => 'warning'],
            ['year' => 2026, 'month' => 8, 'actual' => 88.0, 'previous' => 82.5, 'status' => 'on_target'],
        ]);
        $user = $this->actingAsRole('production_manager');
        $this->putOnLayout($user, 'kpi.oee');

        $row = collect($this->getJson('/api/v1/dashboard/layout?rich=1')->assertOk()->json('data'))
            ->firstWhere('key', 'kpi.oee');

        $this->assertNotNull($row, 'kpi.oee missing from the rich layout');
        $this->assertSame(['2026-06', '2026-07', '2026-08'], array_column($row['data']['points'], 'label'));
        // JSON has one number type, so 88.0 arrives as 88 — cast rather than
        // loosen the comparison, so a string "88" would still fail.
        $this->assertSame(88.0, (float) $row['data']['points'][2]['value']);
        $this->assertSame('on_target', $row['data']['status']);
        $this->assertSame(85.0, (float) $row['data']['target']);
        // (88.0 - 82.5) / 82.5 = +6.7%
        $this->assertSame(6.7, (float) $row['data']['delta']);
    }

    /**
     * A KPI that fell month-over-month reports a NEGATIVE delta even when lower
     * is better (DPPM). The SPA colours the delta by sign, so inverting it here
     * would paint a green "+" on a number that went down.
     */
    public function test_delta_is_signed_in_raw_terms_for_lower_is_better_kpis(): void
    {
        $this->snapshots('dppm', [
            ['year' => 2026, 'month' => 7, 'actual' => 800.0, 'previous' => null, 'status' => 'off_target', 'target' => 500.0],
            ['year' => 2026, 'month' => 8, 'actual' => 400.0, 'previous' => 800.0, 'status' => 'on_target', 'target' => 500.0],
        ]);
        $user = $this->actingAsRole('qc_inspector');
        $this->putOnLayout($user, 'kpi.dppm');

        $row = collect($this->getJson('/api/v1/dashboard/layout?rich=1')->assertOk()->json('data'))
            ->firstWhere('key', 'kpi.dppm');

        $this->assertSame(-50.0, (float) $row['data']['delta']);
    }

    /** No snapshots yet → no rich payload, so the tile shows the scalar. */
    public function test_an_uncomputed_kpi_degrades_to_the_scalar_path(): void
    {
        $user = $this->actingAsRole('production_manager');
        $this->putOnLayout($user, 'kpi.oee');

        $row = collect($this->getJson('/api/v1/dashboard/layout?rich=1')->assertOk()->json('data'))
            ->firstWhere('key', 'kpi.oee');

        $this->assertNull($row['data']);

        $summary = app(DashboardWidgetDataService::class)->summaries(['kpi.oee'], $user)['kpi.oee'];
        $this->assertTrue($summary['available']);
        $this->assertNull($summary['value']);
        $this->assertSame('Not computed for any period yet', $summary['helper']);
    }

    public function test_scalar_summary_reports_the_latest_period_target_and_status(): void
    {
        $this->snapshots('oee', [
            ['year' => 2026, 'month' => 7, 'actual' => 80.0, 'previous' => null, 'status' => 'warning'],
            ['year' => 2026, 'month' => 8, 'actual' => 90.25, 'previous' => 80.0, 'status' => 'on_target'],
        ]);
        $user = $this->actingAsRole('production_manager');

        $summary = app(DashboardWidgetDataService::class)->summaries(['kpi.oee'], $user)['kpi.oee'];

        $this->assertSame('90.25', $summary['value']);
        $this->assertSame('percent', $summary['kind']);
        $this->assertStringContainsString('2026-08', $summary['helper']);
        $this->assertStringContainsString('target 85.00', $summary['helper']);
        $this->assertStringContainsString('On Target', $summary['helper']);
    }

    /** Days and ratios keep two decimals; 'count' would round a 6.2 turnover to 6. */
    public function test_ratio_and_day_units_render_as_decimals(): void
    {
        $this->snapshots('inventory_turnover', [
            ['year' => 2026, 'month' => 8, 'actual' => 6.25, 'previous' => null, 'status' => 'on_target', 'target' => 6.0],
        ]);
        $user = $this->actingAsRole('warehouse_staff');

        $summary = app(DashboardWidgetDataService::class)->summaries(['kpi.inventory_turnover'], $user)['kpi.inventory_turnover'];

        $this->assertSame('6.25', $summary['value']);
        $this->assertSame('decimal', $summary['kind']);
    }

    /**
     * The tile's "Open →" comes from the widget row, not from a map in the SPA.
     * KPI tiles resolve to the scorecard, which carries the columns a tile can't.
     */
    public function test_layout_rows_carry_the_server_owned_link_path(): void
    {
        $user = $this->actingAsRole('production_manager');
        $this->putOnLayout($user, 'kpi.oee');

        $row = collect($this->getJson('/api/v1/dashboard/layout')->assertOk()->json('data'))
            ->firstWhere('key', 'kpi.oee');

        $this->assertSame('/dashboard/scorecard', $row['link_path']);
    }

    public function test_widget_picker_metadata_carries_link_path(): void
    {
        $this->actingAsRole('production_manager');

        $rows = collect($this->getJson('/api/v1/dashboard/widgets')->assertOk()->json('data'));

        $this->assertNotEmpty($rows);
        $this->assertTrue(
            $rows->every(fn (array $r) => array_key_exists('link_path', $r) && $r['link_path'] !== null),
            'a pickable widget arrived without a link_path',
        );
    }
}
