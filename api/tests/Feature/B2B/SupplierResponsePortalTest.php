<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Support\Money;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseOrderResponse;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Supplier-response negotiation through the B2B supplier portal:
 * the respond endpoint, the can_respond capability, the latest_response
 * contract, and the D2 accepted-GRN invoice-quantity fix.
 */
class SupplierResponsePortalTest extends TestCase
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
            'item_id'           => Item::factory()->create()->id,
            'description'       => 'Relay Cover',
            'quantity'          => $quantity,
            'unit'              => 'pcs',
            'unit_price'        => '10.00',
            'total'             => Money::mul($quantity, '10.00'),
            'quantity_received' => '0.00',
        ]);
    }

    private function respondUrl(PurchaseOrder $po): string
    {
        return "/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/respond";
    }

    /* ─── respond endpoint ───────────────────────────────────────── */

    public function test_portal_accept_response_acknowledges_the_po(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');

        $this->actAs($user);

        $response = $this->postJson($this->respondUrl($po), [
            'type'                   => 'accept',
            'proposed_delivery_date' => '2026-10-20',
            'notes'                  => 'Accepted as ordered.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'accept')
            ->assertJsonPath('data.status', 'accepted');

        $fresh = $po->fresh();
        $this->assertSame('acknowledged', $fresh->status->value);
        $this->assertSame('2026-10-20', $fresh->confirmed_delivery_date->toDateString());

        $this->assertDatabaseHas('purchase_order_responses', [
            'purchase_order_id' => $po->id,
            'vendor_id'         => $vendor->id,
            'response_type'     => 'accept',
            'status'            => 'accepted',
        ]);
    }

    public function test_portal_propose_response_stores_items(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');
        $item = $this->makePoItem($po, '100.00');

        $this->actAs($user);

        $this->postJson($this->respondUrl($po), [
            'type'  => 'propose',
            'items' => [[
                'purchase_order_item_id' => $item->hash_id,
                'proposed_quantity'      => '120.00',
                'proposed_unit_price'    => '9.50',
                'reason'                 => 'Cavity capacity.',
            ]],
        ])->assertStatus(201)->assertJsonPath('data.type', 'propose');

        $this->assertSame('supplier_proposed', $po->fresh()->status->value);

        $response = PurchaseOrderResponse::query()
            ->where('purchase_order_id', $po->id)
            ->firstOrFail();
        $this->assertSame('pending', $response->status->value);
        $this->assertSame(1, $response->items()->count());
        $stored = $response->items()->firstOrFail();
        $this->assertSame((int) $item->id, (int) $stored->purchase_order_item_id);
        $this->assertSame('120.00', (string) $stored->proposed_quantity);
        $this->assertSame('9.50', (string) $stored->proposed_unit_price);
        $this->assertSame('Cavity capacity.', $stored->reason);
    }

    public function test_portal_propose_requires_at_least_one_item(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');

        $this->actAs($user);

        $this->postJson($this->respondUrl($po), [
            'type'  => 'propose',
            'items' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['items']);
    }

    public function test_portal_decline_response_marks_the_po_supplier_declined(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');

        $this->actAs($user);

        $this->postJson($this->respondUrl($po), [
            'type'  => 'decline',
            'notes' => 'Cannot fulfil.',
        ])->assertStatus(201)->assertJsonPath('data.type', 'decline');

        $this->assertSame('supplier_declined', $po->fresh()->status->value);
    }

    public function test_portal_respond_is_forbidden_for_another_vendor(): void
    {
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $userA = $this->makePortalUser($vendorA);
        $poB = $this->makePo($vendorB, 'sent');

        $this->actAs($userA);

        $this->postJson($this->respondUrl($poB), ['type' => 'accept'])->assertStatus(403);

        $this->assertSame('sent', $poB->fresh()->status->value);
        $this->assertDatabaseCount('purchase_order_responses', 0);
    }

    public function test_portal_respond_rejects_a_received_po(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'received');

        $this->actAs($user);

        $this->postJson($this->respondUrl($po), ['type' => 'accept'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This purchase order is not open to supplier response.');

        $this->assertSame('received', $po->fresh()->status->value);
    }

    /* ─── can_respond capability ─────────────────────────────────── */

    public function test_can_respond_capability_tracks_the_negotiable_states(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $this->makePo($vendor, 'sent');
        $this->makePo($vendor, 'supplier_declined');

        $this->actAs($user);

        $rows = $this->getJson('/api/v1/b2b/supplier/purchase-orders')->assertOk()->json('data');
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertTrue($row['capabilities']['can_respond']);
            $this->assertArrayHasKey('can_acknowledge', $row['capabilities']);
            $this->assertArrayHasKey('can_update_shipment', $row['capabilities']);
            $this->assertArrayHasKey('can_upload_document', $row['capabilities']);
            $this->assertArrayHasKey('can_submit_invoice', $row['capabilities']);
            $this->assertArrayHasKey('can_schedule_delivery', $row['capabilities']);
        }

        // A received PO is not negotiable.
        $received = $this->makePo($vendor, 'received');
        $row = $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$received->hash_id}")
            ->assertOk()->json('data');
        $this->assertFalse($row['capabilities']['can_respond']);
    }

    /* ─── latest_response contract ───────────────────────────────── */

    public function test_po_detail_returns_the_latest_response_block(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');
        $item = $this->makePoItem($po, '100.00');

        $this->actAs($user);

        $this->postJson($this->respondUrl($po), [
            'type'                   => 'propose',
            'proposed_delivery_date' => '2026-11-05',
            'notes'                  => 'Proposing adjusted terms.',
            'items'                  => [[
                'purchase_order_item_id' => $item->hash_id,
                'proposed_quantity'      => '110.00',
                'proposed_unit_price'    => '9.00',
                'reason'                 => 'Material cost.',
            ]],
        ])->assertStatus(201);

        $block = $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}")
            ->assertOk()
            ->json('data.latest_response');

        $this->assertSame([
            'id', 'type', 'status', 'proposed_delivery_date', 'notes',
            'responded_at', 'resolved_at', 'resolution_notes', 'items',
        ], array_keys($block));
        $this->assertSame('propose', $block['type']);
        $this->assertSame('pending', $block['status']);
        $this->assertSame('2026-11-05', $block['proposed_delivery_date']);
        $this->assertSame('Proposing adjusted terms.', $block['notes']);
        $this->assertNotNull($block['responded_at']);
        $this->assertNull($block['resolved_at']);
        $this->assertNull($block['resolution_notes']);

        $this->assertCount(1, $block['items']);
        $this->assertSame($item->hash_id, $block['items'][0]['purchase_order_item_id']);
        $this->assertSame('110.00', $block['items'][0]['proposed_quantity']);
        $this->assertSame('9.00', $block['items'][0]['proposed_unit_price']);
        $this->assertSame('Material cost.', $block['items'][0]['reason']);
    }

    public function test_po_detail_latest_response_is_null_before_any_reply(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');

        $this->actAs($user);

        $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.latest_response', null);
    }

    public function test_po_detail_exposes_confirmed_delivery_date(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');
        $po->forceFill(['confirmed_delivery_date' => '2026-09-30'])->save();

        $this->actAs($user);

        $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.confirmed_delivery_date', '2026-09-30');
    }

    /* ─── D2 — invoice lines follow the accepted GRN ─────────────── */

    public function test_submit_invoice_bills_the_accepted_grn_quantities_not_the_ordered_quantity(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $item = Item::factory()->create();
        $po = $this->makePo($vendor, 'sent');
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Resin Type A',
            'quantity'          => '2.00',
            'unit'              => 'kg',
            'unit_price'        => '100.00',
            'total'             => '200.00',
            'quantity_received' => '0.00',
        ]);
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $vendor->id,
            'status'            => 'accepted',
            'accepted_by'       => User::factory()->create()->id,
            'accepted_at'       => now(),
        ]);
        GrnItem::create([
            'goods_receipt_note_id'    => $grn->id,
            'purchase_order_item_id'   => $poItem->id,
            'item_id'                  => $item->id,
            'location_id'              => WarehouseLocation::factory()->create()->id,
            // Only 1.5 of the 2.0 ordered kg passed QC, at a revised cost.
            'quantity_received'        => '1.50',
            'quantity_accepted'        => '1.50',
            'unit_cost'                => '90.00',
        ]);

        $this->actAs($user);

        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-INV-GRN-QTY',
            'date'        => '2026-08-10',
            'is_vatable'  => false,
        ])->assertStatus(201);

        $bill = \App\Modules\Accounting\Models\Bill::query()
            ->where('vendor_id', $vendor->id)
            ->where('bill_number', 'SUP-INV-GRN-QTY')
            ->firstOrFail();

        $line = $bill->items()->firstOrFail();
        // D2 — the payable is 1.50 × 90.00 = 135.00, never 2.00 × 100.00.
        $this->assertSame('1.50', (string) $line->quantity);
        $this->assertSame('90.00', (string) $line->unit_price);
        $this->assertSame('135.00', (string) $line->total);
        $this->assertSame('135.00', (string) $bill->total_amount);
    }
}
