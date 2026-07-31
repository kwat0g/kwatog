<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\KpiSnapshotService;
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
            $mock->shouldReceive('computeAll')->once()->with(2025, 12);
        });

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dashboard/kpi/compute')
            ->assertOk()
            ->assertJsonPath('message', 'KPIs computed for 2025-12');
    }
}
