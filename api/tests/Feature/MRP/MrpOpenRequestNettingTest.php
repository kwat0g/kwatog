<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Services\MrpEngineService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Test MRP open-request netting: unplanned PRs and partial PO conversions
 * must be correctly accounted for to prevent double-ordering.
 */
class MrpOpenRequestNettingTest extends TestCase
{
    use RefreshDatabase;

    private MrpEngineService $engine;
    private User $user;
    private Product $product;
    private Item $material;
    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([\App\Modules\MRP\Events\MrpPlanGenerated::class]);

        $this->engine = app(MrpEngineService::class);
        $this->user = User::factory()->create();

        $this->product = Product::create([
            'part_number'     => 'TEST-001',
            'name'            => 'Test Product',
            'unit_of_measure' => 'pcs',
            'standard_cost'   => '10.00',
            'is_active'       => true,
        ]);

        $this->material = Item::factory()->create([
            'code'            => 'RM-TEST-001',
            'unit_of_measure' => 'pcs',
            'lead_time_days'  => 7,
            'standard_cost'   => '5.00',
        ]);

        $this->location = WarehouseLocation::factory()->create();
    }

    private function createBom(float $qtyPerUnit = 2.0): Bom
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
            'waste_factor'      => '0.00',
            'sort_order'        => 0,
        ]);

        return $bom;
    }

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

        $deliveryDate = now()->addDays($daysAhead)->format('Y-m-d');
        $total = $lineQty * 10;
        $so->items()->create([
            'product_id'    => $this->product->id,
            'quantity'      => $lineQty,
            'unit_price'    => '10.00',
            'total'         => $total,
            'delivery_date' => $deliveryDate,
        ]);

        return $so;
    }

    private function createCustomer()
    {
        return \App\Modules\Accounting\Models\Customer::factory()->create()->id;
    }

    /**
     * Test 1: An unplanned Approved reorder PR for 100 units of item X
     * should net MRP demand of 100 → no new PR line for X.
     */
    public function test_unplanned_approved_pr_nets_mrp_demand(): void
    {
        $this->createBom(50.0); // 50 units of material per product

        $so = $this->createConfirmedSo(2); // Needs 100 units (2 * 50)

        // Create an unplanned (reorder-point) PR for 100 units, Approved status.
        $unplannedPr = PurchaseRequest::create([
            'pr_number'         => 'PR-202601-0001',
            'requested_by'      => $this->user->id,
            'department_id'     => null,
            'mrp_plan_id'       => null, // No MRP plan = unplanned
            'date'              => now()->toDateString(),
            'reason'            => 'Auto-reorder',
            'priority'          => 'normal',
            'is_auto_generated' => true,
        ]);
        $unplannedPr->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();

        PurchaseRequestItem::create([
            'purchase_request_id'  => $unplannedPr->id,
            'item_id'              => $this->material->id,
            'description'          => $this->material->name,
            'quantity'             => '100.000',
            'unit'                 => 'pcs',
            'estimated_unit_price' => '5.00',
        ]);

        // Run MRP for the SO.
        $plan = $this->engine->runForSalesOrder($so);

        $this->assertSame('active', $plan->status->value);

        // Check diagnostics: gross demand is 100, unplanned request covers all.
        $diags = $plan->diagnostics;
        $this->assertNotEmpty($diags);
        $materialDiag = collect($diags)->firstWhere('item_code', $this->material->code);
        $this->assertNotNull($materialDiag);
        $this->assertEqualsWithDelta(100.0, $materialDiag['gross'], 0.01);
        $this->assertEqualsWithDelta(0.0, $materialDiag['open_purchase_requests'], 0.01); // SO-scoped, none
        $this->assertEqualsWithDelta(100.0, $materialDiag['open_unplanned_requests'], 0.01); // Unplanned counted
        $this->assertEqualsWithDelta(0.0, $materialDiag['net'], 0.01); // No net demand

        // Verify no new PR was created for this item.
        $autoPrs = PurchaseRequest::where('is_auto_generated', true)
            ->where('status', PurchaseRequestStatus::Draft->value)
            ->get();
        foreach ($autoPrs as $pr) {
            $hasThisItem = $pr->items->where('item_id', $this->material->id)->isNotEmpty();
            $this->assertFalse($hasThisItem, 'MRP should not create a PR when unplanned PR covers demand');
        }
    }

    /**
     * Test 2: An MRP PR of 100 for SO1 partially converted to a PO of 60
     * should count 40 as open request + 60 in transit (no double count).
     * With zero stock, net = 0 for demand 100.
     */
    public function test_partially_converted_pr_counts_only_remaining(): void
    {
        $this->createBom(50.0);
        $so = $this->createConfirmedSo(2); // Needs 100 units

        // Run MRP: should create a Draft PR for 100.
        $plan = $this->engine->runForSalesOrder($so);
        $this->assertSame('active', $plan->status->value);

        $autoPr = PurchaseRequest::where('is_auto_generated', true)
            ->where('mrp_plan_id', $plan->id)
            ->firstOrFail();
        // MRP creates PRs in Draft status; they move to Approved only via workflow.
        $this->assertSame('draft', $autoPr->status->value);

        // Manually approve the PR to simulate workflow completion.
        $autoPr->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();

        $prLine = $autoPr->items->firstOrFail();

        // Manually convert 60 units to a PO (simulate a partial conversion).
        $po = PurchaseOrder::create([
            'po_number'           => 'PO-202601-0001',
            'purchase_request_id' => $autoPr->id,
            'vendor_id'           => $this->createVendor(),
            'date'                => now()->format('Y-m-d'),
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Sent])->save();

        PurchaseOrderItem::create([
            'purchase_order_id'      => $po->id,
            'purchase_request_item_id' => $prLine->id,
            'item_id'                => $this->material->id,
            'description'            => $this->material->name,
            'quantity'               => '60.000', // Partial: only 60 of 100
            'quantity_received'      => '0.000',
            'unit'                   => 'pcs',
            'unit_price'             => '5.00',
            'total'                  => '300.00', // 60 * 5.00
        ]);

        // Now run MRP again for the same SO (a rerun scenario).
        $plan2 = $this->engine->runForSalesOrder($so);

        $diags2 = $plan2->diagnostics;
        $materialDiag2 = collect($diags2)->firstWhere('item_code', $this->material->code);
        $this->assertNotNull($materialDiag2);

        // SO-scoped open request: 100 - 60 = 40 remaining.
        $this->assertEqualsWithDelta(40.0, $materialDiag2['open_purchase_requests'], 0.01);
        // In-transit (from PO): 60
        $this->assertEqualsWithDelta(60.0, $materialDiag2['in_transit'], 0.01);
        // Total available = in_transit + open_requests = 120 >= gross 100 → net = 0
        $this->assertEqualsWithDelta(0.0, $materialDiag2['net'], 0.01);
    }

    /**
     * Test 3: A Cancelled PR is not counted in open requests.
     */
    public function test_cancelled_pr_not_counted(): void
    {
        $this->createBom(50.0);
        $so = $this->createConfirmedSo(2); // Needs 100 units

        // Create an unplanned PR, then cancel it.
        $cancelledPr = PurchaseRequest::create([
            'pr_number'         => 'PR-202601-0002',
            'requested_by'      => $this->user->id,
            'department_id'     => null,
            'mrp_plan_id'       => null,
            'date'              => now()->toDateString(),
            'reason'            => 'Auto-reorder',
            'priority'          => 'normal',
            'is_auto_generated' => true,
        ]);
        $cancelledPr->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();

        PurchaseRequestItem::create([
            'purchase_request_id'  => $cancelledPr->id,
            'item_id'              => $this->material->id,
            'description'          => $this->material->name,
            'quantity'             => '100.000',
            'unit'                 => 'pcs',
            'estimated_unit_price' => '5.00',
        ]);

        // Cancel the PR.
        $cancelledPr->forceFill(['status' => PurchaseRequestStatus::Cancelled->value])->save();

        // Run MRP: should see zero open unplanned requests, create a PR for full 100.
        $plan = $this->engine->runForSalesOrder($so);

        $diags = $plan->diagnostics;
        $materialDiag = collect($diags)->firstWhere('item_code', $this->material->code);
        $this->assertNotNull($materialDiag);
        $this->assertEqualsWithDelta(0.0, $materialDiag['open_unplanned_requests'], 0.01);
        $this->assertEqualsWithDelta(100.0, $materialDiag['net'], 0.01); // Full demand, no coverage

        // Verify a new draft PR was created.
        $newPr = PurchaseRequest::where('is_auto_generated', true)
            ->where('status', PurchaseRequestStatus::Draft->value)
            ->where('id', '!=', $cancelledPr->id)
            ->first();
        $this->assertNotNull($newPr);
    }

    private function createVendor()
    {
        return \App\Modules\Accounting\Models\Vendor::factory()->create()->id;
    }
}
