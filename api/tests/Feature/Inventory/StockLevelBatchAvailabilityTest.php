<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockLevelBatchAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_level_page_batches_lot_history_reads(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
        ]);
        $location = WarehouseLocation::factory()->create();

        foreach (range(1, 5) as $index) {
            $item = Item::factory()->create(['code' => 'BATCHED-'.$index]);
            StockLevel::factory()->create(['item_id' => $item->id, 'location_id' => $location->id]);
        }

        $lotHistoryQueries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$lotHistoryQueries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'stock_movements')
                && str_contains($sql, 'lot_number')
                && str_contains($sql, 'from_location_id')
                && str_contains($sql, 'to_location_id')) {
                $lotHistoryQueries++;
            }
        });

        $this->actingAs($actor)
            ->getJson('/api/v1/inventory/stock-levels?per_page=10')
            ->assertOk()
            ->assertJsonCount(5, 'data');

        $this->assertSame(1, $lotHistoryQueries, 'Lot history must load once for the stock-level page.');
    }
}
