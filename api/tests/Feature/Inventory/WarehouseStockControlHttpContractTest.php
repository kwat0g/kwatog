<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Exceptions\InvalidMovementException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * 2026-09-01 re-audit (M040) — the warehouse/stock-control HTTP contract.
 *
 * Every test here exists because nothing in the repository exercised these
 * endpoints over HTTP. Of the module's 32 routes only six had any HTTP-level
 * test; the stock-count lifecycle (9 routes), the transfer-order lifecycle
 * (5 routes) and all three soft-delete restore routes had none. Probing them
 * found three 500s and three dead routes.
 *
 * Covers, in order:
 *   N1 — an ordinary count no longer overflows variance_percent numeric(8,2).
 *   N2 — counted_quantity rejects scientific notation instead of reaching bcmath.
 *   N3 — transfer-order quantity rejects overflow and 4th-decimal truncation.
 *   F07 — a transfer to its own source is refused at create, not at execute.
 *   F03 — a warehouse/zone scope cannot silently widen to every location.
 *   F13 — the three restore routes can bind a soft-deleted record.
 *   F08 — reservations reject non-positive quantities.
 */
class WarehouseStockControlHttpContractTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([StockMovementCompleted::class]);

        $role = Role::firstOrCreate(['slug' => 'warehouse_staff'], ['name' => 'Warehouse Staff']);
        foreach ([
            'inventory.view', 'inventory.adjust', 'inventory.warehouse.manage',
            'inventory.stock_count.view', 'inventory.stock_count.manage',
        ] as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'inventory']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    /** @return array{0:Warehouse,1:WarehouseZone,2:WarehouseLocation,3:WarehouseLocation,4:Item} */
    private function fixture(): array
    {
        $warehouse = Warehouse::factory()->create();
        $zone = WarehouseZone::factory()->create(['warehouse_id' => $warehouse->id]);

        return [
            $warehouse,
            $zone,
            WarehouseLocation::factory()->create(['zone_id' => $zone->id]),
            WarehouseLocation::factory()->create(['zone_id' => $zone->id]),
            Item::factory()->create(['is_active' => true]),
        ];
    }

    private function receipt(Item $item, WarehouseLocation $location, string $qty, string $cost = '10.00'): void
    {
        app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::GrnReceipt,
            itemId: $item->id,
            quantity: $qty,
            fromLocationId: null,
            toLocationId: $location->id,
            unitCost: $cost,
        ));
    }

    private function startedSession(Warehouse $warehouse, WarehouseZone $zone): array
    {
        $created = $this->actingAs($this->user)->postJson('/api/v1/inventory/stock-counts', [
            'title' => 'contract test count',
            'scope' => 'zone',
            'warehouse_id' => $warehouse->hash_id,
            'zone_id' => $zone->hash_id,
        ]);
        $created->assertCreated();
        $sessionId = $created->json('data.id');

        $this->actingAs($this->user)
            ->postJson("/api/v1/inventory/stock-counts/{$sessionId}/start")
            ->assertOk();

        $itemId = $this->actingAs($this->user)
            ->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->json('data.items.0.id');

        return [$sessionId, $itemId];
    }

    // ── N1 ──────────────────────────────────────────────────────────────────

    /**
     * `variance_percent` is numeric(8,2), so it cannot hold a percentage above
     * 999999.99. The percentage used to be computed unbounded in floats, so a
     * system quantity that had drifted to a fraction against a real bulk count
     * produced SQLSTATE[22003] "numeric field overflow" — a 500 on the primary
     * data-entry action of the whole stock-count workflow.
     *
     * 0.500 counted as 6000 is a 1,199,900% variance: it must be stored,
     * saturated at the column ceiling, not rejected and not a 500.
     */
    public function test_an_ordinary_count_does_not_overflow_variance_percent(): void
    {
        [$warehouse, $zone, $location, , $item] = $this->fixture();
        $this->receipt($item, $location, '0.500');
        [, $countItemId] = $this->startedSession($warehouse, $zone);

        $this->actingAs($this->user)
            ->postJson("/api/v1/inventory/stock-counts/items/{$countItemId}/count", [
                'counted_quantity' => '6000',
            ])
            ->assertOk()
            ->assertJsonPath('data.variance', '5999.500');

        $this->assertSame(
            '999999.99',
            (string) DB::table('stock_count_items')->orderByDesc('id')->value('variance_percent'),
            'variance_percent must saturate at the numeric(8,2) ceiling rather than overflow',
        );
    }

    /** An everyday variance is untouched by the saturation and is exact. */
    public function test_an_everyday_variance_percent_is_exact(): void
    {
        [$warehouse, $zone, $location, , $item] = $this->fixture();
        $this->receipt($item, $location, '200');
        [, $countItemId] = $this->startedSession($warehouse, $zone);

        $this->actingAs($this->user)
            ->postJson("/api/v1/inventory/stock-counts/items/{$countItemId}/count", [
                'counted_quantity' => '190',
            ])
            ->assertOk()
            ->assertJsonPath('data.variance', '-10.000')
            ->assertJsonPath('data.variance_percent', '5.00');
    }

    // ── N2 ──────────────────────────────────────────────────────────────────

    /**
     * `counted_quantity` was validated `numeric`, which admits scientific
     * notation. `1e3` then reached bcsub() as a malformed operand (ValueError →
     * 500) and `1e17` overflowed numeric(15,3) as 22003. Both must be 422.
     */
    public function test_counted_quantity_rejects_scientific_notation_and_overflow(): void
    {
        [$warehouse, $zone, $location, , $item] = $this->fixture();
        $this->receipt($item, $location, '100');
        [, $countItemId] = $this->startedSession($warehouse, $zone);

        foreach (['1e3', '1e17', '1e20'] as $quantity) {
            $this->actingAs($this->user)
                ->postJson("/api/v1/inventory/stock-counts/items/{$countItemId}/count", [
                    'counted_quantity' => $quantity,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('counted_quantity');
        }

        // A legitimate 3-decimal count still stores byte-for-byte.
        $this->actingAs($this->user)
            ->postJson("/api/v1/inventory/stock-counts/items/{$countItemId}/count", [
                'counted_quantity' => '1.999',
            ])
            ->assertOk()
            ->assertJsonPath('data.counted_quantity', '1.999');
    }

    // ── N3 ──────────────────────────────────────────────────────────────────

    /**
     * `transfer_orders.quantity` is numeric(15,3). Validated `numeric`, the
     * endpoint answered 500 (22003) for 1e17/1e20 and silently truncated
     * `10.00005` to `10.000` — a phantom quantity accepted as if exact.
     */
    public function test_transfer_order_quantity_rejects_overflow_and_silent_truncation(): void
    {
        [, , $from, $to, $item] = $this->fixture();
        $this->receipt($item, $from, '1000');

        foreach (['1e3', '1e17', '1e20', '10.00005'] as $quantity) {
            $this->actingAs($this->user)
                ->postJson('/api/v1/inventory/transfer-orders', [
                    'from_location_id' => $from->hash_id,
                    'to_location_id' => $to->hash_id,
                    'item_id' => $item->hash_id,
                    'quantity' => $quantity,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('quantity');
        }

        $this->assertSame(0, DB::table('transfer_orders')->count());

        // Three decimals is the column's precision and must survive exactly.
        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/transfer-orders', [
                'from_location_id' => $from->hash_id,
                'to_location_id' => $to->hash_id,
                'item_id' => $item->hash_id,
                'quantity' => '1.999',
            ])
            ->assertCreated()
            ->assertJsonPath('data.quantity', '1.999');
    }

    // ── F07 ─────────────────────────────────────────────────────────────────

    /**
     * The movement boundary refuses a transfer whose source and destination
     * match, so such an order could be created (201) but never executed (422) —
     * a permanently stuck row. Refuse it where it is entered.
     */
    public function test_a_transfer_order_to_its_own_source_is_refused_at_create(): void
    {
        [, , $from, , $item] = $this->fixture();
        $this->receipt($item, $from, '100');

        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/transfer-orders', [
                'from_location_id' => $from->hash_id,
                'to_location_id' => $from->hash_id,
                'item_id' => $item->hash_id,
                'quantity' => '10',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_location_id');

        $this->assertSame(0, DB::table('transfer_orders')->count());
    }

    // ── F03 ─────────────────────────────────────────────────────────────────

    /**
     * `createSession()` applies its location predicate only when the scope's id
     * is present, so a `warehouse` or `zone` scope with a null id fell through
     * to EVERY active location — a silently company-wide count that also froze
     * every location it covered against all stock movement.
     */
    public function test_a_scoped_count_cannot_silently_widen_to_every_location(): void
    {
        [$warehouse, $zone, $location, , $item] = $this->fixture();
        $this->receipt($item, $location, '100');

        // A second warehouse whose stock must never be pulled into the count.
        $other = Warehouse::factory()->create();
        $otherZone = WarehouseZone::factory()->create(['warehouse_id' => $other->id]);
        $otherLocation = WarehouseLocation::factory()->create(['zone_id' => $otherZone->id]);
        $this->receipt($item, $otherLocation, '100');

        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/stock-counts', ['title' => 'widen', 'scope' => 'warehouse'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('warehouse_id');

        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/stock-counts', ['title' => 'widen', 'scope' => 'zone'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('zone_id');

        // A zone from another warehouse is not countable under this one.
        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/stock-counts', [
                'title' => 'cross warehouse',
                'scope' => 'zone',
                'warehouse_id' => $warehouse->hash_id,
                'zone_id' => $otherZone->hash_id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('zone_id');

        $this->assertSame(0, DB::table('stock_count_sessions')->count());

        // The correctly scoped count still covers exactly its own zone.
        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/stock-counts', [
                'title' => 'scoped',
                'scope' => 'zone',
                'warehouse_id' => $warehouse->hash_id,
                'zone_id' => $zone->hash_id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_locations', 1);
    }

    // ── F13 ─────────────────────────────────────────────────────────────────

    /**
     * All three restore routes used ordinary implicit binding, which excludes
     * trashed rows — so restore 404'd for every valid target and a soft-deleted
     * warehouse, zone or location could never be brought back.
     */
    public function test_restore_routes_bind_soft_deleted_records(): void
    {
        [$warehouse, $zone, $location] = $this->fixture();
        $warehouse->delete();
        $zone->delete();
        $location->delete();

        $this->actingAs($this->user)
            ->patchJson("/api/v1/inventory/warehouses/{$warehouse->hash_id}/restore")
            ->assertOk();
        $this->actingAs($this->user)
            ->patchJson("/api/v1/inventory/zones/{$zone->hash_id}/restore")
            ->assertOk();
        $this->actingAs($this->user)
            ->patchJson("/api/v1/inventory/locations/{$location->hash_id}/restore")
            ->assertOk();

        $this->assertNull($warehouse->fresh()->deleted_at);
        $this->assertNull($zone->fresh()->deleted_at);
        $this->assertNull($location->fresh()->deleted_at);
    }

    // ── F08 ─────────────────────────────────────────────────────────────────

    /**
     * Reservation quantities are magnitudes. A negative reserve passed the
     * availability check (any negative is below available) and drove
     * reserved_quantity below zero; a negative release pushed it above on-hand,
     * making the location unissuable. Neither changes physical stock, so both
     * corrupted availability with no ledger trace.
     */
    public function test_reservations_reject_non_positive_quantities(): void
    {
        [, , $location, , $item] = $this->fixture();
        $this->receipt($item, $location, '100');

        $movements = app(StockMovementService::class);
        $movements->reserve($item->id, $location->id, '10');

        foreach (['-50', '0'] as $quantity) {
            try {
                $movements->reserve($item->id, $location->id, $quantity);
                $this->fail("reserve({$quantity}) must be refused");
            } catch (InvalidMovementException) {
                // expected
            }
            try {
                $movements->release($item->id, $location->id, $quantity);
                $this->fail("release({$quantity}) must be refused");
            } catch (InvalidMovementException) {
                // expected
            }
        }

        $level = StockLevel::where('item_id', $item->id)
            ->where('location_id', $location->id)
            ->firstOrFail();

        $this->assertSame('10.000', $level->reserved_quantity, 'the refused calls must leave the reservation intact');
        $this->assertSame('100.000', $level->quantity);
        $this->assertLessThanOrEqual(
            0,
            bccomp((string) $level->reserved_quantity, (string) $level->quantity, 3),
            'reserved_quantity must never exceed on-hand',
        );
    }
}
