<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Common\Services\OutboxEventCodec;
use App\Modules\Auth\Events\UserInviteChanged;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\UserInvite;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserInviteRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');
    }

    public function test_issuing_an_invite_stages_a_content_free_directory_refresh(): void
    {
        $admin = $this->admin();
        $employee = $this->employee();
        $role = Role::query()->where('slug', 'employee')->firstOrFail();

        $this->actingAs($admin)->postJson('/api/v1/auth/invites', [
            'employee_id' => $employee->hash_id,
            'role_id' => $role->hash_id,
            'email' => 'invite-refresh@example.test',
        ])->assertCreated();

        $this->assertInviteRefresh('created', (string) $admin->hash_id);
    }

    public function test_accepting_an_invite_stages_a_content_free_directory_refresh(): void
    {
        $admin = $this->admin();
        $employee = $this->employee('accept-refresh@example.test');
        $role = Role::query()->where('slug', 'employee')->firstOrFail();
        $invite = $this->invite($admin, $employee, $role, 'accept-refresh@example.test');

        $response = $this->postJson('/api/v1/auth/invites/accept', [
            'token' => $invite->token,
            'name' => 'Invite Acceptance User',
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertCreated();

        $this->assertInviteRefresh('accepted', (string) $response->json('data.id'));
    }

    public function test_revoking_an_invite_stages_a_content_free_directory_refresh(): void
    {
        $admin = $this->admin();
        $employee = $this->employee('revoke-refresh@example.test');
        $role = Role::query()->where('slug', 'employee')->firstOrFail();
        $invite = $this->invite($admin, $employee, $role, 'revoke-refresh@example.test');

        $this->actingAs($admin)
            ->deleteJson('/api/v1/auth/invites/'.$invite->hash_id)
            ->assertNoContent();

        $this->assertInviteRefresh('revoked', (string) $admin->hash_id);
    }

    public function test_only_active_invite_managers_can_subscribe_to_the_directory_refresh(): void
    {
        $admin = $this->admin();
        $employeeRole = Role::query()->where('slug', 'employee')->firstOrFail();
        $employee = User::factory()->create(['role_id' => $employeeRole->id, 'is_active' => true]);
        $inactiveAdmin = $this->admin();
        $inactiveAdmin->forceFill(['is_active' => false])->save();
        $channel = 'private-entity.user_invite.visible';

        $this->authorizeInviteChannel($admin, $channel)->assertOk();
        $this->authorizeInviteChannel($employee, $channel)->assertForbidden();
        $this->authorizeInviteChannel($inactiveAdmin, $channel)->assertUnauthorized();
    }

    private function assertInviteRefresh(string $action, string $actorHashId): void
    {
        $row = DB::table('event_outbox')
            ->where('event_type', UserInviteChanged::class)
            ->sole();
        $event = app(OutboxEventCodec::class)->decode(
            UserInviteChanged::class,
            json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR),
        );
        $broadcastPayload = $event->broadcastWith();

        $this->assertSame('private-entity.user_invite.visible', $event->broadcastOn()->name);
        $this->assertSame('auth.user-invite.changed', $event->broadcastAs());
        $this->assertSame($action, $broadcastPayload['action']);
        $this->assertSame('user_invite', $broadcastPayload['entity_type']);
        $this->assertSame($actorHashId, $broadcastPayload['actor_id']);
        $this->assertTrue($broadcastPayload['refresh']);
        $this->assertNotEmpty($broadcastPayload['event_id']);
        $this->assertNotEmpty($broadcastPayload['version']);
        $this->assertNotEmpty($broadcastPayload['timestamp']);
        $this->assertArrayNotHasKey('email', $broadcastPayload);
        $this->assertArrayNotHasKey('token', $broadcastPayload);
        $this->assertArrayNotHasKey('employee_id', $broadcastPayload);
        $this->assertArrayNotHasKey('invite_id', $broadcastPayload);
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Invite Refresh Admin',
            'email' => 'invite-admin-'.uniqid().'@example.test',
            'password' => bcrypt('Password1!'),
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
    }

    private function employee(string $email = 'invite-refresh@example.test'): Employee
    {
        $code = strtoupper(substr(uniqid('T', true), 0, 16));
        $department = Department::query()->create(['name' => 'Invite '.$code, 'code' => $code]);
        $position = Position::query()->create([
            'title' => 'Invite Test',
            'department_id' => $department->id,
            'salary_grade' => null,
            'default_role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        return Employee::factory()->create([
            'employee_no' => 'INV-'.uniqid(),
            'first_name' => 'Invite',
            'last_name' => 'Refresh',
            'birth_date' => '1995-01-15',
            'gender' => 'female',
            'civil_status' => 'single',
            'email' => $email,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employment_type' => 'regular',
            'pay_type' => 'monthly',
            'date_hired' => '2026-01-01',
            'basic_monthly_salary' => 30000,
            'status' => 'active',
        ]);
    }

    private function invite(User $admin, Employee $employee, Role $role, string $email): UserInvite
    {
        return UserInvite::query()->create([
            'employee_id' => $employee->id,
            'email' => $email,
            'token' => bin2hex(random_bytes(32)),
            'role_id' => $role->id,
            'expires_at' => now()->addHours(72),
            'invited_by' => $admin->id,
        ]);
    }

    private function authorizeInviteChannel(User $user, string $channel): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }
}
