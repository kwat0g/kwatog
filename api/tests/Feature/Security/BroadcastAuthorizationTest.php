<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BroadcastAuthorizationTest extends TestCase
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
        require base_path('routes/channels.php');
    }

    public function test_user_can_authorize_their_hash_id_private_channel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-user.{$user->hash_id}",
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_user_cannot_authorize_another_users_private_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-user.{$other->hash_id}",
            ])
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_authorize_a_private_channel(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-user.{$user->hash_id}",
        ])->assertUnauthorized();
    }

    public function test_user_without_notification_permission_cannot_authorize_their_channel(): void
    {
        $user = User::factory()->create();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => DB::table('permissions')->where('slug', 'notifications.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $user->id,
            'reason' => 'Private notification channel test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->flushPermissionsCache();

        $this->actingAs($user)
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-user.{$user->hash_id}",
            ])
            ->assertForbidden();
    }
}
