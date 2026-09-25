<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\Vehicle;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReturnCaseCustomerReplacementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
    }

    public function test_customer_redelivery_resolves_after_normal_no_invoice_delivery_lifecycle(): void
    {
        $customerService = $this->userForRole('customer_service_officer');
        $approver = $this->userForRole('finance_officer');
        $salesOfficer = $this->userForRole('sales_officer');
        $warehouseOperator = $this->userWithPermissions([
            'supply_chain.deliveries.create', 'supply_chain.deliveries.confirm',
            'supply_chain.deliveries.view', 'inventory.view',
        ]);
        $inspector = User::factory()->create(['is_active' => true]);
        $reviewer = $this->userForRole('production_manager');
        $driver = $this->userForRole('driver');

        $customer = Customer::factory()->create();
        $product = Product::create([
            'part_number' => 'RMA-REPL-'.Str::upper(Str::random(8)),
            'name' => 'Replacement product', 'unit_of_measure' => 'pcs',
            'standard_cost' => '12.00', 'is_active' => true,
        ]);
        $inventoryItem = Item::factory()->create([
            'code' => $product->part_number, 'name' => $product->name,
            'item_type' => ItemType::FinishedGood->value, 'unit_of_measure' => 'pcs', 'is_active' => true,
        ]);
        $sourceOrder = SalesOrder::factory()->create([
            'customer_id' => $customer->id, 'status' => SalesOrderStatus::PartiallyDelivered->value,
            'created_by' => $salesOfficer->id,
        ]);
        $sourceOrderLine = SalesOrderItem::factory()->create([
            'sales_order_id' => $sourceOrder->id, 'product_id' => $product->id,
            'quantity' => '10.00', 'quantity_delivered' => '10.00', 'unit_price' => '25.00',
        ]);
        $sourceDelivery = Delivery::create([
            'delivery_number' => 'RMA-SOURCE-'.Str::upper(Str::random(8)),
            'sales_order_id' => $sourceOrder->id, 'status' => DeliveryStatus::Delivered->value,
            'scheduled_date' => now()->toDateString(), 'delivered_at' => now(), 'created_by' => $salesOfficer->id,
        ]);
        $sourceLine = DeliveryItem::create([
            'delivery_id' => $sourceDelivery->id, 'sales_order_item_id' => $sourceOrderLine->id,
            'quantity' => '10.00', 'unit_price' => '25.00',
        ]);

        $case = $this->actingAs($customerService)->postJson('/api/v1/return-management/cases', [
            'source_kind' => 'delivery', 'source_id' => $sourceDelivery->hash_id,
            'request_key' => (string) Str::uuid(), 'description' => 'Five units were missing from this shipment.',
            'preferred_resolution' => 'redelivery',
            'lines' => [[
                'source_line_id' => $sourceLine->hash_id,
                'received_quantity' => '5.000', 'defective_quantity' => '0.000', 'reason' => 'Short shipped',
            ]],
        ])->assertCreated()->json('data');
        $casePath = '/api/v1/return-management/cases/'.$case['id'];
        $actions = $casePath.'/actions';

        $this->actingAs($customerService)->postJson($actions, [
            'action' => 'agree', 'resolution' => 'redelivery', 'message' => 'Replace the five verified missing units.',
        ])->assertOk()->assertJsonPath('data.status', 'action_agreed');
        $replacementId = $this->actingAs($approver)->postJson($actions, ['action' => 'create_replacement'])
            ->assertOk()->json('data.replacement_order.id');
        $replacement = SalesOrder::query()->where('return_case_id', ReturnCase::tryDecodeHash($case['id']))->firstOrFail();
        $this->assertSame('0.00', $replacement->total_amount);
        $this->actingAs($salesOfficer)->postJson('/api/v1/crm/sales-orders/'.$replacementId.'/confirm')->assertOk();
        $replacement->refresh();
        $this->assertSame(SalesOrderStatus::Confirmed, $replacement->status);

        // A real delivery needs output-bound, maker-checked outgoing Quality.
        // Model completed production output as the prerequisite and exercise
        // the normal create, dispatch, proof, and confirmation endpoints below.
        $replacementLine = $replacement->items()->firstOrFail();
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-RMA-REPL-'.Str::upper(Str::random(6)),
            'product_id' => $product->id, 'sales_order_id' => $replacement->id,
            'sales_order_item_id' => $replacementLine->id, 'quantity_target' => 5,
            'quantity_produced' => 5, 'quantity_good' => 5, 'quantity_rejected' => 0,
            'planned_start' => now()->subDay(), 'planned_end' => now(), 'status' => 'completed',
            'created_by' => $salesOfficer->id,
        ]);
        $output = WorkOrderOutput::create([
            'work_order_id' => $workOrder->id, 'recorded_by' => $salesOfficer->id,
            'recorded_at' => now(), 'good_count' => 5, 'reject_count' => 0,
            'batch_code' => 'RMA-'.Str::upper(Str::random(8)),
        ]);
        $inspection = Inspection::create([
            'inspection_number' => 'QC-RMA-REPL-'.Str::upper(Str::random(6)),
            'stage' => InspectionStage::Outgoing->value, 'status' => InspectionStatus::Passed->value,
            'inspector_id' => $inspector->id, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(),
            'product_id' => $product->id, 'entity_type' => InspectionEntityType::WorkOrder->value,
            'entity_id' => $workOrder->id, 'work_order_output_id' => $output->id,
            'batch_quantity' => 5, 'accepted_quantity' => 5, 'sample_size' => 1,
            'accept_count' => 1, 'reject_count' => 0, 'defect_count' => 0, 'completed_at' => now(),
        ]);

        $warehouse = Warehouse::factory()->create();
        $finishedZone = WarehouseZone::factory()->create([
            'warehouse_id' => $warehouse->id, 'zone_type' => 'finished_goods',
        ]);
        $finishedLocation = WarehouseLocation::factory()->create(['zone_id' => $finishedZone->id]);
        Event::fake([StockMovementCompleted::class]);
        app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::GrnReceipt, itemId: $inventoryItem->id,
            quantity: '5.000', toLocationId: $finishedLocation->id,
            unitCost: '12.00', createdBy: $warehouseOperator->id,
        ));

        $delivery = $this->actingAs($warehouseOperator)->postJson('/api/v1/supply-chain/deliveries', [
            'sales_order_id' => $replacement->hash_id, 'scheduled_date' => now()->toDateString(),
            'items' => [[
                'sales_order_item_id' => $replacementLine->hash_id, 'quantity' => '5.00',
                'inspection_id' => $inspection->hash_id,
            ]],
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data');
        $deliveryPath = '/api/v1/supply-chain/deliveries/'.$delivery['id'];

        $vehicle = Vehicle::query()->create([
            'plate_number' => 'RMA-'.Str::upper(Str::random(6)), 'name' => 'RMA replacement van',
            'vehicle_type' => 'van', 'status' => 'available',
        ]);
        $this->actingAs($warehouseOperator)->patchJson($deliveryPath.'/assignment', [
            'vehicle_id' => $vehicle->hash_id, 'driver_id' => $driver->hash_id,
            'reason' => 'Assign replacement shipment.',
        ])->assertOk();
        $this->actingAs($warehouseOperator)->patchJson($deliveryPath.'/status', ['status' => 'loading'])->assertOk();
        $this->actingAs($warehouseOperator)->patchJson($deliveryPath.'/status', ['status' => 'in_transit'])->assertOk();
        $this->actingAs($warehouseOperator)->patchJson($deliveryPath.'/status', ['status' => 'delivered'])->assertOk();
        $this->actingAs($warehouseOperator)->postJson($deliveryPath.'/proofs', [
            'proof_type' => 'signed_dr', 'file' => UploadedFile::fake()->create('signed-dr.pdf', 10, 'application/pdf'),
        ])->assertCreated();
        $this->actingAs($warehouseOperator)->postJson($deliveryPath.'/confirm', [
            'receiver_name' => 'Customer receiving desk',
        ])->assertOk()->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.invoice_handoff.status', 'not_required');

        $this->assertSame(0, $replacement->invoices()->count());
        $this->assertDatabaseCount('invoices', 0);
        $this->actingAs($customerService)->postJson($actions, ['action' => 'resolve'])
            ->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertSame(0, $replacement->fresh()->invoices()->count());
    }

    private function userForRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'), 'is_active' => true,
        ]);
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create([
            'name' => 'Return lifecycle '.Str::random(6), 'slug' => 'return-lifecycle-'.Str::random(8),
            'is_system' => false,
        ]);
        foreach ($permissions as $slug) {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug], ['name' => $slug, 'module' => 'supply_chain'],
            );
            $role->permissions()->attach($permission->id);
        }

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
