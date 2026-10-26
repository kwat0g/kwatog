<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\MRP\Models\Machine;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
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

    public function test_department_head_can_only_subscribe_to_purchase_requests_in_their_row_scope(): void
    {
        $ownDepartment = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $head = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => Employee::factory()->create(['department_id' => $ownDepartment->id])->id,
        ]);
        $requester = User::factory()->create();
        $own = PurchaseRequest::factory()->create([
            'department_id' => $ownDepartment->id,
            'requested_by' => $requester->id,
        ]);
        $foreign = PurchaseRequest::factory()->create([
            'department_id' => $otherDepartment->id,
            'requested_by' => $requester->id,
        ]);

        $this->authorizeChannel($head, "private-chain.purchase_request.{$own->hash_id}")->assertOk();
        $this->authorizeChannel($head, "private-chain.purchase_request.{$foreign->hash_id}")->assertForbidden();
    }

    public function test_department_head_cannot_subscribe_to_purchase_orders_outside_their_row_scope(): void
    {
        $ownDepartment = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $head = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => Employee::factory()->create(['department_id' => $ownDepartment->id])->id,
        ]);
        $requester = User::factory()->create();
        $ownRequest = PurchaseRequest::factory()->create([
            'department_id' => $ownDepartment->id,
            'requested_by' => $requester->id,
        ]);
        $foreignRequest = PurchaseRequest::factory()->create([
            'department_id' => $otherDepartment->id,
            'requested_by' => $requester->id,
        ]);
        $ownOrder = PurchaseOrder::factory()->create([
            'purchase_request_id' => $ownRequest->id,
            'created_by' => $requester->id,
        ]);
        $foreignOrder = PurchaseOrder::factory()->create([
            'purchase_request_id' => $foreignRequest->id,
            'created_by' => $requester->id,
        ]);

        $this->authorizeChannel($head, "private-chain.purchase_order.{$ownOrder->hash_id}")->assertOk();
        $this->authorizeChannel($head, "private-chain.purchase_order.{$foreignOrder->hash_id}")->assertForbidden();
    }

    public function test_subject_channels_and_unscoped_chain_channels_require_existing_records(): void
    {
        $adminRoleId = Role::query()->where('slug', 'system_admin')->value('id');
        $user = User::factory()->create(['role_id' => $adminRoleId]);
        $workOrder = WorkOrder::factory()->create();
        $machine = Machine::factory()->create();
        $period = PayrollPeriod::factory()->create();
        $missingId = app('hashids')->encode(999999999);

        foreach ([
            "private-production.wo.{$workOrder->hash_id}",
            "private-production.machine.{$machine->hash_id}",
            "private-payroll.period.{$period->hash_id}",
            "private-chain.work_order.{$workOrder->hash_id}",
        ] as $channel) {
            $this->authorizeChannel($user, $channel)->assertOk();
        }

        foreach ([
            "private-production.wo.{$missingId}",
            "private-production.machine.{$missingId}",
            "private-payroll.period.{$missingId}",
            "private-chain.work_order.{$missingId}",
        ] as $channel) {
            $this->authorizeChannel($user, $channel)->assertForbidden();
        }
    }

    private function authorizeChannel(User $user, string $channel): TestResponse
    {
        return $this->actingAs($user)
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => $channel,
            ]);
    }
}
