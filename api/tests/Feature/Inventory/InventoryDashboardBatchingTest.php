<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\InventoryDashboardService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryDashboardBatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_low_stock_alerts_load_open_prs_and_pos_in_batched_queries(): void
    {
        Item::factory()->count(10)->create([
            'is_active' => true,
            'reorder_point' => '10.000',
            'safety_stock' => '0.000',
        ]);

        $prQueries = 0;
        $poQueries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$prQueries, &$poQueries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'purchase_requests')) {
                $prQueries++;
            }
            if (str_contains($sql, 'purchase_orders')) {
                $poQueries++;
            }
        });

        $summary = app(InventoryDashboardService::class)->summary();

        $this->assertCount(10, $summary['low_stock_alerts']);
        $this->assertSame(1, $prQueries);
        $this->assertSame(1, $poQueries);
    }
}
