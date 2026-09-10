<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\MaterialIssueService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IN-01 — the reservation-linked manual issue path. Every guard the audit
 * found absent (release-then-move ordering, ownership, status, partial-issue
 * remainder) is pinned here; before the fix each of these scenarios either
 * threw InsufficientStockException on its own happy path or silently
 * corrupted reserved_quantity.
 */
class MaterialIssueReservationLinkTest extends TestCase
{
    use RefreshDatabase;

    private MaterialIssueService $issues;

    private StockMovementService $movements;

    private User $user;

    private Item $item;

    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->issues = app(MaterialIssueService::class);
        $this->movements = app(StockMovementService::class);
        $this->user = User::factory()->create();
        $this->item = Item::factory()->create(['name' => 'Reservation Link Material']);
        $this->location = WarehouseLocation::factory()->create(['code' => 'RSV-L-'.substr(uniqid(), -5)]);

        StockLevel::create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => '100.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '25.0000',
        ]);
    }

    private function level(Item $item, WarehouseLocation $location): StockLevel
    {
        return StockLevel::where('item_id', $item->id)
            ->where('location_id', $location->id)
            ->firstOrFail();
    }

    private function reserve(Item $item, WarehouseLocation $location, string $quantity, ?int $workOrderId = null): MaterialReservation
    {
        $this->movements->reserve($item->id, $location->id, $quantity);

        return MaterialReservation::create([
            'item_id' => $item->id,
            'work_order_id' => $workOrderId,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'status' => ReservationStatus::Reserved,
            'reserved_at' => now(),
        ]);
    }

    private function issueAgainst(MaterialReservation $reservation, string $quantity, ?int $workOrderId = null): MaterialIssueSlip
    {
        return $this->issues->create([
            'work_order_id' => $workOrderId,
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $reservation->item_id,
                'location_id' => $reservation->location_id,
                'quantity_issued' => $quantity,
                'material_reservation_id' => $reservation->id,
            ]],
        ], $this->user);
    }

    /**
     * The happy path the old ordering could never reach: stock fully spoken
     * for by the very reservation being drawn against. Release must happen
     * before the movement or move()'s availability check refuses.
     */
    public function test_fully_reserved_stock_is_issuable_against_its_reservation(): void
    {
        $wo = WorkOrder::factory()->create();
        $reservation = $this->reserve($this->item, $this->location, '100', $wo->id);

        $slip = $this->issueAgainst($reservation, '100', $wo->id);

        $level = $this->level($this->item, $this->location);
        $this->assertSame('0.000', (string) $level->quantity);
        $this->assertSame('0.000', (string) $level->reserved_quantity);

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Issued, $reservation->status);
        $this->assertNotNull($reservation->released_at);

        $this->assertDatabaseHas('material_issue_slip_items', [
            'material_issue_slip_id' => $slip->id,
            'material_reservation_id' => $reservation->id,
            'quantity_issued' => '100.000',
        ]);
    }

    /**
     * A partial issue must release exactly the issued quantity and keep the
     * remainder Reserved — decrementing the reservation row so the sum of
     * live reservations still agrees with reserved_quantity, and the rest
     * stays drawable instead of leaking as a permanently locked block.
     */
    public function test_partial_issue_keeps_the_remainder_reserved_and_bookable(): void
    {
        $reservation = $this->reserve($this->item, $this->location, '40');

        $this->issueAgainst($reservation, '15');

        $level = $this->level($this->item, $this->location);
        $this->assertSame('85.000', (string) $level->quantity);
        $this->assertSame('25.000', (string) $level->reserved_quantity);

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Reserved, $reservation->status);
        $this->assertSame('25.000', (string) $reservation->quantity);
        $this->assertNull($reservation->released_at);

        $activeSum = (string) MaterialReservation::query()
            ->where('status', ReservationStatus::Reserved)
            ->sum('quantity');
        $this->assertSame('25.000', $activeSum, 'live reservation rows must equal reserved_quantity');

        // The remainder is still drawable: finish the reservation in a second slip.
        $this->issueAgainst($reservation, '25');

        $level = $this->level($this->item, $this->location);
        $this->assertSame('60.000', (string) $level->quantity);
        $this->assertSame('0.000', (string) $level->reserved_quantity);
        $this->assertSame(ReservationStatus::Issued, $reservation->fresh()->status);
    }

    public function test_a_reservation_for_another_item_is_refused(): void
    {
        $other = Item::factory()->create(['name' => 'Reservation Link Other Item']);
        StockLevel::create([
            'item_id' => $other->id,
            'location_id' => $this->location->id,
            'quantity' => '100.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '25.0000',
        ]);
        $reservation = $this->reserve($other, $this->location, '40');

        try {
            $this->issues->create([
                'issued_date' => now()->toDateString(),
                'items' => [[
                    'item_id' => $this->item->id,
                    'location_id' => $this->location->id,
                    'quantity_issued' => '10',
                    'material_reservation_id' => $reservation->id,
                ]],
            ], $this->user);
            $this->fail('A reservation for another item must not be consumable by this line.');
        } catch (BusinessRuleException) {
            // expected
        }

        $this->assertSame('100.000', (string) $this->level($this->item, $this->location)->quantity);
        $this->assertSame('100.000', (string) $this->level($other, $this->location)->quantity);
        $this->assertSame('40.000', (string) $this->level($other, $this->location)->reserved_quantity);
        $this->assertSame(ReservationStatus::Reserved, $reservation->fresh()->status);
        $this->assertDatabaseCount('material_issue_slips', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_a_reservation_for_another_location_is_refused(): void
    {
        $otherLocation = WarehouseLocation::factory()->create(['code' => 'RSV-L-'.substr(uniqid(), -5)]);
        StockLevel::create([
            'item_id' => $this->item->id,
            'location_id' => $otherLocation->id,
            'quantity' => '100.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '25.0000',
        ]);
        $reservation = $this->reserve($this->item, $otherLocation, '40');

        try {
            $this->issues->create([
                'issued_date' => now()->toDateString(),
                'items' => [[
                    'item_id' => $this->item->id,
                    'location_id' => $this->location->id,
                    'quantity_issued' => '10',
                    'material_reservation_id' => $reservation->id,
                ]],
            ], $this->user);
            $this->fail('A reservation for another location must not be consumable by this line.');
        } catch (BusinessRuleException) {
            // expected
        }

        $this->assertSame('100.000', (string) $this->level($this->item, $this->location)->quantity);
        $this->assertSame('0.000', (string) $this->level($this->item, $this->location)->reserved_quantity);
        $this->assertSame('100.000', (string) $this->level($this->item, $otherLocation)->quantity);
        $this->assertSame('40.000', (string) $this->level($this->item, $otherLocation)->reserved_quantity);
        $this->assertSame(ReservationStatus::Reserved, $reservation->fresh()->status);
        $this->assertDatabaseCount('material_issue_slips', 0);
    }

    public function test_a_reservation_for_another_work_order_is_refused(): void
    {
        $owningWo = WorkOrder::factory()->create();
        $otherWo = WorkOrder::factory()->create();
        $reservation = $this->reserve($this->item, $this->location, '40', $owningWo->id);

        foreach ([$otherWo->id, null] as $slipWorkOrderId) {
            try {
                $this->issueAgainst($reservation, '10', $slipWorkOrderId);
                $this->fail('A reservation must only be consumable by a slip for its own work order.');
            } catch (BusinessRuleException) {
                // expected
            }
        }

        $level = $this->level($this->item, $this->location);
        $this->assertSame('100.000', (string) $level->quantity);
        $this->assertSame('40.000', (string) $level->reserved_quantity);
        $this->assertSame(ReservationStatus::Reserved, $reservation->fresh()->status);
        $this->assertDatabaseCount('material_issue_slips', 0);
    }

    /**
     * Without the status guard the same reservation was replayable: the
     * second release silently ate other reservations' reserved quantity
     * (floored at zero — IN-08).
     */
    public function test_a_spent_reservation_cannot_be_presented_twice(): void
    {
        $reservation = $this->reserve($this->item, $this->location, '40');
        $this->issueAgainst($reservation, '40');

        try {
            $this->issueAgainst($reservation, '40');
            $this->fail('An already-issued reservation must not be consumable a second time.');
        } catch (BusinessRuleException) {
            // expected
        }

        $level = $this->level($this->item, $this->location);
        $this->assertSame('60.000', (string) $level->quantity);
        $this->assertSame('0.000', (string) $level->reserved_quantity);
        $this->assertSame(ReservationStatus::Issued, $reservation->fresh()->status);
        $this->assertSame(1, DB::table('stock_movements')
            ->where('movement_type', 'material_issue')
            ->count());
    }

    public function test_issuing_more_than_the_reservation_quantity_is_refused(): void
    {
        $reservation = $this->reserve($this->item, $this->location, '40');

        try {
            $this->issueAgainst($reservation, '50');
            $this->fail('An issue larger than its reservation must be refused.');
        } catch (BusinessRuleException) {
            // expected
        }

        $level = $this->level($this->item, $this->location);
        $this->assertSame('100.000', (string) $level->quantity);
        $this->assertSame('40.000', (string) $level->reserved_quantity);
        $this->assertSame('40.000', (string) $reservation->fresh()->quantity);
        $this->assertSame(ReservationStatus::Reserved, $reservation->fresh()->status);
        $this->assertDatabaseCount('material_issue_slips', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    /**
     * The per-line over-issue guard, aggregated: two lines drawing 30 each
     * against a 40 reservation — the first is within the reservation, the
     * second exceeds the remainder, so the whole slip is refused.
     */
    public function test_two_lines_cannot_overdraw_one_reservation(): void
    {
        $reservation = $this->reserve($this->item, $this->location, '40');

        try {
            $this->issues->create([
                'issued_date' => now()->toDateString(),
                'items' => [
                    [
                        'item_id' => $this->item->id,
                        'location_id' => $this->location->id,
                        'quantity_issued' => '30',
                        'material_reservation_id' => $reservation->id,
                    ],
                    [
                        'item_id' => $this->item->id,
                        'location_id' => $this->location->id,
                        'quantity_issued' => '30',
                        'material_reservation_id' => $reservation->id,
                    ],
                ],
            ], $this->user);
            $this->fail('Two lines must not overdraw one reservation.');
        } catch (BusinessRuleException) {
            // expected
        }

        $level = $this->level($this->item, $this->location);
        $this->assertSame('100.000', (string) $level->quantity);
        $this->assertSame('40.000', (string) $level->reserved_quantity);
        $this->assertDatabaseCount('material_issue_slips', 0);
    }

    /**
     * The same reservation funding several lines of one slip is fine as long
     * as the total stays within its quantity — the second line re-reads the
     * decremented remainder, so nothing is double-released.
     */
    public function test_a_reservation_can_fund_several_lines_up_to_its_quantity(): void
    {
        $reservation = $this->reserve($this->item, $this->location, '40');

        $this->issues->create([
            'issued_date' => now()->toDateString(),
            'items' => [
                [
                    'item_id' => $this->item->id,
                    'location_id' => $this->location->id,
                    'quantity_issued' => '15',
                    'material_reservation_id' => $reservation->id,
                ],
                [
                    'item_id' => $this->item->id,
                    'location_id' => $this->location->id,
                    'quantity_issued' => '25',
                    'material_reservation_id' => $reservation->id,
                ],
            ],
        ], $this->user);

        $level = $this->level($this->item, $this->location);
        $this->assertSame('60.000', (string) $level->quantity);
        $this->assertSame('0.000', (string) $level->reserved_quantity);
        $this->assertSame(ReservationStatus::Issued, $reservation->fresh()->status);
    }
}
