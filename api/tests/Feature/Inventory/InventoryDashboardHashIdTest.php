<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class InventoryDashboardHashIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_linkable_item_identifiers_are_public_hash_ids(): void
    {
        $item = Item::factory()->create([
            'reorder_point' => 10,
            'safety_stock' => 5,
        ]);

        StockMovement::query()->create([
            'item_id' => $item->id,
            'movement_type' => StockMovementType::MaterialIssue,
            'quantity' => 3,
            'unit_cost' => 2,
            'total_cost' => 6,
            'created_at' => now(),
        ]);

        Cache::forget('inv:dashboard:summary');
        $dashboard = app(InventoryDashboardService::class)->summary();

        $this->assertSame($item->hash_id, $dashboard['low_stock_alerts'][0]['item_id']);
        $this->assertSame($item->hash_id, $dashboard['top_consumed_materials'][0]['id']);
        $this->assertNotSame((string) $item->id, $dashboard['top_consumed_materials'][0]['id']);
    }
}
