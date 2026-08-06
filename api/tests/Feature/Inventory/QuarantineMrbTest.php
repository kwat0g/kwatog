<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\MrbStatus;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\MaterialIssueService;
use App\Modules\Inventory\Services\PickingListService;
use App\Modules\Inventory\Services\QuarantineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * REC-08 — Material Review Board hold/quarantine workflow.
 *
 * Covers: physical hold (source → quarantine transfer + MRB row), issue block
 * from quarantine, picking exclusion, rework release (→ good location), scrap
 * release, released-again guard, and the permission gate.
 */
class QuarantineMrbTest extends TestCase
{
    use RefreshDatabase;

    private QuarantineService $svc;
    private Warehouse $warehouse;
    private WarehouseLocation $sourceLoc;      // good (raw_materials)
    private WarehouseLocation $quarantineLoc;  // quarantine zone
    private WarehouseLocation $scrapLoc;       // scrap zone
    private WarehouseLocation $goodTargetLoc;  // finished_goods (release target)
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        // Auto-replenishment listener would try to create a PR for a missing
        // system user — suppress the async side-effect.
        Event::fake([StockMovementCompleted::class]);

        $this->seed(RolePermissionSeeder::class);
        $this->svc = app(QuarantineService::class);

        $this->warehouse = Warehouse::factory()->create();

        $rawZone   = $this->makeZone('raw_materials');
        $quarZone  = $this->makeZone('quarantine');
        $scrapZone = $this->makeZone('scrap');
        $fgZone    = $this->makeZone('finished_goods');

        $this->sourceLoc     = WarehouseLocation::factory()->create(['zone_id' => $rawZone->id, 'is_active' => true]);
        $this->quarantineLoc = WarehouseLocation::factory()->create(['zone_id' => $quarZone->id, 'is_active' => true]);
        $this->scrapLoc      = WarehouseLocation::factory()->create(['zone_id' => $scrapZone->id, 'is_active' => true]);
        $this->goodTargetLoc = WarehouseLocation::factory()->create(['zone_id' => $fgZone->id, 'is_active' => true]);

        $this->item = Item::factory()->create(['is_active' => true]);
    }

    private function makeZone(string $type): WarehouseZone
    {
        return WarehouseZone::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'zone_type'    => $type,
        ]);
    }

    private function stock(int $locationId, string $qty, string $wac = '25.00'): StockLevel
    {
        return StockLevel::create([
            'item_id'           => $this->item->id,
            'location_id'       => $locationId,
            'quantity'          => $qty,
            'reserved_quantity' => '0',
            'weighted_avg_cost' => $wac,
            'lock_version'      => 0,
        ]);
    }

    private function userWith(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'email'   => $roleSlug.'+'.substr(uniqid(), -8).'@t.local',
            'is_active' => true,
        ]);
    }

    // ── 1. hold() moves stock source→quarantine and opens a Held MRB ────────────

    public function test_hold_moves_stock_into_quarantine_and_opens_mrb(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $by = $this->userWith('warehouse_staff');

        $mrb = $this->svc->hold([
            'item_id'                => $this->item->id,
            'quantity'               => '30',
            'source_location_id'     => $this->sourceLoc->id,
            'quarantine_location_id' => $this->quarantineLoc->id,
            'notes'                  => 'Failed incoming moisture check',
        ], $by);

        $this->assertSame(MrbStatus::Held, $mrb->status);
        $this->assertNotNull($mrb->mrb_number);
        $this->assertStringStartsWith('MRB-', $mrb->mrb_number);
        $this->assertNotNull($mrb->hold_movement_id);

        $src  = StockLevel::where('item_id', $this->item->id)->where('location_id', $this->sourceLoc->id)->first();
        $quar = StockLevel::where('item_id', $this->item->id)->where('location_id', $this->quarantineLoc->id)->first();
        $this->assertSame('70.000', (string) $src->quantity);
        $this->assertSame('30.000', (string) $quar->quantity);
    }

    public function test_hold_auto_resolves_quarantine_location_in_same_warehouse(): void
    {
        $this->stock($this->sourceLoc->id, '50');
        $by = $this->userWith('warehouse_staff');

        $mrb = $this->svc->hold([
            'item_id'            => $this->item->id,
            'quantity'           => '10',
            'source_location_id' => $this->sourceLoc->id,
        ], $by);

        $this->assertSame($this->quarantineLoc->id, $mrb->quarantine_location_id);
    }

    // ── 2. issuing from a quarantine location is blocked ────────────────────────

    public function test_issue_from_quarantine_location_is_rejected(): void
    {
        $this->stock($this->quarantineLoc->id, '40');
        $by = $this->userWith('warehouse_staff');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot issue stock from a quarantine location');

        app(MaterialIssueService::class)->create([
            'issued_date' => now()->toDateString(),
            'items'       => [[
                'item_id'         => $this->item->id,
                'location_id'     => $this->quarantineLoc->id,
                'quantity_issued' => '5',
            ]],
        ], $by);
    }

    // ── 3. picking never suggests a quarantine location ─────────────────────────

    public function test_picking_excludes_quarantine_stock(): void
    {
        // The ONLY stock sits in a quarantine location.
        $this->stock($this->quarantineLoc->id, '80');

        $result = app(PickingListService::class)->generateForWorkOrder(1, [[
            'item_id'  => $this->item->id,
            'quantity' => '10',
        ]]);

        $this->assertSame([], $result['lines'][0]['suggestions']);
    }

    // ── 4. release(rework) → good location, status Released ─────────────────────

    public function test_release_rework_moves_stock_to_good_location(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $by = $this->userWith('warehouse_staff');

        $mrb = $this->svc->hold([
            'item_id'                => $this->item->id,
            'quantity'               => '20',
            'source_location_id'     => $this->sourceLoc->id,
            'quarantine_location_id' => $this->quarantineLoc->id,
        ], $by);

        $mrb = $this->svc->release($mrb, 'rework', $by, $this->goodTargetLoc->id, 'reworked to spec');

        $this->assertSame(MrbStatus::Released, $mrb->status);
        $this->assertSame('rework', $mrb->disposition);
        $this->assertNotNull($mrb->release_movement_id);
        $this->assertSame($this->goodTargetLoc->id, $mrb->release_location_id);

        $quar = StockLevel::where('item_id', $this->item->id)->where('location_id', $this->quarantineLoc->id)->first();
        $good = StockLevel::where('item_id', $this->item->id)->where('location_id', $this->goodTargetLoc->id)->first();
        $this->assertSame('0.000', (string) $quar->quantity);
        $this->assertSame('20.000', (string) $good->quantity);
    }

    public function test_release_rework_requires_a_target_location(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $by = $this->userWith('warehouse_staff');
        $mrb = $this->svc->hold([
            'item_id' => $this->item->id, 'quantity' => '20',
            'source_location_id' => $this->sourceLoc->id,
            'quarantine_location_id' => $this->quarantineLoc->id,
        ], $by);

        $this->expectException(RuntimeException::class);
        $this->svc->release($mrb, 'rework', $by, null, null);
    }

    // ── 5. release(scrap) → Scrapped, stock leaves quarantine ───────────────────

    public function test_release_scrap_marks_scrapped_and_moves_out_of_quarantine(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $by = $this->userWith('warehouse_staff');

        $mrb = $this->svc->hold([
            'item_id'                => $this->item->id,
            'quantity'               => '15',
            'source_location_id'     => $this->sourceLoc->id,
            'quarantine_location_id' => $this->quarantineLoc->id,
        ], $by);

        $mrb = $this->svc->release($mrb, 'scrap', $by, null, null);

        $this->assertSame(MrbStatus::Scrapped, $mrb->status);
        $this->assertSame('scrap', $mrb->disposition);
        // F-07: scrap removes from the source (quarantine), does NOT transfer
        // to scrap-zone. release_location_id is the source location.
        $this->assertSame($this->quarantineLoc->id, $mrb->release_location_id);

        $quar  = StockLevel::where('item_id', $this->item->id)->where('location_id', $this->quarantineLoc->id)->first();
        $scrap = StockLevel::where('item_id', $this->item->id)->where('location_id', $this->scrapLoc->id)->first();
        $this->assertSame('0.000', (string) $quar->quantity);
        $this->assertNull($scrap, 'Scrap write-off removes stock from ledger; no stock at scrap zone');
    }

    // ── 6. releasing an already-released MRB throws ─────────────────────────────

    public function test_releasing_an_already_released_mrb_throws(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $by = $this->userWith('warehouse_staff');
        $mrb = $this->svc->hold([
            'item_id' => $this->item->id, 'quantity' => '10',
            'source_location_id' => $this->sourceLoc->id,
            'quarantine_location_id' => $this->quarantineLoc->id,
        ], $by);
        $mrb = $this->svc->release($mrb, 'rework', $by, $this->goodTargetLoc->id, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not held');
        $this->svc->release($mrb->fresh(), 'scrap', $by, null, null);
    }

    // ── 7. permission gate ──────────────────────────────────────────────────────

    public function test_permission_gate_blocks_unauthorized_and_allows_warehouse_staff(): void
    {
        $this->stock($this->sourceLoc->id, '100');

        $payload = [
            'item_id'                => $this->item->hash_id,
            'quantity'               => '5',
            'source_location_id'     => $this->sourceLoc->hash_id,
            'quarantine_location_id' => $this->quarantineLoc->hash_id,
        ];

        // No inventory.mrb.manage → 403.
        $unauth = $this->userWith('maintenance_tech');
        $this->actingAs($unauth)
            ->postJson('/api/v1/inventory/mrb', $payload)
            ->assertForbidden();

        // warehouse_staff → 201.
        $staff = $this->userWith('warehouse_staff');
        $this->actingAs($staff)
            ->postJson('/api/v1/inventory/mrb', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'held');

        $this->assertSame(1, MaterialReviewRecord::query()->count());
    }
}
