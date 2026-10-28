<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\MRP\Models\Machine;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\SupplyChain\Models\Delivery;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Database\Eloquent\Model;
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
        foreach (['accounting', 'crm', 'inventory', 'maintenance', 'mrp', 'payroll', 'production', 'purchasing', 'supply_chain'] as $feature) {
            app(SettingsService::class)->set("modules.{$feature}", true, 'modules');
        }
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

        $this->authorizeChain($head, $own, 'purchase_request')->assertOk();
        $this->authorizeChain($head, $foreign, 'purchase_request')->assertForbidden();
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

        $this->authorizeChain($head, $ownOrder)->assertOk();
        $this->authorizeChain($head, $foreignOrder)->assertForbidden();
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

    public function test_payroll_progress_channel_matches_period_detail_access(): void
    {
        $user = $this->userWithPermissions('payroll.periods.view');
        $period = PayrollPeriod::factory()->create();

        $this->authorizeChannel($user, "private-payroll.period.{$period->hash_id}")->assertOk();
        $this->authorizeChannel($this->userWithPermissions(), "private-payroll.period.{$period->hash_id}")
            ->assertForbidden();

        app(SettingsService::class)->set('modules.payroll', false, 'modules');
        $this->authorizeChannel($user, "private-payroll.period.{$period->hash_id}")->assertForbidden();

        app(SettingsService::class)->set('modules.payroll', true, 'modules');
        $missingHash = app('hashids')->encode(999999999);
        $this->authorizeChannel($user, "private-payroll.period.{$missingHash}")->assertForbidden();
    }

    public function test_production_dashboard_channel_matches_dashboard_route_access(): void
    {
        $user = $this->userWithPermissions('production.view', 'production.dashboard.view');
        $dashboardPermissionOnly = $this->userWithPermissions('production.dashboard.view');

        $this->authorizeChannel($user, 'private-production.dashboard')->assertOk();
        $this->authorizeChannel($dashboardPermissionOnly, 'private-production.dashboard')->assertForbidden();

        app(SettingsService::class)->set('modules.production', false, 'modules');
        $this->authorizeChannel($user, 'private-production.dashboard')->assertForbidden();
    }

    public function test_production_work_order_channel_matches_detail_route_and_record(): void
    {
        $user = $this->userWithPermissions('production.view', 'production.work_orders.view');
        $workOrderOnly = $this->userWithPermissions('production.work_orders.view');
        $workOrder = WorkOrder::factory()->create();

        $this->authorizeChannel($user, "private-production.wo.{$workOrder->hash_id}")->assertOk();
        $this->authorizeChannel($workOrderOnly, "private-production.wo.{$workOrder->hash_id}")->assertForbidden();

        $missingHash = app('hashids')->encode(999999999);
        $this->authorizeChannel($user, "private-production.wo.{$missingHash}")->assertForbidden();
    }

    public function test_production_machine_channel_matches_machine_detail_route(): void
    {
        $user = $this->userWithPermissions('mrp.machines.view');
        $machine = Machine::factory()->create();

        $this->authorizeChannel($user, "private-production.machine.{$machine->hash_id}")->assertOk();

        app(SettingsService::class)->set('modules.mrp', false, 'modules');
        $this->authorizeChannel($user, "private-production.machine.{$machine->hash_id}")->assertForbidden();

        app(SettingsService::class)->set('modules.mrp', true, 'modules');
        $missingHash = app('hashids')->encode(999999999);
        $this->authorizeChannel($user, "private-production.machine.{$missingHash}")->assertForbidden();
    }

    public function test_maintenance_dashboard_channel_requires_enabled_module_and_read_permission(): void
    {
        $user = $this->userWithPermissions('maintenance.view');

        $this->authorizeChannel($user, 'private-maintenance.dashboard')->assertOk();
        $this->authorizeChannel($this->userWithPermissions(), 'private-maintenance.dashboard')->assertForbidden();

        app(SettingsService::class)->set('modules.maintenance', false, 'modules');
        $this->authorizeChannel($user, 'private-maintenance.dashboard')->assertForbidden();
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

    public function test_purchase_request_chain_channel_uses_the_record_visibility_scope(): void
    {
        $owner = $this->userWithPermissions('purchasing.view');
        $own = PurchaseRequest::factory()->create(['requested_by' => $owner->id]);
        $foreign = PurchaseRequest::factory()->create();

        $this->authorizeChain($owner, $own, 'purchase_request')->assertOk();
        $this->authorizeChain($owner, $foreign, 'purchase_request')->assertForbidden();
    }

    public function test_work_order_chain_channel_requires_both_detail_route_permissions(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $routeUser = $this->userWithPermissions('production.view', 'production.work_orders.view');
        $modulePermissionOnly = $this->userWithPermissions('production.work_orders.view');

        $this->authorizeChain($routeUser, $workOrder, 'work_order')->assertOk();
        $this->authorizeChain($modulePermissionOnly, $workOrder, 'work_order')->assertForbidden();
    }

    public function test_delivery_chain_channel_accepts_the_narrow_detail_route_permission(): void
    {
        $user = $this->userWithPermissions('supply_chain.deliveries.view');
        $salesOrder = SalesOrder::factory()->create();
        $delivery = Delivery::create([
            'delivery_number' => 'DEL-CHAIN-'.uniqid(),
            'sales_order_id' => $salesOrder->id,
            'status' => 'scheduled',
            'scheduled_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        $this->authorizeChain($user, $delivery, 'delivery')->assertOk();
    }

    public function test_supported_chain_channels_match_detail_permissions_and_require_live_records(): void
    {
        $cases = [
            ['sales_order', fn (User $user): Model => SalesOrder::factory()->create(['created_by' => $user->id]), ['crm.sales_orders.view']],
            ['work_order', fn (): Model => WorkOrder::factory()->create(), ['production.view', 'production.work_orders.view']],
            ['purchase_request', fn (User $user): Model => PurchaseRequest::factory()->create(['requested_by' => $user->id]), ['purchasing.view']],
            ['purchase_order', fn (User $user): Model => PurchaseOrder::factory()->create(['created_by' => $user->id]), ['purchasing.view']],
            ['delivery', function (User $user): Model {
                return Delivery::create([
                    'delivery_number' => 'DEL-CHAIN-'.uniqid(),
                    'sales_order_id' => SalesOrder::factory()->create()->id,
                    'status' => 'scheduled',
                    'scheduled_date' => now()->toDateString(),
                    'created_by' => $user->id,
                ]);
            }, ['supply_chain.view']],
            ['grn', fn (): Model => GoodsReceiptNote::factory()->create(), ['inventory.view']],
            ['bill', fn (): Model => Bill::factory()->create(), ['accounting.bills.view']],
            ['invoice', fn (): Model => Invoice::factory()->create(), ['accounting.invoices.view']],
        ];

        foreach ($cases as [$type, $makeRecord, $permissions]) {
            $authorized = $this->userWithPermissions(...$permissions);
            $unauthorized = $this->userWithPermissions();
            $record = $makeRecord($authorized);

            $this->authorizeChain($authorized, $record, $type)->assertOk();
            $this->authorizeChain($unauthorized, $record, $type)->assertForbidden();
        }
    }

    public function test_chain_channel_denies_missing_records_and_disabled_modules(): void
    {
        $user = $this->userWithPermissions('crm.sales_orders.view');
        $salesOrder = SalesOrder::factory()->create();

        $this->authorizeChain($user, $salesOrder, 'sales_order')->assertOk();
        app(SettingsService::class)->set('modules.crm', false, 'modules');
        $this->authorizeChain($user, $salesOrder, 'sales_order')->assertForbidden();

        app(SettingsService::class)->set('modules.crm', true, 'modules');
        $missingHash = app('hashids')->encode(999999999);
        $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-chain.sales_order.{$missingHash}",
        ])->assertForbidden();
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

    private function authorizeChain(User $user, Model $record, string $type = 'purchase_order'): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-chain.{$type}.{$record->hash_id}",
        ]);
    }
}
