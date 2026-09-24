<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\ItemQualityPlan;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualitySamplingBoundsTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_sampling_rejects_a_batch_above_the_operational_bound(): void
    {
        $user = User::factory()->create();
        app(SettingsService::class)->set('quality.full_sampling.max_units', 2, 'quality');

        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $purchaseOrder = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $user->id,
        ]);
        $purchaseOrderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'item_id' => $item->id,
            'description' => 'Bounded full-sampling material',
            'quantity' => '3.000',
            'unit' => 'kg',
            'unit_price' => '10.00',
            'total' => '30.00',
            'quantity_received' => '3.000',
        ]);
        $grn = GoodsReceiptNote::create([
            'grn_number' => 'GRN-FULL-BOUND',
            'purchase_order_id' => $purchaseOrder->id,
            'vendor_id' => $purchaseOrder->vendor_id,
            'received_date' => now()->toDateString(),
            'received_by' => $user->id,
            'status' => GrnStatus::PendingQc,
        ]);
        $line = GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => '3.000',
            'quantity_accepted' => '0.000',
            'unit_cost' => '10.00',
        ]);
        $plan = ItemQualityPlan::create([
            'item_id' => $item->id,
            'vendor_id' => null,
            'version' => 1,
            'stage' => 'incoming',
            'sampling_method' => 'full',
            'fixed_sample_size' => null,
            'parameters' => [[
                'parameter_name' => 'Visual condition',
                'parameter_type' => 'visual',
                'is_critical' => true,
            ]],
            'effective_from' => now()->toDateString(),
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        try {
            app(InspectionService::class)->createIncomingFromPlan($plan, $line, $grn, $user);
            $this->fail('Full sampling must reject a batch above the operational bound.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('Full sampling is limited to 2 unit(s)', $exception->getMessage());
        }

        $this->assertDatabaseMissing('inspections', ['grn_item_id' => $line->id]);
    }
}
