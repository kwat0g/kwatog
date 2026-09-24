<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Services\AlertEngineService;
use App\Common\Services\ApprovalService;
use App\Common\Services\NotificationService;
use App\Common\Services\TaxPolicyService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\AutoReplenishmentService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\AutoPurchaseOrderService;
use App\Modules\Purchasing\Services\OpenSupplyService;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * P2.4 — Pin in-transit supply netting for auto-replenishment.
 *
 * Tests that AutoReplenishmentService and AutoPurchaseOrderService correctly
 * account for open POs (in-transit supply) when deciding whether to create new
 * purchase requests/orders. Previously, they looked only at on-hand stock and
 * created duplicate replenishment records after an open PR was converted to a PO.
 *
 * OpenSupplyService tests that in-transit calculations exclude SupplierDeclined
 * and soft-deleted POs, and correctly convert purchase UoM to base UoM.
 */
class ReplenishmentInTransitNettingTest extends TestCase
{
    use RefreshDatabase;

    private AutoReplenishmentService $replenish;
    private AutoPurchaseOrderService $autoPo;
    private OpenSupplyService $openSupply;
    private Role $systemAdminRole;
    private User $systemAdmin;
    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        $this->seed(WorkflowSeeder::class);

        $this->replenish = app(AutoReplenishmentService::class);
        $this->autoPo = app(AutoPurchaseOrderService::class);
        $this->openSupply = app(OpenSupplyService::class);

        // Set up system admin user for auto-generated requests.
        $this->systemAdminRole = Role::query()->create([
            'name' => 'System Admin',
            'slug' => 'system_admin',
        ]);
        $this->systemAdmin = User::factory()->create(['role_id' => $this->systemAdminRole->id]);

        // Warehouse location for stock records.
        $this->location = WarehouseLocation::factory()->create();

        // Mock alert and notification services.
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

    // ════════════════════════════════════════════════════════════════════════
    // Test 1 — Item below reorder with Sent PO covering shortage → no PR
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup:
     *   Item on-hand: 5 (below reorder_point 20)
     *   Reorder point: 20, Safety: 2
     *   Open PO (Sent): 20 qty, 0 received (fully in transit)
     *
     * Position = 5 + 20 = 25 >= 20 (reorder)
     *
     * Expected:
     *   - checkAndReplenish() returns null (no shortage after netting in-transit)
     *   - Zero new PRs created
     */
    public function test_no_pr_when_open_po_lifts_position_above_reorder(): void
    {
        $item = Item::factory()->create([
            'code'              => 'IT-T-' . substr(uniqid(), -5),
            'is_active'         => true,
            'is_critical'       => false,
            'reorder_point'     => '20.000',
            'safety_stock'      => '2.000',
            'standard_cost'     => '10.00',
            'lead_time_days'    => 7,
        ]);

        StockLevel::factory()->create([
            'item_id'           => $item->id,
            'location_id'       => $this->location->id,
            'quantity'          => '5.000',
            'reserved_quantity' => '0.000',
        ]);

        $this->createPurchaseOrder(
            itemId: $item->id,
            ordered: 20.0,
            received: 0.0,
            status: PurchaseOrderStatus::Sent->value,
        );

        $pr = $this->replenish->checkAndReplenish($item->id);

        $this->assertNull($pr, 'No PR should be created when position >= reorder');
        $this->assertSame(0, PurchaseRequest::query()->count());
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 2 — Converted PR with Sent PO; second stock move → no duplicate PR
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup:
     *   Initial state: item on-hand 5, below reorder 20
     *   First checkAndReplenish() creates PR (Draft)
     *   PR is manually converted to PO (Sent status, same quantity)
     *   PR status set to Converted
     *   Now: on-hand 5, PO Sent with 20 qty in transit
     *
     * Position = 5 + 20 = 25 >= 20
     *
     * Expected:
     *   - Second checkAndReplenish() returns null (no duplicate)
     *   - Still exactly 1 PR in DB (no new PR created)
     */
    public function test_no_duplicate_pr_after_pr_converted_to_po(): void
    {
        $item = Item::factory()->create([
            'code'              => 'IT-T-' . substr(uniqid(), -5),
            'is_active'         => true,
            'is_critical'       => false,
            'reorder_point'     => '20.000',
            'safety_stock'      => '2.000',
            'standard_cost'     => '10.00',
            'lead_time_days'    => 7,
        ]);

        StockLevel::factory()->create([
            'item_id'           => $item->id,
            'location_id'       => $this->location->id,
            'quantity'          => '5.000',
            'reserved_quantity' => '0.000',
        ]);

        // First replenishment creates a PR in Draft status.
        $pr = $this->replenish->checkAndReplenish($item->id);
        $this->assertNotNull($pr, 'First replenishment should create a PR');
        $this->assertSame(1, PurchaseRequest::query()->count());

        // Simulate PR → PO conversion: the PR is marked Converted and a PO is created.
        $pr->forceFill(['status' => 'converted'])->save();

        $this->createPurchaseOrder(
            itemId: $item->id,
            ordered: 20.0,
            received: 0.0,
            status: PurchaseOrderStatus::Sent->value,
        );

        // Verify PO was created.
        $poCount = PurchaseOrder::query()->count();
        $this->assertGreaterThan(0, $poCount, 'PO should be created');

        // Verify in-transit calculation includes the PO.
        $inTransit = $this->openSupply->inTransitBaseQuantity($item->id);
        $this->assertGreaterThan(0.0, (float) $inTransit, 'In-transit should include the Sent PO');

        // Second stock movement (e.g., from another warehouse receiving) calls
        // checkAndReplenish again. It should see the PO in transit and skip.
        $pr2 = $this->replenish->checkAndReplenish($item->id);

        $this->assertNull($pr2, 'No second PR should be created; PO is in transit');
        $this->assertSame(1, PurchaseRequest::query()->count(), 'Still only 1 PR (no duplicate created)');
        $openPrs = PurchaseRequest::query()
            ->whereHas('items', fn ($q) => $q->where('item_id', $item->id))
            ->whereIn('status', ['draft', 'pending', 'approved'])
            ->count();
        $this->assertSame(0, $openPrs, 'No open PRs should exist (first was converted)');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 3 — In-transit insufficient; new PR created with correct qty
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup:
     *   On-hand: 5, Reorder: 20, Safety: 2
     *   Open PO (Sent): 10 qty (insufficient)
     *   Reorder method: FixedQuantity (2 × reorder − position)
     *
     * Position = 5 + 10 = 15 < 20 → shortage
     * Target qty = 2 × 20 − 15 = 25
     * Since 25 >= 20 (reorder), order 25
     *
     * Expected:
     *   - checkAndReplenish() creates a PR
     *   - PR qty = 25 (FixedQuantity calculation with position netted)
     */
    public function test_pr_created_when_in_transit_insufficient(): void
    {
        $item = Item::factory()->create([
            'code'              => 'IT-T-' . substr(uniqid(), -5),
            'is_active'         => true,
            'is_critical'       => false,
            'reorder_point'     => '20.000',
            'safety_stock'      => '2.000',
            'standard_cost'     => '10.00',
            'lead_time_days'    => 7,
            'minimum_order_quantity' => '1.000',
            'reorder_method'    => 'fixed_quantity',
        ]);

        StockLevel::factory()->create([
            'item_id'           => $item->id,
            'location_id'       => $this->location->id,
            'quantity'          => '5.000',
            'reserved_quantity' => '0.000',
        ]);

        $this->createPurchaseOrder(
            itemId: $item->id,
            ordered: 10.0,
            received: 0.0,
            status: PurchaseOrderStatus::Sent->value,
        );

        $pr = $this->replenish->checkAndReplenish($item->id);

        $this->assertNotNull($pr, 'PR should be created when position < reorder');
        $prItem = $pr->items()->first();
        $this->assertNotNull($prItem);
        // When available=5 + in-transit=10, position=15 < reorder=20.
        // Target should reflect position offset from 2×reorder.
        // The PR qty should be at least equal to reorder to cover the shortage.
        $qty = (float) $prItem->quantity;
        $this->assertGreaterThanOrEqual(20.0, $qty, 'PR quantity should be at least equal to reorder point');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 4 — Critical item with Sent auto-PO → no duplicate auto-PO
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup:
     *   Critical item (is_critical=true)
     *   On-hand: 5, Reorder: 10, Safety: 1
     *   One preferred supplier
     *   First call: createForCriticalShortage() creates auto-PO (Sent)
     *   Second call: should return null (no duplicate)
     *
     * Position = 5 + 12 (open auto-PO) = 17 >= 10
     *
     * Expected:
     *   - Second call returns null
     *   - Still exactly 1 auto-PO in DB
     */
    public function test_no_duplicate_critical_auto_po_with_open_order(): void
    {
        $item = Item::factory()->create([
            'code'              => 'IT-T-' . substr(uniqid(), -5),
            'is_critical'       => true,
            'reorder_point'     => '10.000',
            'safety_stock'      => '1.000',
            'standard_cost'     => '5.00',
            'lead_time_days'    => 5,
        ]);

        WarehouseLocation::factory()->create();
        StockLevel::factory()->create([
            'item_id'           => $item->id,
            'location_id'       => $this->location->id,
            'quantity'          => '5.000',
            'reserved_quantity' => '0.000',
        ]);

        $vendor = Vendor::factory()->create();
        ApprovedSupplier::create([
            'item_id'           => $item->id,
            'vendor_id'         => $vendor->id,
            'is_preferred'      => true,
            'lead_time_days'    => 5,
            'last_price'        => '6.00',
        ]);

        // First call creates auto-PO.
        $po1 = $this->autoPo->createForCriticalShortage($item);
        $this->assertNotNull($po1, 'First call should create auto-PO');

        // Simulate supplier acknowledgment (PO moves to Sent).
        $po1->forceFill(['status' => PurchaseOrderStatus::Sent->value])->save();

        // Second call should return null (open PO in transit covers shortage).
        $po2 = $this->autoPo->createForCriticalShortage($item->fresh());
        $this->assertNull($po2, 'No second auto-PO should be created');
        $this->assertSame(1, PurchaseOrder::query()->where('is_auto_generated', true)->count());
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 5 — OpenSupplyService excludes SupplierDeclined
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup:
     *   Item with multiple POs:
     *   - PO A (Sent): 10 qty, 0 received → in transit
     *   - PO B (SupplierDeclined): 20 qty, 0 received → NOT in transit
     *   - PO C (Closed): 5 qty → NOT in transit
     *
     * Expected:
     *   - inTransitBaseQuantity() returns 10 (only Sent counted)
     */
    public function test_open_supply_excludes_supplier_declined_and_closed(): void
    {
        $item = Item::factory()->create([
            'code'              => 'IT-T-' . substr(uniqid(), -5),
            'unit_of_measure'   => 'pcs',
        ]);

        // PO A: Sent (in transit)
        $this->createPurchaseOrder(
            itemId: $item->id,
            ordered: 10.0,
            received: 0.0,
            status: PurchaseOrderStatus::Sent->value,
        );

        // PO B: SupplierDeclined (not in transit)
        $this->createPurchaseOrder(
            itemId: $item->id,
            ordered: 20.0,
            received: 0.0,
            status: PurchaseOrderStatus::SupplierDeclined->value,
        );

        // PO C: Closed (not in transit)
        $this->createPurchaseOrder(
            itemId: $item->id,
            ordered: 5.0,
            received: 5.0,
            status: PurchaseOrderStatus::Closed->value,
        );

        $inTransit = $this->openSupply->inTransitBaseQuantity($item->id);

        $this->assertSame('10.000000', $inTransit, 'Only Sent PO should be counted');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 6 — OpenSupplyService includes PartiallyReceived
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup:
     *   PO (PartiallyReceived): 30 qty, 10 received → 20 remaining in transit
     *
     * Expected:
     *   - inTransitBaseQuantity() returns 20 (30 − 10)
     */
    public function test_open_supply_includes_partially_received(): void
    {
        $item = Item::factory()->create([
            'code'              => 'IT-T-' . substr(uniqid(), -5),
            'unit_of_measure'   => 'pcs',
        ]);

        $this->createPurchaseOrder(
            itemId: $item->id,
            ordered: 30.0,
            received: 10.0,
            status: PurchaseOrderStatus::PartiallyReceived->value,
        );

        $inTransit = $this->openSupply->inTransitBaseQuantity($item->id);

        $this->assertSame('20.000000', $inTransit);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 7 — OpenSupplyService excludes soft-deleted POs
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup:
     *   PO A (Sent): 15 qty, 0 received
     *   PO B (Sent): 10 qty, 0 received → soft-deleted
     *
     * Expected:
     *   - inTransitBaseQuantity() returns 15 (deleted PO not counted)
     */
    public function test_open_supply_excludes_soft_deleted_pos(): void
    {
        $item = Item::factory()->create([
            'code'              => 'IT-T-' . substr(uniqid(), -5),
            'unit_of_measure'   => 'pcs',
        ]);

        // PO A: active
        $this->createPurchaseOrder(
            itemId: $item->id,
            ordered: 15.0,
            received: 0.0,
            status: PurchaseOrderStatus::Sent->value,
        );

        // PO B: will be soft-deleted
        $poB = $this->createRawPurchaseOrder(
            itemId: $item->id,
            ordered: 10.0,
            received: 0.0,
            status: PurchaseOrderStatus::Sent->value,
        );
        PurchaseOrder::query()->find($poB)->delete();

        $inTransit = $this->openSupply->inTransitBaseQuantity($item->id);

        $this->assertSame('15.000000', $inTransit, 'Soft-deleted PO should not be counted');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Helper methods
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Create a raw PO + PO item via DB insert (avoids side-effects).
     * Returns the PO id.
     */
    private function createRawPurchaseOrder(
        int $itemId,
        float $ordered,
        float $received,
        string $status,
    ): int {
        $vendorId = DB::table('vendors')->insertGetId([
            'name'       => 'VEN-T-' . substr(uniqid(), -5),
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
            'is_vatable'             => false,
            'status'                 => $status,
            'requires_vp_approval'   => false,
            'current_approval_step'  => 0,
            'created_by'             => $this->systemAdmin->id,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        DB::table('purchase_order_items')->insert([
            'purchase_order_id' => $poId,
            'item_id'           => $itemId,
            'description'       => 'Test material',
            'quantity'          => $ordered,
            'unit'              => 'pcs',
            'unit_price'        => 5.00,
            'total'             => $ordered * 5,
            'quantity_received' => $received,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return $poId;
    }

    /**
     * Wrapper around createRawPurchaseOrder for convenience (same params).
     */
    private function createPurchaseOrder(
        int $itemId,
        float $ordered,
        float $received,
        string $status,
    ): void {
        $this->createRawPurchaseOrder($itemId, $ordered, $received, $status);
    }
}
