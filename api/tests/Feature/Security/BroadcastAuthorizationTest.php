<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
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

    public function test_purchase_order_chain_channel_uses_the_record_visibility_scope(): void
    {
        $user = $this->userWithPermissions('purchasing.view');
        $own = PurchaseOrder::factory()->create(['created_by' => $user->id]);
        $foreign = PurchaseOrder::factory()->create(['created_by' => User::factory()->create()->id]);

        $this->authorizeChain($user, $own)->assertOk();
        $this->authorizeChain($user, $foreign)->assertForbidden();
    }

    public function test_purchase_order_approver_can_authorize_the_detail_channel_without_list_permission(): void
    {
        $approver = $this->userWithPermissions('purchasing.po.approve');
        $purchaseOrder = PurchaseOrder::factory()->create();

        $this->authorizeChain($approver, $purchaseOrder)->assertOk();
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $role = Role::create([
            'name' => 'Broadcast test '.substr(uniqid(), -5),
            'slug' => 'broadcast_test_'.substr(uniqid(), -5),
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', $permissions)->pluck('id')->all(),
        );

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function authorizeChain(User $user, PurchaseOrder $purchaseOrder): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-chain.purchase_order.{$purchaseOrder->hash_id}",
        ]);
    }
}
