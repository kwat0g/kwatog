<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

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

/**
 * Pin the weighted-average-cost (WAC) logic inside StockMovementService.
 *
 * WAC formula on receipt (per service doc):
 *   new_wac = ((old_qty * old_wac) + (recv_qty * unit_cost)) / new_qty
 *   — rounded to 4 decimal places HALF UP via bcmath
 *
 * Issues / deliveries: cost out at current WAC; WAC at source unchanged.
 */
class WeightedAvgCostTest extends TestCase
{
    use RefreshDatabase;

    private StockMovementService $svc;
    private Item $item;
    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        // Suppress the StockMovementCompleted → CheckReorderPoint → AutoReplenishmentService
        // side-effect, which tries to create a PurchaseRequest with `requested_by = 1`
        // (a system user that doesn't exist in the isolated test DB). These tests only
        // pin WAC arithmetic; the auto-replenishment path has its own test coverage.
        Event::fake([StockMovementCompleted::class]);

        $this->svc      = app(StockMovementService::class);
        $this->item     = Item::factory()->create();
        $this->location = WarehouseLocation::factory()->create();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helper: build a receipt input into $this->location
    // ────────────────────────────────────────────────────────────────────────
    private function receipt(string $qty, string $unitCost): void
    {
        // NOTE: StockMovementInput has optional nullable params ($fromLocationId,
        // $toLocationId) declared before a required param ($quantity). PHP 8.3
        // treats them as implicitly required, so we must pass them explicitly.
        $this->svc->move(new StockMovementInput(
            type:           StockMovementType::GrnReceipt,
            itemId:         $this->item->id,
            fromLocationId: null,
            toLocationId:   $this->location->id,
            quantity:       $qty,
            unitCost:       $unitCost,
        ));
    }

    // Helper: build a material-issue out of $this->location
    private function issue(string $qty): void
    {
        $this->svc->move(new StockMovementInput(
            type:           StockMovementType::MaterialIssue,
            itemId:         $this->item->id,
            fromLocationId: $this->location->id,
            toLocationId:   null,
            quantity:       $qty,
        ));
    }

    // ────────────────────────────────────────────────────────────────────────
    // 1. First receipt into empty stock
    // ────────────────────────────────────────────────────────────────────────

    /**
     * When no StockLevel row exists for the (item, location) pair, the service
     * creates one initialised to qty=0, wac=0, then immediately receipts into
     * it. After one receipt:
     *   new_wac = ((0 * 0) + (qty * cost)) / qty = cost          no divide-by-zero
     */
    public function test_first_receipt_sets_wac_to_unit_cost_no_divide_by_zero(): void
    {
        $this->receipt('100', '10.00');

        $level = StockLevel::where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();

        $this->assertSame('100.000', $level->quantity,          'quantity must be 100.000');
        $this->assertSame('10.0000', $level->weighted_avg_cost, 'WAC must equal unit cost on first receipt');
    }

    // ────────────────────────────────────────────────────────────────────────
    // 2. Second receipt blends correctly
    // ────────────────────────────────────────────────────────────────────────

    /**
     * 100 @ ₱10 then 100 @ ₱12:
     *   new_wac = (100*10 + 100*12) / 200 = 2200 / 200 = 11.0000
     */
    public function test_second_receipt_blends_wac_correctly(): void
    {
        $this->receipt('100', '10.00');
        $this->receipt('100', '12.00');

        $level = StockLevel::where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();

        $this->assertSame('200.000', $level->quantity,          'quantity must be 200.000 after two receipts');
        $this->assertSame('11.0000', $level->weighted_avg_cost, 'WAC must be blended average of 11.0000');
    }

    // ────────────────────────────────────────────────────────────────────────
    // 3. Issue does NOT change WAC
    // ────────────────────────────────────────────────────────────────────────

    /**
     * After establishing WAC=11.0000 with qty=200, issue 50 units.
     * Expected: qty=150.000, WAC stays at 11.0000.
     */
    public function test_issue_decrements_quantity_without_changing_wac(): void
    {
        $this->receipt('100', '10.00');
        $this->receipt('100', '12.00');

        $this->issue('50');

        $level = StockLevel::where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();

        $this->assertSame('150.000', $level->quantity,          'quantity must be 150.000 after issue of 50');
        $this->assertSame('11.0000', $level->weighted_avg_cost, 'WAC must be unchanged by an issue');
    }

    // ────────────────────────────────────────────────────────────────────────
    // 4. Insufficient stock throws InsufficientStockException
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Issuing more than available (qty - reserved) must throw the service's
     * dedicated exception rather than producing a negative stock level.
     */
    public function test_issue_beyond_available_throws_insufficient_stock_exception(): void
    {
        $this->receipt('50', '10.00');   // only 50 units available

        $this->expectException(InsufficientStockException::class);

        $this->issue('100');             // 100 > 50 → must throw
    }

    // ────────────────────────────────────────────────────────────────────────
    // 5. lock_version increments on every write
    // ────────────────────────────────────────────────────────────────────────

    /**
     * The service increments $level->lock_version before every save().
     * Verify it goes up after each movement so the optimistic-lock guard
     * has a meaningful counter to check.
     */
    public function test_lock_version_increments_on_each_movement(): void
    {
        // First movement: StockLevel created at lock_version=0, then incremented → 1
        $this->receipt('100', '10.00');

        $level = StockLevel::where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();

        $this->assertSame(1, $level->lock_version, 'lock_version must be 1 after first receipt');

        // Second movement: same row gets incremented again → 2
        $this->receipt('50', '12.00');
        $level->refresh();

        $this->assertSame(2, $level->lock_version, 'lock_version must be 2 after second receipt');

        // Issue also increments (it touches the from-row) → 3
        $this->issue('30');
        $level->refresh();

        $this->assertSame(3, $level->lock_version, 'lock_version must be 3 after issue');
    }
}
