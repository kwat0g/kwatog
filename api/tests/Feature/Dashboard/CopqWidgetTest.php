<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\CopqSnapshot;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Task 5 — COPQ Dashboard Widget endpoint tests.
 *
 * Endpoint: GET /api/v1/dashboards/copq-widget?months=6
 */
class CopqWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    private function qcUser(): User
    {
        $role = Role::where('slug', 'qc_inspector')->firstOrFail();

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function employeeUser(): User
    {
        $role = Role::where('slug', 'employee')->firstOrFail();

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/dashboards/copq-widget')
            ->assertStatus(401);
    }

    public function test_copq_widget_requires_permission(): void
    {
        $user = $this->employeeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/copq-widget')
            ->assertStatus(403);
    }

    public function test_copq_widget_returns_current_and_trend(): void
    {
        $user = $this->qcUser();

        // Seed some historical snapshots
        CopqSnapshot::create([
            'period_year'              => now()->subMonths(2)->year,
            'period_month'             => now()->subMonths(2)->month,
            'prevention_cost'          => 0,
            'appraisal_cost'           => 0,
            'internal_scrap_cost'      => 1500.00,
            'internal_rework_cost'     => 800.00,
            'external_return_cost'     => 200.00,
            'external_complaint_cost'  => 100.00,
            'total_cost'               => 2600.00,
            'breakdown'                => [],
            'computed_at'              => now()->subMonths(2),
        ]);

        CopqSnapshot::create([
            'period_year'              => now()->subMonth()->year,
            'period_month'             => now()->subMonth()->month,
            'prevention_cost'          => 0,
            'appraisal_cost'           => 0,
            'internal_scrap_cost'      => 1200.00,
            'internal_rework_cost'     => 600.00,
            'external_return_cost'     => 150.00,
            'external_complaint_cost'  => 50.00,
            'total_cost'               => 2000.00,
            'breakdown'                => [],
            'computed_at'              => now()->subMonth(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/copq-widget')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'current' => [
                        'internal_failure' => ['scrap_units', 'rework_units', 'scrap_cost', 'rework_cost'],
                        'external_failure' => ['returns', 'complaints', 'return_cost'],
                        'total',
                        'period_label',
                    ],
                    'trend',
                    'period',
                ],
            ]);

        $data = $response->json('data');

        // Should have 2 trend items from the snapshots
        $this->assertCount(2, $data['trend']);
        $this->assertEquals(1500.00, $data['trend'][0]['scrap_cost']);
        $this->assertEquals(800.00, $data['trend'][0]['rework_cost']);
        $this->assertEquals(2600.00, $data['trend'][0]['total']);
    }

    public function test_copq_widget_limits_months(): void
    {
        $user = $this->qcUser();

        // Seed a snapshot 10 months ago — should be outside the default 6-month window
        CopqSnapshot::create([
            'period_year'              => now()->subMonths(10)->year,
            'period_month'             => now()->subMonths(10)->month,
            'prevention_cost'          => 0,
            'appraisal_cost'           => 0,
            'internal_scrap_cost'      => 999.00,
            'internal_rework_cost'     => 0,
            'external_return_cost'     => 0,
            'external_complaint_cost'  => 0,
            'total_cost'               => 999.00,
            'breakdown'                => [],
            'computed_at'              => now()->subMonths(10),
        ]);

        // Default months=6: should NOT include the 10-month-old snapshot
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/copq-widget')
            ->assertOk();

        $this->assertCount(0, $response->json('data.trend'));

        // With months=12: should include it
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/copq-widget?months=12')
            ->assertOk();

        $this->assertCount(1, $response->json('data.trend'));
        $this->assertEquals(999.00, $response->json('data.trend.0.scrap_cost'));
    }
}
