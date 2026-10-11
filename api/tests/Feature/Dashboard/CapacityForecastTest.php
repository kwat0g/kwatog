<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\Analytics\CapacityWidgetAnalytics;
use App\Modules\Dashboard\Services\DashboardWidgetDataService;
use App\Modules\Dashboard\Services\ForecastingDashboardService;
use App\Modules\Dashboard\Services\PlantManagerDashboardService;
use App\Modules\Dashboard\Services\PpcDashboardService;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Task 7 — capacity forecast on the ppc + plant-manager dashboards, read-only.
 *
 * Plant-level aggregate engine: open work-order load (planned hours prorated
 * by month overlap) ÷ machine capacity (available hours × days in month,
 * OEE-derated). Any month over the breach threshold flags the whole horizon.
 * No machines degrades to the empty shape, never a divide-by-zero. Both
 * payloads gate the panel on `forecasting.view` (PanelGate strips it
 * server-side, never CSS-hidden).
 */
class CapacityForecastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'email' => 'cpcy+'.substr(uniqid(), -8).'@t.test',
        ]);
    }

    public function test_no_machines_return_the_empty_shape_without_dividing_by_zero(): void
    {
        $result = app(ForecastingDashboardService::class)->capacityForecast();

        $this->assertSame([], $result['months']);
        $this->assertSame([], $result['forecast']);
        $this->assertNull($result['confidence']);
        $this->assertSame('stable', $result['trend']);
        $this->assertFalse($result['breach']);
    }

    public function test_overloaded_fixture_breaches_on_month_two_only(): void
    {
        $horizon = app(SettingsService::class)->requiredInt('forecasting.default_horizon_months', 1, 12);
        $this->assertGreaterThanOrEqual(2, $horizon);

        Machine::factory()->create(['available_hours_per_day' => 16.0]);

        // One open order filling month 2 around the clock: 24h/day of planned
        // work against 16h/day of plant capacity derated by OEE 1.0 (no
        // snapshots seeded) → 150% utilisation, over the breach threshold.
        $m2 = now()->addMonthsNoOverflow(2);
        WorkOrder::factory()->create([
            'status' => 'confirmed',
            'machine_id' => null,
            'planned_start' => $m2->copy()->startOfMonth()->toDateTimeString(),
            'planned_end' => $m2->copy()->endOfMonth()->toDateTimeString(),
        ]);

        $result = app(ForecastingDashboardService::class)->capacityForecast();

        $this->assertCount($horizon, $result['months']);
        foreach ($result['months'] as $month) {
            $this->assertArrayHasKey('load_hours', $month);
            $this->assertArrayHasKey('capacity_hours', $month);
            $this->assertArrayHasKey('util_pct', $month);
            $this->assertArrayHasKey('confidence', $month);
            $this->assertGreaterThan(0, $month['capacity_hours']);
        }
        $this->assertSame(0.0, $result['months'][0]['util_pct']);
        $this->assertGreaterThan(90.0, $result['months'][1]['util_pct']);
        $this->assertTrue($result['breach']);
        $this->assertContains($result['trend'], ['up', 'down', 'stable']);
    }

    public function test_ppc_payload_strips_capacity_without_the_forecasting_grant(): void
    {
        $role = Role::create([
            'name' => 'CP Role '.substr(uniqid(), -6),
            'slug' => 'cpcy-'.substr(uniqid(), -6),
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            \App\Modules\Auth\Models\Permission::query()
                ->whereIn('slug', ['dashboard.ppc.view'])->pluck('id')->all(),
        );
        $user = User::factory()->create([
            'role_id' => $role->id,
            'email' => 'cpcy+'.substr(uniqid(), -8).'@t.test',
        ]);

        $panels = app(PpcDashboardService::class)->ppc($user)['panels'];

        $this->assertArrayNotHasKey('capacity_forecast', $panels);
        // Ungated panels are untouched.
        $this->assertArrayHasKey('gantt_horizon_days', $panels);
    }

    public function test_ppc_and_plant_manager_carry_capacity_with_the_grant_and_widget_paths_agree(): void
    {
        $ppcPanels = app(PpcDashboardService::class)->ppc($this->userWithRole('ppc_head'))['panels'];
        $this->assertArrayHasKey('capacity_forecast', $ppcPanels);
        $this->assertArrayHasKey('months', $ppcPanels['capacity_forecast']);
        $this->assertArrayHasKey('breach', $ppcPanels['capacity_forecast']);

        $plantPanels = app(PlantManagerDashboardService::class)
            ->plantManager($this->userWithRole('production_manager'))['panels'];
        $this->assertArrayHasKey('capacity_forecast', $plantPanels);

        $scalar = app(DashboardWidgetDataService::class)
            ->summaries(['forecast.capacity'], $this->userWithRole('ppc_head'));

        $this->assertSame('percent', $scalar['forecast.capacity']['kind']);

        $rich = app(CapacityWidgetAnalytics::class)
            ->payload('forecast.capacity', $this->userWithRole('ppc_head'));

        // No machines: the rich path degrades to the scalar, never an axis
        // with no points behind it.
        $this->assertSame([], $rich);
    }
}
