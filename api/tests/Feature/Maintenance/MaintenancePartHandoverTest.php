<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Maintenance\Models\MaintenanceLog;
use App\Modules\Maintenance\Models\MaintenancePartRequest;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaintenancePartHandoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Event::fake();
    }

    private function fixture(): array
    {
        $department = Department::factory()->create(['code' => 'MAINT', 'is_active' => true]);
        $employee = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);
        $technician = User::factory()->create(['role_id' => Role::where('slug', 'employee')->value('id'), 'employee_id' => $employee->id]);
        $warehouse = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $supervisor = User::factory()->create(['role_id' => Role::where('slug', 'maintenance_supervisor')->value('id')]);
        $order = MaintenanceWorkOrder::create(['mwo_number' => 'MWO-HANDOVER-'.Str::random(6), 'maintainable_type' => 'machine',
            'maintainable_id' => Machine::factory()->create(['status' => 'maintenance'])->id, 'type' => 'corrective', 'priority' => 'low',
            'description' => 'Replace screw', 'status' => 'in_progress', 'assigned_to' => $employee->id, 'created_by' => $supervisor->id,
            'verification_required' => false, 'started_at' => now(), 'pre_maintenance_status' => 'idle']);
        $log = MaintenanceLog::create(['work_order_id' => $order->id, 'finding' => 'Loose screw', 'action_taken' => 'Replacement needed',
            'result' => 'in_progress', 'description' => 'Inspect screw', 'logged_by' => $technician->id]);
        $item = Item::factory()->create(['item_type' => 'spare_part', 'unit_of_measure' => 'PCS']);
        $location = WarehouseLocation::factory()->create();
        $stock = StockLevel::factory()->create(['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 5,
            'reserved_quantity' => 0, 'weighted_avg_cost' => 12]);
        return [$technician, $warehouse, $supervisor, $order, $log, $item, $location, $stock];
    }

    private function request(array $fixture): array
    {
        [$technician, , , $order, $log, $item, $location] = $fixture;
        $payload = ['operation_key' => (string) Str::uuid(), 'item_id' => $item->hash_id, 'location_id' => $location->hash_id,
            'quantity' => '2', 'work_record_id' => $log->hash_id];
        $response = $this->actingAs($technician)->postJson('/api/v1/maintenance/work-orders/'.$order->hash_id.'/spare-parts', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'requested');
        return [$response->json('data.id'), $payload];
    }

    public function test_request_then_warehouse_handover_posts_once_and_unblocks_repair(): void
    {
        $fixture = $this->fixture();
        [$technician, $warehouse, $supervisor, $order, , , , $stock] = $fixture;
        [$requestId, $payload] = $this->request($fixture);
        $url = '/api/v1/maintenance/work-orders/'.$order->hash_id;
        $this->assertSame('5.000', $stock->fresh()->quantity);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->postJson($url.'/spare-parts', $payload)->assertCreated()->assertJsonPath('data.id', $requestId);
        $this->postJson($url.'/work-records', ['finding' => 'Screw', 'action_taken' => 'Inspect only', 'result' => 'in_progress'])->assertCreated();
        $this->postJson($url.'/work-records', ['finding' => 'Screw', 'action_taken' => 'Installed', 'result' => 'passed'])
            ->assertUnprocessable()->assertJsonValidationErrors('parts_handover');
        $this->postJson($url.'/reconcile-parts', ['confirm_consumed' => true])->assertUnprocessable()->assertJsonValidationErrors('parts_handover');
        $this->actingAs($supervisor)->patchJson($url.'/complete')->assertUnprocessable()->assertJsonValidationErrors('parts_handover');
        $this->actingAs($warehouse)->getJson('/api/v1/inventory/material-handovers?status=pending')
            ->assertOk()->assertJsonPath('data.0.label', $order->mwo_number)->assertJsonPath('data.0.maintenance_work_order_id', $order->hash_id);
        $this->getJson('/api/v1/inventory/material-handovers/sources?maintenance_work_order_id='.$order->hash_id)
            ->assertOk()->assertJsonPath('data.sources.0.request_id', $requestId)->assertJsonPath('data.default_recipient_id', $technician->employee->hash_id);
        $handoverUrl = '/api/v1/inventory/maintenance-part-requests/'.$requestId.'/handover';
        $confirm = ['received_by_employee_id' => $technician->employee->hash_id, 'expected_quantity' => '2.000'];
        $this->actingAs($technician)->postJson($handoverUrl, $confirm)->assertForbidden();
        $this->actingAs($warehouse)->postJson($handoverUrl, $confirm)->assertOk();
        $this->postJson($handoverUrl, $confirm)->assertOk();
        $this->assertSame('3.000', $stock->fresh()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('material_handovers', 1);
        $partsHandedOver = json_decode((string) DB::table('notifications')->where('notifiable_id', $technician->id)
            ->where('type', 'maintenance.parts_handed_over')->value('data'), true);
        $this->assertSame(MaintenanceWorkOrder::class, $partsHandedOver['subject_type']);
        $this->assertSame((string) $order->id, $partsHandedOver['subject_id']);
        $this->actingAs($technician)->getJson($url)->assertOk()->assertJsonPath('data.parts_handover.pending', 0)
            ->assertJsonPath('data.part_requests.0.status', 'handed_over')->assertJsonPath('data.cost', '24.00');
        $this->postJson($url.'/work-records', ['finding' => 'Screw', 'action_taken' => 'Installed', 'result' => 'passed'])->assertCreated();
        $this->postJson($url.'/reconcile-parts', ['confirm_consumed' => true])->assertOk();
        $this->actingAs($supervisor)->patchJson($url.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_withdrawal_and_stock_conflicts_never_create_false_handover(): void
    {
        $fixture = $this->fixture();
        [$technician, $warehouse, , $order, , , , $stock] = $fixture;
        [$requestId] = $this->request($fixture);
        $url = '/api/v1/inventory/maintenance-part-requests/'.$requestId.'/handover';
        $other = Employee::factory()->create();
        $this->actingAs($warehouse)->postJson($url, ['received_by_employee_id' => $other->hash_id, 'expected_quantity' => '2'])->assertUnprocessable();
        $stock->update(['quantity' => 1]);
        $this->postJson($url, ['received_by_employee_id' => $technician->employee->hash_id, 'expected_quantity' => '2'])->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('material_handovers', 0);
        $withdraw = '/api/v1/maintenance/work-orders/'.$order->hash_id.'/part-requests/'.$requestId.'/withdraw';
        $this->actingAs($technician)->postJson($withdraw)->assertOk();
        $this->postJson($withdraw)->assertOk();
        $this->actingAs($warehouse)->postJson($url, ['received_by_employee_id' => $technician->employee->hash_id, 'expected_quantity' => '2'])->assertConflict();
        $this->actingAs($technician)->getJson('/api/v1/maintenance/work-orders/'.$order->hash_id)->assertOk()->assertJsonPath('data.parts_handover.pending', 0);
    }

    public function test_existing_maintenance_issue_enters_queue_without_duplicate_stock(): void
    {
        [$technician, $warehouse, , $order, $log, $item, $location] = $this->fixture();
        $movement = StockMovement::create(['item_id' => $item->id, 'from_location_id' => $location->id, 'movement_type' => 'material_issue',
            'quantity' => 2, 'unit_cost' => 12, 'total_cost' => 24, 'reference_type' => 'maintenance_work_order', 'reference_id' => $order->id, 'created_at' => now()]);
        \App\Modules\Maintenance\Models\SparePartUsage::create(['work_order_id' => $order->id, 'work_log_id' => $log->id,
            'item_id' => $item->id, 'quantity' => 2, 'unit_cost' => 12, 'total_cost' => 24, 'stock_movement_id' => $movement->id]);
        $this->actingAs($warehouse)->getJson('/api/v1/inventory/material-handovers?status=pending')->assertOk()->assertJsonPath('data.0.label', $order->mwo_number);
        $this->postJson('/api/v1/inventory/stock-movements/'.$movement->hash_id.'/handover',
            ['received_by_employee_id' => $technician->employee->hash_id, 'expected_quantity' => '2'])->assertOk();
        $this->assertDatabaseCount('stock_movements', 1);
        $order->update(['status' => 'completed']);
        \App\Modules\Inventory\Models\MaterialHandover::query()->delete();
        $this->getJson('/api/v1/inventory/material-handovers?status=needs_review')->assertOk()->assertJsonPath('data.0.status', 'needs_review');
        $this->postJson('/api/v1/inventory/stock-movements/'.$movement->hash_id.'/handover',
            ['received_by_employee_id' => $technician->employee->hash_id, 'expected_quantity' => '2'])->assertConflict();
    }

    public function test_part_request_notice_excludes_issue_creators_who_cannot_open_picking(): void
    {
        [$technician, $warehouse, , $order, $log, $item, $location] = $this->fixture();
        $role = Role::create(['name' => 'Issue Creator Without Picking', 'slug' => 'issue_creator_no_picking', 'description' => 'Test']);
        $permission = \App\Modules\Auth\Models\Permission::firstOrCreate(
            ['slug' => 'inventory.issue.create'],
            ['name' => 'Create Issue', 'module' => 'inventory'],
        );
        $role->permissions()->sync([$permission->id]);
        $restricted = User::factory()->create(['role_id' => $role->id]);

        $this->request([$technician, $warehouse, null, $order, $log, $item, $location]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $warehouse->id,
            'type' => 'maintenance.parts_requested',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $restricted->id,
            'type' => 'maintenance.parts_requested',
        ]);
        $notice = json_decode((string) DB::table('notifications')->where('notifiable_id', $warehouse->id)
            ->where('type', 'maintenance.parts_requested')->value('data'), true);
        $this->assertSame(MaintenancePartRequest::class, $notice['subject_type']);
        $this->assertSame((string) MaintenancePartRequest::query()->firstOrFail()->id, $notice['subject_id']);
        $this->assertSame('/inventory/picking?maintenance_work_order_id='.$order->hash_id, $notice['link_to']);
    }
}
