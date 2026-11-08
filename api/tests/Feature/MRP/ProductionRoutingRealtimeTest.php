<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\ProductionRoutingRealtimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ProductionRoutingRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_routing_change_refreshes_active_work_order_readiness_for_product(): void
    {
        $product = Product::factory()->create();
        $workOrder = WorkOrder::factory()->create([
            'product_id' => $product->id,
            'status' => 'confirmed',
        ]);
        $routing = ProductRouting::query()->create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'total_cycle_time' => 30,
        ]);
        DB::table('event_outbox')->delete();

        DB::transaction(function () use ($routing, $product): void {
            app(ProductionRoutingRealtimeService::class)->stage($routing, $product, 'updated');
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Production\\Events\\AssignedWorkOrderChanged')
            ->get();

        $this->assertCount(1, $messages, 'A routing change must refresh active work-order readiness for its product.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($workOrder->hash_id, $payload['workOrderHashId']);
    }

    public function test_product_master_change_refreshes_active_work_order_readiness(): void
    {
        $product = Product::factory()->create();
        $workOrder = WorkOrder::factory()->create([
            'product_id' => $product->id,
            'status' => 'confirmed',
        ]);
        DB::table('event_outbox')->delete();

        $product->update(['unit_of_measure' => 'kg']);

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Production\\Events\\AssignedWorkOrderChanged')
            ->get();

        $this->assertCount(1, $messages, 'Product setup changes must refresh linked active readiness checks.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($workOrder->hash_id, $payload['workOrderHashId']);
    }
}
