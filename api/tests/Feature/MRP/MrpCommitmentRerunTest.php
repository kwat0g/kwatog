<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\MaterialIssueService;
use App\Modules\Inventory\Services\MaterialReturnService;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Resources\MrpPlanResource;
use App\Modules\MRP\Services\MrpEngineService;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MrpCommitmentRerunTest extends TestCase
{
    use RefreshDatabase;

    private MrpEngineService $engine;

    private Item $item;

    private Product $product;

    private StockLevel $stock;

    private SalesOrder $so;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([\App\Modules\MRP\Events\MrpPlanGenerated::class]);
        $this->engine = app(MrpEngineService::class);
        $this->product = Product::factory()->create(['part_number' => 'MRP-COMMIT-01']);
        $this->item = Item::factory()->create(['code' => 'RM-COMMIT-01', 'standard_cost' => '5.00', 'minimum_order_quantity' => '0.000']);
        $location = WarehouseLocation::factory()->create();
        $bom = Bom::create(['product_id' => $this->product->id, 'version' => 1, 'is_active' => true]);
        BomItem::create(['bom_id' => $bom->id, 'item_id' => $this->item->id, 'quantity_per_unit' => '1.0000', 'unit' => $this->item->unit_of_measure, 'waste_factor' => '0.00', 'sort_order' => 0]);
        $this->stock = StockLevel::create(['item_id' => $this->item->id, 'location_id' => $location->id, 'quantity' => '0.000', 'reserved_quantity' => '0.000', 'weighted_avg_cost' => '5.0000', 'lock_version' => 0]);
        $this->so = $this->order('01');
    }

    private function order(string $suffix): SalesOrder
    {
        $customerId = DB::table('customers')->insertGetId(['name' => 'MRP Commitment '.$suffix, 'is_active' => true, 'payment_terms_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
        $so = SalesOrder::create(['so_number' => 'SO-CMT-'.$suffix, 'customer_id' => $customerId, 'date' => now()->toDateString(), 'subtotal' => '100.00', 'vat_amount' => '0.00', 'total_amount' => '100.00', 'status' => 'confirmed', 'payment_terms_days' => 30, 'created_by' => User::factory()->create()->id]);
        $so->items()->create(['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => '10.00', 'total' => '100.00', 'delivery_date' => now()->addDays(30)->toDateString()]);

        return $so;
    }

    private function orderForOriginalPr(PurchaseOrderStatus $status, string $received = '0.000', string $quantity = '10.000'): PurchaseOrder
    {
        $pr = PurchaseRequest::whereHas('mrpPlan', fn ($q) => $q->where('sales_order_id', $this->so->id))->firstOrFail();
        $pr->forceFill(['status' => PurchaseRequestStatus::Converted])->save();
        $po = PurchaseOrder::create(['po_number' => 'PO-CMT-01', 'purchase_request_id' => $pr->id, 'vendor_id' => Vendor::factory()->create()->id, 'date' => now()->toDateString()]);
        $po->forceFill(['status' => $status])->save();
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'purchase_request_item_id' => $pr->items()->value('id'), 'item_id' => $this->item->id, 'description' => $this->item->name, 'quantity' => $quantity, 'quantity_received' => $received, 'unit' => $this->item->unit_of_measure, 'unit_price' => '5.00', 'total' => '50.00']);

        return $po;
    }

    public function test_draft_po_keeps_original_so_supply_committed_without_a_second_pr(): void
    {
        $this->engine->runForSalesOrder($this->so);
        $this->orderForOriginalPr(PurchaseOrderStatus::Draft);

        $plan = $this->engine->runForSalesOrder($this->so->fresh());

        $this->assertSame(0, $plan->auto_pr_count);
        $this->assertSame('awaiting_po_approval', collect($plan->diagnostics)->firstWhere('item_id', $this->item->id)['action']);
        $this->assertSame(1, PurchaseRequest::where('is_auto_generated', true)->count());
    }

    public function test_received_but_not_yet_accepted_is_a_hold_not_a_new_procurement_request(): void
    {
        $this->engine->runForSalesOrder($this->so);
        $po = $this->orderForOriginalPr(PurchaseOrderStatus::PartiallyReceived, '10.000');
        $grn = GoodsReceiptNote::create(['grn_number' => 'GRN-CMT-01', 'purchase_order_id' => $po->id, 'vendor_id' => $po->vendor_id, 'received_date' => now()->toDateString(), 'received_by' => $this->so->created_by, 'status' => 'pending_qc']);
        GrnItem::create(['goods_receipt_note_id' => $grn->id, 'purchase_order_item_id' => $po->items()->value('id'), 'item_id' => $this->item->id, 'location_id' => $this->stock->location_id, 'quantity_received' => '10.000', 'quantity_accepted' => '0.000', 'unit_cost' => '5.00']);

        $plan = $this->engine->runForSalesOrder($this->so->fresh());

        $this->assertSame(0, $plan->auto_pr_count);
        $this->assertSame('awaiting_qc', collect($plan->diagnostics)->firstWhere('item_id', $this->item->id)['action']);
    }

    public function test_issued_material_and_good_output_are_not_purchased_or_produced_twice(): void
    {
        $this->engine->runForSalesOrder($this->so);
        $this->orderForOriginalPr(PurchaseOrderStatus::Received, '10.000');
        $wo = WorkOrder::where('sales_order_item_id', $this->so->items()->value('id'))->whereNull('parent_wo_id')->firstOrFail();
        $wo->materials()->update(['actual_quantity_issued' => '10.000']);
        $wo->forceFill(['status' => WorkOrderStatus::InProgress])->save();

        $runningPlan = $this->engine->runForSalesOrder($this->so->fresh());
        $this->assertSame(0, $runningPlan->auto_pr_count);
        $this->assertCount(1, (new MrpPlanResource($runningPlan))->toArray(request())['work_orders']);
        $this->assertSame(1, WorkOrder::whereNull('parent_wo_id')->where('sales_order_id', $this->so->id)->count());

        $wo->forceFill(['status' => WorkOrderStatus::Completed, 'quantity_produced' => 10, 'quantity_good' => 10])->save();
        $finishedPlan = $this->engine->runForSalesOrder($this->so->fresh());
        $this->assertSame(0, $finishedPlan->auto_pr_count);
        $this->assertSame('production_already_committed', collect($finishedPlan->diagnostics)->first()['type']);
        $this->assertCount(1, (new MrpPlanResource($finishedPlan))->toArray(request())['work_orders']);
        $this->assertSame(1, WorkOrder::whereNull('parent_wo_id')->where('sales_order_id', $this->so->id)->count());
    }

    public function test_partial_good_output_and_rejects_leave_only_true_replacement_demand(): void
    {
        $this->engine->runForSalesOrder($this->so);
        $this->orderForOriginalPr(PurchaseOrderStatus::Received, '10.000');
        $wo = WorkOrder::where('sales_order_item_id', $this->so->items()->value('id'))->whereNull('parent_wo_id')->firstOrFail();
        $wo->forceFill(['status' => WorkOrderStatus::Completed, 'quantity_produced' => 10, 'quantity_good' => 8, 'quantity_rejected' => 2])->save();

        $plan = $this->engine->runForSalesOrder($this->so->fresh());

        $this->assertEqualsWithDelta(2.0, (float) PurchaseRequest::where('mrp_plan_id', $plan->id)->firstOrFail()->items()->firstOrFail()->quantity, 0.001);
        $this->assertSame(2, (int) WorkOrder::whereNull('parent_wo_id')->where('sales_order_id', $this->so->id)->latest('id')->firstOrFail()->quantity_target);
    }

    public function test_single_order_run_does_not_claim_another_orders_linked_po(): void
    {
        $this->engine->runForSalesOrder($this->so);
        $this->orderForOriginalPr(PurchaseOrderStatus::Sent);
        $other = $this->order('02');

        $plan = $this->engine->runForSalesOrder($other);

        $this->assertSame(1, $plan->auto_pr_count);
    }

    public function test_failed_outgoing_qc_reopens_production_need_without_counting_rejected_output_as_good(): void
    {
        $this->engine->runForSalesOrder($this->so);
        $wo = WorkOrder::where('sales_order_item_id', $this->so->items()->value('id'))->firstOrFail();
        $wo->forceFill(['status' => WorkOrderStatus::Completed, 'quantity_produced' => 10, 'quantity_good' => 10])->save();
        $output = WorkOrderOutput::create(['work_order_id' => $wo->id, 'recorded_by' => $this->so->created_by, 'recorded_at' => now(), 'good_count' => 10, 'reject_count' => 0]);
        Inspection::create(['inspection_number' => 'QC-CMT-01', 'stage' => 'outgoing', 'status' => 'failed', 'product_id' => $this->product->id, 'entity_type' => 'work_order', 'entity_id' => $wo->id, 'work_order_output_id' => $output->id, 'batch_quantity' => 10, 'sample_size' => 10]);

        $plan = $this->engine->runForSalesOrder($this->so->fresh());

        $this->assertSame(1, $plan->draft_wo_count);
        $this->assertSame(10, (int) WorkOrder::where('sales_order_item_id', $this->so->items()->value('id'))->latest('id')->firstOrFail()->quantity_target);
    }

    public function test_reserved_material_on_the_so_work_order_is_not_requested_again(): void
    {
        $this->stock->update(['quantity' => '10.000', 'reserved_quantity' => '10.000']);
        $this->engine->runForSalesOrder($this->so);
        $wo = WorkOrder::where('sales_order_item_id', $this->so->items()->value('id'))->firstOrFail();
        $wo->forceFill(['status' => WorkOrderStatus::Confirmed])->save();
        DB::table('material_reservations')->insert(['item_id' => $this->item->id, 'location_id' => $this->stock->location_id, 'work_order_id' => $wo->id, 'quantity' => '10.000', 'status' => 'reserved', 'reserved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $plan = $this->engine->runForSalesOrder($this->so->fresh());

        $this->assertSame(0, $plan->auto_pr_count);
    }

    public function test_unplanned_reorder_draft_covers_shortage_while_awaiting_submission(): void
    {
        $pr = PurchaseRequest::create(['pr_number' => 'PR-CMT-01', 'requested_by' => $this->so->created_by, 'date' => now()->toDateString(), 'reason' => 'Reorder point', 'priority' => 'normal', 'is_auto_generated' => true]);
        PurchaseRequestItem::create(['purchase_request_id' => $pr->id, 'item_id' => $this->item->id, 'description' => $this->item->name, 'quantity' => '10.000', 'unit' => $this->item->unit_of_measure, 'estimated_unit_price' => '5.00']);

        $plan = $this->engine->runForSalesOrder($this->so);

        $this->assertSame(0, $plan->auto_pr_count);
    }

    public function test_standalone_auto_po_awaiting_approval_covers_the_shortage(): void
    {
        $po = PurchaseOrder::create(['po_number' => 'PO-CMT-02', 'vendor_id' => Vendor::factory()->create()->id, 'date' => now()->toDateString(), 'is_auto_generated' => true]);
        $po->forceFill(['status' => PurchaseOrderStatus::PendingApproval])->save();
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'item_id' => $this->item->id, 'description' => $this->item->name, 'quantity' => '10.000', 'quantity_received' => '0.000', 'unit' => $this->item->unit_of_measure, 'unit_price' => '5.00', 'total' => '50.00']);

        $plan = $this->engine->runForSalesOrder($this->so);

        $this->assertSame(0, $plan->auto_pr_count);
    }

    public function test_cancelled_paused_wo_does_not_erase_its_existing_good_output(): void
    {
        $this->engine->runForSalesOrder($this->so);
        $wo = WorkOrder::where('sales_order_item_id', $this->so->items()->value('id'))->firstOrFail();
        // A paused WO may be cancelled after recording some good output.
        $wo->forceFill(['status' => WorkOrderStatus::Cancelled, 'quantity_produced' => 4, 'quantity_good' => 4])->save();

        $plan = $this->engine->runForSalesOrder($this->so->fresh());

        $this->assertEqualsWithDelta(6.0, (float) PurchaseRequest::where('mrp_plan_id', $plan->id)->firstOrFail()->items()->firstOrFail()->quantity, 0.001);
    }

    public function test_partial_active_output_requires_material_for_rejects_without_an_extra_work_order(): void
    {
        $this->engine->runForSalesOrder($this->so);
        $this->orderForOriginalPr(PurchaseOrderStatus::Received, '10.000');
        $wo = WorkOrder::where('sales_order_item_id', $this->so->items()->value('id'))->firstOrFail();
        $wo->materials()->update(['actual_quantity_issued' => '10.000']);
        $wo->forceFill(['status' => WorkOrderStatus::InProgress, 'quantity_produced' => 5, 'quantity_good' => 4, 'quantity_rejected' => 1])->save();

        $plan = $this->engine->runForSalesOrder($this->so->fresh());

        $this->assertEqualsWithDelta(1.0, (float) PurchaseRequest::where('mrp_plan_id', $plan->id)->firstOrFail()->items()->firstOrFail()->quantity, 0.001);
        // The running WO still owes six GOOD pieces, including the rejected
        // unit. MRP replaces its material shortfall without duplicating that
        // production commitment in a second work order.
        $this->assertSame(0, WorkOrder::where('mrp_plan_id', $plan->id)->whereNull('parent_wo_id')->count());
        $this->assertSame(10, (int) $wo->fresh()->quantity_target);
    }

    public function test_manual_material_returns_reduce_mrp_commitments_and_allow_replenishment_of_the_gap(): void
    {
        $this->so->items()->firstOrFail()->update(['quantity' => 20]);
        $firstPlan = $this->engine->runForSalesOrder($this->so->fresh());
        $purchaseRequest = PurchaseRequest::query()->where('mrp_plan_id', $firstPlan->id)->firstOrFail();
        $purchaseRequest->forceFill(['status' => PurchaseRequestStatus::Cancelled->value])->save();
        $workOrder = WorkOrder::query()
            ->where('sales_order_item_id', $this->so->items()->value('id'))
            ->whereNull('parent_wo_id')
            ->firstOrFail();
        $workOrder->forceFill(['status' => WorkOrderStatus::Confirmed->value])->save();

        StockMovement::create([
            'item_id' => $this->item->id,
            'from_location_id' => null,
            'to_location_id' => $this->stock->location_id,
            'movement_type' => StockMovementType::Opening->value,
            'quantity' => '10.000',
            'unit_cost' => '5.0000',
            'total_cost' => '50.00',
            'created_by' => $this->so->created_by,
            'created_at' => now()->subMinute(),
        ]);
        $this->stock->update(['quantity' => '10.000', 'weighted_avg_cost' => '5.0000']);
        $slip = app(MaterialIssueService::class)->create([
            'work_order_id' => $workOrder->hash_id,
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->hash_id,
                'location_id' => $this->stock->location_id,
                'quantity_issued' => '10.000',
            ]],
        ], User::query()->findOrFail($this->so->created_by));
        $source = $slip->items()->firstOrFail()->stockMovement;
        app(MaterialReturnService::class)->returnUnused($source, [
            'quantity_returned' => '5.000',
            'expected_returned_quantity' => '0.000',
            'reason' => 'Return manual material not needed yet',
            'idempotency_key' => 'mrp-manual-return-netting-001',
        ], User::query()->findOrFail($this->so->created_by));

        $plan = $this->engine->runForSalesOrder($this->so->fresh());
        $diagnostic = collect($plan->diagnostics)->firstWhere('item_id', $this->item->id);

        $this->assertEqualsWithDelta(5.0, (float) $diagnostic['work_order_material'], 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $diagnostic['net'], 0.001);
        $this->assertSame(1, $plan->auto_pr_count);
        $this->assertSame('10.00', (string) PurchaseRequest::query()
            ->where('mrp_plan_id', $plan->id)
            ->firstOrFail()
            ->items()
            ->firstOrFail()
            ->quantity);
        $this->assertSame('0.000', (string) $workOrder->materials()->value('actual_quantity_issued'));
    }
}
