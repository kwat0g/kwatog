<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Services\MrpEngineService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * P2.3 — Pin MRP net-requirement netting in MrpEngineService::runForSalesOrder().
 *
 * Net-requirement formula (from service docblock):
 *   gross      = BomItem.effective_quantity * SO line quantity
 *              = quantity_per_unit * (1 + waste_factor/100) * line.quantity
 *   on_hand    = Σ stock_levels.quantity  (all locations)
 *   reserved   = Σ stock_levels.reserved_quantity (all locations)
 *   in_transit = Σ (poi.quantity - poi.quantity_received) for POs in
 *                  approved / sent / partially_received
 *   net        = max(0, gross - on_hand + reserved - in_transit)
 *
 * A PurchaseRequest (is_auto_generated=true, status=draft) is created iff net > 0.
 *
 * In-transit note: the service queries `purchase_order_items` / `purchase_orders`
 * directly via DB::table, so we insert raw rows rather than using factories to
 * avoid triggering unrelated service side-effects.
 */
class MrpNettingTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private MrpEngineService $engine;

    /** @var User */
    private User $user;

    /** @var Product */
    private Product $product;

    /** @var Item */
    private Item $material;

    /** @var WarehouseLocation */
    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        // Suppress MrpPlanGenerated broadcast; it fires after-commit and
        // tries to notify WebSocket channels that don't exist in test env.
        Event::fake([\App\Modules\MRP\Events\MrpPlanGenerated::class]);

        $this->engine = app(MrpEngineService::class);

        $this->user = User::factory()->create();

        // A finished-good product (CRM side).
        $this->product = Product::create([
            'part_number'     => 'TEST-001',
            'name'            => 'Test Product',
            'unit_of_measure' => 'pcs',
            'standard_cost'   => 10.00,
            'is_active'       => true,
        ]);

        // A raw-material item (Inventory side).
        $this->material = Item::factory()->create([
            'code'             => 'RM-TEST-001',
            'unit_of_measure'  => 'pcs',
            'lead_time_days'   => 7,
            'standard_cost'    => 5.00,
        ]);

        // A warehouse location for stock records.
        $this->location = WarehouseLocation::factory()->create();
    }

    // ── Helper: build an active BOM linking product → material ────────────────

    /**
     * @param float $qtyPerUnit       units of material needed per finished unit
     * @param float $wasteFactor      waste % (e.g. 10 = 10 %)
     */
    private function createBom(float $qtyPerUnit = 2.0, float $wasteFactor = 0.0): Bom
    {
        $bom = Bom::create([
            'product_id' => $this->product->id,
            'version'    => 1,
            'is_active'  => true,
        ]);

        BomItem::create([
            'bom_id'            => $bom->id,
            'item_id'           => $this->material->id,
            'quantity_per_unit' => $qtyPerUnit,
            'unit'              => 'pcs',
            'waste_factor'      => $wasteFactor,
            'sort_order'        => 0,
        ]);

        return $bom;
    }

    /**
     * Build a confirmed SalesOrder with one line for $this->product.
     *
     * @param int $lineQty    units of finished product ordered
     * @param int $daysAhead  delivery_date = today + daysAhead
     */
    private function createConfirmedSo(int $lineQty, int $daysAhead = 30): SalesOrder
    {
        $so = SalesOrder::create([
            'so_number'          => 'SO-' . now()->format('Ym') . '-' . rand(1000, 9999),
            'customer_id'        => $this->createCustomer(),
            'date'               => now()->format('Y-m-d'),
            'subtotal'           => $lineQty * 10,
            'vat_amount'         => 0,
            'total_amount'       => $lineQty * 10,
            'status'             => 'confirmed',
            'payment_terms_days' => 30,
            'created_by'         => $this->user->id,
        ]);

        SalesOrderItem::create([
            'sales_order_id'  => $so->id,
            'product_id'      => $this->product->id,
            'quantity'        => $lineQty,
            'unit_price'      => 10.00,
            'total'           => $lineQty * 10,
            'delivery_date'   => Carbon::today()->addDays($daysAhead)->format('Y-m-d'),
        ]);

        return $so;
    }

    /** Minimal customer insert, returns id. */
    private function createCustomer(): int
    {
        return DB::table('customers')->insertGetId([
            'name'               => 'Test Customer',
            'is_active'          => true,
            'payment_terms_days' => 30,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    /** Set on-hand quantity for $this->material at $this->location. */
    private function setOnHand(float $qty, float $reserved = 0.0): StockLevel
    {
        return StockLevel::create([
            'item_id'           => $this->material->id,
            'location_id'       => $this->location->id,
            'quantity'          => $qty,
            'reserved_quantity' => $reserved,
            'weighted_avg_cost' => 5.00,
            'lock_version'      => 0,
        ]);
    }

    /**
     * Insert a raw PO + PO item directly so the in-transit query in
     * MrpEngineService::inTransit() finds the row without firing the
     * PurchaseOrderService side-effects.
     *
     * @param float  $ordered   poi.quantity
     * @param float  $received  poi.quantity_received
     * @param string $poStatus  purchase_orders.status — must be one of approved|sent|partially_received
     */
    private function createInTransitPo(float $ordered, float $received = 0.0, string $poStatus = 'approved'): void
    {
        $vendorId = DB::table('vendors')->insertGetId([
            'name'       => 'Test Vendor',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number'              => 'PO-' . now()->format('Ym') . '-' . rand(1000, 9999),
            'vendor_id'              => $vendorId,
            'date'                   => now()->format('Y-m-d'),
            'expected_delivery_date' => now()->addDays(14)->format('Y-m-d'),
            'subtotal'               => $ordered * 5,
            'vat_amount'             => 0,
            'total_amount'           => $ordered * 5,
            'is_vatable'             => true,
            'status'                 => $poStatus,
            'requires_vp_approval'   => false,
            'current_approval_step'  => 0,
            'created_by'             => $this->user->id,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        DB::table('purchase_order_items')->insert([
            'purchase_order_id' => $poId,
            'item_id'           => $this->material->id,
            'description'       => 'Test material',
            'quantity'          => $ordered,
            'unit'              => 'pcs',
            'unit_price'        => 5.00,
            'total'             => $ordered * 5,
            'quantity_received' => $received,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 1 — Sufficient on-hand: no shortage → NO purchase request
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup:
     *   BOM:     2 pcs material per finished unit, 0% waste
     *   SO line: 10 units → gross demand = 20 pcs
     *   On-hand: 20 pcs (reserved = 0, in-transit = 0)
     *
     * Net = max(0, 20 - 20 + 0 - 0) = 0
     *
     * Expected: MrpPlan created with shortages_found=0, zero PRs in DB.
     */
    public function test_sufficient_on_hand_creates_no_purchase_request(): void
    {
        $this->createBom(qtyPerUnit: 2.0, wasteFactor: 0.0);
        $this->setOnHand(qty: 20.0, reserved: 0.0);
        $so = $this->createConfirmedSo(lineQty: 10);

        $plan = $this->engine->runForSalesOrder($so);

        $this->assertSame(0, $plan->shortages_found, 'shortages_found must be 0 when on-hand covers gross demand');
        $this->assertSame(0, $plan->auto_pr_count,   'auto_pr_count must be 0 when no shortage');

        $prCount = PurchaseRequest::where('is_auto_generated', true)
            ->where('mrp_plan_id', $plan->id)
            ->count();
        $this->assertSame(0, $prCount, 'No auto-generated PR must exist when stock is sufficient');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 2 — Shortage: a PR is created with net qty = gross - on_hand
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup:
     *   BOM:     2 pcs per unit, 0% waste
     *   SO line: 10 units → gross = 20 pcs
     *   On-hand: 8 pcs (reserved = 0, in-transit = 0)
     *
     * Net = max(0, 20 - 8 + 0 - 0) = 12
     *
     * Expected:
     *   - MrpPlan.shortages_found = 1
     *   - MrpPlan.auto_pr_count   = 1
     *   - 1 PurchaseRequest (is_auto_generated=true, status='draft')
     *   - 1 PurchaseRequestItem for $this->material with quantity = 12
     */
    public function test_shortage_creates_auto_pr_with_correct_net_quantity(): void
    {
        $this->createBom(qtyPerUnit: 2.0, wasteFactor: 0.0);
        $this->setOnHand(qty: 8.0, reserved: 0.0);
        $so = $this->createConfirmedSo(lineQty: 10);

        $plan = $this->engine->runForSalesOrder($so);

        $this->assertSame(1, $plan->shortages_found, 'shortages_found must be 1');
        $this->assertSame(1, $plan->auto_pr_count,   'auto_pr_count must be 1');

        $pr = PurchaseRequest::where('is_auto_generated', true)
            ->where('mrp_plan_id', $plan->id)
            ->firstOrFail();

        $this->assertSame('draft', $pr->status->value, 'PR status must be draft');
        $this->assertTrue($pr->is_auto_generated, 'PR must be flagged is_auto_generated');

        $prItem = $pr->items()->where('item_id', $this->material->id)->firstOrFail();
        // net = 20 - 8 = 12, stored rounded to 2 decimal places
        $this->assertSame('12.00', $prItem->quantity, 'PR item qty must equal net shortage (12)');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 3 — Reserved stock adds to demand (is NOT treated as available)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * The formula explicitly adds reserved back to demand so that units
     * already reserved by other WOs are not double-counted as free.
     *
     * Setup:
     *   BOM:     2 pcs per unit, 0% waste
     *   SO line: 10 units → gross = 20 pcs
     *   On-hand: 20 pcs (but reserved = 5 for other WOs)
     *   In-transit: 0
     *
     * Net = max(0, 20 - 20 + 5 - 0) = 5
     *
     * Without the +reserved term, net would be 0 and no PR would be raised —
     * but 5 pcs are spoken for, so a PR for 5 is correct.
     */
    public function test_reserved_stock_increases_net_requirement(): void
    {
        $this->createBom(qtyPerUnit: 2.0, wasteFactor: 0.0);
        $this->setOnHand(qty: 20.0, reserved: 5.0);
        $so = $this->createConfirmedSo(lineQty: 10);

        $plan = $this->engine->runForSalesOrder($so);

        $this->assertSame(1, $plan->shortages_found, 'shortages_found must be 1 because reserved is not free stock');

        $pr = PurchaseRequest::where('is_auto_generated', true)
            ->where('mrp_plan_id', $plan->id)
            ->firstOrFail();
        $prItem = $pr->items()->where('item_id', $this->material->id)->firstOrFail();
        // net = 20 - 20 + 5 - 0 = 5
        $this->assertSame('5.00', $prItem->quantity, 'PR item qty must be 5 (reserved adds to demand)');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 4 — In-transit (existing approved PO) reduces net requirement
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup:
     *   BOM:     2 pcs per unit, 0% waste
     *   SO line: 10 units → gross = 20 pcs
     *   On-hand: 5 pcs  (reserved = 0)
     *   In-transit: 10 pcs (approved PO: ordered=10, received=0)
     *
     * Net = max(0, 20 - 5 + 0 - 10) = 5
     *
     * Without the in-transit deduction, net would be 15 — the in-transit
     * stock already coming in should reduce what we need to order.
     */
    public function test_in_transit_po_reduces_net_requirement(): void
    {
        $this->createBom(qtyPerUnit: 2.0, wasteFactor: 0.0);
        $this->setOnHand(qty: 5.0, reserved: 0.0);
        $this->createInTransitPo(ordered: 10.0, received: 0.0, poStatus: 'approved');
        $so = $this->createConfirmedSo(lineQty: 10);

        $plan = $this->engine->runForSalesOrder($so);

        $this->assertSame(1, $plan->shortages_found, 'shortages_found must be 1 (partial in-transit, still short)');

        $pr = PurchaseRequest::where('is_auto_generated', true)
            ->where('mrp_plan_id', $plan->id)
            ->firstOrFail();
        $prItem = $pr->items()->where('item_id', $this->material->id)->firstOrFail();
        // net = 20 - 5 + 0 - 10 = 5
        $this->assertSame('5.00', $prItem->quantity, 'PR item qty must be 5 after in-transit reduces demand');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 4b — In-transit fully covers shortage → no PR
    // ════════════════════════════════════════════════════════════════════════

    /**
     * When in-transit alone covers the gap between on-hand and gross demand,
     * net = 0 and no PR should be created.
     *
     * Setup:
     *   BOM:     2 pcs per unit, 0% waste
     *   SO line: 10 units → gross = 20 pcs
     *   On-hand: 5 pcs  (reserved = 0)
     *   In-transit: 15 pcs (sent PO: ordered=15, received=0)
     *
     * Net = max(0, 20 - 5 + 0 - 15) = 0
     */
    public function test_in_transit_fully_covering_shortage_creates_no_pr(): void
    {
        $this->createBom(qtyPerUnit: 2.0, wasteFactor: 0.0);
        $this->setOnHand(qty: 5.0, reserved: 0.0);
        $this->createInTransitPo(ordered: 15.0, received: 0.0, poStatus: 'sent');
        $so = $this->createConfirmedSo(lineQty: 10);

        $plan = $this->engine->runForSalesOrder($so);

        $this->assertSame(0, $plan->shortages_found, 'shortages_found must be 0 when in-transit fully covers demand');
        $this->assertSame(0, $plan->auto_pr_count,   'auto_pr_count must be 0');

        $prCount = PurchaseRequest::where('is_auto_generated', true)
            ->where('mrp_plan_id', $plan->id)
            ->count();
        $this->assertSame(0, $prCount, 'No PR must be created when in-transit covers the gap');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 5 — Waste factor is included in gross demand
    // ════════════════════════════════════════════════════════════════════════

    /**
     * BomItem.effective_quantity = quantity_per_unit * (1 + waste_factor/100)
     *
     * Setup:
     *   BOM:     2 pcs per unit + 10% waste → effective = 2 * 1.10 = 2.2
     *   SO line: 10 units → gross = 22 pcs
     *   On-hand: 20 pcs (reserved = 0, in-transit = 0)
     *
     * Net = max(0, 22 - 20 + 0 - 0) = 2
     *
     * Without waste, gross would be 20 and net would be 0 — confirming the
     * waste_factor is correctly included in gross by BomService::explode().
     */
    public function test_waste_factor_is_included_in_gross_demand(): void
    {
        $this->createBom(qtyPerUnit: 2.0, wasteFactor: 10.0); // effective = 2.2
        $this->setOnHand(qty: 20.0, reserved: 0.0);
        $so = $this->createConfirmedSo(lineQty: 10);

        $plan = $this->engine->runForSalesOrder($so);

        $this->assertSame(1, $plan->shortages_found, 'shortages_found must be 1 because waste inflates gross');

        $pr = PurchaseRequest::where('is_auto_generated', true)
            ->where('mrp_plan_id', $plan->id)
            ->firstOrFail();
        $prItem = $pr->items()->where('item_id', $this->material->id)->firstOrFail();
        // gross = 2 * 1.10 * 10 = 22.0; net = 22 - 20 = 2
        $this->assertSame('2.00', $prItem->quantity, 'PR item qty must reflect waste-factor-inflated gross (2)');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 6 — Partially received in-transit PO: only outstanding qty counts
    // ════════════════════════════════════════════════════════════════════════

    /**
     * in_transit = ordered - received for partially_received POs.
     *
     * Setup:
     *   BOM:     2 pcs per unit, 0% waste
     *   SO line: 10 units → gross = 20 pcs
     *   On-hand: 0 pcs  (reserved = 0)
     *   PO:      ordered=12, received=6  → in-transit = 6
     *
     * Net = max(0, 20 - 0 + 0 - 6) = 14
     */
    public function test_partially_received_po_only_counts_outstanding_qty_as_in_transit(): void
    {
        $this->createBom(qtyPerUnit: 2.0, wasteFactor: 0.0);
        $this->setOnHand(qty: 0.0, reserved: 0.0);
        $this->createInTransitPo(ordered: 12.0, received: 6.0, poStatus: 'partially_received');
        $so = $this->createConfirmedSo(lineQty: 10);

        $plan = $this->engine->runForSalesOrder($so);

        $pr = PurchaseRequest::where('is_auto_generated', true)
            ->where('mrp_plan_id', $plan->id)
            ->firstOrFail();
        $prItem = $pr->items()->where('item_id', $this->material->id)->firstOrFail();
        // in_transit = 12 - 6 = 6; net = 20 - 0 + 0 - 6 = 14
        $this->assertSame('14.00', $prItem->quantity, 'PR item qty must use (ordered - received) for partially_received PO');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 7 — Draft / cancelled POs are NOT counted as in-transit
    // ════════════════════════════════════════════════════════════════════════

    /**
     * The inTransit() method only queries POs with status in:
     *   approved | sent | partially_received
     *
     * A PO in 'draft' status must NOT reduce the net requirement.
     *
     * Setup:
     *   BOM:     2 pcs per unit, 0% waste
     *   SO line: 10 units → gross = 20 pcs
     *   On-hand: 5 pcs (reserved = 0)
     *   Draft PO: ordered=15, received=0 → NOT in-transit (wrong status)
     *
     * Net = max(0, 20 - 5 + 0 - 0) = 15
     */
    public function test_draft_po_is_not_counted_as_in_transit(): void
    {
        $this->createBom(qtyPerUnit: 2.0, wasteFactor: 0.0);
        $this->setOnHand(qty: 5.0, reserved: 0.0);
        $this->createInTransitPo(ordered: 15.0, received: 0.0, poStatus: 'draft');
        $so = $this->createConfirmedSo(lineQty: 10);

        $plan = $this->engine->runForSalesOrder($so);

        $pr = PurchaseRequest::where('is_auto_generated', true)
            ->where('mrp_plan_id', $plan->id)
            ->firstOrFail();
        $prItem = $pr->items()->where('item_id', $this->material->id)->firstOrFail();
        // Draft PO ignored → net = 20 - 5 = 15
        $this->assertSame('15.00', $prItem->quantity, 'Draft PO must not count as in-transit; net must be full shortage');
    }
}
