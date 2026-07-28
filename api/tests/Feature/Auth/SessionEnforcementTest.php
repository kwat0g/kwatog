<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('security.session_timeout_default', 30, 'security');

        $this->user = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'is_active' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    public function test_idle_timeout_is_enforced_on_internal_route_that_did_not_declare_it(): void
    {
        $this->user->forceFill(['last_activity' => now()->subMinutes(31)])->save();

        $this->actingAs($this->user)
            ->getJson('/api/v1/alerts')
            ->assertStatus(401)
            ->assertJsonPath('code', 'session_timeout');
    }

    public function test_active_session_can_use_internal_route_and_refreshes_old_activity_stamp(): void
    {
        $old = now()->subMinutes(2);
        $this->user->forceFill(['last_activity' => $old])->save();

        $this->actingAs($this->user)
            ->getJson('/api/v1/alerts')
            ->assertOk();

        $this->assertTrue($this->user->fresh()->last_activity->gt($old));
    }

    public function test_password_change_requirement_does_not_trap_user_out_of_logout(): void
    {
        $this->user->forceFill([
            'must_change_password' => true,
            'last_activity' => now(),
        ])->save();

        $this->withSession(['_token' => 'logout-test-token'])
            ->withHeaders([
                'Origin' => 'http://localhost',
                'X-CSRF-TOKEN' => 'logout-test-token',
            ])
            ->actingAs($this->user)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();
    }
}
