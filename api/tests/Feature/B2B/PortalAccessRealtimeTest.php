<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalAccessRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'portal-access-test-key',
            'broadcasting.connections.reverb.secret' => 'portal-access-test-secret',
            'broadcasting.connections.reverb.app_id' => 'portal-access-test-app',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        $this->seed(RolePermissionSeeder::class);
        require base_path('routes/channels.php');
        app(SettingsService::class)->set('modules.crm', true, 'modules');
        app(SettingsService::class)->set('modules.b2b_portals', true, 'modules');
        app(SettingsService::class)->set('modules.supply_chain', true, 'modules');
    }

    public function test_portal_access_viewer_can_subscribe_to_the_admin_refresh_channel(): void
    {
        $role = Role::query()->where('slug', 'system_admin')->firstOrFail();
        $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        self::assertTrue($user->is_active);
        self::assertTrue($user->hasPermission('b2b.portal_access.view'));
        self::assertTrue(app(SettingsService::class)->requiredBool('modules.b2b_portals'));

        foreach ([
            'private-entity.b2b_portal_access.visible',
            'private-entity.delivery_schedule.visible',
            'private-entity.shipment.visible',
            'private-entity.vehicle.visible',
        ] as $channel) {
            $response = $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => $channel,
            ]);
            self::assertSame(200, $response->status(), "{$channel}: {$response->getContent()}");
            $response->assertJsonStructure(['auth']);
        }
    }

    public function test_user_without_portal_access_view_permission_cannot_subscribe(): void
    {
        $role = Role::query()->create([
            'name' => 'Unrelated portal channel viewer',
            'slug' => 'unrelated_portal_channel_'.substr(uniqid(), -8),
            'is_system' => false,
        ]);
        $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        foreach ([
            'private-entity.b2b_portal_access.visible',
            'private-entity.delivery_schedule.visible',
            'private-entity.shipment.visible',
            'private-entity.vehicle.visible',
        ] as $channel) {
            $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => $channel,
            ])->assertForbidden();
        }
    }
}
