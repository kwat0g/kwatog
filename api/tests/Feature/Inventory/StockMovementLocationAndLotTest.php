<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StockMovementLocationAndLotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([StockMovementCompleted::class]);
    }

    public function test_receipt_cannot_target_an_inactive_location(): void
    {
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create(['is_active' => false]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('inactive');

        $this->movement()->move(new StockMovementInput(
            type: StockMovementType::GrnReceipt,
            itemId: $item->id,
            fromLocationId: null,
            toLocationId: $location->id,
            quantity: '1.000',
            unitCost: '1.0000',
        ));
    }

    public function test_material_issue_cannot_consume_from_an_inactive_location(): void
    {
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create(['is_active' => false]);
        StockLevel::create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '3.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '1.0000',
            'lock_version' => 0,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('inactive');

        $this->movement()->move(new StockMovementInput(
            type: StockMovementType::MaterialIssue,
            itemId: $item->id,
            fromLocationId: $location->id,
            toLocationId: null,
            quantity: '1.000',
        ));
    }

    public function test_adjustment_out_cannot_originate_from_an_inactive_location(): void
    {
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create(['is_active' => false]);
        StockLevel::create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '3.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '1.0000',
            'lock_version' => 0,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('inactive');

        $this->movement()->move(new StockMovementInput(
            type: StockMovementType::AdjustmentOut,
            itemId: $item->id,
            fromLocationId: $location->id,
            toLocationId: null,
            quantity: '1.000',
        ));
    }

    public function test_issue_cannot_overdraw_a_specific_lot_when_other_lots_exist(): void
    {
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $movementService = $this->movement();
        foreach ([['LOT-A', '5.000'], ['LOT-B', '5.000']] as [$lot, $quantity]) {
            $movementService->move(new StockMovementInput(
                type: StockMovementType::GrnReceipt,
                itemId: $item->id,
                fromLocationId: null,
                toLocationId: $location->id,
                quantity: $quantity,
                unitCost: '1.0000',
                lotNumber: $lot,
            ));
        }

        try {
            $movementService->move(new StockMovementInput(
                type: StockMovementType::MaterialIssue,
                itemId: $item->id,
                fromLocationId: $location->id,
                toLocationId: null,
                quantity: '6.000',
                lotNumber: 'LOT-A',
            ));
            $this->fail('An issue must not consume six units from a five-unit lot.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('LOT-A', $e->getMessage());
        }

        $this->assertSame('10.000', (string) StockLevel::query()
            ->where('item_id', $item->id)
            ->where('location_id', $location->id)
            ->value('quantity'));
    }

    private function movement(): StockMovementService
    {
        return app(StockMovementService::class);
    }
}
