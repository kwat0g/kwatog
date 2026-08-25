<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\KpiSnapshotService;
use App\Modules\Dashboard\Models\KpiSnapshot;
use Carbon\Carbon;
use Database\Seeders\KpiDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class KpiScorecardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, KpiDefinitionSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_role_filtered_scorecard_is_always_a_json_list(): void
    {
        $role = Role::where('slug', 'ppc_head')->firstOrFail();
        $user = User::factory()->create(['role_id' => $role->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpi/scorecard?year=2026&month=6')
            ->assertOk();

        $items = $response->json('data');
        $this->assertIsArray($items);
        $this->assertTrue(array_is_list($items), 'Role-filtered KPI data must encode as a JSON array.');
        $this->assertSame('on_time_delivery', $items[0]['definition']['code']);
    }

    public function test_compute_defaults_to_previous_calendar_month_across_year_boundary(): void
    {
        Carbon::setTestNow('2026-01-31 12:00:00');
        $role = Role::where('slug', 'system_admin')->firstOrFail();
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->mock(KpiSnapshotService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('computeAll')->once()->with(2025, 12)->andReturn([
                'computed' => 0,
                'no_data' => 0,
                'failed' => [],
            ]);
        });

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dashboard/kpi/compute')
            ->assertOk()
            ->assertJsonPath('message', 'KPIs computed for 2025-12');
    }

    public function test_batch_trends_returns_requested_kpis_in_one_payload(): void
    {
        $definition = \App\Modules\Dashboard\Models\KpiDefinition::query()
            ->where('code', 'oee')->firstOrFail();
        KpiSnapshot::query()->create([
            'definition_id' => $definition->id,
            'period_year' => 2026,
            'period_month' => 6,
            'actual_value' => '82.5000',
            'target_value' => '85.0000',
            'trend' => 'flat',
            'status' => 'warning',
            'computed_at' => now(),
        ]);

        $user = User::factory()->create(['role_id' => Role::where('slug', 'system_admin')->firstOrFail()->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/kpi/trends?codes=oee&months=6')
            ->assertOk()
            ->assertJsonPath('data.oee.0.period', '2026-06')
            ->assertJsonPath('data.oee.0.value', '82.5000');
    }
}
