<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidMovementException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\MaterialIssueService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M042 (material issues & reservations) — the reservation invariants that were
 * MEASURED to hold on 2026-09-01, pinned so they cannot silently regress.
 *
 * IMPORTANT, so nobody mistakes green here for a healthy module: every
 * assertion in this file passed on its FIRST run against unmodified source.
 * These are forward regression guards, NOT evidence that any audit finding was
 * fixed. The module's P0 defects (a reservation cannot be drawn against; a
 * partial issue orphans the remainder; issuing against one reservation destroys
 * another work order's; a spent reservation is replayable) are deliberately
 * NOT encoded here — asserting broken behaviour green is how a dead subsystem
 * stays invisible. They are documented with measurements in
 * audit/domains/inventory/material-issues-reservations/audit-report.md and
 * ordered in action-plan.md item 1.
 */
class MaterialIssueReservationInvariantTest extends TestCase
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
        $this->item = Item::factory()->create(['name' => 'Reservation Invariant Material']);
        $this->location = WarehouseLocation::factory()->create(['code' => 'RSV-'.substr(uniqid(), -5)]);

        StockLevel::create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => '100.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '25.0000',
        ]);
    }

    private function level(): StockLevel
    {
        return StockLevel::where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();
    }

    private function issue(string $quantity): void
    {
        $this->issues->create([
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->id,
                'location_id' => $this->location->id,
                'quantity_issued' => $quantity,
            ]],
        ], $this->user);
    }

    /**
     * `available = on_hand - reserved`, checked with raw SQL rather than through
     * the model, so an accessor cannot make a wrong stored value look right.
     */
    public function test_available_equals_on_hand_minus_reserved_in_raw_sql(): void
    {
        $this->movements->reserve($this->item->id, $this->location->id, '30');
        $this->issue('10');

        $row = DB::selectOne(
            'select quantity, reserved_quantity, (quantity - reserved_quantity) as available
             from stock_levels where item_id = ? and location_id = ?',
            [$this->item->id, $this->location->id]
        );

        $this->assertSame('90.000', (string) $row->quantity);
        $this->assertSame('30.000', (string) $row->reserved_quantity);
        $this->assertSame('60.000', (string) $row->available);
    }

    public function test_reserved_can_never_exceed_on_hand(): void
    {
        $this->expectException(InsufficientStockException::class);
        $this->movements->reserve($this->item->id, $this->location->id, '101');
    }

    public function test_cumulative_reservations_cannot_exceed_on_hand(): void
    {
        $this->movements->reserve($this->item->id, $this->location->id, '60');

        try {
            $this->movements->reserve($this->item->id, $this->location->id, '60');
            $this->fail('Two reservations totalling more than on-hand must not both be accepted.');
        } catch (InsufficientStockException) {
            // The first reservation must survive the refusal untouched.
            $this->assertSame('60.000', (string) $this->level()->reserved_quantity);
        }
    }

    /**
     * The promise a reservation makes to everyone ELSE: an unrelated issue
     * cannot consume stock that is spoken for.
     */
    public function test_stock_cannot_be_issued_out_from_under_a_reservation(): void
    {
        $this->movements->reserve($this->item->id, $this->location->id, '100');

        try {
            $this->issue('10');
            $this->fail('An unrelated issue must not draw reserved stock.');
        } catch (InsufficientStockException) {
            $this->assertSame('100.000', (string) $this->level()->quantity);
        }
    }

    /** Same rule for a write-off: an adjustment cannot push on-hand below reserved. */
    public function test_adjustment_cannot_take_stock_below_an_existing_reservation(): void
    {
        $this->movements->reserve($this->item->id, $this->location->id, '100');

        try {
            $this->movements->move(new StockMovementInput(
                type: StockMovementType::AdjustmentOut,
                itemId: $this->item->id,
                fromLocationId: $this->location->id,
                toLocationId: null,
                quantity: '60',
                unitCost: '25',
                referenceType: null,
                referenceId: null,
                remarks: 'invariant probe',
                createdBy: $this->user->id,
            ));
            $this->fail('An adjustment must not push on-hand below reserved quantity.');
        } catch (InsufficientStockException) {
            $level = $this->level();
            $this->assertSame('100.000', (string) $level->quantity);
            $this->assertLessThanOrEqual(
                0,
                bccomp((string) $level->reserved_quantity, (string) $level->quantity, 3),
                'reserved must never exceed on_hand',
            );
        }
    }

    /**
     * Reservation quantities are magnitudes. A signed delta smuggled into any of
     * the three call sites previously corrupted `reserved_quantity` in both
     * directions.
     */
    public function test_negative_quantities_are_refused_at_reserve_release_and_issue(): void
    {
        foreach (['reserve', 'release'] as $operation) {
            try {
                $this->movements->{$operation}($this->item->id, $this->location->id, '-50');
                $this->fail("{$operation}() must refuse a negative quantity.");
            } catch (InvalidMovementException) {
                // expected
            }
        }

        try {
            $this->issue('-50');
            $this->fail('An issue must refuse a negative quantity.');
        } catch (InvalidMovementException) {
            // expected
        }

        $level = $this->level();
        $this->assertSame('0.000', (string) $level->reserved_quantity);
        $this->assertSame('100.000', (string) $level->quantity);
    }

    public function test_release_floors_at_zero_and_is_idempotent(): void
    {
        $this->movements->reserve($this->item->id, $this->location->id, '20');

        $this->movements->release($this->item->id, $this->location->id, '999');
        $this->assertSame('0.000', (string) $this->level()->reserved_quantity);

        $this->movements->release($this->item->id, $this->location->id, '20');
        $this->assertSame('0.000', (string) $this->level()->reserved_quantity);
    }

    /**
     * The issued-quantity rule is `decimal:0,3` + `min:0.001`, not the
     * `numeric|min:0` shape that silently truncated or overflowed in nine other
     * modules. Pinned because that rule is the reason this surface is clean.
     */
    public function test_issued_quantity_rule_rejects_overflow_exponent_and_sub_precision_values(): void
    {
        $warehouse = User::factory()->create([
            'role_id' => Role::where('slug', 'warehouse_staff')->value('id'),
        ]);

        foreach (['1e3', '1e17', '1e20', '10.00005', '0', '-5', '0.0001', ['a' => 1]] as $value) {
            $response = $this->actingAs($warehouse)->postJson('/api/v1/inventory/material-issues', [
                'issued_date' => now()->toDateString(),
                'items' => [[
                    'item_id' => $this->item->hash_id,
                    'location_id' => $this->location->hash_id,
                    'quantity_issued' => $value,
                ]],
            ]);

            $response->assertStatus(422);
        }

        // A legitimate three-decimal quantity stores exactly, with no rounding.
        $ok = $this->actingAs($warehouse)->postJson('/api/v1/inventory/material-issues', [
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->hash_id,
                'location_id' => $this->location->hash_id,
                'quantity_issued' => '1.999',
            ]],
        ]);
        $ok->assertCreated();
        $this->assertSame('1.999', (string) DB::table('material_issue_slip_items')
            ->orderByDesc('id')->value('quantity_issued'));
    }

    /**
     * Both roles the registry assigns to this module must be able to complete
     * their part, and a user with no inventory permission must be refused on
     * every endpoint including the list.
     */
    public function test_registry_roles_can_complete_their_part_and_others_cannot(): void
    {
        $slip = $this->issues->create([
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->id,
                'location_id' => $this->location->id,
                'quantity_issued' => '5.000',
            ]],
        ], $this->user);

        foreach (['warehouse_staff', 'system_admin'] as $slug) {
            $actor = User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id')]);

            $this->actingAs($actor)->getJson('/api/v1/inventory/material-issues')->assertOk();
            $this->actingAs($actor)
                ->getJson('/api/v1/inventory/material-issues/'.$slip->hash_id)->assertOk();
            $this->actingAs($actor)->postJson('/api/v1/inventory/material-issues', [
                'issued_date' => now()->toDateString(),
                'items' => [[
                    'item_id' => $this->item->hash_id,
                    'location_id' => $this->location->hash_id,
                    'quantity_issued' => '1.000',
                ]],
            ])->assertCreated();
        }

        $employee = User::factory()->create(['role_id' => Role::where('slug', 'employee')->value('id')]);
        $this->actingAs($employee)->getJson('/api/v1/inventory/material-issues')->assertForbidden();
        $this->actingAs($employee)
            ->getJson('/api/v1/inventory/material-issues/'.$slip->hash_id)->assertForbidden();
        $this->actingAs($employee)->postJson('/api/v1/inventory/material-issues', [
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->hash_id,
                'location_id' => $this->location->hash_id,
                'quantity_issued' => '1.000',
            ]],
        ])->assertForbidden();
    }

    /**
     * Cancelling an issued slip must put back exactly the value it removed, so
     * the reversal is WAC-neutral even when receipts landed in between.
     */
    public function test_cancelling_an_issue_is_weighted_average_cost_neutral(): void
    {
        $slip = $this->issues->create([
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->id,
                'location_id' => $this->location->id,
                'quantity_issued' => '10.000',
            ]],
        ], $this->user);

        // A receipt at a different cost moves WAC while the issue is outstanding.
        $this->movements->move(new StockMovementInput(
            type: StockMovementType::GrnReceipt,
            itemId: $this->item->id,
            fromLocationId: null,
            toLocationId: $this->location->id,
            quantity: '10',
            unitCost: '100',
            referenceType: null,
            referenceId: null,
            remarks: 'invariant probe receipt',
            createdBy: $this->user->id,
        ));
        $this->assertSame('32.5000', (string) $this->level()->weighted_avg_cost);

        $this->issues->cancel($slip, $this->user);

        // (100 * 32.50 + 10 * 25.00) / 110 = 31.8182
        $level = $this->level();
        $this->assertSame('110.000', (string) $level->quantity);
        $this->assertSame('31.8182', (string) $level->weighted_avg_cost);

        // And it cannot be returned to stock twice.
        $this->expectException(BusinessRuleException::class);
        $this->issues->cancel($slip, $this->user);
    }

    /**
     * A reservation is a magnitude held in `stock_levels.reserved_quantity`, and
     * that scalar must agree with the rows that justify it. Pinned at the one
     * point it is currently known to agree — a single fully-released
     * reservation — so a future change cannot quietly widen the divergence
     * documented as M042-N02a/b/c.
     */
    public function test_a_fully_released_reservation_leaves_the_ledger_and_the_table_agreeing(): void
    {
        $this->movements->reserve($this->item->id, $this->location->id, '40');
        $reservation = MaterialReservation::create([
            'item_id' => $this->item->id,
            'work_order_id' => null,
            'location_id' => $this->location->id,
            'quantity' => '40',
            'status' => ReservationStatus::Reserved,
            'reserved_at' => now(),
        ]);

        $this->assertSame('40.000', (string) $this->level()->reserved_quantity);

        $this->movements->release($this->item->id, $this->location->id, '40');
        $reservation->fill(['released_at' => now()]);
        $reservation->status = ReservationStatus::Released;
        $reservation->save();

        $activeSum = (string) MaterialReservation::query()
            ->where('status', ReservationStatus::Reserved)
            ->sum('quantity');

        $this->assertSame('0.000', (string) $this->level()->reserved_quantity);
        $this->assertSame('0', $activeSum);
    }
}
