<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Support\Money;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderResponseStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderResponseType;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseOrderResponse;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\SupplierResponseService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Supplier response / negotiation lifecycle (Purchasing side).
 *
 * Covers respond() transitions (accept/propose/decline), the supersede rule
 * for re-submissions, and resolve() — the internal decision that either
 * applies a counter-offer with exact decimal money math or returns the PO to
 * `sent` — plus the A3 cancel guard and B1 is_billable audit fixes.
 */
class SupplierResponseTest extends TestCase
{
    use RefreshDatabase;

    private SupplierResponseService $svc;
    private PurchaseOrderService $poService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        $this->svc = app(SupplierResponseService::class);
        $this->poService = app(PurchaseOrderService::class);
    }

    /* ─── Helpers ────────────────────────────────────────────────── */

    private function makePo(Vendor $vendor, string $status = 'sent'): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill(['status' => $status])->save();

        return $po->refresh();
    }

    private function makePoItem(PurchaseOrder $po, string $quantity, string $unitPrice, ?Item $item = null): PurchaseOrderItem
    {
        return PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => ($item ?? Item::factory()->create())->id,
            'description'       => 'Relay Cover',
            'quantity'          => $quantity,
            'unit'              => 'pcs',
            'unit_price'        => $unitPrice,
            'total'             => Money::mul($quantity, $unitPrice),
            'quantity_received' => '0.00',
        ]);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }

    /* ─── respond(): supplier side ───────────────────────────────── */

    public function test_accept_response_moves_po_to_acknowledged_and_resolves_immediately(): void
    {
        $vendor = Vendor::factory()->create();
        $po = $this->makePo($vendor, 'sent');

        $response = $this->svc->respond($po, (int) $vendor->id, null, [
            'type'                   => 'accept',
            'proposed_delivery_date' => '2026-10-15',
            'notes'                  => 'We can deliver on time.',
        ]);

        $this->assertSame(PurchaseOrderResponseType::Accept, $response->response_type);
        $this->assertSame(PurchaseOrderResponseStatus::Accepted, $response->status);
        $this->assertSame('2026-10-15', $response->proposed_delivery_date->toDateString());

        $fresh = $po->fresh();
        $this->assertSame(PurchaseOrderStatus::Acknowledged, $fresh->status);
        // The supplier's agreed date is confirmed_delivery_date; OGAMI's
        // required date must never be overwritten by the supplier.
        $this->assertSame('2026-10-15', $fresh->confirmed_delivery_date->toDateString());
        $this->assertSame($po->expected_delivery_date->toDateString(), $fresh->expected_delivery_date->toDateString());
    }

    public function test_propose_response_moves_po_to_supplier_proposed_and_stores_items(): void
    {
        $vendor = Vendor::factory()->create();
        $po = $this->makePo($vendor, 'sent');
        $item = $this->makePoItem($po, '100.00', '10.00');

        $response = $this->svc->respond($po, (int) $vendor->id, null, [
            'type'                   => 'propose',
            'proposed_delivery_date' => '2026-11-01',
            'items'                  => [[
                'purchase_order_item_id' => $item->hash_id,
                'proposed_quantity'      => '120.00',
                'proposed_unit_price'    => '9.50',
                'reason'                 => 'Tooling adjustment.',
            ]],
        ]);

        $this->assertSame(PurchaseOrderResponseType::Propose, $response->response_type);
        $this->assertSame(PurchaseOrderResponseStatus::Pending, $response->status);
        $this->assertSame(PurchaseOrderStatus::SupplierProposed, $po->fresh()->status);

        $this->assertCount(1, $response->items);
        $stored = $response->items->first();
        $this->assertSame((int) $item->id, (int) $stored->purchase_order_item_id);
        $this->assertSame('120.00', (string) $stored->proposed_quantity);
        $this->assertSame('9.50', (string) $stored->proposed_unit_price);
        $this->assertSame('Tooling adjustment.', $stored->reason);

        // The original PO line is untouched until purchasing accepts.
        $item->refresh();
        $this->assertSame('100.00', (string) $item->quantity);
        $this->assertSame('10.00', (string) $item->unit_price);
    }

    public function test_decline_response_moves_po_to_supplier_declined(): void
    {
        $vendor = Vendor::factory()->create();
        $po = $this->makePo($vendor, 'sent');

        $response = $this->svc->respond($po, (int) $vendor->id, null, [
            'type'  => 'decline',
            'notes' => 'Cannot fulfil at this price.',
        ]);

        $this->assertSame(PurchaseOrderResponseType::Decline, $response->response_type);
        $this->assertSame(PurchaseOrderResponseStatus::Pending, $response->status);
        $this->assertSame(PurchaseOrderStatus::SupplierDeclined, $po->fresh()->status);
    }

    public function test_respond_requires_po_visibility_to_the_supplier(): void
    {
        $vendor = Vendor::factory()->create();
        $other = Vendor::factory()->create();
        $po = $this->makePo($other, 'sent');

        $this->expectException(\App\Common\Exceptions\ForbiddenActionException::class);

        $this->svc->respond($po, (int) $vendor->id, null, ['type' => 'accept']);
    }

    public function test_respond_refuses_a_po_outside_the_negotiable_states(): void
    {
        $vendor = Vendor::factory()->create();
        $received = $this->makePo($vendor, 'received');
        $draft = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]); // draft

        foreach ([$received, $draft] as $po) {
            try {
                $this->svc->respond($po, (int) $vendor->id, null, ['type' => 'accept']);
                $this->fail("Expected BusinessRuleException for PO in state {$po->status->value}.");
            } catch (\App\Common\Exceptions\BusinessRuleException $e) {
                $this->assertSame('This purchase order is not open to supplier response.', $e->getMessage());
            }
        }
    }

    public function test_resubmission_supersedes_the_prior_pending_response(): void
    {
        $vendor = Vendor::factory()->create();
        $po = $this->makePo($vendor, 'sent');
        $item = $this->makePoItem($po, '100.00', '10.00');

        $first = $this->svc->respond($po, (int) $vendor->id, null, [
            'type'  => 'propose',
            'items' => [[
                'purchase_order_item_id' => $item->hash_id,
                'proposed_quantity'      => '90.00',
            ]],
        ]);
        $this->assertSame(PurchaseOrderResponseStatus::Pending, $first->status);

        $second = $this->svc->respond($po, (int) $vendor->id, null, [
            'type'  => 'propose',
            'items' => [[
                'purchase_order_item_id' => $item->hash_id,
                'proposed_quantity'      => '110.00',
            ]],
        ]);

        $this->assertSame(PurchaseOrderResponseStatus::Superseded, $first->fresh()->status);
        $this->assertSame(PurchaseOrderResponseStatus::Pending, $second->fresh()->status);

        // Only one pending response remains actionable for the PO.
        $this->assertSame(
            1,
            PurchaseOrderResponse::query()
                ->where('purchase_order_id', $po->id)
                ->where('status', PurchaseOrderResponseStatus::Pending)
                ->count(),
        );
    }

    public function test_supplier_response_notifies_purchasing_approvers(): void
    {
        $approver = $this->userWithRole('vice_president'); // holds purchasing.po.approve
        $vendor = Vendor::factory()->create();
        $po = $this->makePo($vendor, 'sent');

        $this->svc->respond($po, (int) $vendor->id, null, ['type' => 'decline']);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $approver->id,
            'type'          => 'supplier.po_responded',
        ]);
    }

    /* ─── resolve(): purchasing side ─────────────────────────────── */

    public function test_accept_of_propose_applies_counter_offer_and_recomputes_totals_exactly(): void
    {
        $vendor = Vendor::factory()->create();
        $po = PurchaseOrder::factory()->create([
            'vendor_id'     => $vendor->id,
            'is_vatable'    => true,
            'subtotal'      => '2000.00',
            'vat_amount'    => '240.00',
            'total_amount'  => '2240.00',
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Sent])->save();
        $itemA = $this->makePoItem($po, '100.00', '10.00'); // 1000.00
        $itemB = $this->makePoItem($po, '50.00', '20.00');  // 1000.00

        $response = $this->svc->respond($po, (int) $vendor->id, null, [
            'type'                   => 'propose',
            'proposed_delivery_date' => '2026-11-10',
            'items'                  => [
                [
                    'purchase_order_item_id' => $itemA->hash_id,
                    'proposed_quantity'      => '120.00',
                    'proposed_unit_price'    => '9.50',
                ],
                [
                    'purchase_order_item_id' => $itemB->hash_id,
                    'proposed_unit_price'    => '25.00',
                ],
            ],
        ]);

        $approver = $this->userWithRole('vice_president');
        Mail::fake();

        $resolved = $this->svc->resolve($response, $approver, 'accept', 'Agreed.');

        $this->assertSame(PurchaseOrderResponseStatus::Accepted, $resolved->status);
        $this->assertSame($approver->id, $resolved->resolved_by);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame('Agreed.', $resolved->resolution_notes);

        // Counter-offer applied line-by-line, exact decimals:
        // A: 120 × 9.50 = 1140.00 ; B: 50 × 25.00 = 1250.00
        $itemA->refresh();
        $itemB->refresh();
        $this->assertSame('120.00', (string) $itemA->quantity);
        $this->assertSame('9.50', (string) $itemA->unit_price);
        $this->assertSame('1140.00', (string) $itemA->total);
        $this->assertSame('50.00', (string) $itemB->quantity);
        $this->assertSame('25.00', (string) $itemB->unit_price);
        $this->assertSame('1250.00', (string) $itemB->total);

        // subtotal 2390.00 → vat 12% = 286.80 → total 2676.80
        $fresh = $po->fresh();
        $this->assertSame('2390.00', (string) $fresh->subtotal);
        $this->assertSame('286.80', (string) $fresh->vat_amount);
        $this->assertSame('2676.80', (string) $fresh->total_amount);
        $this->assertSame('2026-11-10', $fresh->confirmed_delivery_date->toDateString());
        $this->assertSame(PurchaseOrderStatus::Acknowledged, $fresh->status);
        // Below the 50k VP threshold → no new approval required.
        $this->assertFalse((bool) $fresh->requires_vp_approval);
    }

    public function test_accept_of_propose_crossing_vp_threshold_refreshes_requires_vp_approval(): void
    {
        $vendor = Vendor::factory()->create();
        $po = PurchaseOrder::factory()->create([
            'vendor_id'  => $vendor->id,
            'is_vatable' => false,
            'subtotal'   => '1000.00', 'vat_amount' => '0.00', 'total_amount' => '1000.00',
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Sent])->save();
        $item = $this->makePoItem($po, '1.00', '1000.00');

        $response = $this->svc->respond($po, (int) $vendor->id, null, [
            'type'  => 'propose',
            'items' => [[
                'purchase_order_item_id' => $item->hash_id,
                'proposed_unit_price'    => '60000.00',
            ]],
        ]);

        $approver = $this->userWithRole('vice_president');
        Mail::fake();

        $this->svc->resolve($response, $approver, 'accept');

        $fresh = $po->fresh();
        $this->assertSame('60000.00', (string) $fresh->total_amount);
        $this->assertTrue((bool) $fresh->requires_vp_approval);
    }

    public function test_accept_of_decline_records_the_decision_but_leaves_po_supplier_declined(): void
    {
        $vendor = Vendor::factory()->create();
        $po = $this->makePo($vendor, 'sent');
        $response = $this->svc->respond($po, (int) $vendor->id, null, ['type' => 'decline']);
        $this->assertSame(PurchaseOrderStatus::SupplierDeclined, $po->fresh()->status);

        $approver = $this->userWithRole('vice_president');
        Mail::fake();

        $resolved = $this->svc->resolve($response, $approver, 'accept');

        $this->assertSame(PurchaseOrderResponseStatus::Accepted, $resolved->status);
        // The order is not resurrected; purchasing must cancel or re-source.
        $this->assertSame(PurchaseOrderStatus::SupplierDeclined, $po->fresh()->status);
    }

    public function test_reject_returns_po_to_sent_so_supplier_can_respond_again(): void
    {
        $vendor = Vendor::factory()->create();
        $po = $this->makePo($vendor, 'sent');
        $item = $this->makePoItem($po, '100.00', '10.00');

        $response = $this->svc->respond($po, (int) $vendor->id, null, [
            'type'  => 'propose',
            'items' => [[
                'purchase_order_item_id' => $item->hash_id,
                'proposed_quantity'      => '90.00',
            ]],
        ]);
        $this->assertSame(PurchaseOrderStatus::SupplierProposed, $po->fresh()->status);

        $approver = $this->userWithRole('vice_president');
        Mail::fake();

        $resolved = $this->svc->resolve($response, $approver, 'reject', 'Price is not competitive.');

        $this->assertSame(PurchaseOrderResponseStatus::Rejected, $resolved->status);
        $this->assertSame('Price is not competitive.', $resolved->resolution_notes);
        $this->assertSame(PurchaseOrderStatus::Sent, $po->fresh()->status);

        // The supplier can file a fresh reply after the rejection.
        $item->refresh();
        $this->assertSame('100.00', (string) $item->quantity, 'Rejected proposal must not mutate the PO line.');
    }

    public function test_reject_of_decline_returns_po_to_sent_for_reconsideration(): void
    {
        $vendor = Vendor::factory()->create();
        $po = $this->makePo($vendor, 'sent');
        $response = $this->svc->respond($po, (int) $vendor->id, null, ['type' => 'decline']);
        $this->assertSame(PurchaseOrderStatus::SupplierDeclined, $po->fresh()->status);

        $approver = $this->userWithRole('vice_president');
        Mail::fake();

        $this->svc->resolve($response, $approver, 'reject', 'Reconsider — we can adjust the terms.');

        $this->assertSame(PurchaseOrderStatus::Sent, $po->fresh()->status);
    }

    public function test_resolve_refuses_a_non_pending_response(): void
    {
        $vendor = Vendor::factory()->create();
        $po = $this->makePo($vendor, 'sent');
        $response = $this->svc->respond($po, (int) $vendor->id, null, ['type' => 'accept']);
        // An accept resolves immediately, so it can never be resolved again.
        $this->assertSame(PurchaseOrderResponseStatus::Accepted, $response->status);

        $approver = $this->userWithRole('vice_president');

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('Only a pending supplier response can be resolved.');

        $this->svc->resolve($response, $approver, 'accept');
    }

    /* ─── A3 — cancel with only a draft GRN ──────────────────────── */

    public function test_cancel_succeeds_when_only_a_draft_grn_exists(): void
    {
        $user = User::factory()->create();
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Sent->value,
            'created_by' => $user->id,
        ]);
        GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $po->vendor_id,
            'received_by'       => $user->id,
            'status'            => 'draft',
        ]);

        $result = $this->poService->cancel($po, 'Supplier could not meet the terms.');

        $this->assertSame(PurchaseOrderStatus::Cancelled, $result->status);
    }

    public function test_cancel_refuses_when_a_non_draft_grn_exists(): void
    {
        $user = User::factory()->create();
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Sent->value,
            'created_by' => $user->id,
        ]);
        GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $po->vendor_id,
            'received_by'       => $user->id,
            'status'            => 'pending_qc',
        ]);

        try {
            $this->poService->cancel($po, 'too late');
            $this->fail('A PO with a non-draft GRN must refuse cancellation.');
        } catch (\App\Common\Exceptions\BusinessRuleException $e) {
            $this->assertSame('Cannot cancel a PO with received goods.', $e->getMessage());
        }

        $this->assertSame(PurchaseOrderStatus::Sent, $po->fresh()->status);
    }

    /* ─── B1 — is_billable reflects an accepted GRN ──────────────── */

    public function test_is_billable_requires_an_accepted_grn(): void
    {
        $buyer = $this->userWithRole('purchasing_officer');
        $vendor = Vendor::factory()->create();
        $po = $this->makePo($vendor, 'sent');
        $this->makePoItem($po, '10.00', '5.00');

        $response = $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}");
        $response->assertOk();
        $this->assertFalse($response->json('data.is_billable'));

        GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $vendor->id,
            'received_by'       => $buyer->id,
            'status'            => 'accepted',
        ]);

        $response = $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}");
        $response->assertOk();
        $this->assertTrue($response->json('data.is_billable'));
    }

    public function test_is_billable_stays_false_for_a_pending_qc_receipt(): void
    {
        $buyer = $this->userWithRole('purchasing_officer');
        $vendor = Vendor::factory()->create();
        $po = $this->makePo($vendor, 'sent');
        $this->makePoItem($po, '10.00', '5.00');

        GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $vendor->id,
            'received_by'       => $buyer->id,
            'status'            => 'pending_qc',
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.is_billable', false);
    }

    /* ─── Internal response endpoints ────────────────────────────── */

    public function test_internal_responses_index_lists_rows_for_the_po(): void
    {
        $buyer = $this->userWithRole('purchasing_officer');
        $vendor = Vendor::factory()->create();
        $po = $this->makePo($vendor, 'sent');
        $this->svc->respond($po, (int) $vendor->id, null, ['type' => 'decline']);

        $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/responses")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'decline')
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_internal_responses_index_requires_purchasing_view(): void
    {
        $outsider = $this->userWithRole('employee');
        $vendor = Vendor::factory()->create();
        $po = $this->makePo($vendor, 'sent');

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/responses")
            ->assertForbidden();
    }

    public function test_internal_accept_endpoint_resolves_a_proposal(): void
    {
        $approver = $this->userWithRole('vice_president');
        $vendor = Vendor::factory()->create();
        $po = PurchaseOrder::factory()->create([
            'vendor_id'  => $vendor->id,
            'is_vatable' => false,
            'subtotal'   => '1000.00', 'vat_amount' => '0.00', 'total_amount' => '1000.00',
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Sent])->save();
        $item = $this->makePoItem($po, '10.00', '100.00');

        $response = $this->svc->respond($po, (int) $vendor->id, null, [
            'type'  => 'propose',
            'items' => [[
                'purchase_order_item_id' => $item->hash_id,
                'proposed_unit_price'    => '120.00',
            ]],
        ]);
        Mail::fake();

        $this->actingAs($approver, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-order-responses/{$response->hash_id}/accept", ['notes' => 'Approved.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.resolution_notes', 'Approved.');

        $this->assertSame('acknowledged', $po->fresh()->status->value);
        $item->refresh();
        $this->assertSame('120.00', (string) $item->unit_price);
        $this->assertSame('1200.00', (string) $item->total);

        Mail::assertQueued(\App\Modules\Purchasing\Mail\SupplierPoDecisionMail::class, function ($mail) use ($po) {
            return $mail->decision === 'accept' && (int) $mail->purchaseOrder->id === (int) $po->id;
        });
    }

    public function test_internal_reject_endpoint_returns_po_to_sent(): void
    {
        $approver = $this->userWithRole('vice_president');
        $vendor = Vendor::factory()->create();
        $po = $this->makePo($vendor, 'sent');
        $response = $this->svc->respond($po, (int) $vendor->id, null, ['type' => 'propose']);
        Mail::fake();

        $this->actingAs($approver, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-order-responses/{$response->hash_id}/reject", ['reason' => 'Unacceptable terms.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame('sent', $po->fresh()->status->value);
    }

    public function test_internal_accept_endpoint_requires_purchasing_po_approve(): void
    {
        $outsider = $this->userWithRole('employee');
        $vendor = Vendor::factory()->create();
        $po = $this->makePo($vendor, 'sent');
        $response = $this->svc->respond($po, (int) $vendor->id, null, ['type' => 'accept']);

        $this->actingAs($outsider, 'sanctum')
            ->patchJson("/api/v1/purchasing/purchase-order-responses/{$response->hash_id}/accept", [])
            ->assertForbidden();
    }

    /* ─── Required delivery date on PR → PO conversion ───────────── */

    public function test_convert_from_pr_shares_the_required_delivery_date_across_pos(): void
    {
        $buyer = $this->userWithRole('purchasing_officer');
        $pr = PurchaseRequest::factory()->create();
        $pr->forceFill(['status' => 'approved'])->save();

        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $prItemA = PurchaseRequestItem::create([
            'purchase_request_id'  => $pr->id,
            'item_id'              => Item::factory()->create()->id,
            'description'          => 'Resin Type A',
            'quantity'             => '100.00',
            'unit'                 => 'kg',
            'estimated_unit_price' => '10.00',
        ]);
        $prItemB = PurchaseRequestItem::create([
            'purchase_request_id'  => $pr->id,
            'item_id'              => Item::factory()->create()->id,
            'description'          => 'Packing Box',
            'quantity'             => '50.00',
            'unit'                 => 'pcs',
            'estimated_unit_price' => '20.00',
        ]);

        $pos = $this->poService->convertFromPr(
            $pr,
            [
                (int) $prItemA->id => (int) $vendorA->id,
                (int) $prItemB->id => (int) $vendorB->id,
            ],
            $buyer,
            false,
            '2026-12-01',
        );

        $this->assertCount(2, $pos);
        foreach ($pos as $po) {
            $this->assertSame('2026-12-01', $po->fresh()->expected_delivery_date->toDateString());
        }
    }
}
