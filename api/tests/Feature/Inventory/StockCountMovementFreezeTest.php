<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StockCountMovementFreezeTest extends TestCase
{
    use RefreshDatabase;

    private StockMovementService $movements;
    private Item $item;
    private WarehouseLocation $location;
    private StockCountSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->item = Item::factory()->create();
        $this->location = WarehouseLocation::factory()->create();
        $this->movements = app(StockMovementService::class);

        StockLevel::create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => '10.000',
            'reserved_quantity' => '2.000',
            'weighted_avg_cost' => '5.0000',
            'lock_version' => 0,
        ]);

        $this->session = StockCountSession::create([
            'session_number' => 'SC-FREEZE-001',
            'title' => 'Freeze regression',
            'scope' => 'zone',
            'zone_id' => $this->location->zone_id,
            'status' => 'in_progress',
            'total_locations' => 1,
            'created_by' => $user->id,
            'frozen_at' => now(),
        ]);

        StockCountItem::create([
            'session_id' => $this->session->id,
            'location_id' => $this->location->id,
            'item_id' => $this->item->id,
            'system_quantity' => '10.000',
            'status' => 'pending',
        ]);
    }

    public function test_movement_is_blocked_while_location_is_under_count(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is frozen by stock count SC-FREEZE-001');

        $this->movements->move(new StockMovementInput(
            type: StockMovementType::MaterialIssue,
            itemId: $this->item->id,
            fromLocationId: $this->location->id,
            toLocationId: null,
            quantity: '1.000',
        ));
    }

    public function test_reservation_and_release_are_blocked_while_location_is_under_count(): void
    {
        foreach (['reserve', 'release'] as $operation) {
            try {
                $this->movements->{$operation}($this->item->id, $this->location->id, '1.000');
                $this->fail("{$operation} should be blocked during an active stock count.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('is frozen by stock count SC-FREEZE-001', $exception->getMessage());
            }
        }

        $level = StockLevel::where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();
        $this->assertSame('2.000', $level->reserved_quantity);
    }

    public function test_movement_and_reservation_resume_after_count_is_cancelled(): void
    {
        $this->session->update(['status' => 'cancelled']);

        $this->movements->reserve($this->item->id, $this->location->id, '1.000');
        $this->movements->release($this->item->id, $this->location->id, '1.000');
        $this->movements->move(new StockMovementInput(
            type: StockMovementType::MaterialIssue,
            itemId: $this->item->id,
            fromLocationId: $this->location->id,
            toLocationId: null,
            quantity: '1.000',
        ));

        $level = StockLevel::where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();
        $this->assertSame('9.000', $level->quantity);
        $this->assertSame('2.000', $level->reserved_quantity);
    }
}
