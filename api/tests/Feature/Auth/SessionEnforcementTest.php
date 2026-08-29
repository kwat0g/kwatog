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

    /**
     * M001 re-audit — the idle timeout used to be skipped whenever an
     * Authorization header was present, so a cookie-authenticated internal user
     * could opt out of it indefinitely by sending `Authorization: Bearer
     * <anything>`. Measured before the fix: 25 min idle on a 15 min policy
     * returned 200 with the header and 401 without it. The skip must key off the
     * resolved principal, never off a header the client controls.
     */
    public function test_idle_timeout_cannot_be_bypassed_with_a_client_supplied_bearer_header(): void
    {
        $this->user->forceFill(['last_activity' => now()->subMinutes(31)])->save();

        $this->actingAs($this->user)
            ->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/v1/alerts')
            ->assertStatus(401)
            ->assertJsonPath('code', 'session_timeout');
    }

    /**
     * Regression lock (passes before and after the fix): narrowing the skip must
     * not start rejecting an internal session that is still inside its window
     * merely because an Authorization header rode along.
     */
    public function test_active_internal_session_is_still_allowed_when_a_bearer_header_is_present(): void
    {
        $this->user->forceFill(['last_activity' => now()->subMinutes(2)])->save();

        $this->actingAs($this->user)
            ->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/v1/alerts')
            ->assertOk();
    }

    /**
     * Regression lock (passes before and after the fix): with no internal cookie
     * session the web guard resolves nothing, so this middleware must leave the
     * request to `auth:sanctum` rather than claiming it as an expired internal
     * session. Distinguished by the absence of a `code` key — SessionTimeout
     * answers with `session_timeout` and the guard-type check with
     * `guard_mismatch`, while the Sanctum guard's own 401 carries neither.
     */
    public function test_bearer_only_request_without_an_internal_session_is_left_to_the_sanctum_guard(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/v1/alerts')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonMissingPath('code');
    }
}
