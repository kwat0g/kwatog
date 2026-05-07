<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Models\DashboardLayout;
use App\Modules\Dashboard\Services\DashboardLayoutService;
use Database\Seeders\DashboardRoleLayoutSeeder;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Series R — Task R4.
 *
 * Covers: role default rendering, first-login clone idempotency,
 * widget permission filtering, and reset-to-default.
 */
class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DashboardWidgetSeeder::class);
        $this->seed(DashboardRoleLayoutSeeder::class);
    }

    public function test_layout_endpoint_returns_role_default_when_user_has_none(): void
    {
        $user = $this->seedUserWithRole('hr_officer');

        $resp = $this->actingAs($user)
            ->getJson('/api/v1/dashboard/layout')
            ->assertOk();

        $this->assertNotEmpty($resp->json('data'));
        $this->assertSame('role', $resp->json('data.0.source'));
    }

    public function test_clone_role_default_to_user_is_idempotent(): void
    {
        $user = $this->seedUserWithRole('hr_officer');
        $service = app(DashboardLayoutService::class);

        $service->cloneRoleDefaultToUser($user);
        $count1 = DashboardLayout::query()
            ->where('owner_type', DashboardLayout::OWNER_USER)
            ->where('owner_id', $user->id)
            ->count();

        // Second call must not duplicate.
        $service->cloneRoleDefaultToUser($user);
        $count2 = DashboardLayout::query()
            ->where('owner_type', DashboardLayout::OWNER_USER)
            ->where('owner_id', $user->id)
            ->count();

        $this->assertSame($count1, $count2);
        $this->assertGreaterThan(0, $count1);
    }

    public function test_user_layout_overrides_role_default(): void
    {
        $user = $this->seedUserWithRole('hr_officer');
        $service = app(DashboardLayoutService::class);

        $service->saveUserLayout($user, [
            ['key' => 'approvals.pending', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 4],
        ]);

        $effective = $service->getEffectiveLayout($user);
        $this->assertCount(1, $effective);
        $this->assertSame('approvals.pending', $effective[0]['key']);
        $this->assertSame('user', $effective[0]['source']);
    }

    public function test_reset_endpoint_restores_role_default(): void
    {
        $user = $this->seedUserWithRole('hr_officer');
        $service = app(DashboardLayoutService::class);

        $service->saveUserLayout($user, [
            ['key' => 'approvals.pending', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 4],
        ]);
        $this->assertSame('user', $service->getEffectiveLayout($user)[0]['source']);

        $this->actingAs($user)
            ->postJson('/api/v1/dashboard/layout/reset')
            ->assertOk();

        $effective = $service->getEffectiveLayout($user);
        $this->assertNotEmpty($effective);
        $this->assertSame('role', $effective[0]['source']);
    }

    public function test_widgets_endpoint_strips_widgets_user_lacks_permission_for(): void
    {
        // Plain employee role: must NOT see finance.cash_position (requires accounting.dashboard.view).
        $user = $this->seedUserWithRole('employee');

        $resp = $this->actingAs($user)
            ->getJson('/api/v1/dashboard/widgets')
            ->assertOk();

        $keys = collect($resp->json('data'))->pluck('key')->all();
        $this->assertNotContains('finance.cash_position', $keys);
        // Self-service widgets have no permission requirement and should be present.
        $this->assertContains('self.payslip_summary', $keys);
    }

    public function test_save_layout_strips_unknown_widget_keys(): void
    {
        $user = $this->seedUserWithRole('hr_officer');

        $this->actingAs($user)
            ->putJson('/api/v1/dashboard/layout', [
                'widgets' => [
                    ['key' => 'approvals.pending', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 4],
                    ['key' => 'this.does.not.exist', 'x' => 0, 'y' => 1, 'w' => 12, 'h' => 4],
                ],
            ])
            ->assertOk();

        $rows = DashboardLayout::query()
            ->where('owner_type', DashboardLayout::OWNER_USER)
            ->where('owner_id', $user->id)
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame('approvals.pending', $rows->first()->widget_key);
    }

    private function seedUserWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
            'email'   => $slug.'+'.uniqid().'@t.test',
        ]);
    }
}
