<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\Product;
use App\Modules\DSS\Detectors\MasterHygieneDetector;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterHygieneDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function detector(): MasterHygieneDetector
    {
        return app(MasterHygieneDetector::class);
    }

    public function test_clean_slate_emits_nothing(): void
    {
        $this->assertSame([], $this->detector()->detect()->emissions);
    }

    public function test_flags_gaps_with_evidence_and_fix_routes(): void
    {
        // Factory item: reorder_point=0, standard_cost=0, no approved supplier.
        $item = Item::factory()->create();
        Customer::factory()->create(); // credit_limit null
        $product = Product::factory()->create(); // no BOM

        $scan = $this->detector()->detect();
        $emissions = $scan->emissions;
        $byKey = [];
        foreach ($emissions as $emission) {
            $byKey[$emission->rationale['gap']] = $emission;
        }

        $this->assertArrayHasKey('items_missing_reorder_point', $byKey);
        $this->assertArrayHasKey('items_missing_standard_cost', $byKey);
        $this->assertArrayHasKey('items_without_approved_supplier', $byKey);
        $this->assertArrayHasKey('customers_missing_credit_limit', $byKey);
        $this->assertArrayHasKey('products_without_active_bom', $byKey);

        $reorder = $byKey['items_missing_reorder_point'];
        $this->assertSame('warning', $reorder->severity);
        $this->assertSame(1, $reorder->rationale['affected_count']);
        $this->assertContains($item->code, $reorder->rationale['evidence']);
        $this->assertSame('/inventory/items', $reorder->action['route']);
        $this->assertSame(['inventory.items.manage'], $reorder->ownerPermissions);
        $this->assertSame('master_data_gap.items_missing_reorder_point', $reorder->decisionCode);
        $this->assertSame(['gap' => 'items_missing_reorder_point'], $reorder->verificationContext);
        $this->assertSame(1.0, $reorder->confidence);

        $bom = $byKey['products_without_active_bom'];
        $this->assertContains($product->part_number, $bom->rationale['evidence']);
        $this->assertSame(['mrp.boms.manage'], $bom->ownerPermissions);
        $this->assertSame('/purchasing/approved-suppliers', $byKey['items_without_approved_supplier']->action['route']);
        $this->assertSame(['purchasing.po.create'], $byKey['items_without_approved_supplier']->ownerPermissions);
        $this->assertSame(['accounting.customers.manage'], $byKey['customers_missing_credit_limit']->ownerPermissions);
        $this->assertGreaterThan(0, $scan->rowsScanned);
    }

    public function test_complete_master_data_clears_its_gap(): void
    {
        Item::factory()->create([
            'reorder_point' => 10, 'safety_stock' => 5, 'lead_time_days' => 7,
            'standard_cost' => 100,
        ]);

        $keys = array_map(
            static fn ($emission) => $emission->rationale['gap'],
            $this->detector()->detect()->emissions
        );

        $this->assertNotContains('items_missing_reorder_point', $keys);
        $this->assertNotContains('items_missing_safety_stock', $keys);
        $this->assertNotContains('items_missing_lead_time', $keys);
        // standard_cost=100 set, but no approved supplier still flags.
        $this->assertContains('items_without_approved_supplier', $keys);
    }

    public function test_detector_contract_metadata(): void
    {
        $detector = $this->detector();

        $this->assertSame('dss.master_hygiene', $detector->id());
        $this->assertSame('platform', $detector->domain());
        $this->assertSame(['admin.settings.manage'], $detector->ownerPermissions());
        $this->assertSame('v3', $detector->version());
    }

    public function test_registered_in_production_registry(): void
    {
        $this->assertTrue(app(DetectorRegistry::class)->has('dss.master_hygiene'));
    }
}
