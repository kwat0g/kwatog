<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Enums\PurchaseOrderResponseType;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Supplier PO capabilities: status × action matrix.
 *
 * Verifies the unified gate: capabilities returned by GET detail, and enforce
 * by POST respond/shipment/invoice/schedule endpoints.
 */
class SupplierPoCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
    }

    /* ─── Helpers ────────────────────────────────────────────────── */

    private function makePortalUser(?Vendor $vendor = null): SupplierPortalUser
    {
        $vendor ??= Vendor::factory()->create();

        return SupplierPortalUser::create([
            'vendor_id' => $vendor->id,
            'name'      => 'SupUser-'.substr(uniqid(), -5),
            'email'     => 'su-'.uniqid().'@t.test',
            'password'  => bcrypt('Password1!'),
            'is_active' => true,
        ]);
    }

    private function actAs(SupplierPortalUser $user): self
    {
        Sanctum::actingAs($user, ['*'], 'supplier_portal');

        return $this;
    }

    private function makePo(Vendor $vendor, string $status = 'sent', ?string $sentAt = null, bool $sent = true): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill([
            'status' => $status,
            'sent_to_supplier_at' => $sent && $status !== 'draft' ? ($sentAt ?? now()) : null,
        ])->save();

        return $po->refresh();
    }

    private function makePoItem(PurchaseOrder $po, string $quantity = '500.00'): PurchaseOrderItem
    {
        return PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => Item::factory()->create()->id,
            'description'       => 'Relay Cover',
            'quantity'          => $quantity,
            'quantity_received' => '0.00',
            'quantity_accepted' => '0.00',
            'unit'              => 'pcs',
            'unit_price'        => '10.00',
            'total'             => Money::mul($quantity, '10.00'),
        ]);
    }

    private function makeAcceptedGrn(PurchaseOrder $po, PurchaseOrderItem $item): GoodsReceiptNote
    {
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $po->vendor_id,
            'status'            => 'accepted',
            'accepted_by'       => User::factory()->create()->id,
            'accepted_at'       => now(),
        ]);
        GrnItem::create([
            'goods_receipt_note_id'    => $grn->id,
            'purchase_order_item_id'   => $item->id,
            'item_id'                  => $item->item_id,
            'location_id'              => WarehouseLocation::factory()->create()->id,
            'quantity_received'        => '100.00',
            'quantity_accepted'        => '100.00',
            'unit_cost'                => '10.00',
        ]);

        return $grn;
    }

    private function makeDraftBillForGrn(GoodsReceiptNote $grn, ?string $supplierInvoiceNumber = null): Bill
    {
        return Bill::forceCreate([
            'vendor_id'              => $grn->vendor_id,
            'purchase_order_id'      => $grn->purchase_order_id,
            'goods_receipt_note_id'  => $grn->id,
            'bill_number'            => 'BILL-'.substr(uniqid(), -5),
            'status'                 => BillStatus::Draft->value,
            'date'                   => now()->toDateString(),
            'due_date'               => now()->addDays(30)->toDateString(),
            'total_amount'           => '1000.00',
            'subtotal'               => '1000.00',
            'vat_amount'             => '0.00',
            'amount_paid'            => '0.00',
            'is_vatable'             => false,
            'supplier_invoice_number' => $supplierInvoiceNumber,
        ]);
    }

    private function respondUrl(PurchaseOrder $po): string
    {
        return "/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/respond";
    }

    private function detailUrl(PurchaseOrder $po): string
    {
        return "/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}";
    }

    /* ─── Capability matrix: status vs action ────────────────────── */

    public function test_sent_po_allows_all_responses(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');

        $this->actAs($user);
        $caps = $this->getJson($this->detailUrl($po))->json('data.capabilities');

        $this->assertTrue($caps['can_accept']);
        $this->assertTrue($caps['can_propose']);
        $this->assertTrue($caps['can_decline']);
        $this->assertTrue($caps['can_respond']);
        $this->assertFalse($caps['can_update_shipment']);
        $this->assertFalse($caps['can_upload_document']);
        $this->assertFalse($caps['can_schedule_delivery']);
        $this->assertFalse($caps['can_submit_invoice']);
    }

    public function test_acknowledged_po_with_open_qty_allows_fulfillment(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '500.00');

        $this->actAs($user);
        $caps = $this->getJson($this->detailUrl($po))->json('data.capabilities');

        $this->assertFalse($caps['can_accept']);
        $this->assertFalse($caps['can_propose']);
        $this->assertFalse($caps['can_decline']);
        $this->assertTrue($caps['can_update_shipment']);
        $this->assertTrue($caps['can_upload_document']);
        $this->assertTrue($caps['can_schedule_delivery']);
    }

    public function test_acknowledged_po_with_no_open_qty_blocks_shipment(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '500.00');
        // Receive all
        $item->forceFill(['quantity_received' => '500.00'])->save();

        $this->actAs($user);
        $caps = $this->getJson($this->detailUrl($po))->json('data.capabilities');

        $this->assertFalse($caps['can_update_shipment']);
        $this->assertFalse($caps['can_schedule_delivery']);
        // Documents don't require open qty
        $this->assertTrue($caps['can_upload_document']);
    }

    public function test_supplier_proposed_po_allows_acceptance_or_reconsideration(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'supplier_proposed');

        $this->actAs($user);
        $caps = $this->getJson($this->detailUrl($po))->json('data.capabilities');

        $this->assertTrue($caps['can_accept']);
        $this->assertTrue($caps['can_propose']);
        $this->assertTrue($caps['can_decline']);
    }

    public function test_supplier_declined_po_with_pending_response_allows_retraction(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'supplier_declined');
        // Supplier filed a pending decline response (purchasing hasn't decided yet)
        $po->latestResponse()->create([
            'vendor_id'      => $vendor->id,
            'response_type'  => PurchaseOrderResponseType::Decline->value,
            'status'         => 'pending',
            'responded_at'   => now(),
        ]);

        $this->actAs($user);
        $caps = $this->getJson($this->detailUrl($po))->json('data.capabilities');

        $this->assertTrue($caps['can_accept'], 'Supplier can retract decline by accepting.');
    }

    public function test_supplier_declined_po_after_acceptance_blocks_retraction(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'supplier_declined');
        // Purchasing already accepted the decline (won't resurrect)
        $po->latestResponse()->create([
            'vendor_id'      => $vendor->id,
            'response_type'  => PurchaseOrderResponseType::Decline->value,
            'status'         => 'accepted',
            'responded_at'   => now(),
        ]);

        $this->actAs($user);
        $caps = $this->getJson($this->detailUrl($po))->json('data.capabilities');

        $this->assertFalse($caps['can_accept']);
        $this->assertFalse($caps['can_propose']);
        $this->assertFalse($caps['can_decline']);
    }

    public function test_partially_received_po_allows_fulfillment_and_invoice(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'partially_received');
        $item = $this->makePoItem($po, '500.00');
        $item->forceFill(['quantity_received' => '250.00'])->save();
        $grn = $this->makeAcceptedGrn($po, $item);

        $this->actAs($user);
        $caps = $this->getJson($this->detailUrl($po))->json('data.capabilities');

        $this->assertTrue($caps['can_update_shipment']);
        $this->assertTrue($caps['can_upload_document']);
        $this->assertTrue($caps['can_schedule_delivery']);
        $this->assertTrue($caps['can_submit_invoice']);
    }

    public function test_received_po_blocks_shipment_but_allows_invoice(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'received');
        $item = $this->makePoItem($po, '500.00');
        $item->forceFill(['quantity_received' => '500.00'])->save();
        $grn = $this->makeAcceptedGrn($po, $item);

        $this->actAs($user);
        $caps = $this->getJson($this->detailUrl($po))->json('data.capabilities');

        $this->assertFalse($caps['can_update_shipment']);
        $this->assertFalse($caps['can_schedule_delivery']);
        $this->assertTrue($caps['can_submit_invoice']);
    }

    public function test_closed_po_blocks_all_actions(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'closed');

        $this->actAs($user);
        $caps = $this->getJson($this->detailUrl($po))->json('data.capabilities');

        $this->assertFalse($caps['can_accept']);
        $this->assertFalse($caps['can_propose']);
        $this->assertFalse($caps['can_decline']);
        $this->assertFalse($caps['can_respond']);
        $this->assertFalse($caps['can_update_shipment']);
        $this->assertFalse($caps['can_upload_document']);
        $this->assertFalse($caps['can_schedule_delivery']);
        $this->assertFalse($caps['can_submit_invoice']);
    }

    public function test_cancelled_never_sent_po_is_404(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'cancelled', sent: false);

        $this->actAs($user);
        $this->getJson($this->detailUrl($po))->assertNotFound();
    }

    public function test_cancelled_sent_po_is_visible_readonly(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'cancelled', '2026-09-20 10:00:00');

        $this->actAs($user);
        $response = $this->getJson($this->detailUrl($po))->assertOk();
        $caps = $response->json('data.capabilities');

        $this->assertFalse($caps['can_accept']);
        $this->assertFalse($caps['can_propose']);
        $this->assertFalse($caps['can_decline']);
        $this->assertFalse($caps['can_respond']);
        $this->assertFalse($caps['can_update_shipment']);
        $this->assertFalse($caps['can_upload_document']);
        $this->assertFalse($caps['can_schedule_delivery']);
        $this->assertFalse($caps['can_submit_invoice']);
    }

    /* ─── Invoice capability: GRN billability and bill state ──────── */

    public function test_invoice_capability_requires_accepted_grn(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '100.00');
        // GRN exists but is still pending_qc (not billable)
        GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $vendor->id,
            'status'            => 'pending_qc',
        ]);

        $this->actAs($user);
        $caps = $this->getJson($this->detailUrl($po))->json('data.capabilities');

        $this->assertFalse($caps['can_submit_invoice']);
    }

    public function test_invoice_capability_true_with_draft_bill_no_supplier_invoice_number(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '100.00');
        $grn = $this->makeAcceptedGrn($po, $item);
        // System auto-created a draft bill; no supplier_invoice_number yet
        $this->makeDraftBillForGrn($grn);

        $this->actAs($user);
        $caps = $this->getJson($this->detailUrl($po))->json('data.capabilities');

        $this->assertTrue($caps['can_submit_invoice']);
    }

    public function test_invoice_capability_false_once_live_bill_has_supplier_invoice_number(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '100.00');
        $grn = $this->makeAcceptedGrn($po, $item);
        // Bill is live (unpaid) with supplier_invoice_number
        Bill::forceCreate([
            'vendor_id'              => $grn->vendor_id,
            'purchase_order_id'      => $grn->purchase_order_id,
            'goods_receipt_note_id'  => $grn->id,
            'bill_number'            => 'INV-001',
            'status'                 => BillStatus::Unpaid->value,
            'date'                   => now()->toDateString(),
            'due_date'               => now()->addDays(30)->toDateString(),
            'total_amount'           => '1000.00',
            'subtotal'               => '1000.00',
            'vat_amount'             => '0.00',
            'amount_paid'            => '0.00',
            'is_vatable'             => false,
            'supplier_invoice_number' => 'SUPP-INV-2026-001',
        ]);

        $this->actAs($user);
        $caps = $this->getJson($this->detailUrl($po))->json('data.capabilities');

        $this->assertFalse($caps['can_submit_invoice']);
    }

    public function test_invoice_capability_true_again_when_bill_cancelled(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '100.00');
        $grn = $this->makeAcceptedGrn($po, $item);
        Bill::forceCreate([
            'vendor_id'              => $grn->vendor_id,
            'purchase_order_id'      => $grn->purchase_order_id,
            'goods_receipt_note_id'  => $grn->id,
            'bill_number'            => 'INV-001',
            'status'                 => BillStatus::Cancelled->value,
            'date'                   => now()->toDateString(),
            'due_date'               => now()->addDays(30)->toDateString(),
            'total_amount'           => '1000.00',
            'subtotal'               => '1000.00',
            'vat_amount'             => '0.00',
            'amount_paid'            => '0.00',
            'is_vatable'             => false,
            'supplier_invoice_number' => 'SUPP-INV-2026-001',
        ]);

        $this->actAs($user);
        $caps = $this->getJson($this->detailUrl($po))->json('data.capabilities');

        $this->assertTrue($caps['can_submit_invoice']);
    }

    /* ─── Acknowledge endpoint creates accepted response ──────────– */

    public function test_acknowledge_endpoint_creates_response(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');

        $this->actAs($user);

        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/acknowledge", [
            'expected_delivery_date' => '2026-10-15',
            'notes' => 'Acknowledged.',
        ])->assertOk()->assertJsonPath('data.type', 'accept');

        $this->assertDatabaseHas('purchase_order_responses', [
            'purchase_order_id' => $po->id,
            'vendor_id'         => $vendor->id,
            'response_type'     => 'accept',
        ]);
    }

    /* ─── Dashboard counts ───────────────────────────────────────── */

    public function test_dashboard_open_po_count_distinct_from_pending_delivery(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        // Open (needing attention): sent, acknowledged, supplier_proposed, supplier_declined (pending), partially_received
        $this->makePo($vendor, 'sent');
        $this->makePo($vendor, 'acknowledged');
        $this->makePo($vendor, 'supplier_proposed');

        // Declined with pending response
        $declined = $this->makePo($vendor, 'supplier_declined');
        $declined->latestResponse()->create([
            'vendor_id'      => $vendor->id,
            'response_type'  => PurchaseOrderResponseType::Decline->value,
            'status'         => 'pending',
            'responded_at'   => now(),
        ]);

        // Partially received
        $pr = $this->makePo($vendor, 'partially_received');
        $prItem = $this->makePoItem($pr, '500.00');

        // Pending delivery: acknowledged with open qty, partially_received with open qty
        $ack = $this->makePo($vendor, 'acknowledged');
        $ackItem = $this->makePoItem($ack, '200.00');

        // Not pending: received (closed)
        $rcvd = $this->makePo($vendor, 'received');
        $rcvdItem = $this->makePoItem($rcvd, '100.00');
        $rcvdItem->forceFill(['quantity_received' => '100.00'])->save();

        $this->actAs($user);

        $dash = $this->getJson('/api/v1/b2b/supplier/dashboard')->json('data');

        // sent + 2× acknowledged + proposed + declined(pending) + partially_received
        $this->assertSame(6, $dash['open_po_count']);
        $this->assertSame(2, $dash['pending_delivery_count'], 'Pending = POs with at least one line qty > qty_received in fulfillment statuses');
    }

    /* ─── Deliveries status filter ───────────────────────────────── */

    public function test_deliveries_unknown_status_returns_empty(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $vendor->id,
            'status'            => 'accepted',
        ]);

        $this->actAs($user);

        $this->getJson('/api/v1/b2b/supplier/deliveries?status=invalid_status')
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
