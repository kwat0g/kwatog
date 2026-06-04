<?php
declare(strict_types=1);
namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Services\AdminDashboardService;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_returns_expected_shape(): void
    {
        $user = User::factory()->create();
        $svc  = app(AdminDashboardService::class);

        $result = $svc->admin($user);

        $this->assertArrayHasKey('kpis', $result);
        $this->assertArrayHasKey('panels', $result);
        $this->assertCount(4, $result['kpis']);
        $this->assertArrayHasKey('active_sessions',  $result['panels']);
        $this->assertArrayHasKey('account_security', $result['panels']);
        $this->assertArrayHasKey('auth_events',      $result['panels']);
        $this->assertArrayHasKey('queue_health',     $result['panels']);
        $this->assertArrayHasKey('recent_audit',     $result['panels']);
        $this->assertArrayHasKey('open_alerts',      $result['panels']);
    }

    public function test_account_security_has_expected_keys(): void
    {
        $user = User::factory()->create();
        $svc  = app(AdminDashboardService::class);

        $result = $svc->admin($user);
        $sec    = $result['panels']['account_security'];

        $this->assertArrayHasKey('total',                $sec);
        $this->assertArrayHasKey('active',               $sec);
        $this->assertArrayHasKey('inactive',             $sec);
        $this->assertArrayHasKey('locked',               $sec);
        $this->assertArrayHasKey('at_risk',              $sec);
        $this->assertArrayHasKey('must_change_password', $sec);
        $this->assertArrayHasKey('locked_accounts',      $sec);
    }

    public function test_auth_events_has_24h_trend_with_24_entries(): void
    {
        $user   = User::factory()->create();
        $svc    = app(AdminDashboardService::class);
        $result = $svc->admin($user);

        $auth = $result['panels']['auth_events'];
        $this->assertArrayHasKey('breakdown_24h',     $auth);
        $this->assertArrayHasKey('success_trend_24h', $auth);
        $this->assertArrayHasKey('recent_failures',   $auth);
        $this->assertCount(24, $auth['success_trend_24h']);
    }

    public function test_admin_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/dashboards/admin')->assertStatus(401);
    }

    public function test_admin_endpoint_returns_200_for_authenticated_user(): void
    {
        $user = User::factory()->withRole('system_admin')->create();
        $this->actingAs($user)
            ->getJson('/api/v1/dashboards/admin')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['kpis', 'panels']]);
    }
}
