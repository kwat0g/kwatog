<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Models\ChainStepRun;
use App\Common\Models\AuditLog;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Events\SupplierInvoiceSubmitted;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Models\PortalShippingDocument;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Models\SupplierShipment;
use App\Modules\B2B\Services\SupplierPortalService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for SupplierPortalService — verifies row-level scoping,
 * PO acknowledgment, shipment update, and delivery schedule submission
 * through the HTTP layer so controllers + services are exercised together.
 */
class SupplierPortalServiceTest extends TestCase
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
            'name' => 'SupUser-'.substr(uniqid(), -5),
            'email' => 'su-'.uniqid().'@t.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
        ]);
    }

    private function actAs(SupplierPortalUser $user): self
    {
        Sanctum::actingAs($user, ['*'], 'supplier_portal');

        return $this;
    }

    /**
     * A purchase order in a lifecycle state the supplier portal may see.
     *
     * PurchaseOrderFactory defaults to `draft`, but a PO only becomes
     * `portal_available` once it is approved (docs/PROCESS-FLOWS.md), so
     * SupplierPortalService hides draft/pending_approval/rejected/cancelled
     * rows. Every supplier-visibility fixture must therefore state the
     * lifecycle state it means instead of relying on the factory default.
     */
    private function makePo(Vendor $vendor, string $status = 'sent'): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill(['status' => $status])->save();

        return $po->refresh();
    }

    private function makePoItem(PurchaseOrder $po, string $quantity = '500.00'): PurchaseOrderItem
    {
        return PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => Item::factory()->create()->id,
            'description' => 'Relay Cover',
            'quantity' => $quantity,
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => Money::mul($quantity, '10.00'),
            'quantity_received' => '0.00',
        ]);
    }

    /**
     * @return array{user: SupplierPortalUser, purchaseOrder: PurchaseOrder}
     */
    private function makeInvoiceFixture(Vendor $vendor): array
    {
        $user = $this->makePortalUser($vendor);
        $item = Item::factory()->create();
        $purchaseOrder = $this->makePo($vendor, 'sent');
        $purchaseOrderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'item_id' => $item->id,
            'description' => 'Resin Type A',
            'quantity' => '2.00',
            'unit' => 'kg',
            'unit_price' => '100.00',
            'total' => '200.00',
            'quantity_received' => '0.00',
        ]);
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'vendor_id' => $vendor->id,
            'status' => 'accepted',
            'accepted_by' => User::factory()->create()->id,
            'accepted_at' => now(),
        ]);
        GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'item_id' => $item->id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity_received' => '2.00',
            'quantity_accepted' => '2.00',
            'unit_cost' => '100.00',
        ]);

        return ['user' => $user, 'purchaseOrder' => $purchaseOrder];
    }

    private function createBill(int $vendorId): Bill
    {
        $internalUser = User::factory()->create();

        return Bill::create([
            'bill_number' => 'BILL-T-'.substr(uniqid(), -5),
            'vendor_id' => $vendorId,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'is_vatable' => true,
            'subtotal' => '1000.00',
            'vat_amount' => '120.00',
            'total_amount' => '1120.00',
            'amount_paid' => '0.00',
            'balance' => '1120.00',
            'status' => 'unpaid',
            'created_by' => $internalUser->id,
        ]);
    }

    /* ─── Dashboard ──────────────────────────────────────────────── */

    public function test_dashboard_returns_own_data(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        PurchaseOrder::factory()->create([
            'vendor_id' => $vendor->id,
        ])->forceFill(['status' => 'approved'])->save();

        PurchaseOrder::factory()->create([
            'vendor_id' => $vendor->id,
        ])->forceFill(['status' => 'sent'])->save();

        // Other vendor's PO — must NOT count.
        $otherVendor = Vendor::factory()->create();
        PurchaseOrder::factory()->create([
            'vendor_id' => $otherVendor->id,
        ])->forceFill(['status' => 'approved'])->save();

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/supplier/dashboard');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.open_po_count'));
    }

    /* ─── Purchase Orders ────────────────────────────────────────── */

    public function test_purchase_orders_scoped_to_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        $this->makePo($vendor, 'approved');
        $this->makePo($vendor, 'sent');
        $this->makePo($vendor, 'partially_received');

        // Own vendor, but pre-approval: an internal draft is not the supplier's
        // business and must not be enumerable through the portal API.
        $draft = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);

        $other = Vendor::factory()->create();
        $this->makePo($other, 'sent');
        $this->makePo($other, 'approved');

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/supplier/purchase-orders');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
        $this->assertNotContains(
            $draft->hash_id,
            array_column($response->json('data'), 'id'),
            'A pre-approval purchase order must never reach the supplier portal list.',
        );
    }

    public function test_purchase_orders_reject_a_non_portal_status_filter(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        $this->makePo($vendor, 'sent');
        PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);

        $this->actAs($user);

        // Asking for a hidden state must return nothing rather than fall back
        // to the unfiltered list.
        $this->getJson('/api/v1/b2b/supplier/purchase-orders?status=draft')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_purchase_order_detail_forbidden_for_other_vendor(): void
    {
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $userA = $this->makePortalUser($vendorA);

        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendorB->id]);

        $this->actAs($userA);

        $response = $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}");

        $response->assertStatus(403);
    }

    public function test_purchase_order_detail_succeeds_for_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');

        $this->actAs($user);

        $response = $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}");

        $response->assertOk();
    }

    public function test_purchase_order_detail_hides_own_vendor_pre_approval_order(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $draft = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);

        $this->actAs($user);

        // The row belongs to this vendor, so this is not a 403 — it is simply
        // not portal-available yet, and must not be readable by hash ID.
        $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$draft->hash_id}")
            ->assertStatus(404);
    }

    /* ─── Acknowledge PO ─────────────────────────────────────────── */

    public function test_acknowledge_po_succeeds(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill(['status' => 'approved'])->save();

        $this->actAs($user);

        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/acknowledge", [
            'expected_delivery_date' => '2026-08-01',
        ]);

        $response->assertOk();
        $this->assertSame('sent', $po->fresh()->status->value);
        $this->assertTrue(ChainStepRun::query()
            ->where('chain', 'p2p')
            ->where('entity_type', 'purchase_order')
            ->where('entity_id', $po->id)
            ->where('step', 'sent')
            ->exists(), 'Supplier acknowledgement must publish the PO sent chain step.');
    }

    public function test_acknowledge_po_forbidden_for_other_vendor(): void
    {
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $userA = $this->makePortalUser($vendorA);

        $poB = PurchaseOrder::factory()->create(['vendor_id' => $vendorB->id]);
        $poB->forceFill(['status' => 'approved'])->save();

        $this->actAs($userA);

        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$poB->hash_id}/acknowledge", [
            'expected_delivery_date' => '2026-08-01',
        ]);

        $response->assertStatus(403);
    }

    public function test_acknowledge_po_rejects_non_approved_state_without_sent_handoff(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill(['status' => 'cancelled'])->save();

        $this->actAs($user);

        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/acknowledge", [
            'expected_delivery_date' => '2026-08-01',
        ])->assertStatus(422);

        $this->assertSame('cancelled', $po->fresh()->status->value);
        $this->assertFalse(ChainStepRun::query()
            ->where('chain', 'p2p')
            ->where('entity_type', 'purchase_order')
            ->where('entity_id', $po->id)
            ->where('step', 'sent')
            ->exists(), 'A rejected acknowledgement must not publish the PO sent chain step.');
    }

    public function test_submit_invoice_stages_unposted_draft_and_retries_idempotently(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $item = Item::factory()->create();
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill(['status' => 'sent'])->save();
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Resin Type A',
            'quantity' => '2.00',
            'unit' => 'kg',
            'unit_price' => '100.00',
            'total' => '200.00',
            'quantity_received' => '0.00',
        ]);
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id' => $vendor->id,
            'status' => 'accepted',
            'accepted_by' => User::factory()->create()->id,
            'accepted_at' => now(),
        ]);
        GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity_received' => '2.00',
            'quantity_accepted' => '2.00',
            'unit_cost' => '100.00',
        ]);

        $this->actAs($user);
        $payload = [
            'bill_number' => 'SUP-INV-001',
            'date' => '2026-08-10',
            'is_vatable' => false,
        ];

        $first = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", $payload);
        $first->assertStatus(201)
            ->assertJsonPath('data.status', 'draft');

        $bill = Bill::query()->where('vendor_id', $vendor->id)->where('bill_number', 'SUP-INV-001')->firstOrFail();
        $this->assertSame('draft', $bill->status->value);
        $this->assertNull($bill->journal_entry_id);
        $this->assertSame($item->id, $bill->items()->firstOrFail()->item_id);

        $second = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", $payload);
        $second->assertStatus(201)
            ->assertJsonPath('data.id', $bill->hash_id);
        $this->assertSame(1, Bill::query()
            ->where('vendor_id', $vendor->id)
            ->where('bill_number', 'SUP-INV-001')
            ->count());
    }

    public function test_submit_invoice_event_failure_preserves_committed_attachment(): void
    {
        $vendor = Vendor::factory()->create();
        $fixture = $this->makeInvoiceFixture($vendor);
        $user = $fixture['user'];
        $purchaseOrder = $fixture['purchaseOrder'];
        Storage::fake('local');
        Event::listen(SupplierInvoiceSubmitted::class, static function (): void {
            throw new \RuntimeException('Injected supplier invoice event failure.');
        });

        try {
            app(SupplierPortalService::class)->submitInvoice(
                $vendor->id,
                $user->id,
                $purchaseOrder,
                [
                    'bill_number' => 'SUP-INV-EVENT-FAILURE',
                    'date' => '2026-08-10',
                    'is_vatable' => false,
                ],
                UploadedFile::fake()->createWithContent('supplier-invoice.pdf', '%PDF-event-failure%'),
            );
            $this->fail('The injected invoice event failure should be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected supplier invoice event failure.', $exception->getMessage());
        }

        $bill = Bill::query()
            ->where('vendor_id', $vendor->id)
            ->where('bill_number', 'SUP-INV-EVENT-FAILURE')
            ->firstOrFail();
        $document = PortalShippingDocument::query()->where('bill_id', $bill->id)->firstOrFail();

        $this->assertTrue(Storage::disk('local')->exists($document->file_path));
        $this->assertDatabaseHas('portal_shipping_documents', [
            'id' => $document->id,
            'file_path' => $document->file_path,
            'bill_id' => $bill->id,
        ]);
    }

    public function test_submit_invoice_transaction_failure_cleans_provisional_attachment(): void
    {
        $vendor = Vendor::factory()->create();
        $fixture = $this->makeInvoiceFixture($vendor);
        $user = $fixture['user'];
        $purchaseOrder = $fixture['purchaseOrder'];
        Storage::fake('local');
        $contents = '%PDF-transaction-failure%';

        // Force the document insert to fail after the bill and provisional
        // file have been created inside the transaction.
        PortalShippingDocument::create([
            'purchase_order_id' => $purchaseOrder->id,
            'document_type' => 'supplier_invoice',
            'file_path' => 'portal/supplier-invoices/existing.pdf',
            'original_filename' => 'existing.pdf',
            'file_size_bytes' => strlen($contents),
            'content_sha256' => hash('sha256', $contents),
            'mime_type' => 'application/pdf',
            'uploaded_by' => $user->id,
            'uploaded_at' => now(),
        ]);

        $failed = false;
        try {
            app(SupplierPortalService::class)->submitInvoice(
                $vendor->id,
                $user->id,
                $purchaseOrder,
                [
                    'bill_number' => 'SUP-INV-TRANSACTION-FAILURE',
                    'date' => '2026-08-10',
                    'is_vatable' => false,
                ],
                UploadedFile::fake()->createWithContent('supplier-invoice.pdf', $contents),
            );
        } catch (\Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'The injected document uniqueness failure should be rethrown.');
        $this->assertDatabaseMissing('bills', [
            'vendor_id' => $vendor->id,
            'bill_number' => 'SUP-INV-TRANSACTION-FAILURE',
        ]);
        $this->assertSame(1, PortalShippingDocument::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->where('content_sha256', hash('sha256', $contents))
            ->count());
        $this->assertEmpty(Storage::disk('local')->allFiles('portal/supplier-invoices'));
    }

    public function test_submit_invoice_portal_audit_failure_preserves_committed_attachment(): void
    {
        $vendor = Vendor::factory()->create();
        $fixture = $this->makeInvoiceFixture($vendor);
        $user = $fixture['user'];
        $purchaseOrder = $fixture['purchaseOrder'];
        Storage::fake('local');
        Event::fake([SupplierInvoiceSubmitted::class]);
        DB::listen(static function (QueryExecuted $query): void {
            $bindings = array_map(static fn (mixed $binding): string => (string) $binding, $query->bindings);
            if (str_contains($query->sql, 'audit_logs') && in_array('supplier_inv.submit', $bindings, true)) {
                throw new \RuntimeException('Injected supplier portal audit failure.');
            }
        });

        try {
            app(SupplierPortalService::class)->submitInvoice(
                $vendor->id,
                $user->id,
                $purchaseOrder,
                [
                    'bill_number' => 'SUP-INV-AUDIT-FAILURE',
                    'date' => '2026-08-10',
                    'is_vatable' => false,
                ],
                UploadedFile::fake()->createWithContent('supplier-invoice.pdf', '%PDF-audit-failure%'),
            );
            $this->fail('The injected supplier portal audit failure should be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected supplier portal audit failure.', $exception->getMessage());
        }

        $bill = Bill::query()
            ->where('vendor_id', $vendor->id)
            ->where('bill_number', 'SUP-INV-AUDIT-FAILURE')
            ->firstOrFail();
        $document = PortalShippingDocument::query()->where('bill_id', $bill->id)->firstOrFail();

        $this->assertTrue(Storage::disk('local')->exists($document->file_path));
        $this->assertDatabaseHas('portal_shipping_documents', [
            'id' => $document->id,
            'file_path' => $document->file_path,
            'bill_id' => $bill->id,
        ]);
    }

    public function test_submit_invoice_rejects_bill_number_already_attached_to_another_po(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $item = Item::factory()->create();
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Resin Type A',
            'quantity' => '1.00',
            'unit' => 'kg',
            'unit_price' => '100.00',
            'total' => '100.00',
            'quantity_received' => '0.00',
        ]);

        $otherPo = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $existing = $this->createBill($vendor->id);
        $existing->forceFill([
            'bill_number' => 'SUP-INV-CONFLICT',
            'purchase_order_id' => $otherPo->id,
        ])->save();

        $this->actAs($user);

        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-INV-CONFLICT',
            'date' => '2026-08-10',
            'is_vatable' => false,
        ])->assertStatus(422);

        $this->assertSame($otherPo->id, $existing->fresh()->purchase_order_id);
        $this->assertSame(1, Bill::query()
            ->where('vendor_id', $vendor->id)
            ->where('bill_number', 'SUP-INV-CONFLICT')
            ->count());
    }

    /* ─── Shipment Update ────────────────────────────────────────── */

    public function test_shipment_update_succeeds(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');

        $this->actAs($user);

        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipment-update", [
            'shipped_date' => '2026-07-10',
            'carrier' => 'Maersk',
            'tracking_number' => 'MAEU1234567',
            'estimated_arrival' => '2026-07-15',
            'notes' => 'Container sealed at origin.',
        ]);

        $response->assertOk();
        $this->assertSame('2026-07-15', $po->fresh()->expected_delivery_date->toDateString());

        // Shipment state is a structured row, not free text appended to the PO
        // remarks: receiving and logistics have to be able to query the current
        // carrier/tracking value, and a retry must not contradict history.
        $shipment = SupplierShipment::query()->where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame('2026-07-10', $shipment->shipped_date->toDateString());
        $this->assertSame('Maersk', $shipment->carrier);
        $this->assertSame('MAEU1234567', $shipment->tracking_number);
        $this->assertSame('2026-07-15', $shipment->estimated_arrival->toDateString());
        $this->assertSame('Container sealed at origin.', $shipment->notes);
        $this->assertSame($user->id, $shipment->portal_user_id);
        $this->assertSame(1, $shipment->updates()->count());
    }

    public function test_shipment_update_replaces_current_state_and_snapshots_history(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');

        $this->actAs($user);
        $url = "/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipment-update";

        $this->postJson($url, ['carrier' => 'Maersk', 'tracking_number' => 'MAEU1234567'])->assertOk();
        $this->postJson($url, ['carrier' => 'DHL', 'tracking_number' => 'DHL-999'])->assertOk();

        // One current-state row per PO, latest values win, both updates retained.
        $this->assertSame(1, SupplierShipment::query()->where('purchase_order_id', $po->id)->count());
        $shipment = SupplierShipment::query()->where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame('DHL', $shipment->carrier);
        $this->assertSame('DHL-999', $shipment->tracking_number);
        $this->assertSame(2, $shipment->updates()->count());
    }

    public function test_shipment_update_rejects_terminal_po_without_mutation(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill([
            'status' => 'cancelled',
            'remarks' => 'Cancelled by Purchasing.',
        ])->save();

        $this->actAs($user);

        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipment-update", [
            'carrier' => 'DHL',
            'tracking_number' => 'DHL-001',
        ])->assertStatus(422);

        $fresh = $po->fresh();
        $this->assertSame('cancelled', $fresh->status->value);
        $this->assertSame('Cancelled by Purchasing.', $fresh->remarks);
    }

    public function test_shipment_update_forbidden_for_other_vendor(): void
    {
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $userA = $this->makePortalUser($vendorA);

        $poB = PurchaseOrder::factory()->create(['vendor_id' => $vendorB->id]);

        $this->actAs($userA);

        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$poB->hash_id}/shipment-update", [
            'carrier' => 'DHL',
        ]);

        $response->assertStatus(403);
    }

    /* ─── Invoices / Bills ───────────────────────────────────────── */

    public function test_invoices_exclude_draft_and_cancelled_ap_workflow_rows(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        $this->createBill($vendor->id);
        // A draft bill is an internal AP workflow row, not a supplier invoice.
        $this->createBill($vendor->id)->forceFill(['status' => 'draft'])->save();
        $this->createBill($vendor->id)->forceFill(['status' => 'cancelled'])->save();

        $this->actAs($user);

        $this->getJson('/api/v1/b2b/supplier/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_supplier_purchase_order_response_omits_internal_workflow_fields(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');
        $this->makePoItem($po);

        $this->actAs($user);

        $row = $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}")
            ->assertOk()
            ->json('data');

        // The internal PurchaseOrderResource exposes the approval chain, budget
        // warnings, internal remarks, the originating PR and dispatch evidence.
        // None of that is the supplier's business, and hiding it in the SPA is
        // not a boundary — the allowlist has to be server-side.
        foreach ([
            'current_approval_step', 'approval_steps', 'approvals', 'approved_by', 'approved_at',
            'budget_warning', 'budget_acknowledged_by', 'budget_acknowledged_at',
            'remarks', 'purchase_request', 'pr_number', 'created_by', 'creator',
            'dispatch', 'dispatch_status', 'vendor',
        ] as $internal) {
            $this->assertArrayNotHasKey($internal, $row, "Supplier PO contract must not expose `{$internal}`.");
        }

        // And the fields it does expose are the opaque/portal-safe ones.
        $this->assertSame($po->hash_id, $row['id']);
        $this->assertArrayHasKey('capabilities', $row);
        $this->assertTrue($row['capabilities']['can_update_shipment']);
        $this->assertFalse($row['capabilities']['can_acknowledge']);
    }

    public function test_supplier_finance_totals_are_exact_decimal_strings(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        // Values chosen so a float round-trip or a number_format() presentation
        // step is visible: the thousands separator alone breaks any client that
        // parses these as decimals, and decimal(15,2) exists to stop the drift.
        foreach (['1234567.89', '0.10', '0.20', '0.01'] as $balance) {
            $this->createBill($vendor->id)->forceFill([
                'subtotal' => $balance,
                'vat_amount' => '0.00',
                'total_amount' => $balance,
                'amount_paid' => '0.00',
                'balance' => $balance,
            ])->save();
        }

        $this->actAs($user);

        $dashboardTotal = $this->getJson('/api/v1/b2b/supplier/dashboard')
            ->assertOk()->json('data.total_unpaid_amount');
        $this->assertSame('1234568.20', $dashboardTotal);
        $this->assertIsString($dashboardTotal);
        $this->assertStringNotContainsString(',', $dashboardTotal);

        $soa = $this->getJson('/api/v1/b2b/supplier/statement-of-account')->assertOk()->json('data');
        $this->assertSame('1234568.20', $soa['total_outstanding']);

        // The buckets must reconcile to the total exactly, not approximately.
        $bucketSum = Money::add(...array_values($soa['aging_buckets']));
        $this->assertSame($soa['total_outstanding'], $bucketSum);
        foreach ($soa['aging_buckets'] as $key => $value) {
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', (string) $value, "aging bucket `{$key}` must be a 2-dp decimal string.");
        }
    }

    public function test_invoices_scoped_to_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        $this->createBill($vendor->id);
        $this->createBill($vendor->id);

        $other = Vendor::factory()->create();
        $this->createBill($other->id);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/supplier/invoices');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_supplier_invoice_pdf_renders_a_vendor_bill(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $bill = $this->createBill($vendor->id);

        $this->actAs($user);

        $this->get("/api/v1/b2b/supplier/invoices/{$bill->hash_id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_supplier_invoice_pdf_rejects_another_vendors_bill(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $otherBill = $this->createBill(Vendor::factory()->create()->id);

        $this->actAs($user);

        $this->get("/api/v1/b2b/supplier/invoices/{$otherBill->hash_id}/pdf")
            ->assertStatus(403);
    }

    public function test_invoice_detail_forbidden_for_other_vendor(): void
    {
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $userA = $this->makePortalUser($vendorA);

        $bill = $this->createBill($vendorB->id);

        $this->actAs($userA);

        $response = $this->getJson("/api/v1/b2b/supplier/invoices/{$bill->hash_id}");

        $response->assertStatus(403);
    }

    public function test_deliveries_are_scoped_and_use_the_supplier_allowlist(): void
    {
        $vendor = Vendor::factory()->create();
        $otherVendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        $purchaseOrder = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $ownDelivery = GoodsReceiptNote::factory()->create([
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $purchaseOrder->id,
            'received_date' => '2026-08-12',
            'status' => 'accepted',
            'remarks' => 'Internal receiving remarks must not cross the portal boundary.',
            'rejected_reason' => 'Internal rejection reason must not cross the portal boundary.',
        ]);

        $otherPurchaseOrder = PurchaseOrder::factory()->create(['vendor_id' => $otherVendor->id]);
        $otherDelivery = GoodsReceiptNote::factory()->create([
            'vendor_id' => $otherVendor->id,
            'purchase_order_id' => $otherPurchaseOrder->id,
            'grn_number' => 'GRN-OTHER-0001',
        ]);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/supplier/deliveries');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonMissing(['grn_number' => $otherDelivery->grn_number]);
        $row = $response->json('data.0');
        $this->assertSame([
            'id', 'grn_number', 'received_date', 'status', 'status_label', 'purchase_order',
        ], array_keys($row));
        $this->assertSame($ownDelivery->hash_id, $row['id']);
        $this->assertSame($ownDelivery->grn_number, $row['grn_number']);
        $this->assertSame('2026-08-12', $row['received_date']);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('Accepted', $row['status_label']);
        $this->assertSame([
            'id' => $purchaseOrder->hash_id,
            'po_number' => $purchaseOrder->po_number,
        ], $row['purchase_order']);

        foreach ([
            'numeric_id', 'vendor_id', 'received_by', 'accepted_by', 'qc_inspection_id',
            'journal_entry_id', 'rejected_reason', 'remarks', 'incoming_qc_handoff_message',
            'created_at', 'updated_at',
        ] as $sensitiveField) {
            $this->assertArrayNotHasKey($sensitiveField, $row);
        }
    }

    /* ─── Delivery Schedules ─────────────────────────────────────── */

    public function test_delivery_schedules_scoped_to_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);

        DeliverySchedule::create([
            'customer_id' => null, // supplier-submitted rows have no customer link
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $po->id,
            'month' => '2026-07',
            'status' => 'submitted',
            'lines' => [['item' => 'X', 'qty' => 100]],
        ]);

        // Other vendor's schedule — must NOT appear.
        $other = Vendor::factory()->create();
        $otherPo = PurchaseOrder::factory()->create(['vendor_id' => $other->id]);
        DeliverySchedule::create([
            'customer_id' => null, // supplier-submitted rows have no customer link
            'vendor_id' => $other->id,
            'purchase_order_id' => $otherPo->id,
            'month' => '2026-07',
            'status' => 'submitted',
            'lines' => [['item' => 'Y', 'qty' => 200]],
        ]);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/supplier/delivery-schedules');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_store_delivery_schedule_rejects_other_vendors_purchase_order(): void
    {
        $vendor = Vendor::factory()->create();
        $otherVendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $otherPo = $this->makePo($otherVendor, 'sent');
        $otherItem = $this->makePoItem($otherPo);

        $this->actAs($user);

        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $otherPo->hash_id,
            'month' => '2026-08',
            'lines' => [['purchase_order_item_id' => $otherItem->hash_id, 'quantity' => 500]],
        ])
            // Assert the vendor check specifically: an otherwise-complete payload
            // must fail on ownership, not incidentally on a missing field.
            ->assertStatus(422)
            ->assertJsonValidationErrors(['purchase_order_id']);

        $this->assertDatabaseMissing('delivery_schedules', [
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $otherPo->id,
        ]);
    }

    public function test_store_delivery_schedule_is_idempotent_for_same_po_and_month(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');
        $poItem = $this->makePoItem($po);

        $this->actAs($user);

        $payload = [
            'purchase_order_id' => $po->hash_id,
            'month' => '2026-08',
            'lines' => [['purchase_order_item_id' => $poItem->hash_id, 'quantity' => 500]],
        ];

        $first = $this->postJson('/api/v1/b2b/supplier/delivery-schedules', $payload);
        $first->assertStatus(201);

        // A double-click or a retried request must not stack a second row.
        $second = $this->postJson('/api/v1/b2b/supplier/delivery-schedules', $payload);
        $second->assertStatus(201);

        $this->assertDatabaseCount('delivery_schedules', 1);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_store_delivery_schedule_rejects_quantity_beyond_the_remaining_po_quantity(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');
        $poItem = $this->makePoItem($po, '100.00');

        $this->actAs($user);

        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => '2026-08',
            'lines' => [['purchase_order_item_id' => $poItem->hash_id, 'quantity' => 101]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('delivery_schedules', 0);
    }

    public function test_store_delivery_schedule_rejects_a_line_from_another_purchase_order(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');
        // Same vendor, different PO — the line still does not belong to $po.
        $foreignItem = $this->makePoItem($this->makePo($vendor, 'sent'));

        $this->actAs($user);

        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => '2026-08',
            'lines' => [['purchase_order_item_id' => $foreignItem->hash_id, 'quantity' => 10]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('delivery_schedules', 0);
    }

    public function test_store_delivery_schedule_rejects_a_pre_dispatch_purchase_order(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'approved');
        $poItem = $this->makePoItem($po);

        $this->actAs($user);

        // Approved is portal-visible but not yet dispatched; scheduling deliveries
        // against it would commit the supplier before the order is transmitted.
        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => '2026-08',
            'lines' => [['purchase_order_item_id' => $poItem->hash_id, 'quantity' => 500]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('delivery_schedules', 0);
    }

    public function test_shipping_document_upload_is_idempotent_for_same_file(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill(['status' => 'sent'])->save();
        $this->actAs($user);
        Storage::fake('local');

        $endpoint = "/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipping-documents";
        $payload = fn () => [
            'document_type' => 'packing_list',
            'file' => UploadedFile::fake()->create('packing-list.pdf', 120),
        ];

        $first = $this->postJson($endpoint, $payload());
        $first->assertStatus(201);

        // A double-click or a retried request with the same file must not
        // stack a second document row (or orphan a second stored file).
        $second = $this->postJson($endpoint, $payload());
        $second->assertStatus(201);

        $this->assertDatabaseCount('portal_shipping_documents', 1);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_shipping_document_upload_stores_a_revision_when_only_the_content_differs(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');
        $this->actAs($user);
        Storage::fake('local');

        $endpoint = "/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipping-documents";

        // Same filename, same byte size, different bytes — a corrected packing
        // list. The old dedupe key was (po, type, filename, size), which
        // silently returned the SUPERSEDED document and threw the revision
        // away; identity has to come from the content digest.
        $first = $this->postJson($endpoint, [
            'document_type' => 'packing_list',
            'file' => UploadedFile::fake()->createWithContent('packing-list.pdf', 'REV-A-CONTENT'),
        ]);
        $first->assertStatus(201);

        $second = $this->postJson($endpoint, [
            'document_type' => 'packing_list',
            'file' => UploadedFile::fake()->createWithContent('packing-list.pdf', 'REV-B-CONTENT'),
        ]);
        $second->assertStatus(201);

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('portal_shipping_documents', 2);
        $this->assertSame(2, \App\Modules\B2B\Models\PortalShippingDocument::query()
            ->where('purchase_order_id', $po->id)
            ->distinct()
            ->count('content_sha256'));
    }

    public function test_shipping_document_resource_returns_opaque_identifiers(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');
        $this->actAs($user);
        Storage::fake('local');

        $row = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipping-documents", [
            'document_type' => 'packing_list',
            'file' => UploadedFile::fake()->create('packing-list.pdf', 12),
        ])->assertStatus(201)->json('data');

        // Raw integer keys make tenant-boundary mistakes and enumeration easier.
        $this->assertSame($po->hash_id, $row['purchase_order_id']);
        $this->assertIsNotNumeric($row['purchase_order_id']);
        $this->assertSame($user->hash_id, $row['uploaded_by']['id'] ?? null);
        $this->assertSame($user->name, $row['uploaded_by']['name'] ?? null);
    }

    public function test_shipping_document_upload_is_rejected_before_dispatch(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'approved');
        $this->actAs($user);
        Storage::fake('local');

        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipping-documents", [
            'document_type' => 'packing_list',
            'file' => UploadedFile::fake()->create('packing-list.pdf', 12),
        ])->assertStatus(422);

        $this->assertDatabaseCount('portal_shipping_documents', 0);
        // A rejected upload must not leave the stored file behind.
        $this->assertEmpty(Storage::disk('local')->allFiles("portal/shipping-docs/{$po->id}"));
    }

    public function test_shipping_document_download_scoped_to_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill(['status' => 'sent'])->save();
        // Create fixtures before acting as the portal user: HasAuditLog reads
        // Auth::id() (the portal guard after Sanctum::actingAs) and would hit
        // the audit_logs.users FK for portal-user ids.
        $other = Vendor::factory()->create();
        $this->actAs($user);
        Storage::fake('local');

        $upload = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipping-documents", [
            'document_type' => 'packing_list',
            'file' => UploadedFile::fake()->create('packing-list.pdf', 120),
        ]);
        $upload->assertStatus(201);
        $docId = $upload->json('data.id');

        // Own vendor can download the stored file (the SPA fetches this with
        // the portal Bearer token — never as a bare new-tab link).
        $download = $this->get("/api/v1/b2b/supplier/shipping-documents/{$docId}/download");
        $download->assertOk();
        $this->assertStringContainsString('packing-list.pdf', (string) $download->headers->get('content-disposition'));

        // Another vendor must be blocked from downloading it — the tenancy
        // middleware scopes PortalShippingDocument to the current vendor, so
        // the cross-tenant lookup 404s (no existence leak).
        $this->actAs($this->makePortalUser($other));
        $this->get("/api/v1/b2b/supplier/shipping-documents/{$docId}/download")->assertStatus(404);
    }

    /* ─── Auth guard ─────────────────────────────────────────────── */

    public function test_portal_mutations_record_the_supplier_principal_not_just_the_system_actor(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');

        $this->actAs($user);
        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipment-update", [
            'carrier' => 'Maersk',
        ])->assertOk();

        // Portal writes impersonate a system user so audit_logs.user_id can
        // satisfy its FK. Without this row an audit reviewer sees a system-user
        // write and cannot tell WHICH supplier account made it.
        $row = AuditLog::query()
            ->where('action', 'supplier_ship.update')
            ->where('model_type', PurchaseOrder::class)
            ->where('model_id', $po->id)
            ->firstOrFail();

        $this->assertSame('supplier_portal', $row->actor_type);
        $this->assertNull($row->user_id, 'A portal principal is not an internal user.');
        $this->assertSame($user->id, $row->new_values['portal_user_id'] ?? null);
        $this->assertSame($vendor->id, $row->new_values['vendor_id'] ?? null);
        $this->assertNotEmpty($row->ip_address);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/b2b/supplier/dashboard')->assertStatus(401);
        $this->getJson('/api/v1/b2b/supplier/purchase-orders')->assertStatus(401);
        $this->getJson('/api/v1/b2b/supplier/invoices')->assertStatus(401);
        $this->getJson('/api/v1/b2b/supplier/deliveries')->assertStatus(401);
    }
}
