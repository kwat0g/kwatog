<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Enums\MaterialIssueStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialIssueSlipItem;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Maintenance\Models\MaintenanceLog;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaterialHandoverIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Event::fake();
    }

    public function test_bare_index_lists_production_and_maintenance_groups(): void
    {
        $warehouse = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $item = Item::factory()->create(['item_type' => 'spare_part', 'unit_of_measure' => 'PCS']);
        $location = WarehouseLocation::factory()->create();
        StockLevel::factory()->create(['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 5,
            'reserved_quantity' => 0, 'weighted_avg_cost' => 12]);
        $slip = MaterialIssueSlip::create(['slip_number' => 'MIS-IDX-'.Str::random(6), 'work_order_id' => null,
            'issued_date' => now()->toDateString(), 'issued_by' => $warehouse->id, 'created_by' => $warehouse->id,
            'status' => MaterialIssueStatus::Issued, 'total_value' => '10.00']);
        $movement = StockMovement::create(['item_id' => $item->id, 'from_location_id' => $location->id, 'to_location_id' => null,
            'movement_type' => StockMovementType::MaterialIssue, 'quantity' => '1.000', 'unit_cost' => '10.0000',
            'total_cost' => '10.00', 'reference_type' => 'material_issue_slip', 'reference_id' => $slip->id,
            'lot_number' => 'IDX-LOT', 'created_by' => $warehouse->id]);
        MaterialIssueSlipItem::create(['material_issue_slip_id' => $slip->id, 'item_id' => $item->id,
            'location_id' => $location->id, 'quantity_issued' => '1.000', 'unit_cost' => '10.0000',
            'total_cost' => '10.00', 'lot_number' => 'IDX-LOT', 'stock_movement_id' => $movement->id]);

        $wo = WorkOrder::factory()->create(['status' => 'in_progress']);
        $wo->materials()->create(['item_id' => $item->id, 'bom_quantity' => '2.000']);
        StockMovement::create(['item_id' => $item->id, 'from_location_id' => $location->id, 'to_location_id' => null,
            'movement_type' => StockMovementType::MaterialIssue, 'quantity' => '2.000', 'unit_cost' => '10.0000',
            'total_cost' => '20.00', 'reference_type' => 'work_order', 'reference_id' => $wo->id,
            'lot_number' => 'IDX-WO-LOT', 'created_by' => $warehouse->id]);

        $department = Department::factory()->create(['code' => 'MAINT', 'is_active' => true]);
        $employee = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);
        $technician = User::factory()->create(['role_id' => Role::where('slug', 'employee')->value('id'), 'employee_id' => $employee->id]);
        $order = MaintenanceWorkOrder::create(['mwo_number' => 'MWO-IDX-'.Str::random(6), 'maintainable_type' => 'machine',
            'maintainable_id' => Machine::factory()->create(['status' => 'maintenance'])->id, 'type' => 'corrective', 'priority' => 'low',
            'description' => 'Replace screw', 'status' => 'in_progress', 'assigned_to' => $employee->id, 'created_by' => $warehouse->id]);
        $log = MaintenanceLog::create(['work_order_id' => $order->id, 'finding' => 'Loose screw', 'action_taken' => 'Replacement needed',
            'result' => 'in_progress', 'description' => 'Inspect screw', 'logged_by' => $technician->id]);
        $this->actingAs($technician)->postJson('/api/v1/maintenance/work-orders/'.$order->hash_id.'/spare-parts', [
            'operation_key' => (string) Str::uuid(), 'item_id' => $item->hash_id, 'location_id' => $location->hash_id,
            'quantity' => '1', 'work_record_id' => $log->hash_id])->assertCreated();

        $this->actingAs($warehouse)->getJson('/api/v1/inventory/material-handovers')
            ->assertOk()->assertJsonCount(3, 'data');
    }
}
