<?php

declare(strict_types=1);

namespace Tests\Feature\Forecasting;

use App\Modules\Forecasting\Services\StockOutProjectionService;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
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
}
