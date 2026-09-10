<?php

declare(strict_types=1);

namespace Tests\Feature\Forecasting;

use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\Product;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\Forecasting\Services\ForecastMrpService;
use App\Modules\Inventory\Models\Item;
use App\Modules\MRP\Services\BomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class ForecastMrpDoubleCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_row_wins_when_per_customer_rows_coexist(): void
    {
        $product = Product::factory()->create(['include_forecast_in_mrp' => true]);
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $item = Item::factory()->create(['safety_stock' => 0]);

        DemandForecast::factory()->create([
            'product_id' => $product->id,
            'customer_id' => null,
            'forecast_year' => 2026,
            'forecast_month' => 9,
            'forecasted_quantity' => 100,
        ]);
        DemandForecast::factory()->create([
            'product_id' => $product->id,
            'customer_id' => $customerA->id,
            'forecast_year' => 2026,
            'forecast_month' => 9,
            'forecasted_quantity' => 60,
        ]);
        DemandForecast::factory()->create([
            'product_id' => $product->id,
            'customer_id' => $customerB->id,
            'forecast_year' => 2026,
            'forecast_month' => 9,
            'forecasted_quantity' => 40,
        ]);

        $bom = Mockery::mock(BomService::class);
        $bom->shouldReceive('explode')
            ->once()
            ->with($product->id, 100.0)
            ->andReturn(collect([
                [
                    'item_id' => $item->id,
                    'item_code' => $item->code,
                    'item_name' => $item->name,
                    'gross_quantity' => '200.000',
                ],
            ]));

        $result = (new ForecastMrpService($bom))->project(2026, 9);

        $this->assertCount(1, $result['products']);
        $this->assertSame($product->hash_id, $result['products'][0]['product_id']);
        $this->assertSame('100.00', $result['products'][0]['forecasted_quantity']);
        $this->assertSame('200.000', $result['materials'][0]['gross_required']);
    }

    public function test_per_customer_rows_are_summed_when_no_total_row_exists(): void
    {
        $product = Product::factory()->create(['include_forecast_in_mrp' => true]);
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();

        DemandForecast::factory()->create([
            'product_id' => $product->id,
            'customer_id' => $customerA->id,
            'forecast_year' => 2026,
            'forecast_month' => 9,
            'forecasted_quantity' => 60,
        ]);
        DemandForecast::factory()->create([
            'product_id' => $product->id,
            'customer_id' => $customerB->id,
            'forecast_year' => 2026,
            'forecast_month' => 9,
            'forecasted_quantity' => 40,
        ]);

        $bom = Mockery::mock(BomService::class);
        $bom->shouldReceive('explode')
            ->once()
            ->with($product->id, 100.0)
            ->andReturn(new Collection());

        $result = (new ForecastMrpService($bom))->project(2026, 9);

        $this->assertCount(1, $result['products']);
        $this->assertSame($product->hash_id, $result['products'][0]['product_id']);
        $this->assertSame('100.00', $result['products'][0]['forecasted_quantity']);
        $this->assertFalse($result['products'][0]['has_bom']);
    }

    public function test_total_row_alone_is_unchanged(): void
    {
        $product = Product::factory()->create(['include_forecast_in_mrp' => true]);

        DemandForecast::factory()->create([
            'product_id' => $product->id,
            'customer_id' => null,
            'forecast_year' => 2026,
            'forecast_month' => 9,
            'forecasted_quantity' => 75,
        ]);

        $bom = Mockery::mock(BomService::class);
        $bom->shouldReceive('explode')
            ->once()
            ->with($product->id, 75.0)
            ->andReturn(new Collection());

        $result = (new ForecastMrpService($bom))->project(2026, 9);

        $this->assertCount(1, $result['products']);
        $this->assertSame($product->hash_id, $result['products'][0]['product_id']);
        $this->assertSame('75.00', $result['products'][0]['forecasted_quantity']);
    }

    public function test_product_appears_once_when_both_row_families_exist_across_products(): void
    {
        $withBoth = Product::factory()->create(['include_forecast_in_mrp' => true]);
        $onlyTotal = Product::factory()->create(['include_forecast_in_mrp' => true]);
        $customer = Customer::factory()->create();

        DemandForecast::factory()->create([
            'product_id' => $withBoth->id,
            'customer_id' => null,
            'forecast_year' => 2026,
            'forecast_month' => 9,
            'forecasted_quantity' => 50,
        ]);
        DemandForecast::factory()->create([
            'product_id' => $withBoth->id,
            'customer_id' => $customer->id,
            'forecast_year' => 2026,
            'forecast_month' => 9,
            'forecasted_quantity' => 30,
        ]);
        DemandForecast::factory()->create([
            'product_id' => $onlyTotal->id,
            'customer_id' => null,
            'forecast_year' => 2026,
            'forecast_month' => 9,
            'forecasted_quantity' => 20,
        ]);

        $bom = Mockery::mock(BomService::class);
        $bom->shouldReceive('explode')->andReturn(new Collection());

        $result = (new ForecastMrpService($bom))->project(2026, 9);

        $ids = array_column($result['products'], 'product_id');
        $this->assertCount(2, $ids);
        $this->assertSame(array_unique($ids), $ids);
        $this->assertContains($withBoth->hash_id, $ids);
        $this->assertContains($onlyTotal->hash_id, $ids);
    }
}
