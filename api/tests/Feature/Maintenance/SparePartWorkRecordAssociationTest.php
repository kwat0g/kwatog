<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Maintenance\Models\MaintenanceLog;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SparePartWorkRecordAssociationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_issue_requires_a_structured_work_record_from_the_same_order(): void
    {
        [$technician, $order] = $this->technicianAndOrder();
        [, $otherOrder] = $this->technicianAndOrder($technician);
        $item = Item::factory()->create(['item_type' => 'spare_part']);
        $location = WarehouseLocation::factory()->create();
        $stock = $this->receive($item, $location, '10.000', 'ISSUE-RECORD-LOT');
        $workRecord = $this->workRecord($order);
        $otherOrderRecord = $this->workRecord($otherOrder);
        $lifecycleLog = MaintenanceLog::query()->create([
            'work_order_id' => $order->id,
            'description' => 'Technician started repair.',
            'logged_by' => $technician->id,
        ]);
        $url = "/api/v1/maintenance/work-orders/{$order->hash_id}/spare-parts";
        $base = [
            'operation_key' => '5a3a1138-8943-4d3f-a060-1e6f0b0ec2ef',
            'item_id' => $item->hash_id,
            'location_id' => $location->hash_id,
            'quantity' => '1.000',
        ];

        foreach ([$base, [...$base, 'work_record_id' => $otherOrderRecord->hash_id], [...$base, 'work_record_id' => $lifecycleLog->hash_id]] as $payload) {
            $this->actingAs($technician)->postJson($url, $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('work_record_id');
        }

        $this->assertSame('10.000', (string) $stock->fresh()->quantity);
        $this->assertSame(0, StockMovement::query()->where('movement_type', StockMovementType::MaterialIssue->value)->count());

        $this->actingAs($technician)->postJson($url, [...$base, 'work_record_id' => $workRecord->hash_id])->assertCreated();
    }

    public function test_every_lot_row_links_to_work_record_and_replay_returns_original_issue(): void
    {
        [$technician, $order] = $this->technicianAndOrder();
        $item = Item::factory()->create(['item_type' => 'spare_part']);
        $location = WarehouseLocation::factory()->create();
        $this->receive($item, $location, '1.000', 'PART-LOT-A');
        $this->receive($item, $location, '2.000', 'PART-LOT-B');
        $workRecord = $this->workRecord($order);
        $url = "/api/v1/maintenance/work-orders/{$order->hash_id}/spare-parts";
        $payload = [
            'operation_key' => '68a65bb0-ddab-4e4f-b1e4-6100bc9de58a',
            'item_id' => $item->hash_id,
            'location_id' => $location->hash_id,
            'quantity' => '2.000',
            'work_record_id' => $workRecord->hash_id,
        ];

        $first = $this->actingAs($technician)->postJson($url, $payload)->assertCreated();
        $requestId = $first->json('data.id');
        $warehouse = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $this->actingAs($warehouse)->postJson('/api/v1/inventory/maintenance-part-requests/'.$requestId.'/handover',
            ['received_by_employee_id' => $technician->employee->hash_id, 'expected_quantity' => '2'])->assertOk();
        $rows = \App\Modules\Maintenance\Models\SparePartUsage::query()->where('issue_group_key', $payload['operation_key'])->get();
        $this->assertCount(2, $rows);
        $this->assertSame([$workRecord->id], $rows->pluck('work_log_id')->unique()->values()->all());

        $detail = $this->actingAs($technician)->getJson("/api/v1/maintenance/work-orders/{$order->hash_id}")->assertOk();
        $this->assertSame($workRecord->hash_id, $detail->json('data.spare_parts.0.work_record.id'));
        $this->assertSame('Damaged seal.', $detail->json('data.spare_parts.0.work_record.finding'));
        // Issuer is the warehouse confirmer who physically handed over the stock.
        $this->assertSame($warehouse->name, $detail->json('data.spare_parts.0.issuer.name'));
        $this->assertFalse($detail->json('data.parts_readiness.can_close_parts'));
        $this->assertCount(2, $detail->json('data.parts_readiness.outstanding'));

        $this->actingAs($technician)->postJson("/api/v1/maintenance/work-orders/{$order->hash_id}/reconcile-parts", [
            'confirm_consumed' => true,
        ])->assertOk();
        $reconciled = $this->actingAs($technician)->getJson("/api/v1/maintenance/work-orders/{$order->hash_id}")->assertOk();
        $this->assertTrue($reconciled->json('data.parts_readiness.can_close_parts'));
        $this->assertNotNull($reconciled->json('data.parts_reconciled_at'));
        $this->assertSame($technician->name, $reconciled->json('data.parts_reconciled_by.name'));

        $replay = $this->actingAs($technician)->postJson($url, $payload)->assertCreated();
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertCount(2, $rows->fresh());
    }

    /** @return array{User, MaintenanceWorkOrder} */
    private function technicianAndOrder(?User $technician = null): array
    {
        if ($technician === null) {
            $department = Department::query()->firstOrCreate(['code' => 'MAINT'], ['name' => 'Maintenance & Engineering']);
            $employee = Employee::factory()->create(['department_id' => $department->id]);
            $technician = User::factory()->create([
                'role_id' => Role::query()->where('slug', 'maintenance_tech')->value('id'),
                'employee_id' => $employee->id,
            ]);
        } else {
            $employee = Employee::query()->findOrFail($technician->employee_id);
        }
        $machine = Machine::factory()->create(['status' => 'maintenance']);
        $order = MaintenanceWorkOrder::query()->create([
            'mwo_number' => 'MWO-PART-'.uniqid(),
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'type' => 'corrective',
            'priority' => 'high',
            'description' => 'Repair machine',
            'status' => 'in_progress',
            'assigned_to' => $employee->id,
            'created_by' => $technician->id,
        ]);

        return [$technician, $order];
    }

    private function workRecord(MaintenanceWorkOrder $order): MaintenanceLog
    {
        return MaintenanceLog::query()->create([
            'work_order_id' => $order->id,
            'description' => 'Finding: Damaged seal. Action taken: Replaced seal. Result: passed.',
            'finding' => 'Damaged seal.',
            'action_taken' => 'Replaced seal.',
            'result' => 'passed',
            'logged_by' => $order->created_by,
        ]);
    }

    private function receive(Item $item, WarehouseLocation $location, string $quantity, string $lot): \App\Modules\Inventory\Models\StockLevel
    {
        app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::GrnReceipt,
            itemId: $item->id,
            toLocationId: $location->id,
            quantity: $quantity,
            unitCost: '2.0000',
            lotNumber: $lot,
        ));

        return \App\Modules\Inventory\Models\StockLevel::query()->where('item_id', $item->id)->where('location_id', $location->id)->firstOrFail();
    }
}
