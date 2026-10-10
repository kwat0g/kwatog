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

    public function test_expired_lot_is_excluded_from_usable_stock_and_material_issue(): void
    {
        $item = Item::factory()->create();
        $bin = WarehouseLocation::factory()->create();
        $this->movement()->move(new StockMovementInput(type: StockMovementType::GrnReceipt, itemId: $item->id,
            toLocationId: $bin->id, quantity: '5.000', unitCost: '2.0000', lotNumber: 'EXPIRED',
            expiryDate: now()->timezone(config('app.timezone'))->subDay()->toDateString()));
        $availability = app(\App\Modules\Inventory\Services\StockLocationSummaryService::class)
            ->availability(StockLevel::query()->where('item_id', $item->id)->get());
        $this->assertSame('5.000', $availability['expired_quantity']);
        $this->assertSame('0.000', $availability['usable_quantity']);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Expired');
        $this->movement()->move(new StockMovementInput(type: StockMovementType::MaterialIssue, itemId: $item->id,
            fromLocationId: $bin->id, quantity: '1.000', lotNumber: 'EXPIRED'));
    }

    public function test_material_issue_splits_nonexpired_lots_and_preserves_value(): void
    {
        $item = Item::factory()->create();
        $bin = WarehouseLocation::factory()->create();
        foreach (['A', 'B'] as $lot) {
            $this->movement()->move(new StockMovementInput(type: StockMovementType::GrnReceipt, itemId: $item->id,
                toLocationId: $bin->id, quantity: '5.000', unitCost: '2.0000', lotNumber: $lot,
                expiryDate: now()->timezone(config('app.timezone'))->addDays($lot === 'A' ? 1 : 2)->toDateString()));
        }
        $movements = $this->movement()->issueAcrossLots(new StockMovementInput(type: StockMovementType::MaterialIssue,
            itemId: $item->id, fromLocationId: $bin->id, quantity: '8.000'));
        $this->assertSame(['A', 'B'], $movements->pluck('lot_number')->all());
        $this->assertSame(['5.000', '3.000'], $movements->pluck('quantity')->all());
        $this->assertSame('2.000', (string) StockLevel::query()->where('item_id', $item->id)->value('quantity'));
        $this->assertSame('16.0000', $movements->reduce(fn (string $sum, $row): string => bcadd($sum, (string) $row->total_cost, 4), '0.0000'));
    }

    public function test_expiry_today_is_usable_and_expired_stock_can_move_to_quarantine(): void
    {
        $item = Item::factory()->create();
        $source = WarehouseLocation::factory()->create();
        $destination = WarehouseLocation::factory()->create();
        foreach ([['TODAY', now()->timezone(config('app.timezone'))->toDateString()], ['OLD', now()->timezone(config('app.timezone'))->subDay()->toDateString()]] as [$lot, $expiry]) {
            $this->movement()->move(new StockMovementInput(type: StockMovementType::GrnReceipt, itemId: $item->id,
                toLocationId: $source->id, quantity: '2.000', unitCost: '2.0000', lotNumber: $lot, expiryDate: $expiry));
        }
        $issued = $this->movement()->move(new StockMovementInput(type: StockMovementType::MaterialIssue,
            itemId: $item->id, fromLocationId: $source->id, quantity: '1.000'));
        $this->assertSame('TODAY', $issued->lot_number);
        $transferred = app(\App\Modules\Inventory\Services\StockTransferService::class)->transfer(
            $item->id, $source->id, $destination->id, '2.000', 'Expired custody transfer', \App\Modules\Auth\Models\User::factory()->create());
        $this->assertSame('OLD', $transferred->lot_number);
    }

    public function test_split_issue_rolls_back_when_mixed_untracked_balance_needs_reconciliation(): void
    {
        $item = Item::factory()->create();
        $bin = WarehouseLocation::factory()->create();
        foreach (['A', null] as $lot) {
            $this->movement()->move(new StockMovementInput(type: StockMovementType::GrnReceipt, itemId: $item->id,
                toLocationId: $bin->id, quantity: '5.000', unitCost: '2.0000', lotNumber: $lot));
        }
        try {
            $this->movement()->issueAcrossLots(new StockMovementInput(type: StockMovementType::MaterialIssue,
                itemId: $item->id, fromLocationId: $bin->id, quantity: '8.000'));
            $this->fail('Mixed untracked balance must require reconciliation.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('reconciliation', $e->getMessage());
        }
        $this->assertSame('10.000', (string) StockLevel::query()->where('item_id', $item->id)->value('quantity'));
        $this->assertSame(0, \App\Modules\Inventory\Models\StockMovement::query()->where('movement_type', 'material_issue')->count());
    }

    private function movement(): StockMovementService
    {
        return app(StockMovementService::class);
    }
}
