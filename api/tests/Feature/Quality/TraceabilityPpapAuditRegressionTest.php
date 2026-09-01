<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Enums\PpapStatus;
use App\Modules\Quality\Models\PpapSubmission;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\ShipmentLot;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M058 audit regression — traceability + PPAP.
 *
 * Every test here was confirmed RED against unmodified source during the
 * 2026-09-01 audit. See audit/domains/quality/traceability-ppap/fix-log.md.
 */
class TraceabilityPpapAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $qc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->qc = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
    }

    /**
     * material lot -> GRN -> work order (batch) -> shipment lot -> delivery -> customer
     *
     * @return array{grnItem: GrnItem, wo: WorkOrder, lot: ShipmentLot, customer: Customer}
     */
    private function chain(): array
    {
        $item = Item::factory()->create();
        $product = Product::factory()->create();
        $customer = Customer::factory()->create(['name' => 'Toyota Motor Philippines']);
        $so = SalesOrder::factory()->create(['customer_id' => $customer->id]);

        $grn = GoodsReceiptNote::factory()->create();
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $grn->purchase_order_id,
            'item_id' => $item->id,
            'description' => 'Resin A (ABS)',
            'quantity' => '1000.000',
            'unit' => 'kg',
            'unit_price' => '80.00',
            'total' => '80000.00',
            'quantity_received' => '0.000',
        ]);
        $matLot = 'MLOT-20260820-01';
        $grnItem = GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'quantity_received' => '500.000',
            'quantity_accepted' => '500.000',
            'unit_cost' => '80.00',
            'material_lot_number' => $matLot,
            'supplier_lot_reference' => 'SL-TW-0234',
            'coa_verified' => false,
        ]);

        $wo = WorkOrder::factory()->create([
            'product_id' => $product->id,
            'batch_number' => 'BATCH-20260820-0001',
            'quantity_good' => 500,
            'material_lot_references' => [[
                'item_id' => $item->id,
                'item_code' => $item->code,
                'material_lot_number' => $matLot,
                'grn_number' => $grn->grn_number,
                'quantity_used' => 150,
            ]],
        ]);

        $delivery = Delivery::create([
            'delivery_number' => 'DR-20260821-0001',
            'sales_order_id' => $so->id,
            'status' => 'delivered',
            'scheduled_date' => '2026-08-20',
            'delivered_at' => '2026-08-21 09:00:00',
            'created_by' => $this->qc->id,
        ]);

        $lot = ShipmentLot::create([
            'lot_number' => 'LOT-20260821-0001',
            'delivery_id' => $delivery->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'work_order_ids' => [$wo->id],
            'quantity' => 500,
            'lot_date' => '2026-08-21',
            'created_by' => $this->qc->id,
        ]);

        return compact('grnItem', 'wo', 'lot', 'customer');
    }

    /**
     * R1 — the forward trace must reach the consuming batch.
     *
     * `whereJsonContains($col, ['k' => $v])` encodes a JSON object, and
     * `material_lot_references` is a JSON array of objects; PostgreSQL containment
     * refuses that shape, so this returned zero work orders for a lot that had
     * demonstrably been consumed.
     */
    public function test_material_lot_search_reaches_the_consuming_work_order(): void
    {
        $c = $this->chain();

        $data = $this->actingAs($this->qc)
            ->getJson('/api/v1/quality/traceability/search?term='.$c['grnItem']->material_lot_number)
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['found']);
        $this->assertSame('material_lot', $data['type']);
        $this->assertCount(1, $data['trace']['forward']['work_orders']);
        $this->assertSame(
            'BATCH-20260820-0001',
            $data['trace']['forward']['work_orders'][0]['batch_number'],
        );
    }

    /**
     * R1 — recall by material lot must name the customers that received the parts.
     * This is the IATF forward trace; it previously answered `found: false`.
     */
    public function test_recall_by_material_lot_resolves_to_customers_and_deliveries(): void
    {
        $c = $this->chain();

        $data = $this->actingAs($this->qc)
            ->getJson('/api/v1/quality/traceability/recall-simulation?lot='.$c['grnItem']->material_lot_number)
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['found'], 'a consumed and shipped material lot must be found');
        $this->assertCount(1, $data['affected_customers']);
        $this->assertSame('Toyota Motor Philippines', $data['affected_customers'][0]['name']);
        $this->assertCount(1, $data['affected_deliveries']);
        $this->assertSame('DR-20260821-0001', $data['affected_deliveries'][0]['delivery_number']);
        $this->assertSame(500, $data['total_affected_qty']);
    }

    /** The backward trace (shipment → lots) must keep working. */
    public function test_shipment_lot_search_still_resolves_backward(): void
    {
        $c = $this->chain();

        $data = $this->actingAs($this->qc)
            ->getJson('/api/v1/quality/traceability/search?term='.$c['lot']->lot_number)
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['found']);
        $this->assertCount(1, $data['trace']['backward']['work_orders']);
        $this->assertCount(1, $data['trace']['backward']['work_orders'][0]['materials']);
        $this->assertSame(
            'Toyota Motor Philippines',
            $data['trace']['forward']['customer']['name'],
        );
    }

    /** R3 — an array-shaped query parameter must be refused, not 500. */
    public function test_array_query_parameters_are_refused_on_both_traceability_routes(): void
    {
        $this->actingAs($this->qc)
            ->getJson('/api/v1/quality/traceability/search?term[a]=b')
            ->assertStatus(422);

        $this->actingAs($this->qc)
            ->getJson('/api/v1/quality/traceability/recall-simulation?lot[a]=b')
            ->assertStatus(422);
    }

    /** Benign identifiers keep answering 200/found:false rather than erroring. */
    public function test_benign_and_hostile_scalar_terms_answer_not_found(): void
    {
        foreach (['', '   ', "' or 1=1 --", '%', '_'] as $term) {
            $this->actingAs($this->qc)
                ->getJson('/api/v1/quality/traceability/search?term='.urlencode($term))
                ->assertOk()
                ->assertJsonPath('data.found', false);
        }

        // Over-length is now bounded by the request rather than reaching the query.
        $this->actingAs($this->qc)
            ->getJson('/api/v1/quality/traceability/search?term='.str_repeat('X', 5000))
            ->assertStatus(422);
    }

    private function submission(): PpapSubmission
    {
        $this->actingAs($this->qc)->postJson('/api/v1/quality/ppap', [
            'vendor_id' => Vendor::factory()->create()->hash_id,
            'item_id' => Item::factory()->create()->hash_id,
            'ppap_level' => '3',
        ])->assertStatus(201);

        return PpapSubmission::query()->latest('id')->firstOrFail();
    }

    private function approve(PpapSubmission $p): void
    {
        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p->hash_id}/submit")->assertOk();
        foreach ($p->elements as $e) {
            $this->actingAs($this->qc)->patchJson(
                "/api/v1/quality/ppap/{$p->hash_id}/elements/{$e->hash_id}",
                ['status' => 'accepted', 'document_path' => 'ppap/original-psw.pdf'],
            )->assertOk();
        }
        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p->hash_id}/approve")->assertOk();
        $this->assertSame(PpapStatus::Approved, $p->fresh()->status);
    }

    /**
     * R10 — the approved package must keep matching what was approved.
     * The evidence path and status were both freely rewritable after approval.
     */
    public function test_evidence_cannot_be_swapped_after_approval(): void
    {
        $p = $this->submission();
        $this->approve($p);
        $el = $p->elements()->firstOrFail();

        $this->actingAs($this->qc)->patchJson(
            "/api/v1/quality/ppap/{$p->hash_id}/elements/{$el->hash_id}",
            ['document_path' => 'ppap/SWAPPED-EVIDENCE.pdf', 'status' => 'rejected'],
        )->assertStatus(422);

        $fresh = $el->fresh();
        $this->assertSame('ppap/original-psw.pdf', $fresh->document_path);
        $this->assertSame('accepted', $fresh->status->value);
        $this->assertSame(PpapStatus::Approved, $p->fresh()->status);
    }

    /** Evidence stays editable while the submission is still under review. */
    public function test_evidence_is_still_editable_before_approval(): void
    {
        $p = $this->submission();
        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p->hash_id}/submit")->assertOk();
        $el = $p->elements()->firstOrFail();

        $this->actingAs($this->qc)->patchJson(
            "/api/v1/quality/ppap/{$p->hash_id}/elements/{$el->hash_id}",
            ['document_path' => 'ppap/psw-rev-b.pdf', 'status' => 'accepted'],
        )->assertOk();

        $this->assertSame('ppap/psw-rev-b.pdf', $el->fresh()->document_path);
    }

    /**
     * R14 — the update route accepted a raw integer PK and refused the HashID the rest
     * of the API speaks, and `ppap_level` was unvalidated so any string 500'd.
     */
    public function test_ppap_update_speaks_hashids_and_validates_the_level(): void
    {
        $p = $this->submission();
        $product = Product::factory()->create();

        // the HashID is accepted
        $this->actingAs($this->qc)
            ->putJson("/api/v1/quality/ppap/{$p->hash_id}", ['product_id' => $product->hash_id])
            ->assertOk();
        $this->assertSame($product->id, $p->fresh()->product_id);

        // the raw primary key is refused
        $this->actingAs($this->qc)
            ->putJson("/api/v1/quality/ppap/{$p->hash_id}", ['product_id' => $product->id])
            ->assertStatus(422);

        // an unresolvable level is refused instead of reaching varchar(1)
        foreach (['banana', '7', '1e0', '1.0'] as $level) {
            $this->actingAs($this->qc)
                ->putJson("/api/v1/quality/ppap/{$p->hash_id}", ['ppap_level' => $level])
                ->assertStatus(422);
        }

        // and the level did not move
        $this->assertSame('3', $p->fresh()->ppap_level->value);
    }

    /**
     * R14 — `Rule::in` compares loosely, so '1e0' passed validation and then overflowed
     * `ppap_level varchar(1)` as a 500, while the float 1.0 became Level 1 silently.
     */
    public function test_ppap_create_refuses_numeric_notation_levels(): void
    {
        $vendor = Vendor::factory()->create();
        $item = Item::factory()->create();
        $before = PpapSubmission::query()->count();

        // `' 3'` is deliberately absent: Laravel's TrimStrings middleware normalises it
        // to the legitimate '3' before validation, which is correct behaviour.
        foreach (['1e0', '1.0', '0', '6', 3.0, '03'] as $level) {
            $this->actingAs($this->qc)->postJson('/api/v1/quality/ppap', [
                'vendor_id' => $vendor->hash_id,
                'item_id' => $item->hash_id,
                'ppap_level' => $level,
            ])->assertStatus(422);
        }

        $this->assertSame($before, PpapSubmission::query()->count());
    }

    /** R14 — an unresolvable optional reference must be refused, not silently nulled. */
    public function test_ppap_create_refuses_unresolvable_optional_references(): void
    {
        $vendor = Vendor::factory()->create();
        $item = Item::factory()->create();

        foreach ([['product_id' => 'NOT-A-HASH'], ['purchase_order_id' => 'zzz']] as $extra) {
            $this->actingAs($this->qc)->postJson('/api/v1/quality/ppap', array_merge([
                'vendor_id' => $vendor->hash_id,
                'item_id' => $item->hash_id,
                'ppap_level' => '3',
            ], $extra))->assertStatus(422);
        }

        $this->assertSame(0, PpapSubmission::query()->count());
    }

    /**
     * Documents the measured trace-erasure blast radius (audit R8). This asserts the
     * CURRENT behaviour so the numbers are pinned; the guards themselves are deferred
     * to a dedicated session because they need migrations in Inventory/SupplyChain/CRM
     * and an IATF policy decision on whether archival is recoverable.
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT — green here does NOT mean healthy.
     */
    public function test_documents_that_archiving_a_delivery_silently_drops_it_from_the_trace(): void
    {
        $c = $this->chain();
        $lot = ShipmentLot::findOrFail($c['lot']->id);
        $lot->delivery->delete(); // the product's own archive button

        $data = $this->actingAs($this->qc)
            ->getJson('/api/v1/quality/traceability/search?term='.$c['lot']->lot_number)
            ->assertOk()
            ->json('data');

        // Still reports success, but the shipment is gone from the answer.
        $this->assertTrue($data['found']);
        $this->assertNull($data['trace']['forward']['delivery'], 'AUDIT R8: known defect');

        $recall = $this->actingAs($this->qc)
            ->getJson('/api/v1/quality/traceability/recall-simulation?lot='.$c['lot']->lot_number)
            ->assertOk()
            ->json('data');

        $this->assertTrue($recall['found']);
        $this->assertSame(500, $recall['total_affected_qty']);
        $this->assertCount(0, $recall['affected_deliveries'], 'AUDIT R8: known defect');
    }

    /**
     * Documents that a dangling work-order id in the JSON list yields a partial trace
     * that is indistinguishable from a complete one (audit R5).
     *
     * PASS-EITHER-WAY LOCK ON A KNOWN DEFECT.
     */
    public function test_documents_that_a_partial_trace_looks_complete(): void
    {
        $c = $this->chain();
        $wo2 = WorkOrder::factory()->create([
            'product_id' => $c['wo']->product_id,
            'batch_number' => 'BATCH-20260820-0002',
            'quantity_good' => 300,
        ]);
        $c['lot']->update(['work_order_ids' => [$c['wo']->id, $wo2->id], 'quantity' => 800]);

        DB::delete('delete from work_orders where id = ?', [$wo2->id]);

        $data = $this->actingAs($this->qc)
            ->getJson('/api/v1/quality/traceability/search?term='.$c['lot']->lot_number)
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['found']);
        $this->assertSame(800, $data['trace']['lot']['quantity']);
        // one of the two batches vanished, with no flag anywhere in the payload
        $this->assertCount(1, $data['trace']['backward']['work_orders'], 'AUDIT R5: known defect');
    }

    /** Unauthenticated access is refused on every route in this surface. */
    public function test_guest_is_refused(): void
    {
        $this->getJson('/api/v1/quality/traceability/search?term=X')->assertStatus(401);
        $this->getJson('/api/v1/quality/traceability/recall-simulation?lot=X')->assertStatus(401);
        $this->getJson('/api/v1/quality/ppap')->assertStatus(401);
    }

    /** A role without the quality bucket is refused on every endpoint, list included. */
    public function test_unprivileged_role_is_refused_on_every_endpoint(): void
    {
        $p = $this->submission();
        $el = $p->elements()->firstOrFail();
        $employee = User::factory()->withRole('employee')->create(['is_active' => true]);

        $calls = [
            ['GET', '/api/v1/quality/ppap'],
            ['GET', "/api/v1/quality/ppap/{$p->hash_id}"],
            ['POST', '/api/v1/quality/ppap'],
            ['PUT', "/api/v1/quality/ppap/{$p->hash_id}"],
            ['PATCH', "/api/v1/quality/ppap/{$p->hash_id}/submit"],
            ['PATCH', "/api/v1/quality/ppap/{$p->hash_id}/review"],
            ['PATCH', "/api/v1/quality/ppap/{$p->hash_id}/approve"],
            ['PATCH', "/api/v1/quality/ppap/{$p->hash_id}/reject"],
            ['PATCH', "/api/v1/quality/ppap/{$p->hash_id}/elements/{$el->hash_id}"],
            ['GET', '/api/v1/quality/traceability/search?term=X'],
            ['GET', '/api/v1/quality/traceability/recall-simulation?lot=X'],
        ];

        foreach ($calls as [$method, $url]) {
            $this->actingAs($employee)
                ->json($method, $url, ['reason' => 'x'])
                ->assertStatus(403, "{$method} {$url} must be 403");
        }
    }
}
