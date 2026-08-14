<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Common\Services\SettingsService;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\MRP\Enums\MrpRunTrigger;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Services\MrpEngineService;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MrpDemandIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private MrpEngineService $engine;

    private Product $product;

    private Item $material;

    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([\App\Modules\MRP\Events\MrpPlanGenerated::class]);

        $settings = app(SettingsService::class);
        $settings->set('mrp.safety_buffer_days', 2, 'mrp');
        $settings->set('mrp.default_lead_time_days', 14, 'mrp');
        $settings->set('mrp.work_order.urgent_delivery_days', 5, 'mrp');
        $settings->set('mrp.work_order.urgent_priority', 100, 'mrp');
        $settings->set('mrp.work_order.normal_priority', 50, 'mrp');
        $settings->set('mrp.bom.max_explode_depth', 10, 'mrp');

        $this->engine = app(MrpEngineService::class);
        $this->product = Product::factory()->create([
            'part_number' => 'FG-DEMAND-001',
            'standard_cost' => '20.00',
        ]);
        $this->material = Item::factory()->create([
            'code' => 'RM-DEMAND-001',
            'standard_cost' => '5.0000',
            'lead_time_days' => 7,
        ]);
        $this->location = WarehouseLocation::factory()->create();

        $bom = Bom::create([
            'product_id' => $this->product->id,
            'version' => 1,
            'is_active' => true,
        ]);
        BomItem::create([
            'bom_id' => $bom->id,
            'item_id' => $this->material->id,
            'quantity_per_unit' => '1.0000',
            'unit' => 'pcs',
            'waste_factor' => '0.00',
            'sort_order' => 0,
        ]);
    }

    public function test_run_all_allocates_shared_stock_across_sales_orders(): void
    {
        $this->setOnHand('10.000');
        $first = $this->salesOrder(10);
        $second = $this->salesOrder(10);

        $this->engine->runForAllActiveSalesOrders(MrpRunTrigger::Manual);

        $this->assertSame(1, PurchaseRequest::where('is_auto_generated', true)
            ->where('status', PurchaseRequestStatus::Draft->value)
            ->count());
        $this->assertSame('10.00', (string) PurchaseRequest::query()
            ->where('is_auto_generated', true)
            ->firstOrFail()
            ->items()
            ->firstOrFail()
            ->quantity);
        $this->assertNotNull($first->fresh()->mrp_plan_id);
        $this->assertNotNull($second->fresh()->mrp_plan_id);
    }

    public function test_partially_delivered_sales_order_plans_only_remaining_quantity(): void
    {
        $this->salesOrder(10, 6);

        $this->engine->runForSalesOrder(SalesOrder::query()->latest('id')->firstOrFail());

        $this->assertSame('4.00', (string) PurchaseRequest::query()
            ->where('is_auto_generated', true)
            ->firstOrFail()
            ->items()
            ->firstOrFail()
            ->quantity);
    }

    public function test_rerun_does_not_create_new_pr_for_open_progressed_request(): void
    {
        $so = $this->salesOrder(10);
        $this->engine->runForSalesOrder($so);

        $existing = PurchaseRequest::query()->where('is_auto_generated', true)->firstOrFail();
        $existing->forceFill(['status' => PurchaseRequestStatus::Pending->value])->save();

        $this->engine->runForSalesOrder($so->fresh());

        $this->assertSame(1, PurchaseRequest::where('is_auto_generated', true)->count());
        $this->assertSame(PurchaseRequestStatus::Pending->value, $existing->fresh()->status->value);
    }

    private function salesOrder(int $quantity, int $delivered = 0): SalesOrder
    {
        $user = User::factory()->create();
        $so = SalesOrder::create([
            'so_number' => 'SO-DMD-'.bin2hex(random_bytes(4)),
            'customer_id' => $this->customer(),
            'date' => Carbon::today()->toDateString(),
            'subtotal' => $quantity * 20,
            'vat_amount' => 0,
            'total_amount' => $quantity * 20,
            'status' => 'confirmed',
            'payment_terms_days' => 30,
            'created_by' => $user->id,
        ]);
        SalesOrderItem::create([
            'sales_order_id' => $so->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'unit_price' => 20,
            'total' => $quantity * 20,
            'quantity_delivered' => $delivered,
            'delivery_date' => Carbon::today()->addDays(30)->toDateString(),
        ]);

        return $so->fresh();
    }

    private function customer(): int
    {
        return DB::table('customers')->insertGetId([
            'name' => 'MRP Demand Customer '.uniqid(),
            'is_active' => true,
            'payment_terms_days' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setOnHand(string $quantity): void
    {
        StockLevel::create([
            'item_id' => $this->material->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '5.0000',
            'lock_version' => 0,
        ]);
    }
}
