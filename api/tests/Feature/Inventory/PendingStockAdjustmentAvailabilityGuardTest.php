<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingStockAdjustmentAvailabilityGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, ChartOfAccountsSeeder::class, SettingsSeeder::class]);
    }

    public function test_high_value_outbound_adjustment_cannot_enter_queue_above_unreserved_stock(): void
    {
        $warehouse = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
            'is_active' => true,
        ]);
        $item = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create();
        StockLevel::query()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '1.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '5.0000',
        ]);
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 1, 'inventory');

        $beforeMovements = StockMovement::query()->count();
        $beforeAdjustments = StockAdjustment::query()->count();
        $beforeAudit = AuditLog::query()
            ->where('model_type', (new StockAdjustment)->getMorphClass())
            ->count();

        $response = $this->actingAs($warehouse)->postJson('/api/v1/inventory/stock-adjustments', [
            'item_id' => $item->hash_id,
            'location_id' => $location->hash_id,
            'direction' => 'out',
            'quantity' => '2',
            'reason_code' => 'damage',
            'reason' => 'Attempt to queue write-off beyond on-hand stock.',
        ]);

        $response->assertUnprocessable()->assertJsonPath(
            'message',
            "Insufficient available stock at location {$location->id} for item {$item->id}: needed 2.000, available 1.000.",
        );
        $this->assertSame('1.000', (string) StockLevel::query()
            ->where('item_id', $item->id)
            ->where('location_id', $location->id)
            ->value('quantity'));
        $this->assertSame($beforeMovements, StockMovement::query()->count());
        $this->assertSame($beforeAdjustments, StockAdjustment::query()->count());
        $this->assertSame($beforeAudit, AuditLog::query()
            ->where('model_type', (new StockAdjustment)->getMorphClass())
            ->count());
    }
}
