<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Models\SupplierShipment;
use App\Modules\B2B\Policies\SupplierPoCapabilities;
use App\Modules\B2B\Resources\SupplierPurchaseOrderResource;
use App\Modules\B2B\Resources\SupplierShipmentResource;
use App\Modules\B2B\Services\SupplierPortalService;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\MrbStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Inventory\Services\QuarantineService;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Resources\NcrResource;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\NcrService;
use App\Modules\Quality\Services\QualityStockHoldService;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
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

    private User $mrbReviewer;

    private GrnService $grns;

    private NcrService $ncrs;

    private WarehouseLocation $location;

    private function sortingProof(): array
    {
        return ['inspected_quantity' => '100', 'evidence_reference' => 'SORT-100-GOOD-LOT',
            'performed_by' => $this->mrbReviewer->id, 'recovery_passed' => true,
            'concession_approval_reference' => 'CUSTOMER-APPROVAL-COSMETIC-100'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);
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
        $this->mrbReviewer = User::factory()->create([
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
    private function receive(array $quantities, ?string $unit = null): GoodsReceiptNote
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->receiver->id,
        ]);

        $lines = [];
        foreach ($quantities as $i => $qty) {
            $item = Item::factory()->create(['is_active' => true, ...($unit ? ['unit_of_measure' => $unit] : [])]);
            $poItem = PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'item_id' => $item->id,
                'description' => 'Material '.$i,
                'quantity' => $qty,
                'unit' => $unit ?? 'pcs',
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

    public function test_checklist_bulk_failure_keeps_full_lot_held_and_sorted_release_uses_kg(): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $grn = $this->receive(['125.500'], 'KG');
        $inspection = $this->inspectionFor($grn, 0)->load('measurements');
        $this->assertSame('lot', $inspection->sampling_basis);
        $this->assertSame('125.500', $inspection->source_quantity);
        $items = $inspection->measurements->map(fn ($row, $index) => ['id' => $row->id,
            'result' => $index === 0 ? 'failed' : 'passed', 'problem_description' => $index === 0 ? 'Contaminated material segregated.' : null])->all();
        $service = app(InspectionService::class);
        $submitted = $service->recordBatchResult($inspection, ['items' => $items, 'expected_result_version' => 0,
            'coverage_confirmed' => true, 'physical_defects_confirmed' => true], $this->mrb);
        $failed = $service->review($submitted, 'failed', 'Confirmed contamination; MRB required.', $this->mrbReviewer, 1);
        $this->assertSame(1, $failed->sample_defect_count);
        $this->assertSame('awaiting_mrb', $this->grns->settleIncomingQc($grn->fresh(), $this->receiver));
        $this->assertSame('0.000', $grn->fresh()->items->first()->quantity_accepted);
        $ncr = NonConformanceReport::where('inspection_id', $failed->id)->firstOrFail();
        $sorter = User::factory()->create(['role_id' => $this->mrb->role_id, 'is_active' => true]);
        $this->ncrs->setDisposition($ncr, 'return_to_supplier', 'Segregated 0.500 KG contamination.', null,
            $sorter, '125.000', ['inspected_quantity' => '125.500', 'evidence_reference' => 'SORT-BULK-125500',
                'performed_by' => $this->mrbReviewer->id, 'recovery_passed' => true]);
        $this->assertSame('125.000', $grn->fresh()->items->first()->quantity_accepted);
        $rma = ReturnRequest::where('source_key', 'grn-rejection:'.$grn->id)->firstOrFail();
        $this->assertSame('0.500', $rma->items->first()->quantity);
        $this->assertSame('failed', $failed->fresh()->status->value);
    }

    public function test_checklist_bulk_pass_review_accepts_exact_receipt_quantity(): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $grn = $this->receive(['125.500'], 'KG');
        $inspection = $this->inspectionFor($grn, 0)->load('measurements');
        $service = app(InspectionService::class);
        $submitted = $service->recordBatchResult($inspection, ['items' => $inspection->measurements->map(fn ($row) => [
            'id' => $row->id, 'result' => 'passed'])->all(), 'expected_result_version' => 0, 'coverage_confirmed' => true], $this->mrb);
        $this->assertSame(GrnStatus::PendingQc, $grn->fresh()->status);
        $service->review($submitted, 'passed', null, $this->mrbReviewer, 1);
        $this->grns->settleIncomingQc($grn->fresh(), $this->receiver);
        $this->assertSame('125.500', $grn->fresh()->items->first()->quantity_accepted);
        $this->assertSame(GrnStatus::Accepted, $grn->fresh()->status);
        $this->grns->settleIncomingQc($grn->fresh(), $this->receiver);
        $this->assertSame('125.500', $grn->fresh()->items->first()->quantity_accepted);
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

    public function test_piece_mrb_rejects_fractional_good_quantity(): void
    {
        $grn = $this->receive(['100.000'], 'pcs');
        $ncr = $this->failLine($grn, 0);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('whole');
        $this->ncrs->setDisposition($ncr, 'return_to_supplier', null, null, $this->mrb, '98.5', $this->sortingProof());
    }

    public function test_pending_receipt_cannot_choose_local_scrap(): void
    {
        $grn = $this->receive(['100.000'], 'pcs');
        $ncr = $this->failLine($grn, 0);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Return to supplier');
        $this->ncrs->setDisposition($ncr, 'scrap', null, null, $this->mrb, '99', $this->sortingProof());
    }

    public function test_cancelled_incoming_retry_creates_active_replacement(): void
    {
        $grn = $this->receive(['100.000'], 'pcs');
        $inspection = $this->inspectionFor($grn, 0);
        app(InspectionService::class)->cancel($inspection, 'Replace cancelled inspection', $this->inspector);
        $retried = $this->grns->retryIncomingQcHandoff($grn->fresh());
        $this->assertSame('generated', $retried->incoming_qc_handoff_status->value);
        $this->assertSame(1, Inspection::query()->where('grn_item_id', $inspection->grn_item_id)->where('status', '!=', 'cancelled')->count());
        $this->assertNotSame($inspection->id, $retried->qc_inspection_id);
    }

    public function test_late_accepted_receipt_ncr_automatically_holds_remaining_material(): void
    {
        $grn = $this->receive(['100.000'], 'pcs');
        $this->passLine($grn, 0);
        $grn = $this->grns->accept($grn->fresh(), $this->receiver);
        $inspection = $this->inspectionFor($grn, 0);
        $line = $grn->items->first();
        $ncr = $this->ncrs->create(['source' => 'inspection_fail', 'severity' => 'high',
            'inspection_id' => $inspection->id, 'affected_quantity' => 1, 'defect_description' => 'Late suspect material'], $this->inspector);
        $levels = StockLevel::query()->where('item_id', $line->item_id)->get();
        $availability = app(StockLocationSummaryService::class)->availability($levels);
        $this->assertSame('0.000', $availability['usable_quantity']);
        $this->assertDatabaseHas('material_review_records', ['ncr_id' => $ncr->id, 'status' => 'held', 'quantity' => '100.000']);
        $this->assertSame(1, (int) $ncr->affected_quantity);
        $this->assertSame('100.000', $ncr->late_hold_quantity);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Resolve its holds');
        $this->ncrs->cancel($ncr, 'Cancel suspect stock', $this->mrb);
    }

    public function test_released_partial_hold_does_not_free_unreviewed_bin(): void
    {
        $grn = $this->receive(['100.000'], 'PCS');
        $this->passLine($grn, 0);
        $grn = $this->grns->accept($grn->fresh(), $this->receiver);
        $line = $grn->items->first();
        $inspection = $this->inspectionFor($grn, 0);
        $ncr = NonConformanceReport::create(['ncr_number' => 'NCR-PARTIAL-HOLD', 'source' => 'inspection_fail',
            'severity' => 'high', 'status' => 'open', 'inspection_id' => $inspection->id,
            'affected_quantity' => 1, 'defect_description' => 'Late defect.', 'created_by' => $this->inspector->id,
            'is_late_incoming' => true, 'late_hold_quantity' => '100.000']);
        $review = MaterialReviewRecord::create(['mrb_number' => 'MRB-PARTIAL-HOLD', 'ncr_id' => $ncr->id,
            'inspection_id' => $inspection->id, 'item_id' => $line->item_id, 'quantity' => '1.000',
            'source_location_id' => $line->location_id, 'quarantine_location_id' => $line->location_id,
            'held_by' => $this->inspector->id, 'held_at' => now()]);
        $review->forceFill(['status' => 'released'])->save();
        $this->assertSame('100.000', app(QualityStockHoldService::class)->heldQuantity($line->item_id, $line->location_id));
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

    public function test_sorting_keeps_the_good_pieces_and_returns_the_rest(): void
    {
        $grn = $this->receive(['100.000']);
        $ncr = $this->failLine($grn, 0);

        $this->ncrs->setDisposition($ncr, 'return_to_supplier', 'Flash on 30 pcs', null, $this->mrb, '70', $this->sortingProof());

        $grn = $grn->fresh()->load('items', 'purchaseOrder.items');
        $this->assertSame(GrnStatus::PartialAccepted, $grn->status);
        $this->assertNotNull($grn->remainder_rejected_at);
        $this->assertSame('70.000', (string) $grn->items->first()->quantity_accepted);
        $this->assertSame('70.000', (string) $grn->purchaseOrder->items->first()->quantity_accepted);

        $rma = ReturnRequest::query()->where('source_key', 'grn-rejection:'.$grn->id)->firstOrFail();
        $this->assertSame('30.000', (string) $rma->items->first()->quantity);
    }

    public function test_counted_supplier_defects_accept_good_pieces_and_open_return_without_ncr(): void
    {
        config(['inspection.checklist_capture_enabled' => true, 'inspection.counted_capture_enabled' => true]);
        $grn = $this->receive(['100.000'], 'PCS');
        $inspection = $this->inspectionFor($grn, 0);
        $this->assertTrue($inspection->isCountOnlyIncoming());
        $this->assertNotNull($inspection->counted_capture);

        $submitted = app(InspectionService::class)->recordBatchResult($inspection, [
            'expected_result_version' => 0,
            'coverage_confirmed' => true,
            'inspected_quantity' => 100,
            'sample_defect_count' => 2,
            'defects_removed_confirmed' => true,
            'problem_description' => 'Two torn bags.',
            'measurements' => [],
        ], $this->mrb);
        $this->assertSame('awaiting_review', $submitted->status->value);
        $reviewed = app(InspectionService::class)->review($submitted, 'failed', 'Confirmed two torn bags.', $this->mrbReviewer, 1);
        $this->assertSame('failed', $reviewed->status->value);
        $this->assertFalse(NonConformanceReport::where('inspection_id', $inspection->id)->exists());
        $this->assertSame('grn_already_terminal', $this->grns->settleIncomingQc($grn->fresh(), $this->receiver));
        $this->assertSame(GrnStatus::PartialAccepted, $grn->fresh()->status);
        $this->assertSame('98.000', (string) $grn->fresh()->items->first()->quantity_accepted);
        $this->assertSame('2.000', (string) ReturnRequest::where('source_key', 'grn-rejection:'.$grn->id)
            ->firstOrFail()->items->first()->quantity);
        $this->assertSame('grn_already_terminal', $this->grns->settleIncomingQc($grn->fresh(), $this->receiver));
        $this->assertSame(1, $this->rmaCount($grn));

        $po = $grn->purchaseOrder->fresh();
        $supplier = SupplierPortalUser::factory()->create(['vendor_id' => $po->vendor_id]);
        $portal = app(SupplierPortalService::class);
        $draftData = (new SupplierPurchaseOrderResource($portal->purchaseOrderDetail((int) $po->vendor_id, $po)))->resolve();
        $this->assertSame([], $draftData['supplier_returns']);

        $rma = ReturnRequest::where('source_key', 'grn-rejection:'.$grn->id)->firstOrFail();
        $this->assertSame($inspection->inspection_number.': Two torn bags.', $rma->reason_description);
        $this->assertSame($inspection->inspection_number.': Two torn bags.', $rma->items->first()->reason);
        Storage::fake('local');
        $buyer = User::factory()->create(['role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id')]);
        $requiredDate = now()->addDays(4)->toDateString();
        $this->actingAs($buyer)->post('/api/v1/return-management/return-requests/'.$rma->hash_id.'/submit', [
            'required_delivery_date' => $requiredDate,
            'defect_photo' => UploadedFile::fake()->image('torn-bags.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'completed');
        $sent = $rma->fresh();
        $this->assertSame(ReturnRequestStatus::Completed, $sent->status);
        $this->assertSame('disposed', $sent->disposition_status);
        $this->assertNull($sent->replacement_purchase_order_id);
        $this->assertSame($requiredDate, $sent->replacement_due_date?->toDateString());
        $this->assertSame(0, $sent->receipts()->count());
        try {
            app(ReturnRequestService::class)->receive($sent, [], null, $buyer);
            $this->fail('Purchasing recorded a second receipt for rejected goods.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('new shipment and GRN', $e->getMessage());
        }
        $approvedData = (new SupplierPurchaseOrderResource($portal->purchaseOrderDetail((int) $po->vendor_id, $po->fresh())))->resolve();
        $this->assertFalse($approvedData['capabilities']['can_submit_invoice']);
        $this->assertSame($rma->rma_number, $approvedData['supplier_returns'][0]['rma_number']);
        $this->assertSame('2.000', $approvedData['supplier_returns'][0]['items'][0]['quantity']);
        $this->assertSame($requiredDate, $approvedData['supplier_returns'][0]['replacement_due_date']);
        $this->assertTrue($approvedData['supplier_returns'][0]['replacement_pending']);
        $this->assertSame('torn-bags.jpg', $approvedData['supplier_returns'][0]['defect_photo_name']);
        Storage::disk('local')->assertExists($sent->defect_photo_path);
        $rma->refresh();
        $this->assertSame(ReturnRequestStatus::Completed, $rma->status);
        $this->assertSame($inspection->id, (int) $rma->inspection_id);
        $this->assertNull($po->fresh()->short_closed_at);
        $this->assertSame('2.00', SupplierPoCapabilities::rejectedDispatchAllowance($po->fresh())[(int) $po->items->first()->id]);
        $po->forceFill(['expected_delivery_date' => now()->toDateString(), 'confirmed_delivery_date' => now()->toDateString()])->save();
        $priorShipment = SupplierShipment::factory()->create(['purchase_order_id' => $po->id, 'portal_user_id' => $supplier->id]);
        $priorShipment->items()->create(['purchase_order_item_id' => $po->items->first()->id, 'quantity_shipped' => '100.00']);
        Sanctum::actingAs($supplier, ['*'], 'supplier_portal');
        $this->post("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipments", [
            'shipped_date' => now()->toDateString(),
            'estimated_arrival' => $requiredDate,
            'return_request_id' => $rma->hash_id,
            'items' => [['purchase_order_item_id' => $po->items->first()->hash_id, 'quantity_shipped' => '2.00']],
            'dispatch_photo' => UploadedFile::fake()->image('replacement.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $replacementShipment = SupplierShipment::query()->where('return_request_id', $rma->id)
            ->with('items.purchaseOrderItem', 'purchaseOrder', 'returnRequest')->firstOrFail();
        $this->assertSame('2.00', (string) $replacementShipment->items->first()->quantity_shipped);
        $this->assertSame($rma->id, $replacementShipment->return_request_id);
        $this->assertFalse((new SupplierShipmentResource($replacementShipment))->resolve()['late_eta']);
        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'QC-WAIT-001',
            'date' => now()->toDateString(),
        ])->assertUnprocessable();

        $replacementGrn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'supplier_shipment_id' => $replacementShipment->id,
            'status' => GrnStatus::PendingQc->value,
        ]);
        $replacementLine = GrnItem::create([
            'goods_receipt_note_id' => $replacementGrn->id,
            'purchase_order_item_id' => $po->items->first()->id,
            'item_id' => $po->items->first()->item_id,
            'location_id' => $this->location->id,
            'quantity_received' => '2.000',
            'quantity_accepted' => '0.000',
            'unit_cost' => '10.00',
        ]);
        $this->assertFalse(SupplierPoCapabilities::canSubmitInvoice($po->fresh()));
        $replacementGrn->forceFill(['status' => GrnStatus::PartialAccepted->value, 'accepted_at' => now()])->save();
        $replacementLine->forceFill(['quantity_accepted' => '1.000'])->save();
        $this->assertFalse(SupplierPoCapabilities::canSubmitInvoice($po->fresh()));

        $secondReturn = ReturnRequest::forceCreate([
            'rma_number' => 'RMA-REPLACEMENT-RETRY',
            'source_key' => 'grn-rejection:'.$replacementGrn->id,
            'type' => 'supplier_return',
            'status' => 'completed',
            'disposition_status' => 'disposed',
            'purchase_order_id' => $po->id,
            'goods_receipt_note_id' => $replacementGrn->id,
            'vendor_id' => $po->vendor_id,
            'completed_at' => now(),
        ]);
        $secondReturn->items()->create([
            'item_id' => $po->items->first()->item_id,
            'source_po_item_id' => $po->items->first()->id,
            'source_grn_item_id' => $replacementLine->id,
            'quantity' => '1.000',
            'reversal_already_applied' => true,
            'disposition' => 'return_to_supplier',
        ]);
        $secondShipment = SupplierShipment::factory()->create([
            'purchase_order_id' => $po->id,
            'portal_user_id' => $supplier->id,
            'return_request_id' => $secondReturn->id,
        ]);
        $secondGrn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'supplier_shipment_id' => $secondShipment->id,
            'status' => GrnStatus::Accepted->value,
            'accepted_at' => now(),
        ]);
        GrnItem::create([
            'goods_receipt_note_id' => $secondGrn->id,
            'purchase_order_item_id' => $po->items->first()->id,
            'item_id' => $po->items->first()->item_id,
            'location_id' => $this->location->id,
            'quantity_received' => '1.000',
            'quantity_accepted' => '1.000',
            'unit_cost' => '10.00',
        ]);
        $this->assertTrue(SupplierPoCapabilities::canSubmitInvoice($po->fresh()));
        $resolvedData = (new SupplierPurchaseOrderResource($portal->purchaseOrderDetail((int) $po->vendor_id, $po->fresh())))->resolve();
        $this->assertFalse($resolvedData['supplier_returns'][0]['replacement_pending']);
        $this->assertFalse($resolvedData['supplier_returns'][1]['replacement_pending']);
        $this->assertSame('98.000', (string) $grn->fresh()->items->first()->quantity_accepted);
        $this->assertSame(0, $rma->receipts()->count());
        $this->assertNull($rma->credit_note_id);
    }

    public function test_counted_supplier_defects_cannot_be_submitted_without_segregation(): void
    {
        config(['inspection.checklist_capture_enabled' => true, 'inspection.counted_capture_enabled' => true]);
        $grn = $this->receive(['100.000'], 'PCS');
        $inspection = $this->inspectionFor($grn, 0);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Segregate defective pieces');
        app(InspectionService::class)->recordBatchResult($inspection, [
            'expected_result_version' => 0,
            'coverage_confirmed' => true,
            'inspected_quantity' => 100,
            'sample_defect_count' => 2,
            'problem_description' => 'Two torn bags.',
            'measurements' => [],
        ], $this->mrb);
    }

    public function test_independent_inspection_reviewer_can_release_sorted_good_without_general_ncr_management(): void
    {
        $grn = $this->receive(['100.000'], 'PCS');
        $ncr = $this->failLine($grn, 0);
        $reviewer = User::factory()->create([
            'is_active' => true,
            'role_id' => Role::query()->where('slug', 'production_manager')->value('id'),
        ]);
        $this->assertTrue($reviewer->hasPermission('quality.inspections.review'));
        $this->assertFalse($reviewer->hasPermission('quality.ncr.manage'));

        $url = "/api/v1/quality/ncrs/{$ncr->hash_id}/disposition";
        $this->actingAs($reviewer)->patchJson($url, [
            'disposition' => 'use_as_is',
        ])->assertForbidden();
        $this->assertNull($ncr->fresh()->disposition);

        $this->actingAs($reviewer)->patchJson($url, [
            'disposition' => 'return_to_supplier',
            'mrb_accepted_quantity' => '98',
            'sorting_evidence' => [
                'inspected_quantity' => '100',
                'evidence_reference' => 'SORT-100-2-DEFECTS',
                'performed_by' => $this->mrb->hash_id,
                'recovery_passed' => true,
            ],
        ])->assertOk();

        $this->assertSame(GrnStatus::PartialAccepted, $grn->fresh()->status);
        $this->assertSame('98.000', (string) $grn->fresh()->items->first()->quantity_accepted);
        $this->actingAs($reviewer)->patchJson($url, [
            'disposition' => 'return_to_supplier',
            'root_cause' => 'Unauthorized follow-up',
        ])->assertForbidden();
    }

    public function test_pending_supplier_receipt_only_allows_return_to_supplier_disposition(): void
    {
        $grn = $this->receive(['100.000'], 'PCS');
        $ncr = $this->failLine($grn, 0);

        foreach (['rework', 'use_as_is'] as $disposition) {
            try {
                $this->ncrs->setDisposition($ncr, $disposition, null, null, $this->mrb);
                $this->fail("Pending supplier receipt accepted {$disposition}.");
            } catch (BusinessRuleException $e) {
                $this->assertStringContainsString('Return to supplier only', $e->getMessage());
            }
        }
        $this->assertNull($ncr->fresh()->disposition);
        $this->assertSame(GrnStatus::PendingQc, $grn->fresh()->status);
    }

    public function test_mrb_decision_is_final_once_the_receipt_is_settled(): void
    {
        $grn = $this->receive(['100.000']);
        $ncr = $this->failLine($grn, 0);
        $this->ncrs->setDisposition($ncr, 'return_to_supplier', 'Flash on 30 pcs', null, $this->mrb, '70', $this->sortingProof());

        // Root cause / corrective action can still be completed later...
        $this->ncrs->setDisposition($ncr->fresh(), 'return_to_supplier', 'Worn gate insert', 'Supplier 8D', $this->mrb);
        $this->assertSame('Worn gate insert', $ncr->fresh()->root_cause);

        // ...but the disposition the receipt was settled against cannot change.
        try {
            $this->ncrs->setDisposition($ncr->fresh(), 'use_as_is', null, null, $this->mrb, null, $this->sortingProof());
            $this->fail('A settled MRB decision was re-dispositioned.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('final', $e->getMessage());
        }
        $this->assertSame('return_to_supplier', $ncr->fresh()->disposition?->value ?? $ncr->fresh()->disposition);
        $this->assertSame('70.000', (string) $grn->fresh()->items->first()->quantity_accepted);
    }

    public function test_known_sample_defects_cannot_enter_stock_through_full_or_partial_acceptance(): void
    {
        $grn = $this->receive(['100.000']);
        $inspection = $this->inspectionFor($grn, 0);
        $inspection->forceFill(['status' => 'passed', 'inspector_id' => $this->inspector->id,
            'reviewed_by' => $this->checker->id, 'reviewed_at' => now(),
            'inspected_quantity' => $inspection->sample_size, 'sample_defect_count' => 1])->save();
        foreach ([null, [$grn->items->first()->id => '100']] as $map) {
            try {
                $map === null ? $this->grns->accept($grn->fresh(), $this->receiver)
                    : $this->grns->partialAccept($grn->fresh(), $map, $this->receiver);
                $this->fail('Observed defective pieces must not enter stock.');
            } catch (BusinessRuleException $e) {
                $this->assertStringContainsString('defective pieces', $e->getMessage());
            }
        }
        $this->assertSame('grn_partially_accepted', $this->grns->settleIncomingQc($grn->fresh(), $this->receiver));
        $this->assertSame('99.000', (string) $grn->fresh()->items->first()->quantity_accepted);
    }

    public function test_positive_sorted_good_quantity_without_evidence_keeps_receipt_held(): void
    {
        $grn = $this->receive(['100.000']);
        $ncr = $this->failLine($grn, 0);
        try {
            $this->ncrs->setDisposition($ncr, 'return_to_supplier', null, null, $this->mrb, '99');
            $this->fail('Sorting requires coverage and independent evidence.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Sorting evidence', $e->getMessage());
        }
        $this->assertNull($ncr->fresh()->mrb_decided_at);
        $this->assertSame(GrnStatus::PendingQc, $grn->fresh()->status);
    }

    public function test_sorted_quantity_must_be_fewer_than_received(): void
    {
        $grn = $this->receive(['100.000']);
        $ncr = $this->failLine($grn, 0);

        $this->expectException(BusinessRuleException::class);
        $this->ncrs->setDisposition($ncr, 'return_to_supplier', null, null, $this->mrb, '100');
    }

    public function test_mixed_receipt_waits_for_the_failed_line_then_settles_both(): void
    {
        $grn = $this->receive(['100.000', '50.000']);
        $ncr = $this->failLine($grn, 0);
        $this->passLine($grn, 1);

        $this->assertSame('awaiting_mrb', $this->grns->settleIncomingQc($grn->fresh(), $this->receiver));
        $this->assertSame(GrnStatus::PendingQc, $grn->fresh()->status);

        $this->ncrs->setDisposition($ncr, 'return_to_supplier', null, null, $this->mrb, '98', $this->sortingProof());

        $grn = $grn->fresh()->load(['items' => fn ($items) => $items->orderBy('id')]);
        $this->assertSame(GrnStatus::PartialAccepted, $grn->status);
        $this->assertSame('98.000', (string) $grn->items->get(0)->quantity_accepted);
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

    public function test_extra_piece_quarantine_hold_rejects_fractional_quantity(): void
    {
        $grn = $this->receive(['100.000'], 'PCS');
        $this->passLine($grn, 0);
        $grn = $this->grns->accept($grn->fresh(), $this->receiver);
        $line = $grn->items->first();
        $before = StockMovement::where('item_id', $line->item_id)->count();
        try {
            app(QuarantineService::class)->hold(['item_id' => $line->item_id, 'quantity' => '0.500',
                'source_location_id' => $line->location_id], $this->mrb);
            $this->fail('Half a physical piece was accepted into quarantine.');
        } catch (BusinessRuleException) {
            $this->assertSame($before, StockMovement::where('item_id', $line->item_id)->count());
        }
    }

    public function test_extra_partial_acceptance_rejects_fractional_physical_piece(): void
    {
        $grn = $this->receive(['100.000'], 'PCS');
        $this->passLine($grn, 0);
        $line = $grn->items->first();
        $before = StockMovement::where('item_id', $line->item_id)->count();
        try {
            $this->grns->partialAccept($grn, [$line->id => '99.500'], $this->receiver);
            $this->fail('Partial acceptance posted half a physical piece.');
        } catch (BusinessRuleException) {
            $this->assertSame('0.000', $line->fresh()->quantity_accepted);
            $this->assertSame($before, StockMovement::where('item_id', $line->item_id)->count());
        }
    }

    public function test_extra_late_ncr_cannot_hold_an_unrelated_lot_of_same_item(): void
    {
        $grn = $this->receive(['100.000'], 'PCS');
        $this->assertStringStartsWith('RCV-', $grn->items->first()->material_lot_number);
        $this->passLine($grn, 0);
        $grn = $this->grns->accept($grn->fresh(), $this->receiver);
        $line = $grn->items->first();
        $inspection = $this->inspectionFor($grn, 0);
        app(StockMovementService::class)->move(new StockMovementInput(type: StockMovementType::Opening,
            itemId: $line->item_id, quantity: '10', toLocationId: $line->location_id,
            unitCost: '10', createdBy: $this->receiver->id, lotNumber: 'UNRELATED-SAFE-LOT'));
        $ncr = $this->ncrs->create(['source' => 'inspection_fail', 'severity' => 'high',
            'inspection_id' => $inspection->id, 'affected_quantity' => 1,
            'defect_description' => 'Only original receipt lot suspect.'], $this->inspector);
        $this->assertSame('100.000', $ncr->late_hold_quantity);
        try {
            app(QuarantineService::class)->hold(['item_id' => $line->item_id, 'quantity' => '1',
                'source_location_id' => $line->location_id, 'ncr_id' => $ncr->id,
                'inspection_id' => $inspection->id, 'lot_number' => 'UNRELATED-SAFE-LOT'], $this->mrb);
            $this->fail('Unrelated lot satisfied original incident ownership.');
        } catch (BusinessRuleException) {
            $this->assertSame('10.000', app(StockLocationSummaryService::class)->lotQuantity($line->item_id, $line->location_id, 'UNRELATED-SAFE-LOT'));
        }
    }

    public function test_extra_untracked_late_hold_survives_transfer_to_another_bin(): void
    {
        $grn = $this->receive(['100.000'], 'PCS');
        $grn->items->first()->update(['material_lot_number' => null]);
        $this->passLine($grn, 0);
        $grn = $this->grns->accept($grn->fresh(), $this->receiver);
        $line = $grn->items->first();
        $inspection = $this->inspectionFor($grn, 0);
        WarehouseLocation::whereHas('zone', fn ($zone) => $zone->where('zone_type', 'quarantine'))
            ->update(['is_active' => false]);
        $ncr = $this->ncrs->create(['source' => 'inspection_fail', 'severity' => 'high',
            'inspection_id' => $inspection->id, 'affected_quantity' => 1,
            'defect_description' => 'Untracked receipt suspected before physical quarantine.'], $this->inspector);
        $target = WarehouseLocation::factory()->create(['zone_id' => $this->location->zone_id]);
        try {
            app(StockTransferService::class)->transfer($line->item_id,
                $line->location_id, $target->id, '100', 'Move suspect material', $this->receiver);
            $this->fail('Historical untracked held stock escaped through transfer.');
        } catch (BusinessRuleException) {
            $this->assertSame('100.000', app(QualityStockHoldService::class)->heldQuantity($line->item_id, $line->location_id));
            $this->assertDatabaseMissing('stock_movements', ['item_id' => $line->item_id, 'to_location_id' => $target->id]);
        }
    }

    public function test_extra_late_incident_remains_selectable_after_missing_quarantine_setup(): void
    {
        $grn = $this->receive(['100.000'], 'PCS');
        $this->passLine($grn, 0);
        $grn = $this->grns->accept($grn->fresh(), $this->receiver);
        WarehouseLocation::whereHas('zone', fn ($zone) => $zone->where('zone_type', 'quarantine'))
            ->update(['is_active' => false]);
        $inspection = $this->inspectionFor($grn, 0);
        $line = $grn->items->first();
        $ncr = $this->ncrs->create(['source' => 'inspection_fail', 'severity' => 'high',
            'inspection_id' => $inspection->id, 'affected_quantity' => 1,
            'defect_description' => 'Late defect requiring quarantine recovery.'], $this->inspector);
        $this->assertFalse(MaterialReviewRecord::where('ncr_id', $ncr->id)->exists());
        $this->assertSame('100.000', app(QualityStockHoldService::class)->heldQuantity($line->item_id, $line->location_id));
        WarehouseLocation::whereHas('zone', fn ($zone) => $zone->where('zone_type', 'quarantine'))
            ->update(['is_active' => true]);
        $options = app(QuarantineService::class)->qualityOptions($line->item->hash_id);
        $this->assertContains($ncr->hash_id, array_column($options['ncrs'], 'id'), 'Late NCR must be selectable for recovery.');
        $this->assertContains($inspection->hash_id, array_column($options['inspections'], 'id'));
        $this->actingAs($this->receiver, 'sanctum')->postJson('/api/v1/quality/ncrs/'.$ncr->hash_id.'/retry-quarantine')->assertForbidden();
        $this->actingAs($this->mrb, 'sanctum')->postJson('/api/v1/quality/ncrs/'.$ncr->hash_id.'/retry-quarantine')->assertSuccessful();
        $this->ncrs->retryQuarantine($ncr, $this->mrbReviewer);
        $this->assertSame(1, MaterialReviewRecord::where('ncr_id', $ncr->id)->count());
        $this->assertSame('100.000', $ncr->fresh()->late_hold_quantity);

    }

    public function test_extra_late_concession_requires_separate_approval_evidence(): void
    {
        $grn = $this->receive(['100.000'], 'PCS');
        $this->passLine($grn, 0);
        $grn = $this->grns->accept($grn->fresh(), $this->receiver);
        $inspection = $this->inspectionFor($grn, 0);
        $ncr = $this->ncrs->create(['source' => 'inspection_fail', 'severity' => 'high',
            'inspection_id' => $inspection->id, 'affected_quantity' => 1,
            'defect_description' => 'One cracked piece confirmed after receipt acceptance.'], $this->inspector);
        $hold = MaterialReviewRecord::where('ncr_id', $ncr->id)->firstOrFail();
        try {
            app(QuarantineService::class)->release($hold, 'use_as_is', $this->mrb, $this->location->id,
                'No repair performed.', ['inspected_quantity' => '100', 'evidence_reference' => 'LATE-ALL-100', 'recovery_passed' => true]);
            $this->fail('Known defect was released with the full lot without repair.');
        } catch (BusinessRuleException) {
            $this->assertSame(MrbStatus::Held, $hold->fresh()->status);
        }
        $ncr->update(['severity' => 'critical']);
        try {
            app(QuarantineService::class)->release($hold->fresh(), 'use_as_is', $this->mrb, $this->location->id, null,
                ['inspected_quantity' => '100', 'evidence_reference' => 'LATE-ALL-100', 'recovery_passed' => true,
                    'concession_approval_reference' => 'CRITICAL-CUSTOMER-SIGNOFF']);
            $this->fail('A critical late incident was released by concession.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('critical failure', $error->getMessage());
            $this->assertSame(MrbStatus::Held, $hold->fresh()->status);
        }
        $ncr->update(['severity' => 'high']);
        $released = app(QuarantineService::class)->release($hold->fresh(), 'use_as_is', $this->mrb, $this->location->id,
            'Customer accepted the noncritical deviation.', ['inspected_quantity' => '100', 'evidence_reference' => 'LATE-ALL-100',
                'recovery_passed' => true, 'concession_approval_reference' => 'CUSTOMER-SIGNOFF-20261006']);
        $this->assertSame('CUSTOMER-SIGNOFF-20261006', $released->concession_approval_reference);
        $this->ncrs->retryQuarantine($ncr, $this->mrbReviewer);
        $this->assertSame(1, MaterialReviewRecord::where('ncr_id', $ncr->id)->count());
        $this->assertSame('100.000', $ncr->fresh()->late_hold_quantity);
    }

    public function test_quarantine_retry_skips_previously_released_portion_of_split_lot(): void
    {
        $grn = $this->receive(['100.000'], 'PCS');
        $this->passLine($grn, 0);
        $grn = $this->grns->accept($grn->fresh(), $this->receiver);
        $line = $grn->items->first();
        $other = WarehouseLocation::factory()->create(['zone_id' => $this->location->zone_id]);
        app(StockTransferService::class)->transfer($line->item_id,
            $this->location->id, $other->id, '50', null, $this->receiver);
        $inspection = $this->inspectionFor($grn, 0);
        $ncr = NonConformanceReport::create(['ncr_number' => 'NCR-RETRY-SPLIT', 'source' => 'inspection_fail',
            'severity' => 'high', 'status' => 'open', 'inspection_id' => $inspection->id,
            'affected_quantity' => 1, 'defect_description' => 'Split suspect stock.', 'created_by' => $this->inspector->id,
            'is_late_incoming' => true, 'late_hold_quantity' => '100.000']);
        $hold = app(QuarantineService::class)->hold(['item_id' => $line->item_id, 'quantity' => '50',
            'source_location_id' => $this->location->id, 'ncr_id' => $ncr->id,
            'inspection_id' => $inspection->id, 'lot_number' => $line->material_lot_number], $this->inspector);
        app(QuarantineService::class)->release($hold, 'rework', $this->mrb, $this->location->id, null,
            ['inspected_quantity' => '50', 'evidence_reference' => 'REPAIRED-50', 'recovery_passed' => true]);
        $this->ncrs->retryQuarantine($ncr, $this->mrbReviewer);
        $this->assertSame(2, MaterialReviewRecord::where('ncr_id', $ncr->id)->count());
        $this->assertSame($other->id, MaterialReviewRecord::where('ncr_id', $ncr->id)->where('status', 'held')->firstOrFail()->source_location_id);
        $this->assertSame('50.000', app(StockLocationSummaryService::class)->lotQuantity($line->item_id, $this->location->id, $line->material_lot_number));
    }
}
