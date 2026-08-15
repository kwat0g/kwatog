<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-01 — cycle-count overages must be valued at the location's WAC.
 *
 * completeSession() used to reconcile overages with unit cost '0'. Because the
 * weighted-average blend weights the added value against the new quantity,
 * every overage counted at zero dragged the location WAC toward 0.00 (10 @
 * 50.00 + 5 @ 0.00 → 33.33). The overage must inherit the current WAC so the
 * blend stays value-neutral.
 */
class CycleCountWacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->item = Item::factory()->create();
        $this->location = WarehouseLocation::factory()->create();

        app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $this->item->id,
            toLocationId: $this->location->id,
            quantity: '10.000',
            unitCost: '50.00',
            referenceType: 'opening',
            createdBy: $this->user->id,
        ));

        $this->session = StockCountSession::create([
            'session_number'  => 'SC-WAC-' . substr(uniqid(), -5),
            'title'           => 'WAC regression',
            'scope'           => 'zone',
            'zone_id'         => $this->location->zone_id,
            'status'          => 'in_progress',
            'total_locations' => 1,
            'created_by'      => $this->user->id,
            'frozen_at'       => now(),
        ]);

        // Record the count through the service (computes variance/percent) and
        // verify it, so completion applies the movement without sign-off.
        $item = StockCountItem::create([
            'session_id'      => $this->session->id,
            'location_id'     => $this->location->id,
            'item_id'         => $this->item->id,
            'system_quantity' => '10.000',
            'counted_quantity' => null,
            'status'          => 'pending',
        ]);
        app(StockCountService::class)->recordCount($item->id, ['counted_quantity' => '15.000'], $this->user);
        app(StockCountService::class)->approveVariance($item->id, $this->user);
    }

    private User $user;
    private Item $item;
    private WarehouseLocation $location;
    private StockCountSession $session;

    public function test_cycle_count_overage_is_valued_at_location_wac(): void
    {
        app(StockCountService::class)->completeSession($this->session->id, $this->user);

        $movement = StockMovement::query()
            ->where('item_id', $this->item->id)
            ->where('from_location_id', null)
            ->where('to_location_id', $this->location->id)
            ->where('movement_type', StockMovementType::AdjustmentIn->value)
            ->where('remarks', 'like', '%Cycle count adjustment%')
            ->firstOrFail();

        $this->assertSame('50.0000', (string) $movement->unit_cost, 'Overage must be valued at the current WAC, never 0');

        $level = StockLevel::where('item_id', $this->item->id)->where('location_id', $this->location->id)->firstOrFail();
        $this->assertSame('15.000', (string) $level->quantity);
        $this->assertSame('50.0000', (string) $level->weighted_avg_cost, 'Value-neutral blend must not move the WAC');
    }

    public function test_cycle_count_overage_into_empty_location_costs_zero(): void
    {
        $fresh = WarehouseLocation::factory()->create();

        $item = StockCountItem::create([
            'session_id'       => $this->session->id,
            'location_id'      => $fresh->id,
            'item_id'          => $this->item->id,
            'system_quantity'  => '0.000',
            'counted_quantity' => null,
            'status'           => 'pending',
        ]);
        app(StockCountService::class)->recordCount($item->id, ['counted_quantity' => '3.000'], $this->user);
        app(StockCountService::class)->approveVariance($item->id, $this->user);

        app(StockCountService::class)->completeSession($this->session->id, $this->user);

        $level = StockLevel::where('item_id', $this->item->id)->where('location_id', $fresh->id)->firstOrFail();
        $this->assertSame('3.000', (string) $level->quantity);
        $this->assertSame('0.0000', (string) $level->weighted_avg_cost);
    }
}
