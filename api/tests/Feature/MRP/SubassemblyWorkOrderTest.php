<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\MRP\Enums\MrpRunTrigger;
use App\Modules\MRP\Events\MrpPlanGenerated;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Services\MrpEngineService;
use App\Modules\Production\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SubassemblyWorkOrderTest extends TestCase
{
    use RefreshDatabase;

    private MrpEngineService $engine;

    private Product $finishedGood;

    private Product $subassembly;

    private Item $subassemblyItem;

    private Item $rawMaterial;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([MrpPlanGenerated::class]);

        $settings = app(SettingsService::class);
        $settings->set('mrp.safety_buffer_days', 2, 'mrp');
        $settings->set('mrp.default_lead_time_days', 14, 'mrp');
        $settings->set('mrp.work_order.urgent_delivery_days', 5, 'mrp');
        $settings->set('mrp.work_order.urgent_priority', 100, 'mrp');
        $settings->set('mrp.work_order.normal_priority', 50, 'mrp');
        $settings->set('mrp.bom.max_explode_depth', 10, 'mrp');

        $this->engine = app(MrpEngineService::class);
        $this->finishedGood = $this->product('FG-HIER-001');
        $this->subassembly = $this->product('SA-HIER-001');
        $this->subassemblyItem = Item::factory()->create([
            'code' => $this->subassembly->part_number,
            'unit_of_measure' => 'pcs',
            'standard_cost' => '8.0000',
        ]);
        $this->rawMaterial = Item::factory()->create([
            'code' => 'RM-HIER-001',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '2.0000',
        ]);

        $this->bom($this->finishedGood, [
            [$this->subassemblyItem, '2.0000'],
        ]);
        $this->bom($this->subassembly, [
            [$this->rawMaterial, '3.0000'],
        ]);
    }

    public function test_mrp_creates_parent_and_child_work_orders_with_direct_materials(): void
    {
        $salesOrder = $this->salesOrder(10);

        $plan = $this->engine->runForSalesOrder($salesOrder);

        $workOrders = WorkOrder::query()
            ->where('sales_order_id', $salesOrder->id)
            ->orderBy('id')
            ->get();
        $root = $workOrders->firstWhere('product_id', $this->finishedGood->id);
        $child = $workOrders->firstWhere('product_id', $this->subassembly->id);

        $this->assertCount(2, $workOrders);
        $this->assertSame(2, $plan->draft_wo_count);
        $this->assertNotNull($root);
        $this->assertNotNull($child);
        $this->assertNull($root->parent_wo_id);
        $this->assertSame($root->id, $child->parent_wo_id);
        $this->assertSame('20', (string) $child->quantity_target);

        $rootMaterialIds = $root->materials()->pluck('item_id')->all();
        $this->assertSame([$this->subassemblyItem->id], $rootMaterialIds);
        $this->assertSame('20.000', (string) $root->materials()->firstOrFail()->bom_quantity);
        $this->assertSame('60.000', (string) $child->materials()->firstOrFail()->bom_quantity);
        $this->assertSame($plan->id, $child->mrp_plan_id);
    }

    public function test_rerunning_mrp_does_not_duplicate_subassembly_work_orders(): void
    {
        $salesOrder = $this->salesOrder(10);

        $this->engine->runForSalesOrder($salesOrder);
        $this->engine->runForSalesOrder($salesOrder->fresh());

        $this->assertSame(2, WorkOrder::query()
            ->where('sales_order_id', $salesOrder->id)
            ->where('status', 'planned')
            ->count());
    }

    private function product(string $partNumber): Product
    {
        return Product::factory()->create([
            'part_number' => $partNumber,
            'unit_of_measure' => 'pcs',
            'standard_cost' => '20.0000',
            'is_active' => true,
        ]);
    }

    /** @param array<int, array{0: Item, 1: string}> $lines */
    private function bom(Product $product, array $lines): Bom
    {
        $bom = Bom::create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
        ]);

        foreach ($lines as $sort => [$item, $quantity]) {
            BomItem::create([
                'bom_id' => $bom->id,
                'item_id' => $item->id,
                'quantity_per_unit' => $quantity,
                'unit' => 'pcs',
                'waste_factor' => '0.00',
                'sort_order' => $sort,
            ]);
        }

        return $bom;
    }

    private function salesOrder(int $quantity): SalesOrder
    {
        $user = User::factory()->create();
        $salesOrder = SalesOrder::create([
            'so_number' => 'SO-HIER-'.bin2hex(random_bytes(4)),
            'customer_id' => DB::table('customers')->insertGetId([
                'name' => 'Hierarchy Customer '.bin2hex(random_bytes(4)),
                'is_active' => true,
                'payment_terms_days' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'date' => Carbon::today()->toDateString(),
            'subtotal' => $quantity * 20,
            'vat_amount' => 0,
            'total_amount' => $quantity * 20,
            'status' => 'confirmed',
            'payment_terms_days' => 30,
            'created_by' => $user->id,
        ]);
        SalesOrderItem::create([
            'sales_order_id' => $salesOrder->id,
            'product_id' => $this->finishedGood->id,
            'quantity' => $quantity,
            'unit_price' => 20,
            'total' => $quantity * 20,
            'quantity_delivered' => 0,
            'delivery_date' => Carbon::today()->addDays(30)->toDateString(),
        ]);

        return $salesOrder->fresh();
    }
}
