<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Models\SalesOrderStockAllocation;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\CRM\Services\SalesOrderSupplyService;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\Inventory\Services\MovementGlPostingService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\MRP\Services\BomService;
use App\Modules\MRP\Services\MrpEngineService;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Services\StockProductionPlanningService;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionSpecService;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Services\DeliveryService;
use App\Modules\SupplyChain\Services\DeliveryStockReservationService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\HasPassedInProcessQc;
use App\Modules\Purchasing\Services\OpenSupplyService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\SupplyChain\Services\DeliveryStockService;
use Tests\TestCase;

class SalesOrderStockFulfillmentTest extends TestCase
{
    use HasPassedInProcessQc, RefreshDatabase, \Tests\Support\PreparesDeliveryShipment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
    }

    public function test_preview_does_not_hold_and_retry_does_not_hold_twice(): void
    {
        [$so, $line, $item, $location, $actor] = $this->stockFixture();
        $service = app(SalesOrderSupplyService::class);
        $this->assertSame('10.000', $service->preview($so)['lines'][0]['proposed_stock']);
        $this->assertSame('0.000', (string) StockLevel::where('item_id', $item->id)->value('reserved_quantity'));
        $key = Str::uuid()->toString();
        $service->allocate($so, $actor, $key);
        $service->allocate($so, $actor, $key);
        $this->assertSame('10.000', (string) StockLevel::where('item_id', $item->id)->value('reserved_quantity'));
        $this->assertSame('10.000', (string) StockLevel::where('item_id', $item->id)->value('quantity'));
        $this->assertSame(1, SalesOrderStockAllocation::count());
        $this->assertSame('0.000', $service->lineCoverage($line)['uncovered']);
    }

    public function test_fulfillment_preview_endpoint_loads_stock_source_quality_spec(): void
    {
        [$so, $line] = $this->stockFixture();
        $reader = User::factory()->withRole('sales_officer')->create();

        $response = $this->actingAs($reader)->getJson('/api/v1/crm/sales-orders/'.$so->hash_id.'/fulfillment');

        $response->assertOk()->assertJsonPath('data.lines.0.policy', 'stock_first')
            ->assertJsonPath('data.lines.0.proposed_stock', '10.000');
    }

    public function test_second_order_cannot_spend_held_stock(): void
    {
        [$so, $line, $item, $location, $actor] = $this->stockFixture();
        $line->update(['quantity' => '6']);
        $service = app(SalesOrderSupplyService::class);
        $service->allocate($so, $actor, Str::uuid()->toString());
        $other = SalesOrder::factory()->create(['status' => 'confirmed', 'created_by' => $actor->id]);
        $otherLine = SalesOrderItem::factory()->create(['sales_order_id' => $other->id, 'product_id' => $line->product_id, 'quantity' => '6', 'fulfillment_policy' => 'stock_first']);
        $service->allocate($other, $actor, Str::uuid()->toString());
        $this->assertSame('10.000', (string) StockLevel::where('item_id', $item->id)->value('reserved_quantity'));
        $this->assertSame('2.000', $service->lineCoverage($otherLine)['uncovered']);
    }

    public function test_unrelated_order_output_and_expired_stock_are_not_eligible(): void
    {
        [$so, $line, $item, $location, $actor, $inspection] = $this->stockFixture();
        WorkOrder::whereKey($inspection->workOrderOutput->work_order_id)->update(['sales_order_id' => $so->id]);
        $this->assertSame('0.000', app(SalesOrderSupplyService::class)->preview($so)['lines'][0]['proposed_stock']);
    }

    public function test_stock_only_mrp_needs_no_new_bom_and_drafts_delivery_without_second_hold(): void
    {
        [$so, $line, $item, $location, $actor] = $this->stockFixture();
        app(MrpEngineService::class)->runForSalesOrder($so);
        $this->assertSame(0, WorkOrder::where('sales_order_id', $so->id)->count());
        $delivery = Delivery::where('sales_order_id', $so->id)->firstOrFail();
        $this->assertSame('10.000', (string) $delivery->items()->sum('quantity'));
        $this->assertSame('10.000', (string) StockLevel::where('item_id', $item->id)->value('reserved_quantity'));
        $this->assertSame('10.000', (string) SalesOrderStockAllocation::first()->transferred_quantity);
        $steps = collect(app(SalesOrderService::class)->chain($so->fresh()))->keyBy('key');
        $this->assertSame('Ready from stock', $steps['in_production']['label']);
        $this->assertSame('done', $steps['in_production']['state']);
        $this->assertNotSame('done', $steps['delivered']['state']);
    }

    public function test_mixed_mrp_produces_only_shortage_and_retry_reuses_work_order(): void
    {
        [$so, $line, $item, $location, $actor] = $this->stockFixture();
        $line->update(['quantity' => '15']);
        $material = Item::factory()->create(['unit_of_measure' => 'kg', 'item_type' => 'raw_material']);
        app(BomService::class)->create($line->product_id, [[
            'item_id' => $material->id, 'quantity_per_unit' => 1, 'unit' => 'kg', 'waste_factor' => 0,
        ]]);
        $engine = app(MrpEngineService::class);
        $engine->runForSalesOrder($so);
        $engine->runForSalesOrder($so->fresh());
        $this->assertSame(5, (int) WorkOrder::where('sales_order_id', $so->id)->where('status', '!=', 'cancelled')->sum('quantity_target'));
        $this->assertSame(0, Delivery::where('sales_order_id', $so->id)->count());
        $this->assertSame('10.000', (string) StockLevel::where('item_id', $item->id)->value('reserved_quantity'));
        config(['inspection.finish_first_wo_enabled' => false]);
        $workOrder = WorkOrder::where('sales_order_id', $so->id)->where('status', '!=', 'cancelled')->firstOrFail();
        $workOrder->forceFill(['status' => 'completed', 'quantity_good' => 5, 'quantity_produced' => 5])->save();
        $this->passInProcessQc($workOrder);
        $output = WorkOrderOutput::create(['work_order_id' => $workOrder->id, 'recorded_by' => $actor->id, 'recorded_at' => now(),
            'good_count' => 5, 'reject_count' => 0, 'batch_code' => 'SHORTAGE-BATCH']);
        $receipt = StockMovement::create(['item_id' => $item->id, 'to_location_id' => $location->id,
            'movement_type' => 'production_receipt', 'quantity' => '5.000', 'unit_cost' => '4.5000', 'total_cost' => '22.50',
            'lot_number' => 'SHORTAGE-BATCH', 'reference_type' => 'work_order_output', 'reference_id' => $output->id,
            'created_by' => $actor->id, 'created_at' => now()]);
        app(MovementGlPostingService::class)->postFor($receipt);
        $output->update(['production_receipt_movement_id' => $receipt->id, 'production_receipt_handoff_status' => 'generated']);
        StockLevel::where('item_id', $item->id)->where('location_id', $location->id)->update(['quantity' => '15.000']);
        $inspection = Inspection::create(['inspection_number' => 'QC-MIXED', 'stage' => 'outgoing', 'status' => 'passed',
            'inspector_id' => $actor->id, 'reviewed_by' => User::factory()->create()->id, 'reviewed_at' => now(),
            'product_id' => $line->product_id, 'entity_type' => 'work_order', 'entity_id' => $workOrder->id,
            'work_order_output_id' => $output->id, 'batch_quantity' => 5, 'accepted_quantity' => 5,
            'sample_size' => 1, 'accept_count' => 1, 'reject_count' => 0, 'defect_count' => 0, 'completed_at' => now()]);
        $service = app(DeliveryService::class);
        $delivery = $service->draftReleasedWorkOrder($workOrder);
        $this->assertNotNull($delivery);
        $this->assertSame('15.000', bcadd((string) $delivery->items()->sum('quantity'), '0', 3));
        $this->assertSame(2, $delivery->items()->count());
        $this->assertTrue($delivery->items()->where('inspection_id', $inspection->id)->exists());
        $this->assertSame('15.000', (string) StockLevel::where('item_id', $item->id)->value('reserved_quantity'));
        $service->draftReleasedWorkOrder($workOrder);
        $this->assertSame(1, Delivery::where('sales_order_id', $so->id)->count());
    }

    public function test_stock_creation_reuses_request_and_rejects_changed_quantity(): void
    {
        [$so, $line, $item, $location, $actor] = $this->stockFixture();
        $material = Item::factory()->create(['unit_of_measure' => 'kg', 'item_type' => 'raw_material']);
        app(BomService::class)->create($line->product_id, [[
            'item_id' => $material->id, 'quantity_per_unit' => '1', 'unit' => 'kg', 'waste_factor' => 0,
        ]]);
        $data = ['product_id' => $line->product_id, 'quantity_target' => 3, 'request_key' => Str::uuid()->toString(),
            'planned_start' => now()->toIso8601String(), 'planned_end' => now()->addDays(10)->toIso8601String(),
            'minimum_workers' => 1, 'maximum_workers' => 1];
        $planner = app(StockProductionPlanningService::class);
        $first = $planner->create($data, $actor);
        $second = $planner->create($data, $actor);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('stock', $first->production_purpose);
        $this->assertNull($first->sales_order_id);
        $this->assertSame(1, PurchaseRequest::where('stock_production_work_order_id', $first->id)->count());
        $this->expectException(BusinessRuleException::class);
        $planner->create(array_replace($data, ['quantity_target' => 4]), $actor);
    }

    public function test_stock_allocation_dispatch_consumes_once_and_release_keeps_history(): void
    {
        [$so, $line, $item, $location, $actor] = $this->stockFixture();
        app(MrpEngineService::class)->runForSalesOrder($so);
        $delivery = Delivery::where('sales_order_id', $so->id)->firstOrFail();
        $delivery->forceFill(['scheduled_date' => today()])->save();
        $this->prepareShipmentForDispatch($delivery);
        $delivery->forceFill(['status' => 'loading'])->save();
        app(DeliveryService::class)->updateStatus($delivery, DeliveryStatus::InTransit);
        $this->assertSame('0.000', (string) StockLevel::where('item_id', $item->id)->value('quantity'));
        $this->assertSame('0.000', (string) StockLevel::where('item_id', $item->id)->value('reserved_quantity'));
        $this->assertSame('10.000', (string) SalesOrderStockAllocation::first()->consumed_quantity);
        $this->assertSame(1, $delivery->items()->first()->stockMovements()->count());
    }

    public function test_safety_stock_produce_new_and_recalled_allocation(): void
    {
        [$so, $line, $item, $location, $actor, $inspection] = $this->stockFixture();
        $item->update(['safety_stock' => '2']);
        $service = app(SalesOrderSupplyService::class);
        $this->assertSame('8.000', $service->preview($so)['lines'][0]['proposed_stock']);
        $line->update(['fulfillment_policy' => 'produce_new']);
        $service->allocate($so, $actor, Str::uuid()->toString());
        $this->assertSame(0, SalesOrderStockAllocation::count());
        $line->update(['fulfillment_policy' => 'stock_first']);
        $service->allocate($so, $actor, Str::uuid()->toString());
        $inspection->update(['status' => 'failed', 'accepted_quantity' => 0]);
        $this->assertSame('10.000', $service->lineCoverage($line)['uncovered']);
    }

    public function test_expiry_and_spec_change_remove_eligible_stock(): void
    {
        [$so, $line, $item, $location, $actor, $inspection] = $this->stockFixture();
        $receipt = $inspection->workOrderOutput->productionReceiptMovement;
        $receipt->update(['expiry_date' => today()->subDay()]);
        $this->assertSame('0.000', app(SalesOrderSupplyService::class)->preview($so)['lines'][0]['proposed_stock']);
        $receipt->update(['expiry_date' => null]);
        $inspection->update(['inspection_spec_id' => null, 'inspection_spec_revision_id' => null]);
        $this->assertSame('0.000', app(SalesOrderSupplyService::class)->preview($so)['lines'][0]['proposed_stock']);
    }

    public function test_allocation_blocks_competing_issue_and_forged_destination(): void
    {
        [$so, $line, $item, $location, $actor, $inspection] = $this->stockFixture();
        $service = app(SalesOrderSupplyService::class);
        $service->allocate($so, $actor, Str::uuid()->toString());
        try {
            app(StockMovementService::class)->move(new StockMovementInput(
                type: StockMovementType::MaterialIssue, itemId: $item->id, quantity: '1.000',
                fromLocationId: $location->id, lotNumber: 'APPROVED-BATCH', createdBy: $actor->id));
            $this->fail('Order-held stock must not be issued elsewhere.');
        } catch (BusinessRuleException|InsufficientStockException $error) {
            $this->assertNotEmpty($error->getMessage());
        }
        $this->assertSame('10.000', (string) StockLevel::where('item_id', $item->id)->value('quantity'));
        $other = SalesOrderItem::factory()->create(['product_id' => $line->product_id]);
        $this->expectException(BusinessRuleException::class);
        $service->assertAllocationForDelivery(SalesOrderStockAllocation::firstOrFail(), $other, $inspection, '1.000');
    }

    public function test_recalled_allocation_cannot_dispatch_and_cancellation_releases_once(): void
    {
        [$so, $line, $item, $location, $actor, $inspection] = $this->stockFixture();
        app(MrpEngineService::class)->runForSalesOrder($so);
        $delivery = Delivery::where('sales_order_id', $so->id)->firstOrFail();
        $delivery->forceFill(['scheduled_date' => today()])->save();
        $this->prepareShipmentForDispatch($delivery);
        $inspection->update(['status' => 'failed', 'accepted_quantity' => 0]);
        try {
            app(DeliveryService::class)->updateStatus($delivery->fresh(), DeliveryStatus::Loading);
            app(DeliveryService::class)->updateStatus($delivery->fresh(), DeliveryStatus::InTransit);
            $this->fail('Revoked QC cannot dispatch.');
        } catch (BusinessRuleException|ValidationException $error) {
            $this->assertNotEmpty($error->getMessage());
        }
        $holds = app(DeliveryStockReservationService::class);
        $holds->releaseForDelivery($delivery);
        $holds->releaseForDelivery($delivery);
        $this->assertSame('0.000', (string) StockLevel::where('item_id', $item->id)->value('reserved_quantity'));
        $this->assertSame('10.000', (string) SalesOrderStockAllocation::first()->released_quantity);
        $this->assertSame('10.000', (string) StockLevel::where('item_id', $item->id)->value('quantity'));
    }

    public function test_policy_requires_ppc_permission_and_rejects_stale_revision(): void
    {
        [$so, $line, $item, $location, $actor] = $this->stockFixture();
        $actor->update(['role_id' => Role::where('slug', 'sales_officer')->value('id')]);
        $payload = ['revision' => str_repeat('0', 64), 'request_key' => Str::uuid()->toString(),
            'reason' => 'Use fresh production', 'lines' => [['id' => $line->hash_id, 'policy' => 'produce_new']]];
        $this->actingAs($actor)->putJson('/api/v1/crm/sales-orders/'.$so->hash_id.'/fulfillment', $payload)->assertForbidden();
        $actor->update(['role_id' => Role::where('slug', 'ppc_head')->value('id')]);
        $ppc = User::factory()->withRole('ppc_head')->create();
        $this->actingAs($ppc)->putJson('/api/v1/crm/sales-orders/'.$so->hash_id.'/fulfillment', $payload)->assertStatus(422);
        $this->assertSame('stock_first', $line->fresh()->fulfillment_policy);
    }

    public function test_ppc_can_choose_fresh_production_before_automatic_shipment_assignment(): void
    {
        [$so, $line, $item, $location, $actor] = $this->stockFixture();
        $raw = Item::factory()->create(['unit_of_measure' => 'pcs', 'item_type' => 'raw_material']);
        app(BomService::class)->create($line->product_id, [['item_id' => $raw->id, 'quantity_per_unit' => 1, 'unit' => 'pcs', 'waste_factor' => 0]]);
        app(MrpEngineService::class)->runForSalesOrder($so);
        $ppc = User::factory()->withRole('ppc_head')->create();
        $payload = ['revision' => app(SalesOrderSupplyService::class)->revision($so), 'request_key' => Str::uuid()->toString(),
            'reason' => 'Customer requested fresh production', 'lines' => [['id' => $line->hash_id, 'policy' => 'produce_new']]];
        $this->actingAs($ppc)->putJson('/api/v1/crm/sales-orders/'.$so->hash_id.'/fulfillment', $payload)->assertOk();
        $this->assertSame('produce_new', $line->fresh()->fulfillment_policy);
        $this->assertSame('0.000', (string) StockLevel::where('item_id', $item->id)->value('reserved_quantity'));
        $this->assertSame('10.000', (string) StockLevel::where('item_id', $item->id)->value('quantity'));
        $this->assertSame(0, Delivery::where('sales_order_id', $so->id)->where('status', '!=', 'cancelled')->count());
        $this->assertSame(10, (int) WorkOrder::where('sales_order_id', $so->id)->where('status', '!=', 'cancelled')->sum('quantity_target'));
        $this->actingAs($ppc)->putJson('/api/v1/crm/sales-orders/'.$so->hash_id.'/fulfillment', $payload)->assertOk();
        $this->assertSame(1, WorkOrder::where('sales_order_id', $so->id)->where('status', '!=', 'cancelled')->count());
    }

    public function test_quantity_constraints_and_downgrade_preserve_history(): void
    {
        [$so, $line, $item, $location, $actor] = $this->stockFixture();
        app(SalesOrderSupplyService::class)->allocate($so, $actor, Str::uuid()->toString());
        $allocation = SalesOrderStockAllocation::firstOrFail();
        try {
            DB::transaction(fn () => $allocation->update(['released_quantity' => '11.000']));
            $this->fail('Over-release must fail the database constraint.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('order_stock_quantities_valid', $error->getMessage());
        }
        $migration = require database_path('migrations/2026_10_09_220000_add_stock_production_and_order_allocations.php');
        try {
            $migration->down();
            $this->fail('Downgrade must preserve stock provenance.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('provenance', $error->getMessage());
        }
        $this->assertSame(1, SalesOrderStockAllocation::count());
        $this->assertSame('10.000', (string) $allocation->fresh()->quantity);
    }

    public function test_batched_list_coverage_and_inventory_summary_remain_consistent(): void
    {
        [$so, $line, $item, $location, $actor] = $this->stockFixture();
        app(SalesOrderSupplyService::class)->allocate($so, $actor, Str::uuid()->toString());
        $orders = app(SalesOrderService::class)->list(['search' => $so->so_number]);
        $this->assertSame('10.000', app(SalesOrderSupplyService::class)->lineCoverage($orders->first()->items->first())['ready_quantity']);
        $summary = app(ItemService::class)->show($item);
        $this->assertSame('0.000', $summary->approved_free_stock);
        $this->assertSame('10.000', (string) $summary->reserved_quantity);
    }

    public function test_stock_planner_creates_genuine_child_without_sales_order(): void
    {
        [$so, $line, $item, $location, $actor] = $this->stockFixture();
        $childProduct = Product::factory()->create(['unit_of_measure' => 'pcs']);
        $childItem = Item::factory()->create(['code' => $childProduct->part_number, 'unit_of_measure' => 'pcs', 'item_type' => 'finished_good']);
        $raw = Item::factory()->create(['unit_of_measure' => 'pcs', 'item_type' => 'raw_material']);
        $boms = app(BomService::class);
        $boms->create($childProduct->id, [['item_id' => $raw->id, 'quantity_per_unit' => 3, 'unit' => 'pcs', 'waste_factor' => 0]]);
        $boms->create($line->product_id, [['item_id' => $childItem->id, 'quantity_per_unit' => 2, 'unit' => 'pcs', 'waste_factor' => 0]]);
        $data = ['product_id' => $line->product_id, 'quantity_target' => 3, 'request_key' => Str::uuid()->toString(),
            'planned_start' => now()->toIso8601String(), 'planned_end' => now()->addDays(10)->toIso8601String(), 'minimum_workers' => 1, 'maximum_workers' => 1];
        $planner = app(StockProductionPlanningService::class);
        $root = $planner->create($data, $actor);
        $planner->create($data, $actor);
        $child = WorkOrder::where('parent_wo_id', $root->id)->firstOrFail();
        $this->assertSame(6, (int) $child->quantity_target);
        $this->assertNull($child->sales_order_id);
        $this->assertSame('stock', $child->production_purpose);
        $this->assertSame(1, WorkOrder::where('parent_wo_id', $root->id)->count());
        $this->assertSame('18.000', bcadd((string) $child->materials()->first()->bom_quantity, '0', 3));
        $boms->create($line->product_id, [['item_id' => $raw->id, 'quantity_per_unit' => 1, 'unit' => 'pcs', 'waste_factor' => 0]]);
        $planner->reconcile($root, $actor);
        $this->assertSame(0, $root->children()->where('status', '!=', 'cancelled')->count());
        $this->assertSame('cancelled', $child->fresh()->status->value);
        $this->assertNull($child->fresh()->deleted_at);
        $this->assertSame('3.000', bcadd((string) $root->materials()->first()->bom_quantity, '0', 3));
    }

    private function stockPlan(array $fixture, int $quantity = 3): WorkOrder
    {
        return app(StockProductionPlanningService::class)->create([
            'product_id' => $fixture[1]->product_id, 'quantity_target' => $quantity,
            'request_key' => Str::uuid()->toString(), 'planned_start' => now()->toIso8601String(),
            'planned_end' => now()->addDays(10)->toIso8601String(), 'minimum_workers' => 1, 'maximum_workers' => 1,
        ], $fixture[4]);
    }

    public function test_safety_cancelling_stock_parent_retires_untouched_dependents(): void
    {
        $fixture = $this->stockFixture();
        $child = Product::factory()->create(['unit_of_measure' => 'pcs']);
        $childItem = Item::factory()->create(['code' => $child->part_number, 'unit_of_measure' => 'pcs', 'item_type' => 'finished_good']);
        $raw = Item::factory()->create(['unit_of_measure' => 'pcs', 'item_type' => 'raw_material']);
        $boms = app(BomService::class);
        $boms->create($child->id, [['item_id' => $raw->id, 'quantity_per_unit' => 3, 'unit' => 'pcs', 'waste_factor' => 0]]);
        $boms->create($fixture[1]->product_id, [['item_id' => $childItem->id, 'quantity_per_unit' => 2, 'unit' => 'pcs', 'waste_factor' => 0]]);
        $root = $this->stockPlan($fixture);
        $dependent = $root->children()->firstOrFail();
        $request = PurchaseRequest::where('stock_production_work_order_id', $root->id)->firstOrFail();
        app(WorkOrderService::class)->cancel($root, 'Customer replenishment no longer needed.');
        $this->assertSame(['cancelled', 'cancelled'], [$dependent->fresh()->status->value, $request->fresh()->status->value]);
    }

    public function test_safety_purchase_plan_nets_existing_unallocated_incoming_supply(): void
    {
        $fixture = $this->stockFixture();
        $raw = Item::factory()->create(['unit_of_measure' => 'kg', 'item_type' => 'raw_material']);
        app(BomService::class)->create($fixture[1]->product_id, [['item_id' => $raw->id, 'quantity_per_unit' => 1, 'unit' => 'kg', 'waste_factor' => 0]]);
        $po = PurchaseOrder::factory()->create();
        $po->forceFill(['status' => 'acknowledged'])->save();
        $po->items()->create(['item_id' => $raw->id, 'description' => $raw->name, 'quantity' => 3, 'unit' => 'kg', 'unit_price' => 1, 'total' => 3]);
        $this->assertSame('3.000000', app(OpenSupplyService::class)->inTransitBaseQuantity($raw->id));
        $root = $this->stockPlan($fixture);
        $this->assertSame('0.000', bcadd((string) PurchaseRequest::where('stock_production_work_order_id', $root->id)->first()?->items()->sum('quantity'), '0', 3));
    }

    public function test_safety_rounded_child_materials_match_purchase_plan(): void
    {
        $fixture = $this->stockFixture();
        $child = Product::factory()->create(['unit_of_measure' => 'pcs']);
        $childItem = Item::factory()->create(['code' => $child->part_number, 'unit_of_measure' => 'pcs', 'item_type' => 'finished_good']);
        $raw = Item::factory()->create(['unit_of_measure' => 'kg', 'item_type' => 'raw_material']);
        $boms = app(BomService::class);
        $boms->create($child->id, [['item_id' => $raw->id, 'quantity_per_unit' => 3, 'unit' => 'kg', 'waste_factor' => 0]]);
        $boms->create($fixture[1]->product_id, [['item_id' => $childItem->id, 'quantity_per_unit' => '0.5', 'unit' => 'pcs', 'waste_factor' => 0]]);
        $root = $this->stockPlan($fixture, 1);
        $dependent = $root->children()->firstOrFail();
        $this->assertSame(1, (int) $dependent->quantity_target);
        $this->assertSame('3.000', bcadd((string) $dependent->materials()->sum('bom_quantity'), '0', 3));
        $request = PurchaseRequest::where('stock_production_work_order_id', $root->id)->firstOrFail();
        $this->assertSame('3.000', bcadd((string) $request->items()->sum('quantity'), '0', 3));
    }

    public function test_safety_fully_stock_covered_order_can_cancel_unassigned_auto_delivery(): void
    {
        [$so] = $this->stockFixture();
        app(MrpEngineService::class)->runForSalesOrder($so);
        $delivery = Delivery::where('sales_order_id', $so->id)->firstOrFail();
        $this->assertNull($delivery->driver_id);
        $this->assertNull($delivery->vehicle_id);
        $this->assertSame('scheduled', $delivery->status->value);
        $cancelled = app(SalesOrderService::class)->cancel($so->fresh(), 'Cancelled before shipment assignment.');
        $this->assertSame('cancelled', $cancelled->status->value);
    }

    public function test_safety_blocked_bin_is_no_longer_ready_supply(): void
    {
        [$so, $line, $item, $location, $actor] = $this->stockFixture();
        $service = app(SalesOrderSupplyService::class);
        $service->allocate($so, $actor, Str::uuid()->toString());
        $location->update(['is_blocked' => true]);
        $this->assertSame(0, app(DeliveryStockService::class)->eligibleLevels($item->id)->count());
        $this->assertSame('0.000', $service->lineCoverage($line->fresh())['ready_quantity']);
    }

    public function test_safety_replacement_stock_rebuilds_revoked_unassigned_auto_shipment(): void
    {
        [$so, $line, $item, $location, $actor, $inspection] = $this->stockFixture();
        app(MrpEngineService::class)->runForSalesOrder($so);
        $oldShipment = Delivery::where('sales_order_id', $so->id)->firstOrFail();
        $replacementLocation = WarehouseLocation::factory()->create(['zone_id' => $location->zone_id]);
        $output = WorkOrderOutput::create(['work_order_id' => $inspection->workOrderOutput->work_order_id,
            'recorded_by' => $actor->id, 'recorded_at' => now(), 'good_count' => 10, 'reject_count' => 0, 'batch_code' => 'REPLACEMENT-STOCK']);
        $receipt = StockMovement::create(['item_id' => $item->id, 'to_location_id' => $replacementLocation->id,
            'movement_type' => 'production_receipt', 'quantity' => '10.000', 'unit_cost' => '4.5000', 'total_cost' => '45.00',
            'lot_number' => 'REPLACEMENT-STOCK', 'reference_type' => 'work_order_output', 'reference_id' => $output->id,
            'created_by' => $actor->id, 'created_at' => now()]);
        app(MovementGlPostingService::class)->postFor($receipt);
        $output->update(['production_receipt_movement_id' => $receipt->id, 'production_receipt_handoff_status' => 'generated']);
        StockLevel::factory()->create(['item_id' => $item->id, 'location_id' => $replacementLocation->id,
            'quantity' => '10.000', 'weighted_avg_cost' => '4.5000']);
        $replacement = $inspection->replicate();
        $replacement->inspection_number = 'QC-REPLACEMENT-STOCK';
        $replacement->work_order_output_id = $output->id;
        $replacement->save();
        $this->assertTrue($replacement->canReleaseOutgoing());
        $inspection->update(['status' => 'failed', 'accepted_quantity' => 0]);
        app(MrpEngineService::class)->runForSalesOrder($so->fresh());
        $this->assertSame('10.000', app(SalesOrderSupplyService::class)->lineCoverage($line->fresh())['ready_quantity']);
        $this->assertSame('cancelled', $oldShipment->fresh()->status->value);
        $active = Delivery::where('sales_order_id', $so->id)->where('status', '!=', 'cancelled')->with('items')->get();
        $this->assertTrue($active->flatMap->items->contains(fn ($row) => $row->inspection_id === $replacement->id),
            'New approved stock is held, but only the revoked inspection remains on the active delivery.');
    }

    public function test_safety_multiple_allocations_load_without_lazy_relationships_and_bin_changes_invalidate_readiness(): void
    {
        [$so, $line, $item, $location, $actor] = $this->stockFixture();
        $second = WarehouseLocation::factory()->create(['zone_id' => $location->zone_id]);
        app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::Transfer, itemId: $item->id, quantity: '5.000',
            fromLocationId: $location->id, toLocationId: $second->id, lotNumber: 'APPROVED-BATCH',
        ));
        $supply = app(SalesOrderSupplyService::class);
        $supply->allocate($so, $actor, Str::uuid()->toString());
        $this->assertSame(2, $line->stockAllocations()->count());
        $this->assertSame('10.000', $supply->preview($so)['lines'][0]['ready_quantity']);
        $orders = app(SalesOrderService::class)->list(['search' => $so->so_number]);
        $this->assertSame('10.000', $supply->lineCoverage($orders->first()->items->first())['ready_quantity']);
        $second->update(['is_active' => false]);
        $orders = app(SalesOrderService::class)->list(['search' => $so->so_number]);
        $this->assertSame('5.000', $supply->lineCoverage($orders->first()->items->first())['ready_quantity']);
    }

    public function test_safety_assigned_auto_shipment_blocks_order_cancellation_and_recovery(): void
    {
        [$so, $line, $item, $location, $actor, $inspection] = $this->stockFixture();
        app(MrpEngineService::class)->runForSalesOrder($so);
        $delivery = Delivery::where('sales_order_id', $so->id)->firstOrFail();
        $this->prepareShipmentForDispatch($delivery);
        $this->assertFalse(app(DeliveryService::class)->isUntouchedAutomaticSupply($delivery->fresh()));
        try { app(SalesOrderService::class)->cancel($so, 'Customer changed order.'); $this->fail('Assigned shipment must remain protected.'); }
        catch (BusinessRuleException $error) { $this->assertNotEmpty($error->getMessage()); }
        $this->assertSame('scheduled', $delivery->fresh()->status->value);
        app(SettingsService::class)->set('modules.supply_chain', true, 'modules');
        $deliveryViewerRole = Role::firstOrCreate(['slug' => 'notification_invalid_supply_viewer'], ['name' => 'Notification Invalid Supply Viewer']);
        $deliveryViewerRole->permissions()->syncWithoutDetaching([
            Permission::query()->where('slug', 'supply_chain.deliveries.create')->value('id'),
            Permission::query()->where('slug', 'supply_chain.deliveries.view')->value('id'),
        ]);
        $createOnlyRole = Role::firstOrCreate(['slug' => 'notification_invalid_supply_creator'], ['name' => 'Notification Invalid Supply Creator']);
        $createOnlyRole->permissions()->syncWithoutDetaching([
            Permission::query()->where('slug', 'supply_chain.deliveries.create')->value('id'),
        ]);
        $deliveryViewer = User::factory()->withRole($deliveryViewerRole->slug)->create(['is_active' => true]);
        $createOnly = User::factory()->withRole($createOnlyRole->slug)->create(['is_active' => true]);
        $inspection->update(['status' => 'failed', 'accepted_quantity' => 0]);
        $result = DB::transaction(fn () => app(DeliveryService::class)->reconcileAutomaticSupply($so, $actor));
        $this->assertCount(1, $result['blockers']);
        $this->assertSame([], $result['retired_delivery_ids']);
        $this->assertSame('scheduled', $delivery->fresh()->status->value);
        $this->assertSame('0.000', app(SalesOrderSupplyService::class)->lineCoverage($line->fresh())['ready_quantity']);
        $notice = $deliveryViewer->notifications()->where('type', 'mrp_run_completed')->firstOrFail();
        $this->assertSame(Delivery::class, $notice->data['subject_type']);
        $this->assertSame((string) $delivery->id, $notice->data['subject_id']);
        $this->assertSame('/supply-chain/deliveries/'.$delivery->hash_id, $notice->data['link_to']);
        $this->assertSame(0, $createOnly->notifications()->where('type', 'mrp_run_completed')->count());
    }

    public function test_safety_incoming_budget_is_shared_once_and_stable_on_both_replay_orders(): void
    {
        $fixture = $this->stockFixture();
        $raw = Item::factory()->create(['unit_of_measure' => 'kg', 'item_type' => 'raw_material', 'safety_stock' => 0, 'minimum_order_quantity' => 0]);
        app(BomService::class)->create($fixture[1]->product_id, [['item_id' => $raw->id, 'quantity_per_unit' => 1, 'unit' => 'kg', 'waste_factor' => 0]]);
        $po = PurchaseOrder::factory()->create(['status' => 'acknowledged', 'purchase_request_id' => null]);
        $po->items()->create(['item_id' => $raw->id, 'description' => $raw->name, 'quantity' => 3, 'quantity_received' => 0, 'unit' => 'kg', 'unit_price' => 1, 'total' => 3]);
        $first = $this->stockPlan($fixture);
        $second = $this->stockPlan($fixture);
        $planner = app(StockProductionPlanningService::class);
        foreach ([[$first, $second], [$second, $first]] as $order) {
            foreach ($order as $work) $planner->reconcile($work, $fixture[4]);
            $requests = PurchaseRequest::whereIn('stock_production_work_order_id', [$first->id, $second->id])->where('status', '!=', 'cancelled')->get();
            $this->assertSame('3.000', bcadd((string) $requests->sum(fn ($request) => $request->items()->sum('quantity')), '0', 3));
        }
        $this->assertSame('0.000', app(OpenSupplyService::class)->openRequestBaseQuantity($raw->id, unplannedOnly: true, includeDraft: true));
        $this->assertSame('0.000', bcadd((string) StockLevel::where('item_id', $raw->id)->sum('quantity'), '0', 3));
    }

    public function test_safety_purchasing_ownership_and_minimum_order_multiples_are_preserved(): void
    {
        $fixture = $this->stockFixture();
        $raw = Item::factory()->create(['unit_of_measure' => 'kg', 'item_type' => 'raw_material', 'safety_stock' => 0, 'minimum_order_quantity' => 2]);
        app(BomService::class)->create($fixture[1]->product_id, [['item_id' => $raw->id, 'quantity_per_unit' => 1, 'unit' => 'kg', 'waste_factor' => 0]]);
        $root = $this->stockPlan($fixture);
        $request = PurchaseRequest::where('stock_production_work_order_id', $root->id)->firstOrFail();
        $this->assertSame('4.000', bcadd((string) $request->items()->sum('quantity'), '0', 3));
        $request->forceFill(['status' => 'approved'])->save();
        $po = PurchaseOrder::factory()->create(['purchase_request_id' => $request->id]);
        $po->forceFill(['status' => 'acknowledged'])->save();
        $po->items()->create(['item_id' => $raw->id, 'description' => $raw->name, 'purchase_request_item_id' => $request->items()->first()->id,
            'quantity' => 4, 'quantity_received' => 1, 'unit' => 'kg', 'unit_price' => 1, 'total' => 4]);
        $budget = app(OpenSupplyService::class)->stockPlanningSupply($raw->id, $root->id);
        $this->assertSame('3.000', $budget['owned_incoming']);
        $this->assertSame('0.000', $budget['uncommitted_incoming']);
        $other = $this->stockPlan($fixture);
        $this->assertSame('0.000', app(OpenSupplyService::class)->stockPlanningSupply($raw->id, $other->id)['available_to_root']);
        $this->assertSame('3.000000', app(OpenSupplyService::class)->inTransitBaseQuantity($raw->id));
        $this->assertSame('0.000', app(OpenSupplyService::class)->openRequestBaseQuantity($raw->id, unplannedOnly: true, includeDraft: true));
    }

    public function test_safety_progressed_procurement_blocks_atomic_stock_cancellation(): void
    {
        $fixture = $this->stockFixture();
        $raw = Item::factory()->create(['unit_of_measure' => 'kg', 'item_type' => 'raw_material', 'safety_stock' => 0]);
        app(BomService::class)->create($fixture[1]->product_id, [['item_id' => $raw->id, 'quantity_per_unit' => 1, 'unit' => 'kg', 'waste_factor' => 0]]);
        $root = $this->stockPlan($fixture);
        $request = PurchaseRequest::where('stock_production_work_order_id', $root->id)->firstOrFail();
        $request->forceFill(['status' => 'approved'])->save();
        try { app(WorkOrderService::class)->cancel($root, 'Replenishment no longer needed.'); $this->fail('Approved purchasing must block automatic cancellation.'); }
        catch (BusinessRuleException $error) { $this->assertStringContainsString($request->pr_number, $error->getMessage()); }
        $this->assertSame('planned', $root->fresh()->status->value);
        $this->assertSame('approved', $request->fresh()->status->value);
    }

    public function test_safety_product_readiness_names_setup_blockers_and_rechecks_create(): void
    {
        $fixture = $this->stockFixture();
        $planner = app(StockProductionPlanningService::class);
        $product = Product::findOrFail($fixture[1]->product_id);
        $this->assertFalse($planner->productReadiness($product)['eligible']);
        $raw = Item::factory()->create(['item_type' => 'raw_material', 'unit_of_measure' => 'kg']);
        app(BomService::class)->create($product->id, [['item_id' => $raw->id, 'quantity_per_unit' => 1, 'unit' => 'kg', 'waste_factor' => 0]]);
        $this->assertTrue($planner->productReadiness($product->fresh())['eligible']);
        $fixture[2]->update(['is_active' => false]);
        $this->assertFalse($planner->productReadiness($product->fresh())['eligible']);
        $this->expectException(BusinessRuleException::class);
        $this->stockPlan($fixture);
    }

    private function stockFixture(bool $secondBin = false, bool $olderLot = false, bool $prepareShipment = true): array
    {
        $actor = User::factory()->create();
        $checker = User::factory()->create();
        $customer = Customer::create(['name' => 'Dispatch provenance customer', 'is_active' => true]);
        $product = Product::factory()->create(['unit_of_measure' => 'pcs', 'standard_cost' => '4.50']);
        $so = SalesOrder::create([
            'so_number' => 'SO-DP-'.substr(uniqid(), -6), 'customer_id' => $customer->id,
            'date' => now()->toDateString(), 'subtotal' => '150.00', 'vat_amount' => '18.00',
            'total_amount' => '168.00', 'status' => 'confirmed', 'created_by' => $actor->id,
        ]);
        $soLine = SalesOrderItem::create([
            'sales_order_id' => $so->id, 'product_id' => $product->id, 'quantity' => '10.00',
            'unit_price' => '15.00', 'total' => '150.00', 'quantity_delivered' => '0.00',
            'delivery_date' => now()->addDay()->toDateString(),
        ]);
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-DP-'.substr(uniqid(), -6), 'product_id' => $product->id,
            'sales_order_id' => $so->id, 'sales_order_item_id' => $soLine->id,
            'quantity_target' => 10, 'quantity_good' => 10, 'quantity_produced' => 10,
            'planned_start' => now()->subDay(), 'planned_end' => now(),
            'status' => 'completed', 'created_by' => $actor->id,
        ]);
        $this->passInProcessQc($workOrder);
        $output = WorkOrderOutput::create([
            'work_order_id' => $workOrder->id, 'recorded_by' => $actor->id, 'recorded_at' => now(),
            'good_count' => 10, 'reject_count' => 0, 'batch_code' => 'APPROVED-BATCH',
        ]);
        $zone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
        $firstLocation = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $stockLocation = $secondBin
            ? WarehouseLocation::factory()->create(['zone_id' => $zone->id])
            : $firstLocation;
        $item = Item::factory()->create(['code' => $product->part_number, 'item_type' => 'finished_good']);
        StockLevel::factory()->create([
            'item_id' => $item->id, 'location_id' => $stockLocation->id,
            'quantity' => $olderLot ? '15.000' : '10.000', 'weighted_avg_cost' => '4.5000',
        ]);
        if ($secondBin) {
            StockLevel::factory()->create(['item_id' => $item->id, 'location_id' => $firstLocation->id]);
        }
        if ($olderLot) {
            StockMovement::create([
                'item_id' => $item->id, 'to_location_id' => $stockLocation->id,
                'movement_type' => 'opening', 'quantity' => '5.000', 'unit_cost' => '4.5000',
                'total_cost' => '22.50', 'lot_number' => 'UNAPPROVED-OLDER',
                'created_by' => $actor->id, 'created_at' => now()->subDay(),
            ]);
        }
        $receipt = StockMovement::create([
            'item_id' => $item->id, 'to_location_id' => $stockLocation->id,
            'movement_type' => 'production_receipt', 'quantity' => '10.000', 'unit_cost' => '4.5000',
            'total_cost' => '45.00', 'lot_number' => 'APPROVED-BATCH',
            'reference_type' => 'work_order_output', 'reference_id' => $output->id,
            'created_by' => $actor->id, 'created_at' => now(),
        ]);
        app(MovementGlPostingService::class)->postFor($receipt);
        $this->assertSame(MovementGlHandoffStatus::Generated, $receipt->fresh()->gl_handoff_status);
        $output->update([
            'production_receipt_movement_id' => $receipt->id,
            'production_receipt_handoff_status' => 'generated',
        ]);
        $inspection = Inspection::create([
            'inspection_number' => 'QC-DP-'.substr(uniqid(), -6), 'stage' => 'outgoing', 'status' => 'passed',
            'inspector_id' => $actor->id, 'reviewed_by' => $checker->id, 'reviewed_at' => now(),
            'product_id' => $product->id, 'entity_type' => 'work_order', 'entity_id' => $workOrder->id,
            'work_order_output_id' => $output->id, 'batch_quantity' => 10, 'accepted_quantity' => 10,
            'sample_size' => 1, 'accept_count' => 1, 'reject_count' => 0, 'defect_count' => 0,
            'completed_at' => now(),
        ]);
        $workOrder->forceFill(['production_purpose' => 'stock', 'sales_order_id' => null, 'sales_order_item_id' => null])->save();
        $spec = app(InspectionSpecService::class)->upsertForProduct($product->id, [[
            'parameter_name' => 'Length', 'unit' => 'mm', 'target_value' => 10, 'min_value' => 9, 'max_value' => 11,
        ]], $actor->id);
        $inspection->forceFill(['inspection_spec_id' => $spec->id, 'inspection_spec_revision_id' => $spec->currentRevision->id])->save();
        $soLine->update(['fulfillment_policy' => 'stock_first']);

        return [$so, $soLine, $item, $stockLocation, $actor, $inspection];
    }
}
