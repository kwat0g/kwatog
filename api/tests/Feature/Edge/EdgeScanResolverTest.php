<?php

declare(strict_types=1);

namespace Tests\Feature\Edge;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Edge\Models\EdgeDevice;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * T2.1 — Edge barcode scan resolver.
 *
 * Verifies POST /api/v1/edge/v1/scan dispatches scanned barcodes to the
 * right entity type (item / WO / PO / GRN / unknown) and surfaces
 * state-aware suggested actions for the device to render.
 */
class EdgeScanResolverTest extends TestCase
{
    use RefreshDatabase;

    private function scannerToken(): string
    {
        $d = EdgeDevice::create([
            'serial_number' => 'SCAN-' . uniqid(),
            'name'          => 'Test Scanner',
            'device_type'   => 'barcode_scanner',
            'location'      => 'Bench',
        ]);
        return $d->createToken('t', ['edge:scan'])->plainTextToken;
    }

    private function plcToken(): string
    {
        $d = EdgeDevice::create([
            'serial_number' => 'PLC-' . uniqid(),
            'name'          => 'Test PLC',
            'device_type'   => 'plc_counter',
            'location'      => 'Press 1',
        ]);
        return $d->createToken('t', ['edge:output'])->plainTextToken;
    }

    private function scan(string $token, array $body): TestResponse
    {
        return $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/edge/v1/scan', $body);
    }

    /**
     * `PurchaseOrder::status` is not in $fillable — factory writes it via
     * forceFill. Tests mirror that here so a desired status actually sticks.
     */
    private function makePo(string $status, ?Vendor $vendor = null): PurchaseOrder
    {
        $vendor ??= Vendor::factory()->create();
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill(['status' => $status])->save();
        return $po->fresh();
    }

    public function test_item_lookup_returns_view_action(): void
    {
        Item::factory()->create(['code' => 'RES-PP-001']);

        $r = $this->scan($this->scannerToken(), ['barcode' => 'RES-PP-001']);

        $r->assertOk();
        $r->assertJsonPath('data.type', 'item');
        $r->assertJsonPath('data.entity.code', 'RES-PP-001');
        $r->assertJsonPath('data.suggested_actions.0.action', 'view_item');
    }

    public function test_item_with_active_wo_context_suggests_issue(): void
    {
        Item::factory()->create(['code' => 'RES-PP-002']);
        $wo = WorkOrder::factory()->create(['status' => 'in_progress']);

        $r = $this->scan($this->scannerToken(), [
            'barcode' => 'RES-PP-002',
            'context' => ['wo_id' => $wo->hash_id],
        ]);

        $r->assertOk();
        $r->assertJsonPath('data.suggested_actions.0.action', 'issue_to_wo');
        $r->assertJsonPath('data.suggested_actions.0.params.wo_id', $wo->hash_id);
    }

    public function test_item_with_draft_grn_context_suggests_add(): void
    {
        Item::factory()->create(['code' => 'RES-PP-003']);
        // GRN's editable / not-yet-finalised state is `pending_qc`
        // (there is no `draft` case in GrnStatus).
        $grn = GoodsReceiptNote::factory()->create(['status' => 'pending_qc']);

        $r = $this->scan($this->scannerToken(), [
            'barcode' => 'RES-PP-003',
            'context' => ['grn_id' => $grn->hash_id],
        ]);

        $r->assertOk();
        $r->assertJsonPath('data.suggested_actions.0.action', 'add_to_grn');
    }

    public function test_work_order_in_progress_offers_output_and_defect(): void
    {
        $wo = WorkOrder::factory()->create(['status' => 'in_progress']);

        $r = $this->scan($this->scannerToken(), ['barcode' => $wo->wo_number]);

        $r->assertOk();
        $r->assertJsonPath('data.type', 'work_order');
        $r->assertJsonFragment(['action' => 'report_output']);
        $r->assertJsonFragment(['action' => 'report_defect']);
        $r->assertJsonFragment(['action' => 'view_wo']);
    }

    public function test_work_order_completed_only_offers_view(): void
    {
        $wo = WorkOrder::factory()->create(['status' => 'completed']);

        $r = $this->scan($this->scannerToken(), ['barcode' => $wo->wo_number]);

        $r->assertOk();
        $actions = collect($r->json('data.suggested_actions'))->pluck('action')->all();
        $this->assertSame(['view_wo'], $actions);
    }

    public function test_purchase_order_approved_offers_open_grn(): void
    {
        $po = $this->makePo('approved');

        $r = $this->scan($this->scannerToken(), ['barcode' => $po->po_number]);

        $r->assertOk();
        $r->assertJsonPath('data.type', 'purchase_order');
        $r->assertJsonFragment(['action' => 'open_grn']);
        $r->assertJsonFragment(['action' => 'view_po']);
    }

    public function test_purchase_order_draft_only_offers_view(): void
    {
        $po = $this->makePo('draft');

        $r = $this->scan($this->scannerToken(), ['barcode' => $po->po_number]);

        $r->assertOk();
        $actions = collect($r->json('data.suggested_actions'))->pluck('action')->all();
        $this->assertSame(['view_po'], $actions);
    }

    public function test_grn_scan_returns_grn(): void
    {
        $grn = GoodsReceiptNote::factory()->create();

        $r = $this->scan($this->scannerToken(), ['barcode' => $grn->grn_number]);

        $r->assertOk();
        $r->assertJsonPath('data.type', 'goods_receipt_note');
        $r->assertJsonFragment(['action' => 'view_grn']);
    }

    public function test_unknown_barcode_is_200_with_unknown_type(): void
    {
        $r = $this->scan($this->scannerToken(), ['barcode' => 'NOPE-XYZ-999']);

        $r->assertOk();
        $r->assertJsonPath('data.type', 'unknown');
        $r->assertJsonPath('data.entity', null);
        $r->assertJsonPath('data.suggested_actions', []);
    }

    public function test_plc_token_cannot_scan(): void
    {
        $r = $this->scan($this->plcToken(), ['barcode' => 'RES-PP-001']);
        $r->assertStatus(403);
    }

    public function test_no_token_is_401(): void
    {
        $this->postJson('/api/v1/edge/v1/scan', ['barcode' => 'X'])
            ->assertStatus(401);
    }

    public function test_whitespace_and_case_are_normalised(): void
    {
        $wo    = WorkOrder::factory()->create(['status' => 'in_progress']);
        $lower = strtolower($wo->wo_number);

        $r = $this->scan($this->scannerToken(), ['barcode' => "  {$lower}  "]);

        $r->assertOk();
        $r->assertJsonPath('data.entity.wo_number', $wo->wo_number);
    }
}
