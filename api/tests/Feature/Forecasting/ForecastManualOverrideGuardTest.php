<?php

declare(strict_types=1);

namespace Tests\Feature\Forecasting;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\Forecasting\Services\ForecastingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ForecastManualOverrideGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 12, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->value('id'),
        ]);
    }

    /**
     * Seed confirmed demand of 300 in Aug 2026 and 600 in Sep 2026 so a
     * 3-month moving average ending Sep 2026 computes to (0+300+600)/3 = 300.
     */
    private function seedHistory(Product $product): void
    {
        foreach ([['2026-08-10', 300], ['2026-09-10', 600]] as [$date, $qty]) {
            $so = SalesOrder::factory()->create([
                'date'   => $date,
                'status' => SalesOrderStatus::Confirmed,
            ]);
            SalesOrderItem::factory()->create([
                'sales_order_id' => $so->id,
                'product_id'     => $product->id,
                'quantity'       => $qty,
                'total'          => $qty * 10,
            ]);
        }
    }

    private function recomputePayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'product_id'      => $product->hash_id,
            'method'          => 'moving_avg',
            'horizon_months'  => 1,
            'lookback_months' => 3,
        ], $overrides);
    }

    public function test_manual_row_survives_recompute_by_default(): void
    {
        $product = Product::factory()->create();
        $this->seedHistory($product);
        $manual = app(ForecastingService::class)->storeManual($product->id, null, 2026, 10, 999.00);

        $this->actingAs($this->user('system_admin'))
            ->postJson('/api/v1/forecasting/demand-forecasts/recompute', $this->recomputePayload($product))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('skipped_manual.0.year', 2026)
            ->assertJsonPath('skipped_manual.0.month', 10);

        $manual->refresh();
        $this->assertSame(DemandForecast::METHOD_MANUAL, $manual->method);
        $this->assertSame('999.00', $manual->forecasted_quantity);
    }

    public function test_overwrite_manual_flag_recomputes_manual_row(): void
    {
        $product = Product::factory()->create();
        $this->seedHistory($product);
        $manual = app(ForecastingService::class)->storeManual($product->id, null, 2026, 10, 999.00);

        $this->actingAs($this->user('system_admin'))
            ->postJson('/api/v1/forecasting/demand-forecasts/recompute', $this->recomputePayload($product, [
                'overwrite_manual' => true,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('skipped_manual', []);

        $manual->refresh();
        $this->assertSame(DemandForecast::METHOD_MOVING_AVG, $manual->method);
        $this->assertSame('300.00', $manual->forecasted_quantity);
    }

    public function test_computed_rows_still_recompute_and_skips_are_reported(): void
    {
        $product = Product::factory()->create();
        $this->seedHistory($product);

        $stale = DemandForecast::factory()->create([
            'product_id'          => $product->id,
            'customer_id'         => null,
            'forecast_year'       => 2026,
            'forecast_month'      => 10,
            'method'              => DemandForecast::METHOD_MOVING_AVG,
            'forecasted_quantity' => 123.45,
        ]);
        $manual = app(ForecastingService::class)->storeManual($product->id, null, 2026, 11, 777.00);

        $this->actingAs($this->user('system_admin'))
            ->postJson('/api/v1/forecasting/demand-forecasts/recompute', $this->recomputePayload($product, [
                'horizon_months' => 2,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.forecast_year', 2026)
            ->assertJsonPath('data.0.forecast_month', 10)
            ->assertJsonPath('data.0.forecasted_quantity', 300)
            ->assertJsonPath('skipped_manual', [['year' => 2026, 'month' => 11]]);

        $this->assertSame('300.00', $stale->refresh()->forecasted_quantity);
        $this->assertSame(DemandForecast::METHOD_MANUAL, $manual->refresh()->method);
        $this->assertSame('777.00', $manual->forecasted_quantity);
    }

    public function test_compute_returns_null_and_preserves_manual_row(): void
    {
        $product = Product::factory()->create();
        $this->seedHistory($product);
        $service = app(ForecastingService::class);
        $manual = $service->storeManual($product->id, null, 2026, 10, 999.00);

        $skipped = $service->compute($product->id, null, 2026, 10, DemandForecast::METHOD_MOVING_AVG, 3);
        $this->assertNull($skipped);
        $this->assertSame(DemandForecast::METHOD_MANUAL, $manual->refresh()->method);

        $overwritten = $service->compute($product->id, null, 2026, 10, DemandForecast::METHOD_MOVING_AVG, 3, null, true);
        $this->assertNotNull($overwritten);
        $this->assertSame(DemandForecast::METHOD_MOVING_AVG, $overwritten->method);
        $this->assertSame('300.00', $overwritten->forecasted_quantity);
    }

    public function test_recompute_batch_skips_manual_rows(): void
    {
        $product = Product::factory()->create();
        $this->seedHistory($product);
        $service = app(ForecastingService::class);
        $service->storeManual($product->id, null, 2026, 10, 999.00);

        $written = $service->recomputeBatch(Carbon::create(2026, 10, 1), 2, DemandForecast::METHOD_MOVING_AVG, null, false, 3);

        $this->assertSame(1, $written);
        $this->assertSame(DemandForecast::METHOD_MANUAL, DemandForecast::query()
            ->where('product_id', $product->id)
            ->where('forecast_year', 2026)
            ->where('forecast_month', 10)
            ->value('method'));
        $this->assertSame(DemandForecast::METHOD_MOVING_AVG, DemandForecast::query()
            ->where('product_id', $product->id)
            ->where('forecast_year', 2026)
            ->where('forecast_month', 11)
            ->value('method'));
    }
}
