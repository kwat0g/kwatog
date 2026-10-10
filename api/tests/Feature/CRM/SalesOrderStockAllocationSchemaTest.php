<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesOrderStockAllocationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_schema_keeps_old_internal_jobs_unclassified(): void
    {
        $this->assertTrue(Schema::hasTable('sales_order_stock_allocations'));
        $this->assertTrue(Schema::hasColumn('delivery_items', 'sales_order_stock_allocation_id'));
        $this->assertNull(WorkOrder::factory()->create(['sales_order_id' => null])->production_purpose);
        $this->assertNull(SalesOrderItem::factory()->create()->fulfillment_policy);
    }

    public function test_empty_provenance_up_down_up_preserves_historical_rows(): void
    {
        $order = SalesOrder::factory()->create(['status' => 'confirmed', 'total_amount' => '19600.00']);
        $line = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'quantity' => '500.00']);
        $internal = WorkOrder::factory()->create(['sales_order_id' => null]);
        $linked = WorkOrder::factory()->create(['sales_order_id' => $order->id]);
        $before = $order->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_09_220000_add_stock_production_and_order_allocations.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame('500.00', (string) $line->fresh()->quantity);
        $this->assertNull($line->fresh()->fulfillment_policy);
        $this->assertNull($internal->fresh()->production_purpose);
        $this->assertSame('order', $linked->fresh()->production_purpose);
    }
}
