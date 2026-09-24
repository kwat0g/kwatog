<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\MrbStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Inventory\Services\QuarantineService;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Resources\NcrResource;
use App\Modules\Quality\Services\NcrService;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Material Review Board for failed incoming QC.
 *
 * With quality.incoming_failure.mrb_review on, a failed incoming inspection
 * does not reject the receipt by itself: the GRN waits in pending_qc until the
 * NCR disposition (the MRB decision) says how much of the lot enters stock.
 */
class IncomingMrbDispositionTest extends TestCase
{
    use RefreshDatabase;

    private User $receiver;
    private User $inspector;
    private User $checker;
    private User $mrb;
    private GrnService $grns;
    private NcrService $ncrs;
    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('quality.incoming_failure.mrb_review', true);

        $employeeRoleId = Role::query()->where('slug', 'employee')->value('id');
        $qcInspectorRoleId = Role::query()->where('slug', 'qc_inspector')->value('id');

        $this->receiver = User::factory()->create(['is_active' => true, 'role_id' => $employeeRoleId]);
        $this->inspector = User::factory()->create(['is_active' => true, 'role_id' => $employeeRoleId]);
        $this->checker = User::factory()->create(['is_active' => true, 'role_id' => $employeeRoleId]);
        $this->mrb = User::factory()->create([
            'is_active' => true,
            'role_id' => $qcInspectorRoleId,
        ]);
        $this->grns = app(GrnService::class);
        $this->ncrs = app(NcrService::class);

        $warehouse = Warehouse::factory()->create();
        $raw = WarehouseZone::factory()->create(['warehouse_id' => $warehouse->id, 'zone_type' => WarehouseZoneType::RawMaterials->value]);
        $quarantine = WarehouseZone::factory()->create(['warehouse_id' => $warehouse->id, 'zone_type' => WarehouseZoneType::Quarantine->value]);
        $this->location = WarehouseLocation::factory()->create(['zone_id' => $raw->id, 'is_active' => true]);
        WarehouseLocation::factory()->create(['zone_id' => $quarantine->id, 'is_active' => true]);
    }

    /** @param list<string> $quantities one GRN line per entry */
    private function receive(array $quantities): GoodsReceiptNote
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->receiver->id,
        ]);

        $lines = [];
        foreach ($quantities as $i => $qty) {
            $item = Item::factory()->create(['is_active' => true]);
            $poItem = PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'item_id' => $item->id,
                'description' => 'Material '.$i,
                'quantity' => $qty,
                'unit' => 'pcs',
                'unit_price' => '10.00',
                'total' => bcmul($qty, '10', 2),
                'quantity_received' => '0.000',
            ]);
            $lines[] = [
                'purchase_order_item_id' => $poItem->id,
                'item_id' => $item->id,
                'location_id' => $this->location->id,
                'quantity_received' => $qty,
                'unit_cost' => '10.00',
            ];
        }

        return $this->grns->create($po, $lines, ['received_date' => now()->toDateString()], $this->receiver);
    }

    private function inspectionFor(GoodsReceiptNote $grn, int $lineIndex): Inspection
    {
        $lineId = $grn->items->get($lineIndex)->id;

        return Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->where('grn_item_id', $lineId)
            ->firstOrFail();
    }

    private function failLine(GoodsReceiptNote $grn, int $lineIndex): NonConformanceReport
    {
        $inspection = $this->inspectionFor($grn, $lineIndex);
        $inspection->forceFill([
            'status' => 'failed',
            'inspector_id' => $this->inspector->id,
            'reviewed_by' => $this->checker->id,
            'reviewed_at' => now(),
        ])->save();

        return $this->ncrs->openFromInspectionFailure($inspection->fresh(), $this->inspector);
    }

    private function passLine(GoodsReceiptNote $grn, int $lineIndex): void
    {
        $this->inspectionFor($grn, $lineIndex)->forceFill([
            'status' => 'passed',
            'inspector_id' => $this->inspector->id,
            'reviewed_by' => $this->checker->id,
            'reviewed_at' => now(),
        ])->save();
    }

    private function rmaCount(GoodsReceiptNote $grn): int
    {
        return ReturnRequest::query()->where('source_key', 'grn-rejection:'.$grn->id)->count();
    }

    public function test_failed_lot_waits_for_the_mrb_instead_of_rejecting(): void
    {
        $grn = $this->receive(['100.000']);
        $this->failLine($grn, 0);

        $this->assertSame('awaiting_mrb', $this->grns->settleIncomingQc($grn->fresh(), $this->receiver));
        $this->assertSame(GrnStatus::PendingQc, $grn->fresh()->status);
        $this->assertSame(0, $this->rmaCount($grn));
    }

    public function test_manual_accept_is_blocked_until_the_mrb_decides(): void
    {
        $grn = $this->receive(['100.000']);
        $this->failLine($grn, 0);

        $this->expectException(BusinessRuleException::class);
        $this->grns->accept($grn->fresh(), $this->receiver);
    }

    public function test_return_to_supplier_rejects_the_lot_with_a_single_rma(): void
    {
        $grn = $this->receive(['100.000']);
        $ncr = $this->failLine($grn, 0);

        $this->ncrs->setDisposition($ncr, 'return_to_supplier', 'Wrong resin grade', null, $this->mrb);

        $grn = $grn->fresh();
        $this->assertSame(GrnStatus::Rejected, $grn->status);
        $this->assertSame(1, $this->rmaCount($grn));

        $ncr = $ncr->fresh();
        $this->assertSame($this->mrb->id, (int) $ncr->mrb_decided_by);
        $this->assertNotNull($ncr->mrb_decided_at);

        // Closing the NCR reuses the receipt's RMA rather than opening a second one.
        $this->ncrs->addAction($ncr, ['action_type' => 'corrective', 'description' => 'Supplier 8D requested'], $this->mrb);
        $this->ncrs->addAction($ncr->fresh(), ['action_type' => 'preventive', 'description' => 'Resin cert check at dock'], $this->mrb);
        $this->ncrs->close($ncr->fresh(), $this->mrb);
        $this->assertSame(1, $this->rmaCount($grn));
    }

    public function test_use_as_is_concession_by_another_user_accepts_the_lot(): void
    {
        $grn = $this->receive(['100.000']);
        $ncr = $this->failLine($grn, 0);

        $this->ncrs->setDisposition($ncr, 'use_as_is', 'Cosmetic only', null, $this->mrb);

        $grn = $grn->fresh()->load(['items' => fn ($items) => $items->orderBy('id')]);
        $this->assertSame(GrnStatus::Accepted, $grn->status);
        $this->assertSame('100.000', (string) $grn->items->first()->quantity_accepted);
        $this->assertSame(0, $this->rmaCount($grn));
    }

    public function test_the_inspector_cannot_grant_a_use_as_is_concession(): void
    {
        $grn = $this->receive(['100.000']);
        $ncr = $this->failLine($grn, 0);

        try {
            $this->ncrs->setDisposition($ncr, 'use_as_is', null, null, $this->inspector);
            $this->fail('The inspector who failed the lot granted its concession.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('concession', $e->getMessage());
        }

        $this->assertSame(GrnStatus::PendingQc, $grn->fresh()->status);
        $this->assertNull($ncr->fresh()->disposition);
    }

    public function test_sorting_keeps_the_good_pieces_and_returns_the_rest(): void
    {
        $grn = $this->receive(['100.000']);
        $ncr = $this->failLine($grn, 0);

        $this->ncrs->setDisposition($ncr, 'return_to_supplier', 'Flash on 30 pcs', null, $this->mrb, '70');

        $grn = $grn->fresh()->load('items', 'purchaseOrder.items');
        $this->assertSame(GrnStatus::PartialAccepted, $grn->status);
        $this->assertNotNull($grn->remainder_rejected_at);
        $this->assertSame('70.000', (string) $grn->items->first()->quantity_accepted);
        $this->assertSame('70.000', (string) $grn->purchaseOrder->items->first()->quantity_accepted);

        $rma = ReturnRequest::query()->where('source_key', 'grn-rejection:'.$grn->id)->firstOrFail();
        $this->assertSame('30.000', (string) $rma->items->first()->quantity);
    }

    public function test_mrb_decision_is_final_once_the_receipt_is_settled(): void
    {
        $grn = $this->receive(['100.000']);
        $ncr = $this->failLine($grn, 0);
        $this->ncrs->setDisposition($ncr, 'return_to_supplier', 'Flash on 30 pcs', null, $this->mrb, '70');

        // Root cause / corrective action can still be completed later...
        $this->ncrs->setDisposition($ncr->fresh(), 'return_to_supplier', 'Worn gate insert', 'Supplier 8D', $this->mrb);
        $this->assertSame('Worn gate insert', $ncr->fresh()->root_cause);

        // ...but the disposition the receipt was settled against cannot change.
        try {
            $this->ncrs->setDisposition($ncr->fresh(), 'use_as_is', null, null, $this->mrb);
            $this->fail('A settled MRB decision was re-dispositioned.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('final', $e->getMessage());
        }
        $this->assertSame('return_to_supplier', $ncr->fresh()->disposition?->value ?? $ncr->fresh()->disposition);
        $this->assertSame('70.000', (string) $grn->fresh()->items->first()->quantity_accepted);
    }

    public function test_sorted_quantity_must_be_fewer_than_received(): void
    {
        $grn = $this->receive(['100.000']);
        $ncr = $this->failLine($grn, 0);

        $this->expectException(BusinessRuleException::class);
        $this->ncrs->setDisposition($ncr, 'return_to_supplier', null, null, $this->mrb, '100');
    }

    public function test_rework_accepts_the_lot_into_quarantine(): void
    {
        $grn = $this->receive(['100.000']);
        $ncr = $this->failLine($grn, 0);

        $this->ncrs->setDisposition($ncr, 'rework', 'Deburr gate', null, $this->mrb);

        $grn = $grn->fresh()->load(['items' => fn ($items) => $items->orderBy('id')]);
        $this->assertSame(GrnStatus::Accepted, $grn->status);

        $hold = MaterialReviewRecord::query()->where('ncr_id', $ncr->id)->firstOrFail();
        $this->assertSame(MrbStatus::Held, $hold->status);
        $this->assertSame('100.000', (string) $hold->quantity);
        $this->assertSame((int) $grn->items->first()->item_id, (int) $hold->item_id);
    }

    public function test_rework_quarantines_the_received_lot_not_older_stock_in_the_bin(): void
    {
        $grn = $this->receive(['100.000']);
        $line = $grn->items->first();
        $line->forceFill(['material_lot_number' => 'LOT-NEW'])->save();

        // An earlier receipt of the same item already sits in the bin under
        // another lot, so it is the bin's preferred (FIFO) lot.
        $movements = app(StockMovementService::class);
        $movements->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: (int) $line->item_id,
            quantity: '40.000',
            toLocationId: $this->location->id,
            unitCost: '10.00',
            createdBy: $this->receiver->id,
            lotNumber: 'LOT-OLD',
        ));

        $ncr = $this->failLine($grn, 0);
        $this->ncrs->setDisposition($ncr, 'rework', 'Deburr gate', null, $this->mrb);

        $hold = MaterialReviewRecord::query()->where('ncr_id', $ncr->id)->firstOrFail();
        $this->assertSame(MrbStatus::Held, $hold->status);
        $this->assertSame('LOT-NEW', StockMovement::query()->findOrFail($hold->hold_movement_id)->lot_number);

        $lots = app(StockLocationSummaryService::class);
        $itemId = (int) $line->item_id;
        $this->assertSame('40.000', $lots->lotQuantity($itemId, $this->location->id, 'LOT-OLD'));
        $this->assertSame('0.000', $lots->lotQuantity($itemId, $this->location->id, 'LOT-NEW'));
        $this->assertSame('100.000', $lots->lotQuantity($itemId, (int) $hold->quarantine_location_id, 'LOT-NEW'));

        $released = app(QuarantineService::class)->release($hold, 'rework', $this->mrb, $this->location->id);
        $this->assertSame('LOT-NEW', StockMovement::query()->findOrFail($released->release_movement_id)->lot_number);
        $this->assertSame('100.000', $lots->lotQuantity($itemId, $this->location->id, 'LOT-NEW'));
        $this->assertSame('40.000', $lots->lotQuantity($itemId, $this->location->id, 'LOT-OLD'));
    }

    public function test_mixed_receipt_waits_for_the_failed_line_then_settles_both(): void
    {
        $grn = $this->receive(['100.000', '50.000']);
        $ncr = $this->failLine($grn, 0);
        $this->passLine($grn, 1);

        $this->assertSame('awaiting_mrb', $this->grns->settleIncomingQc($grn->fresh(), $this->receiver));
        $this->assertSame(GrnStatus::PendingQc, $grn->fresh()->status);

        $this->ncrs->setDisposition($ncr, 'use_as_is', null, null, $this->mrb);

        $grn = $grn->fresh()->load(['items' => fn ($items) => $items->orderBy('id')]);
        $this->assertSame(GrnStatus::Accepted, $grn->status);
        $this->assertSame('100.000', (string) $grn->items->get(0)->quantity_accepted);
        $this->assertSame('50.000', (string) $grn->items->get(1)->quantity_accepted);
    }

    public function test_mixed_receipt_returning_the_failed_line_keeps_the_good_line(): void
    {
        $grn = $this->receive(['100.000', '50.000']);
        $ncr = $this->failLine($grn, 0);
        $this->passLine($grn, 1);

        $this->ncrs->setDisposition($ncr, 'return_to_supplier', null, null, $this->mrb);

        $grn = $grn->fresh()->load(['items' => fn ($items) => $items->orderBy('id')]);
        $this->assertSame(GrnStatus::PartialAccepted, $grn->status);
        $this->assertSame('0.000', (string) $grn->items->get(0)->quantity_accepted);
        $this->assertSame('50.000', (string) $grn->items->get(1)->quantity_accepted);
        $this->assertSame(1, $this->rmaCount($grn));
    }

    public function test_mrb_off_keeps_the_legacy_auto_reject(): void
    {
        app(SettingsService::class)->set('quality.incoming_failure.mrb_review', false);
        $grn = $this->receive(['100.000']);
        $ncr = $this->failLine($grn, 0);

        $this->assertSame('grn_rejected', $this->grns->settleIncomingQc($grn->fresh(), $this->receiver));
        $this->assertFalse($this->isIncomingMrb($ncr));

        // With the receipt already settled the disposition is a record only.
        $this->ncrs->setDisposition($ncr, 'use_as_is', null, null, $this->mrb);
        $this->assertSame(GrnStatus::Rejected, $grn->fresh()->status);
        $this->assertNull($ncr->fresh()->mrb_decided_at);
    }

    public function test_resource_offers_the_mrb_decision_only_while_the_receipt_waits(): void
    {
        $grn = $this->receive(['100.000']);
        $ncr = $this->failLine($grn, 0);
        $this->grns->settleIncomingQc($grn->fresh(), $this->receiver);

        $this->assertTrue($this->isIncomingMrb($ncr));

        $this->ncrs->setDisposition($ncr, 'return_to_supplier', 'Wrong resin grade', null, $this->mrb);
        $this->assertFalse($this->isIncomingMrb($ncr));
    }

    private function isIncomingMrb(NonConformanceReport $ncr): bool
    {
        return (new NcrResource($this->ncrs->show($ncr->fresh())))->resolve()['is_incoming_mrb'];
    }

    public function test_mrb_resource_includes_ncr_disposition_label_after_rework_decision(): void
    {
        $grn = $this->receive(['100.000']);
        $ncr = $this->failLine($grn, 0);
        $this->ncrs->setDisposition($ncr, 'rework', 'Deburr gate', null, $this->mrb);

        $hold = MaterialReviewRecord::query()->where('ncr_id', $ncr->id)->firstOrFail();

        $response = $this->actingAs($this->mrb)->getJson("/api/v1/inventory/mrb/{$hold->hash_id}");
        $response->assertOk();
        $this->assertSame('Rework to spec', $response->json('data.ncr.disposition_label'));
    }
}
