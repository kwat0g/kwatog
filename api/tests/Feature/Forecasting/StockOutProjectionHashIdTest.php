<?php

declare(strict_types=1);

namespace Tests\Feature\Forecasting;

use App\Modules\Forecasting\Services\StockOutProjectionService;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Common\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StockOutProjectionHashIdTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_projection_exposes_a_linkable_item_hash_id(): void
    {
        $item = Item::factory()->create();

        $rows = app(StockOutProjectionService::class)->projectAll();

        $row = collect($rows)->firstWhere('code', $item->code);
        $this->assertNotNull($row);
        $this->assertSame($item->hash_id, $row['item_id']);
        $this->assertNotSame((string) $item->id, $row['item_id']);
    }

    public function test_projection_keeps_quantity_values_as_decimal_strings(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        $code = 'DEC-'.substr(uniqid(), -6);
        $item = Item::factory()->create([
            'code' => $code,
            'safety_stock' => '0.000',
            'reorder_point' => '0.000',
            'minimum_order_quantity' => '0.000',
            'lead_time_days' => 1,
        ]);
        $product = Product::factory()->create(['part_number' => $code]);
        StockLevel::factory()->create([
            'item_id' => $item->id,
            'quantity' => '1.000',
            'reserved_quantity' => '0.000',
        ]);
        DemandForecast::factory()->create([
            'product_id' => $product->id,
            'customer_id' => null,
            'forecast_year' => 2026,
            'forecast_month' => 10,
            'forecasted_quantity' => '0.10',
        ]);

        $row = collect(app(StockOutProjectionService::class)->projectAll(365))
            ->firstWhere('code', $code);

        $this->assertNotNull($row);
        $this->assertSame('1.000', $row['available']);
        $this->assertSame('0.000', $row['safety_stock']);
        $this->assertSame('0.003', $row['daily_demand']);
        $this->assertSame('0.004', $row['suggested_qty']);
    }

    public function test_finished_good_projection_uses_default_production_lead_time_without_purchase_quantity(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        app(SettingsService::class)->set('mrp.default_lead_time_days', 7, 'mrp');

        $code = 'FG-'.substr(uniqid(), -6);
        $item = Item::factory()->create([
            'code' => $code,
            'item_type' => 'finished_good',
            'safety_stock' => '0.000',
            'reorder_point' => '0.000',
            'minimum_order_quantity' => '400.000',
            'lead_time_days' => 0,
        ]);
        $product = Product::factory()->create(['part_number' => $code]);
        $usableLocation = WarehouseLocation::factory()->for(
            WarehouseZone::factory()->state(['zone_type' => 'finished_goods']), 'zone'
        )->create();
        $quarantineLocation = WarehouseLocation::factory()->for(
            WarehouseZone::factory()->state(['zone_type' => 'quarantine']), 'zone'
        )->create();
        StockLevel::factory()->create([
            'item_id' => $item->id,
            'location_id' => $usableLocation->id,
            'quantity' => '1.000',
            'reserved_quantity' => '0.000',
        ]);
        StockLevel::factory()->create([
            'item_id' => $item->id,
            'location_id' => $quarantineLocation->id,
            'quantity' => '10.000',
            'reserved_quantity' => '0.000',
        ]);
        DemandForecast::factory()->create([
            'product_id' => $product->id,
            'customer_id' => null,
            'forecast_year' => 2026,
            'forecast_month' => 10,
            'forecasted_quantity' => '30.00',
        ]);

        $row = collect(app(StockOutProjectionService::class)->projectAll(365))
            ->firstWhere('code', $code);

        $this->assertNotNull($row);
        $this->assertSame('finished_good', $row['item_type']);
        $this->assertSame('1.000', $row['available']);
        $this->assertSame(7, $row['lead_time_days']);
        $this->assertNull($row['suggested_qty']);
    }
}
