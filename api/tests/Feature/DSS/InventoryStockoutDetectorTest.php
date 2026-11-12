<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Modules\CRM\Models\Product;
use App\Modules\DSS\Detectors\InventoryStockoutDetector;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\Inventory\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class InventoryStockoutDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('products');
        Schema::dropIfExists('items');
        Schema::dropIfExists('dss_parameters');

        Schema::create('items', static function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('item_type');
            $table->decimal('reorder_point', 12, 3)->nullable();
            $table->decimal('safety_stock', 12, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });

        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->string('part_number')->unique();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });

        Schema::create('stock_levels', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('location_id');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('reserved_quantity', 12, 3)->default(0);
            $table->decimal('weighted_avg_cost', 12, 4)->default(0);
        });

        Schema::create('dss_parameters', static function (Blueprint $table): void {
            $table->id();
            $table->string('detector_id');
            $table->string('key');
            $table->text('value')->nullable();
        });
    }

    private function detector(): InventoryStockoutDetector
    {
        return app(InventoryStockoutDetector::class);
    }

    public function test_zero_stock_sellable_finished_good_recommends_production(): void
    {
        $item = $this->item([
            'code' => 'PC-001',
            'item_type' => 'finished_good',
            'reorder_point' => 0,
        ]);
        $this->product($item->code, true);

        $scan = $this->detector()->detect();

        $this->assertCount(1, $scan->emissions);
        $recommendation = $scan->emissions[0];
        $this->assertSame('inventory_stockout', $recommendation->type);
        $this->assertSame('critical', $recommendation->severity);
        $this->assertSame('stockout:item:'.$item->id, $recommendation->dedupeKey);
        $this->assertSame('/production/work-orders', $recommendation->action['route']);
        $this->assertSame('production', $recommendation->rationale['replenishment_path']);
        $this->assertSame('0.000', $recommendation->rationale['usable_quantity']);
    }

    public function test_zero_stock_item_with_reorder_policy_recommends_purchasing_review(): void
    {
        $this->item([
            'item_type' => 'raw_material',
            'reorder_point' => 20,
        ]);

        $scan = $this->detector()->detect();

        $this->assertCount(1, $scan->emissions);
        $this->assertSame('/purchasing/purchase-requests', $scan->emissions[0]->action['route']);
        $this->assertSame('purchasing_review', $scan->emissions[0]->rationale['replenishment_path']);
    }

    public function test_zero_stock_without_reorder_policy_or_active_product_is_not_recommended(): void
    {
        $this->item(['item_type' => 'raw_material', 'reorder_point' => 0]);
        $finishedGood = $this->item([
            'code' => 'FG-NOT-SELLABLE',
            'item_type' => 'finished_good',
            'reorder_point' => 0,
        ]);
        $this->product($finishedGood->code, false);

        $this->assertSame([], $this->detector()->detect()->emissions);
    }

    public function test_detector_is_registered_and_uses_inventory_domain_permissions(): void
    {
        $detector = $this->detector();

        $this->assertSame('dss.inventory_stockout', $detector->id());
        $this->assertSame('inventory', $detector->domain());
        $this->assertSame(['inventory.view', 'production.work_orders.view', 'purchasing.pr.create'], $detector->ownerPermissions());
        $this->assertTrue(app(DetectorRegistry::class)->has('dss.inventory_stockout'));
    }

    public function test_stockout_recommendations_are_advisory_by_default(): void
    {
        $this->assertSame('advisory', app(DssParameterService::class)->detectorMode('dss.inventory_stockout'));
        $this->assertSame('shadow', app(DssParameterService::class)->detectorMode('dss.master_hygiene'));
    }

    /** @param array<string, mixed> $attributes */
    private function item(array $attributes): Item
    {
        static $sequence = 0;
        $sequence++;
        $data = array_merge([
            'code' => 'TEST-ITEM-'.$sequence,
            'name' => 'Test item '.$sequence,
            'item_type' => 'raw_material',
            'reorder_point' => 0,
            'safety_stock' => 0,
            'is_active' => true,
        ], $attributes);

        $id = DB::table('items')->insertGetId($data);

        return Item::query()->findOrFail($id);
    }

    private function product(string $partNumber, bool $active): void
    {
        DB::table('products')->insert([
            'part_number' => $partNumber,
            'is_active' => $active,
        ]);
    }
}
