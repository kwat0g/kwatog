<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OvertimeListRealtimeChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.attendance', true, 'modules');
        require base_path('routes/channels.php');
    }

    public function test_overtime_list_channel_matches_the_list_endpoint_permissions(): void
    {
        $approver = $this->userWithPermissions('Overtime list approver', ['attendance.ot.approve']);
        $reader = $this->userWithPermissions('Overtime list reader', ['attendance.view']);
        $unrelated = $this->userWithPermissions('Unrelated list viewer', ['hr.employees.view']);

        $this->authorizeChannel($approver, 'private-entity.overtime_request.visible')->assertOk();
        $this->authorizeChannel($reader, 'private-entity.overtime_request.visible')->assertOk();
        $this->authorizeChannel($unrelated, 'private-entity.overtime_request.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.attendance', false, 'modules');
        $this->authorizeChannel($approver, 'private-entity.overtime_request.visible')->assertForbidden();
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(string $name, array $permissions): User
    {
        $role = Role::create(['name' => $name, 'slug' => str($name)->slug('_')->value()]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id'));

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function authorizeChannel(User $user, string $channel)
    {
        return $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }
}
