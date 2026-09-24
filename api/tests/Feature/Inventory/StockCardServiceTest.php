<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockCardService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StockCardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_card_uses_the_ledger_cost_for_issues_after_wac_rounding(): void
    {
        Event::fake([StockMovementCompleted::class]);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $movements = app(StockMovementService::class);

        foreach ([['100000000.000', '0.0001'], ['200000000.000', '0.0002']] as [$quantity, $unitCost]) {
            $movements->move(new StockMovementInput(
                type: StockMovementType::GrnReceipt,
                itemId: $item->id,
                fromLocationId: null,
                toLocationId: $location->id,
                quantity: $quantity,
                unitCost: $unitCost,
            ));
        }
        $movements->move(new StockMovementInput(
            type: StockMovementType::MaterialIssue,
            itemId: $item->id,
            fromLocationId: $location->id,
            toLocationId: null,
            quantity: '100000000.000',
        ));

        $card = app(StockCardService::class)->card(
            $item,
            Carbon::now()->subDay(),
            Carbon::now()->addDay(),
        );

        $this->assertSame('200000000.000', $card['closing']['balance']);
        $this->assertSame('0.0002', $card['closing']['weighted_avg']);
        $this->assertSame('40000.00', $card['closing']['value']);
    }

    public function test_unfiltered_transfer_is_zero_quantity_and_preserves_each_location_wac(): void
    {
        Event::fake([StockMovementCompleted::class]);
        $item = Item::factory()->create();
        $source = WarehouseLocation::factory()->create();
        $destination = WarehouseLocation::factory()->create();
        $movements = app(StockMovementService::class);
        $movements->move(new StockMovementInput(
            type: StockMovementType::GrnReceipt,
            itemId: $item->id,
            fromLocationId: null,
            toLocationId: $source->id,
            quantity: '10.000',
            unitCost: '3.0000',
        ));
        $transfer = $movements->move(new StockMovementInput(
            type: StockMovementType::Transfer,
            itemId: $item->id,
            fromLocationId: $source->id,
            toLocationId: $destination->id,
            quantity: '4.000',
        ));

        $card = app(StockCardService::class)->card(
            $item,
            Carbon::now()->subDay(),
            Carbon::now()->addDay(),
        );
        $transferRow = collect($card['rows'])->firstWhere('id', $transfer->hash_id);

        $this->assertSame('10.000', $card['closing']['balance']);
        $this->assertSame('30.00', $card['closing']['value']);
        $this->assertSame('4.000', $transferRow['in']);
        $this->assertSame('4.000', $transferRow['out']);
        $this->assertSame('10.000', (string) StockMovement::query()
            ->where('item_id', $item->id)
            ->whereIn('to_location_id', [$source->id, $destination->id])
            ->where('movement_type', StockMovementType::GrnReceipt)
            ->sum('quantity'));
    }
}
