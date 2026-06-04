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
        $this->assertArrayHasKey('chain_stages', $result['panels']);
        $this->assertArrayHasKey('module_activity', $result['panels']);
        $this->assertArrayHasKey('user_activity', $result['panels']);
        $this->assertArrayHasKey('pending_approvals', $result['panels']);
        $this->assertArrayHasKey('recent_audit', $result['panels']);
    }

    public function test_module_activity_has_all_six_modules(): void
    {
        $user = User::factory()->create();
        $svc  = app(AdminDashboardService::class);

        $result  = $svc->admin($user);
        $modules = array_column($result['panels']['module_activity'], 'key');

        $this->assertContains('hr', $modules);
        $this->assertContains('payroll', $modules);
        $this->assertContains('inventory', $modules);
        $this->assertContains('purchasing', $modules);
        $this->assertContains('production', $modules);
        $this->assertContains('quality', $modules);
    }

    public function test_user_activity_has_login_trend(): void
    {
        $user   = User::factory()->create();
        $svc    = app(AdminDashboardService::class);
        $result = $svc->admin($user);

        $ua = $result['panels']['user_activity'];
        $this->assertArrayHasKey('recent_logins', $ua);
        $this->assertArrayHasKey('login_trend_7d', $ua);
        $this->assertCount(7, $ua['login_trend_7d']);
    }
}
