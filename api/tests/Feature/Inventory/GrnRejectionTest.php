<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3.6 — GRN rejection NCR pollution fix.
 *
 * Bug: receiveWithQc(result='failed') unconditionally calls
 * fastCompleteInspection(passed=false), which sets all measurement rows to
 * is_pass=false and triggers InspectionService::complete() → afterCommit →
 * NcrService::openFromInspectionFailure(). This means a LOGISTICS rejection
 * (wrong part number, short shipment) auto-opens an NCR — polluting the NCR
 * queue and NCR-rate metrics with non-quality events.
 *
 * Fix: receiveWithQc() accepts an optional $isQualityFailure parameter
 * (default true). When false, fastCompleteInspection() cancels the inspection
 * instead of force-completing it as failed, preventing NCR auto-creation.
 *
 * Tests
 * ─────
 * 1. test_quality_rejection_still_auto_opens_ncr
 *    A QC failure (result='failed', isQualityFailure=true / default) must
 *    still auto-create an NCR — unchanged behavior.
 *
 * 2. test_logistics_rejection_does_not_open_ncr
 *    A logistics rejection (result='failed', isQualityFailure=false) must
 *    NOT create an NCR.
 */
class GrnRejectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;
    private InspectionSpec $spec;
    private GrnService $grnSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        // Product with a dimensional inspection spec (one critical parameter).
        $this->product = Product::create([
            'part_number'     => 'P3-GRN-TEST',
            'name'            => 'Test Wiper Bushing (GRN)',
            'unit_of_measure' => 'pcs',
            'standard_cost'   => '15.00',
            'is_active'       => true,
        ]);

        $this->spec = InspectionSpec::create([
            'product_id' => $this->product->id,
            'version'    => 1,
            'is_active'  => true,
            'created_by' => $this->user->id,
        ]);

        InspectionSpecItem::create([
            'inspection_spec_id' => $this->spec->id,
            'parameter_name'     => 'Shaft OD',
            'parameter_type'     => 'dimensional',
            'unit_of_measure'    => 'mm',
            'nominal_value'      => '10.00',
            'tolerance_min'      => '9.90',
            'tolerance_max'      => '10.10',
            'is_critical'        => true,
            'sort_order'         => 1,
        ]);

        $this->grnSvc = app(GrnService::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build an approved PO with one line item ready to receive.
     * Returns [$po, $poItem, $item, $location].
     */
    private function buildPurchaseOrder(): array
    {
        $item     = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create();

        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Test line',
            'quantity'          => '100.000',
            'unit'              => 'pcs',
            'unit_price'        => '10.00',
            'total'             => '1000.00',
            'quantity_received' => '0.000',
        ]);

        return [$po, $poItem, $item, $location];
    }

    /**
     * Build the items array for receiveWithQc() from the PO line.
     */
    private function buildItems(
        PurchaseOrderItem $poItem,
        Item $item,
        WarehouseLocation $location,
        string $qty = '50',
    ): array {
        return [[
            'purchase_order_item_id' => $poItem->id,
            'item_id'                => $item->id,
            'location_id'            => $location->id,
            'quantity_received'      => $qty,
            'unit_cost'              => '10.00',
        ]];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 1: Quality rejection still auto-opens NCR (unchanged behavior)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A genuine QC failure (result='failed', default isQualityFailure=true)
     * must still trigger the inspection → NCR path unchanged.
     */
    public function test_quality_rejection_still_auto_opens_ncr(): void
    {
        [$po, $poItem, $item, $location] = $this->buildPurchaseOrder();

        $this->grnSvc->receiveWithQc(
            po:     $po,
            items:  $this->buildItems($poItem, $item, $location),
            meta:   ['received_date' => now()->toDateString()],
            qcData: [
                'result'          => 'failed',
                'product_id'      => $this->product->id,
                'inspector_id'    => $this->user->id,
                'failure_reason'  => 'Critical dimension out of tolerance',
                'disposition'     => 'return_to_supplier',
                // isQualityFailure defaults to true — NCR MUST be created
            ],
            by: $this->user,
        );

        // The inspection linked to the GRN must exist and have failed.
        $inspectionCount = \App\Modules\Quality\Models\Inspection::where('stage', 'incoming')->count();
        $this->assertGreaterThan(0, $inspectionCount, 'An incoming inspection must be created for QC failure');

        $inspection = \App\Modules\Quality\Models\Inspection::where('stage', 'incoming')->first();

        // An NCR must be auto-created from the failed quality inspection.
        $ncrCount = NonConformanceReport::where('inspection_id', $inspection->id)->count();
        $this->assertSame(
            1,
            $ncrCount,
            'A quality (QC) GRN rejection must auto-open exactly one NCR',
        );

        $ncr = NonConformanceReport::where('inspection_id', $inspection->id)->first();
        $this->assertTrue(
            $ncr->is_auto_generated,
            'The auto-opened NCR must be flagged is_auto_generated=true',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 2: Logistics rejection does NOT open an NCR
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A logistics/non-quality rejection (result='failed', isQualityFailure=false)
     * must NOT create any NCR. The GRN must still be rejected cleanly.
     */
    public function test_logistics_rejection_does_not_open_ncr(): void
    {
        [$po, $poItem, $item, $location] = $this->buildPurchaseOrder();

        $result = $this->grnSvc->receiveWithQc(
            po:                $po,
            items:             $this->buildItems($poItem, $item, $location),
            meta:              ['received_date' => now()->toDateString()],
            qcData:            [
                'result'             => 'failed',
                'product_id'         => $this->product->id,
                'inspector_id'       => $this->user->id,
                'failure_reason'     => 'Wrong part number received (logistics error)',
                'disposition'        => 'return_to_supplier',
                'is_quality_failure' => false,  // <-- logistics rejection
            ],
            by: $this->user,
        );

        // GRN must be rejected.
        $this->assertSame(
            'rejected',
            $result['grn']->status->value,
            'GRN must be rejected even for a logistics rejection',
        );

        // No NCR must exist for any inspection linked to this GRN.
        $totalNcrCount = NonConformanceReport::count();
        $this->assertSame(
            0,
            $totalNcrCount,
            'A logistics GRN rejection must NOT create any NCR',
        );
    }
}
