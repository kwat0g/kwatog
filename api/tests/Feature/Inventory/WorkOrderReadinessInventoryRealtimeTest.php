<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Services\OutboxEventCodec;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Production\Events\AssignedWorkOrderChanged;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WorkOrderReadinessInventoryRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_finished_good_item_mapping_changes_refresh_linked_active_work_order_readiness(): void
    {
        $product = Product::factory()->create(['part_number' => 'READINESS-FG-1']);
        $workOrder = WorkOrder::factory()->create([
            'product_id' => $product->id,
            'status' => 'confirmed',
        ]);
        $item = Item::factory()->create([
            'code' => $product->part_number,
            'item_type' => 'finished_good',
        ]);
        DB::table('event_outbox')->delete();

        $item->update(['is_active' => false]);

        $message = DB::table('event_outbox')
            ->where('event_type', AssignedWorkOrderChanged::class)
            ->first();

        $this->assertNotNull($message, 'Changing the finished-good mapping must refresh linked production readiness.');
        $payload = json_decode((string) $message->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($workOrder->hash_id, $payload['workOrderHashId']);
    }

    public function test_finished_goods_location_changes_use_the_authorized_production_readiness_channel(): void
    {
        $warehouse = Warehouse::factory()->create();
        $zone = WarehouseZone::factory()->create([
            'warehouse_id' => $warehouse->id,
            'zone_type' => 'finished_goods',
        ]);
        $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        DB::table('event_outbox')->delete();

        $location->update(['is_blocked' => true]);

        $row = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Inventory\\Events\\WarehouseLayoutChanged')
            ->first();
        $this->assertNotNull($row, 'The layout update should stage the existing warehouse refresh event.');
        $event = app(OutboxEventCodec::class)->decode(
            $row->event_type,
            json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR),
        );
        $channelNames = array_map(
            static fn ($channel): string => $channel->name,
            $event->broadcastOn(),
        );
        $payload = $event->broadcastWith();

        $this->assertContains('private-production.readiness', $channelNames);
        $this->assertSame(['refresh', 'event_id', 'entity_type', 'action', 'version', 'timestamp'], array_keys($payload));
    }
}
