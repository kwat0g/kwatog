<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Services\AlertEngineService;
use App\Common\Services\NotificationService;
use App\Common\Services\TaxPolicyService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Listeners\CheckReorderPoint;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\AutoReplenishmentService;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Services\AutoPurchaseOrderService;
use App\Modules\Purchasing\Services\OpenSupplyService;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AutoReplenishmentOverlapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        $this->seed(WorkflowSeeder::class);
        $role = Role::create(['name' => 'System Admin', 'slug' => 'system_admin']);
        User::factory()->create(['role_id' => $role->id]);

        $alerts = Mockery::mock(AlertEngineService::class);
        $alerts->shouldReceive('raise')->zeroOrMoreTimes();
        $this->app->instance(AlertEngineService::class, $alerts);
        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('send')->zeroOrMoreTimes();
        $this->app->instance(NotificationService::class, $notifications);
        $tax = Mockery::mock(TaxPolicyService::class);
        $tax->shouldReceive('isVatRegistered')->andReturn(false);
        $this->app->instance(TaxPolicyService::class, $tax);
    }

    public function test_replayed_movement_does_not_raise_a_pr_behind_a_live_auto_po(): void
    {
        foreach ([
            PurchaseOrderStatus::Draft,
            PurchaseOrderStatus::PendingApproval,
            PurchaseOrderStatus::Sent,
            PurchaseOrderStatus::Acknowledged,
            PurchaseOrderStatus::PartiallyReceived,
        ] as $status) {
            $item = $this->item(true);
            $this->autoPo($item, $status, '12', $status === PurchaseOrderStatus::PartiallyReceived ? '3' : '0');
            if ($status === PurchaseOrderStatus::PartiallyReceived) {
                $this->stock($item, '3');
            }

            $event = new StockMovementCompleted(new StockMovement(['item_id' => $item->id]));
            app(CheckReorderPoint::class)->handle($event);
            app(CheckReorderPoint::class)->handle($event);

            $this->assertSame(0, PurchaseRequest::query()->whereHas('items', fn ($q) => $q->where('item_id', $item->id))->count(), $status->value);
            $this->assertSame(1, PurchaseOrder::query()->whereHas('items', fn ($q) => $q->where('item_id', $item->id))->count(), $status->value);
        }
    }

    public function test_critical_auto_po_does_not_duplicate_an_unplanned_draft_pr(): void
    {
        $item = $this->item(true);
        $this->draftPr($item, '12');

        $this->assertNull(app(AutoPurchaseOrderService::class)->createForCriticalShortage($item));
        $this->assertNull(app(AutoReplenishmentService::class)->checkAndReplenish($item->id));
        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame(1, PurchaseRequest::count());
    }

    public function test_critical_reorder_nets_an_mrp_draft_pr_before_creating_a_po(): void
    {
        $item = $this->item(true);
        $so = SalesOrder::factory()->create();
        $plan = MrpPlan::create([
            'mrp_plan_no' => 'MRP-T-'.substr(uniqid(), -5),
            'sales_order_id' => $so->id,
            'generated_by' => $so->created_by,
            'generated_at' => now(),
        ]);
        $this->draftPr($item, '12', $plan->id);

        $this->assertNull(app(AutoReplenishmentService::class)->checkAndReplenish($item->id));
        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame(1, PurchaseRequest::count());
    }

    public function test_insufficient_pending_auto_po_can_be_topped_up_without_a_fallback_pr(): void
    {
        $item = $this->item(true);
        $this->autoPo($item, PurchaseOrderStatus::PendingApproval, '2');

        $this->assertNull(app(AutoReplenishmentService::class)->checkAndReplenish($item->id));
        $this->assertSame(2, PurchaseOrder::count());
        $this->assertSame('10.00', (string) PurchaseOrder::orderByDesc('id')->first()->items()->first()->quantity);
        $this->assertSame(0, PurchaseRequest::count());
        $this->assertNull(app(AutoReplenishmentService::class)->checkAndReplenish($item->id));
        $this->assertSame(2, PurchaseOrder::count());
    }

    public function test_insufficient_draft_pr_allows_only_uncovered_replenishment(): void
    {
        $item = $this->item(false);
        $this->draftPr($item, '2');

        $pr = app(AutoReplenishmentService::class)->checkAndReplenish($item->id);
        $this->assertNotNull($pr);
        $this->assertSame('18.00', (string) $pr->items()->first()->quantity);
        $this->assertNull(app(AutoReplenishmentService::class)->checkAndReplenish($item->id));
        $this->assertSame(2, PurchaseRequest::count());
    }

    public function test_pending_auto_po_still_covers_stock_after_item_loses_critical_flag(): void
    {
        $item = $this->item(true);
        $this->autoPo($item, PurchaseOrderStatus::PendingApproval, '12');
        $item->update(['is_critical' => false]);

        $this->assertNull(app(AutoReplenishmentService::class)->checkAndReplenish($item->id));
        $this->assertSame(0, PurchaseRequest::count());
        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_cancelled_or_declined_auto_po_does_not_block_replenishment(): void
    {
        foreach ([PurchaseOrderStatus::Cancelled, PurchaseOrderStatus::SupplierDeclined] as $status) {
            $item = $this->item(true);
            $this->autoPo($item, $status, '12');

            $this->assertNull(app(AutoReplenishmentService::class)->checkAndReplenish($item->id));
            $this->assertSame(2, PurchaseOrder::query()->whereHas('items', fn ($q) => $q->where('item_id', $item->id))->count(), $status->value);
            $this->assertSame(0, PurchaseRequest::query()->whereHas('items', fn ($q) => $q->where('item_id', $item->id))->count());
        }
    }

    public function test_pending_auto_po_balance_subtracts_base_received_quantity_after_purchase_conversion(): void
    {
        $item = $this->item(true);
        $item->update(['unit_of_measure' => 'KG']);
        $kg = Uom::create(['code' => 'KG', 'name' => 'Kilogram']);
        $bag = Uom::create(['code' => 'BAG', 'name' => 'Bag']);
        ItemUomConversion::create(['item_id' => $item->id, 'from_uom_id' => $bag->id, 'to_uom_id' => $kg->id, 'factor' => '5.000000']);
        $this->autoPo($item, PurchaseOrderStatus::PendingApproval, '2', '5');
        PurchaseOrderItem::query()->where('item_id', $item->id)->update(['unit' => 'BAG']);

        $this->assertSame('5.000000', app(AutoPurchaseOrderService::class)->unapprovedBaseQuantity($item));
    }

    public function test_pending_qc_receipt_covers_both_replenishment_paths_after_po_is_fully_received(): void
    {
        foreach ([false, true] as $critical) {
            $item = $this->item($critical);
            $this->receipt($item, GrnStatus::PendingQc, '10.000', '0.000');

            $this->assertSame('0.000000', app(OpenSupplyService::class)->inTransitBaseQuantity($item->id));
            if ($critical) {
                $this->assertNull(app(AutoPurchaseOrderService::class)->createForCriticalShortage($item));
            }
            $this->assertNull(app(AutoReplenishmentService::class)->checkAndReplenish($item->id));
            $this->assertSame('10.000', app(OpenSupplyService::class)->heldQcBaseQuantity($item->id));
            $this->assertSame(0, PurchaseRequest::query()->whereHas('items', fn ($q) => $q->where('item_id', $item->id))->count());
            $this->assertSame(1, PurchaseOrder::query()->whereHas('items', fn ($q) => $q->where('item_id', $item->id))->count());
        }
    }

    public function test_partial_acceptance_nets_only_unaccepted_remainder_and_stops_after_rejecting_it(): void
    {
        $item = $this->item(true);
        [$po, $poLine, $grn] = $this->receipt($item, GrnStatus::PartialAccepted, '12.000', '3.000');
        $this->stock($item, '3.000');

        $this->assertNull(app(AutoPurchaseOrderService::class)->createForCriticalShortage($item));
        $this->assertNull(app(AutoReplenishmentService::class)->checkAndReplenish($item->id));
        $this->assertSame('9.000', app(OpenSupplyService::class)->heldQcBaseQuantity($item->id));
        $this->assertSame(1, PurchaseOrder::count());

        // rejectRemainder retains the partial_accepted status and the original
        // GRN quantities, but marks the remainder rejected and reverses PO receipt.
        $grn->forceFill(['remainder_rejected_at' => now()])->save();
        $poLine->update(['quantity_received' => '3.000']);
        $po->forceFill(['status' => PurchaseOrderStatus::Closed])->save();

        $this->assertSame('0.000', app(OpenSupplyService::class)->heldQcBaseQuantity($item->id));
        $this->assertNotNull(app(AutoPurchaseOrderService::class)->createForCriticalShortage($item));
        $this->assertSame(2, PurchaseOrder::count());
    }

    public function test_rejected_receipt_does_not_cover_a_new_reorder(): void
    {
        $item = $this->item(false);
        [$po, $poLine] = $this->receipt($item, GrnStatus::Rejected, '12.000', '0.000');
        $poLine->update(['quantity_received' => '0.000']);
        $po->forceFill(['status' => PurchaseOrderStatus::Closed])->save();

        $this->assertSame('0.000', app(OpenSupplyService::class)->heldQcBaseQuantity($item->id));
        $this->assertNotNull(app(AutoReplenishmentService::class)->checkAndReplenish($item->id));
        $this->assertSame(1, PurchaseRequest::count());
    }

    public function test_partially_received_po_counts_each_unit_once_between_transit_and_qc(): void
    {
        $item = $this->item(false);
        [$po] = $this->receipt($item, GrnStatus::PendingQc, '5.125', '0.000', '12.000');
        $po->forceFill(['status' => PurchaseOrderStatus::PartiallyReceived])->save();

        $this->assertSame('6.875000', app(OpenSupplyService::class)->inTransitBaseQuantity($item->id));
        $this->assertSame('5.125', app(OpenSupplyService::class)->heldQcBaseQuantity($item->id));
        $this->assertNull(app(AutoReplenishmentService::class)->checkAndReplenish($item->id));
        $this->assertSame(0, PurchaseRequest::count());
    }

    public function test_qc_held_receipt_below_reorder_only_tops_up_the_shortfall(): void
    {
        $item = $this->item(false);
        $this->receipt($item, GrnStatus::PendingQc, '4.000', '0.000');

        $pr = app(AutoReplenishmentService::class)->checkAndReplenish($item->id);
        $this->assertNotNull($pr);
        $this->assertSame('16.00', (string) $pr->items()->first()->quantity);
        $this->assertNull(app(AutoReplenishmentService::class)->checkAndReplenish($item->id));
    }

    public function test_held_qc_ignores_accepted_quantity_and_cancelled_or_deleted_orders(): void
    {
        $item = $this->item(false);
        $this->receipt($item, GrnStatus::Accepted, '12.000', '12.000');
        [$cancelledPo] = $this->receipt($item, GrnStatus::PendingQc, '12.000', '0.000');
        $cancelledPo->forceFill(['status' => PurchaseOrderStatus::Cancelled])->save();
        [$deletedPo] = $this->receipt($item, GrnStatus::PendingQc, '12.000', '0.000');
        $deletedPo->delete();

        $this->assertSame('0.000', app(OpenSupplyService::class)->heldQcBaseQuantity($item->id));
        $this->stock($item, '12.000');
        $this->assertNull(app(AutoReplenishmentService::class)->checkAndReplenish($item->id));
    }

    /** @return array{PurchaseOrder, PurchaseOrderItem, GoodsReceiptNote} */
    private function receipt(Item $item, GrnStatus $status, string $received, string $accepted, ?string $ordered = null): array
    {
        $po = PurchaseOrder::factory()->create();
        $po->forceFill(['status' => PurchaseOrderStatus::Received])->save();
        $poLine = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'quantity' => $ordered ?? $received,
            'quantity_received' => $received,
            'quantity_accepted' => $accepted,
            'unit' => $item->unit_of_measure,
            'unit_price' => '10.00',
            'total' => '120.00',
        ]);
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'status' => $status,
        ]);
        GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $poLine->id,
            'item_id' => $item->id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity_received' => $received,
            'quantity_accepted' => $accepted,
            'unit_cost' => '10.00',
        ]);

        return [$po, $poLine, $grn];
    }

    private function item(bool $critical): Item
    {
        $item = Item::factory()->create([
            'is_critical' => $critical,
            'reorder_point' => '10.000',
            'safety_stock' => '2.000',
            'standard_cost' => '10.00',
        ]);
        if ($critical) {
            ApprovedSupplier::create([
                'item_id' => $item->id,
                'vendor_id' => Vendor::factory()->create()->id,
                'is_preferred' => true,
                'last_price' => '10.00',
            ]);
        }

        return $item;
    }

    private function stock(Item $item, string $quantity): void
    {
        StockLevel::factory()->create([
            'item_id' => $item->id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity' => $quantity,
        ]);
    }

    private function autoPo(Item $item, PurchaseOrderStatus $status, string $quantity, string $received = '0'): void
    {
        $po = PurchaseOrder::factory()->create(['vendor_id' => $item->approvedSuppliers()->first()->vendor_id, 'is_auto_generated' => true]);
        $po->forceFill(['status' => $status])->save();
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'quantity' => $quantity,
            'quantity_received' => $received,
            'unit' => $item->unit_of_measure,
            'unit_price' => '10.00',
            'total' => '120.00',
        ]);
    }

    private function draftPr(Item $item, string $quantity, ?int $mrpPlanId = null): void
    {
        $pr = PurchaseRequest::factory()->create(['is_auto_generated' => true, 'mrp_plan_id' => $mrpPlanId]);
        PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'quantity' => $quantity,
            'unit' => $item->unit_of_measure,
            'estimated_unit_price' => '10.00',
        ]);
    }
}
