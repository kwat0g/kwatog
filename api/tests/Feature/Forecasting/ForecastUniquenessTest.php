<?php

declare(strict_types=1);

namespace Tests\Feature\Forecasting;

use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\Product;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\Forecasting\Services\ForecastingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ForecastUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_forecast_replay_updates_one_null_customer_row(): void
    {
        $product = Product::factory()->create();
        $service = app(ForecastingService::class);

        $first = $service->storeManual($product->id, null, 2027, 1, 100.00);
        $replay = $service->storeManual($product->id, null, 2027, 1, 125.00);

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, DemandForecast::query()
            ->where('product_id', $product->id)
            ->whereNull('customer_id')
            ->where('forecast_year', 2027)
            ->where('forecast_month', 1)
            ->count());
        $this->assertSame('125.00', $replay->forecasted_quantity);
    }

    public function test_customer_forecasts_are_unique_per_customer_but_not_cross_customer(): void
    {
        $product = Product::factory()->create();
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $service = app(ForecastingService::class);

        $first = $service->storeManual($product->id, $customerA->id, 2027, 2, 80.00);
        $replay = $service->storeManual($product->id, $customerA->id, 2027, 2, 90.00);
        $other = $service->storeManual($product->id, $customerB->id, 2027, 2, 70.00);

        $this->assertSame($first->id, $replay->id);
        $this->assertNotSame($first->id, $other->id);
        $this->assertSame(2, DemandForecast::query()
            ->where('product_id', $product->id)
            ->where('forecast_year', 2027)
            ->where('forecast_month', 2)
            ->count());
    }

    public function test_postgres_index_treats_null_customer_as_not_distinct(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('NULLS NOT DISTINCT is PostgreSQL-specific.');
        }

        $rows = DB::select(
            "SELECT indexdef FROM pg_indexes
              WHERE schemaname = current_schema()
                AND indexname = 'demand_forecasts_scope_nulls_not_distinct_unique'"
        );

        $this->assertCount(1, $rows);
        $definition = strtolower($rows[0]->indexdef);
        $this->assertStringContainsString('unique', $definition);
        $this->assertStringContainsString('nulls not distinct', $definition);
        $this->assertStringContainsString('customer_id', $definition);
    }
}
