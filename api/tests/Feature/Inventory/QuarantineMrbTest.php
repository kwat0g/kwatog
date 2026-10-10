<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\Inventory\Enums\MrbStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialIssueSlipItem;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\MaterialIssueService;
use App\Modules\Inventory\Services\PickingListService;
use App\Modules\Inventory\Services\QuarantineService;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use Database\Seeders\ChartOfAccountsSeeder;
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

        $this->seed([RolePermissionSeeder::class, ChartOfAccountsSeeder::class]);
        app(SettingsService::class)->set('modules.accounting', true, 'modules');
        $this->svc = app(QuarantineService::class);

        $this->warehouse = Warehouse::factory()->create();

        $rawZone = $this->makeZone('raw_materials');
        $quarZone = $this->makeZone('quarantine');
        $scrapZone = $this->makeZone('scrap');
        $fgZone = $this->makeZone('finished_goods');

        $this->sourceLoc = WarehouseLocation::factory()->create(['zone_id' => $rawZone->id, 'is_active' => true]);
        $this->quarantineLoc = WarehouseLocation::factory()->create(['zone_id' => $quarZone->id, 'is_active' => true]);
        $this->scrapLoc = WarehouseLocation::factory()->create(['zone_id' => $scrapZone->id, 'is_active' => true]);
        $this->goodTargetLoc = WarehouseLocation::factory()->create(['zone_id' => $fgZone->id, 'is_active' => true]);

        $this->item = Item::factory()->create(['is_active' => true]);
    }

    private function makeZone(string $type): WarehouseZone
    {
        return WarehouseZone::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'zone_type' => $type,
        ]);
    }

    private function stock(int $locationId, string $qty, string $wac = '25.00'): StockLevel
    {
        return StockLevel::create([
            'item_id' => $this->item->id,
            'location_id' => $locationId,
            'quantity' => $qty,
            'reserved_quantity' => '0',
            'weighted_avg_cost' => $wac,
            'lock_version' => 0,
        ]);
    }

    private function userWith(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'email' => $roleSlug.'+'.substr(uniqid(), -8).'@t.local',
            'is_active' => true,
        ]);
    }

    // ── 1. hold() moves stock source→quarantine and opens a Held MRB ────────────

    public function test_hold_moves_stock_into_quarantine_and_opens_mrb(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $by = $this->userWith('warehouse_staff');

        $mrb = $this->svc->hold([
            'item_id' => $this->item->id,
            'quantity' => '30',
            'source_location_id' => $this->sourceLoc->id,
            'quarantine_location_id' => $this->quarantineLoc->id,
            'notes' => 'Failed incoming moisture check',
        ], $by);

        $this->assertSame(MrbStatus::Held, $mrb->status);
        $this->assertNotNull($mrb->mrb_number);
        $this->assertStringStartsWith('MRB-', $mrb->mrb_number);
        $this->assertNotNull($mrb->hold_movement_id);

        $src = StockLevel::where('item_id', $this->item->id)->where('location_id', $this->sourceLoc->id)->first();
        $quar = StockLevel::where('item_id', $this->item->id)->where('location_id', $this->quarantineLoc->id)->first();
        $this->assertSame('70.000', (string) $src->quantity);
        $this->assertSame('30.000', (string) $quar->quantity);
    }

    public function test_hold_auto_resolves_quarantine_location_in_same_warehouse(): void
    {
        $this->stock($this->sourceLoc->id, '50');
        $by = $this->userWith('warehouse_staff');

        $mrb = $this->svc->hold([
            'item_id' => $this->item->id,
            'quantity' => '10',
            'source_location_id' => $this->sourceLoc->id,
        ], $by);

        $this->assertSame($this->quarantineLoc->id, $mrb->quarantine_location_id);
    }

    public function test_public_hold_selects_suspect_lot_and_replay_cannot_switch_lots(): void
    {
        $by = $this->userWith('warehouse_staff');
        $expiry = now()->addMonth()->toDateString();
        foreach (['A', 'B'] as $lot) {
            app(StockMovementService::class)->move(
                new StockMovementInput(
                    type: StockMovementType::AdjustmentIn,
                    itemId: $this->item->id, toLocationId: $this->sourceLoc->id,
                    quantity: '5.000', unitCost: '25.00', lotNumber: $lot, expiryDate: $expiry,
                )
            );
        }
        $payload = ['item_id' => $this->item->hash_id, 'source_location_id' => $this->sourceLoc->hash_id,
            'quantity' => '2.000', 'lot_number' => 'B', 'expiry_date' => '1990-01-01'];
        $this->actingAs($by)->postJson('/api/v1/inventory/mrb', array_diff_key($payload, ['lot_number' => true]))
            ->assertUnprocessable();
        $response = $this->withHeader('Idempotency-Key', 'suspect-lot-test')
            ->postJson('/api/v1/inventory/mrb', $payload)->assertCreated();
        $mrb = MaterialReviewRecord::sole();
        $this->assertSame('B', $mrb->holdMovement->lot_number);
        $this->assertSame($expiry, $mrb->holdMovement->expiry_date->toDateString());
        $lots = collect(app(StockLocationSummaryService::class)->lots($this->item->id, $this->sourceLoc->id))->keyBy('lot_number');
        $this->assertSame('5.000', $lots['A']['net']);
        $this->assertSame('3.000', $lots['B']['net']);
        $this->postJson('/api/v1/inventory/mrb', $payload)->assertCreated();
        $this->postJson('/api/v1/inventory/mrb', array_replace($payload, ['lot_number' => 'A']))->assertUnprocessable();
        $this->assertSame(1, MaterialReviewRecord::count());
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
            'items' => [[
                'item_id' => $this->item->id,
                'location_id' => $this->quarantineLoc->id,
                'quantity_issued' => '5',
            ]],
        ], $by);
    }

    // ── 3. picking never suggests a quarantine location ─────────────────────────

    public function test_picking_excludes_quarantine_stock(): void
    {
        // Quarantine and inactive bins are never suggested; active good stock is.
        $this->stock($this->quarantineLoc->id, '80');
        $this->stock($this->sourceLoc->id, '20');
        $inactive = WarehouseLocation::factory()->create(['is_active' => false]);
        $this->stock($inactive->id, '40');
        $actor = $this->userWith('warehouse_staff');
        $slip = MaterialIssueSlip::create([
            'slip_number' => 'MIS-PICK-'.substr(uniqid(), -5),
            'issued_date' => now()->toDateString(),
            'issued_by' => $actor->id,
            'created_by' => $actor->id,
            'status' => 'issued',
            'total_value' => '0.00',
        ]);
        MaterialIssueSlipItem::create([
            'material_issue_slip_id' => $slip->id,
            'item_id' => $this->item->id,
            'location_id' => $this->quarantineLoc->id,
            'quantity_issued' => '10.000',
            'unit_cost' => '1.0000',
            'total_cost' => '10.00',
        ]);

        $result = app(PickingListService::class)->generateForMis($slip->id);

        $this->assertSame([$this->sourceLoc->id], array_column(
            array_column($result['lines'][0]['suggestions'], 'location'),
            'id',
        ));
    }

    // ── 4. release(rework) → good location, status Released ─────────────────────

    public function test_release_rework_moves_stock_to_good_location(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $by = $this->userWith('warehouse_staff');

        $mrb = $this->svc->hold([
            'item_id' => $this->item->id,
            'quantity' => '20',
            'source_location_id' => $this->sourceLoc->id,
            'quarantine_location_id' => $this->quarantineLoc->id,
        ], $by);

        $checker = $this->userWith('warehouse_staff');
        $mrb = $this->svc->release($mrb, 'rework', $checker, $this->goodTargetLoc->id, 'reworked to spec', ['inspected_quantity' => '20', 'evidence_reference' => 'SORT-20', 'recovery_passed' => true]);

        $this->assertSame(MrbStatus::Released, $mrb->status);
        $this->assertSame('rework', $mrb->disposition);
        $this->assertNotNull($mrb->release_movement_id);
        $this->assertSame($this->goodTargetLoc->id, $mrb->release_location_id);

        $quar = StockLevel::where('item_id', $this->item->id)->where('location_id', $this->quarantineLoc->id)->first();
        $good = StockLevel::where('item_id', $this->item->id)->where('location_id', $this->goodTargetLoc->id)->first();
        $this->assertSame('0.000', (string) $quar->quantity);
        $this->assertSame('20.000', (string) $good->quantity);
    }

    public function test_release_without_complete_reinspection_evidence_leaves_stock_held(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $mrb = $this->svc->hold(['item_id' => $this->item->id, 'quantity' => '20',
            'source_location_id' => $this->sourceLoc->id, 'quarantine_location_id' => $this->quarantineLoc->id], $this->userWith('warehouse_staff'));
        $checker = $this->userWith('warehouse_staff');
        foreach ([[], ['inspected_quantity' => '19', 'evidence_reference' => 'SORT', 'recovery_passed' => true]] as $proof) {
            try {
                $this->svc->release($mrb, 'rework', $checker, $this->goodTargetLoc->id, null, $proof);
                $this->fail('Incomplete reinspection must not release stock.');
            } catch (BusinessRuleException $e) {
                $this->assertStringContainsString('reinspection', $e->getMessage());
            }
        }
        $this->assertSame(MrbStatus::Held, $mrb->fresh()->status);
        $this->assertNull($mrb->fresh()->release_movement_id);
    }

    public function test_hold_creator_cannot_release_own_mrb(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $maker = $this->userWith('warehouse_staff');
        $mrb = $this->svc->hold([
            'item_id' => $this->item->id,
            'quantity' => '20',
            'source_location_id' => $this->sourceLoc->id,
            'quarantine_location_id' => $this->quarantineLoc->id,
        ], $maker);

        try {
            $this->svc->release($mrb, 'rework', $maker, $this->goodTargetLoc->id);
            self::fail('The user who placed an MRB hold must not release that same record.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('A different user must release an MRB hold than the one who placed it.', $exception->getMessage());
        }

        $this->assertSame(MrbStatus::Held, $mrb->fresh()->status);
        $this->assertNull($mrb->fresh()->release_movement_id);
        $this->assertSame('80.000', (string) StockLevel::query()->where('item_id', $this->item->id)->where('location_id', $this->sourceLoc->id)->value('quantity'));
        $this->assertSame('20.000', (string) StockLevel::query()->where('item_id', $this->item->id)->where('location_id', $this->quarantineLoc->id)->value('quantity'));
        $this->assertNull(StockLevel::query()->where('item_id', $this->item->id)->where('location_id', $this->goodTargetLoc->id)->first());
        $this->assertSame(1, StockMovement::query()
            ->where('reference_type', 'material_review_record')
            ->where('reference_id', $mrb->id)
            ->count());
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
        $this->svc->release($mrb, 'rework', $this->userWith('warehouse_staff'), null, null);
    }

    // ── 5. release(scrap) → Scrapped, stock leaves quarantine ───────────────────

    public function test_release_scrap_marks_scrapped_and_moves_out_of_quarantine(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $by = $this->userWith('warehouse_staff');

        $mrb = $this->svc->hold([
            'item_id' => $this->item->id,
            'quantity' => '15',
            'source_location_id' => $this->sourceLoc->id,
            'quarantine_location_id' => $this->quarantineLoc->id,
        ], $by);

        $mrb = $this->svc->release($mrb, 'scrap', $this->userWith('warehouse_staff'), null, null);

        $this->assertSame(MrbStatus::Scrapped, $mrb->status);
        $this->assertSame('scrap', $mrb->disposition);
        // Scrap is written off from quarantine; the resolved scrap location is
        // retained as the physical disposition trace, not as on-hand stock.
        $this->assertSame($this->scrapLoc->id, $mrb->release_location_id);

        $releaseMovement = $mrb->fresh()->releaseMovement;
        $this->assertNotNull($releaseMovement);
        $this->assertSame(MovementGlHandoffStatus::Generated, $releaseMovement->gl_handoff_status);
        $journal = $releaseMovement->journalEntry()->firstOrFail();
        $this->assertSame(JournalEntryStatus::Posted, $journal->status);
        $this->assertSame('375.00', (string) $journal->total_debit);
        $this->assertSame((string) $journal->total_debit, (string) $journal->total_credit);

        $quar = StockLevel::where('item_id', $this->item->id)->where('location_id', $this->quarantineLoc->id)->first();
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
        $checker = $this->userWith('warehouse_staff');
        $mrb = $this->svc->release($mrb, 'rework', $checker, $this->goodTargetLoc->id, null, ['inspected_quantity' => (string) $mrb->quantity, 'evidence_reference' => 'SORT', 'recovery_passed' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not held');
        $this->svc->release($mrb->fresh(), 'scrap', $checker, null, null);
    }

    public function test_hold_rejects_inactive_quarantine_locations(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $by = $this->userWith('warehouse_staff');

        $this->quarantineLoc->update(['is_active' => false]);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('must be active');
        $this->svc->hold([
            'item_id' => $this->item->id,
            'quantity' => '10',
            'source_location_id' => $this->sourceLoc->id,
            'quarantine_location_id' => $this->quarantineLoc->id,
        ], $by);
    }

    public function test_hold_rejects_quarantine_location_from_another_warehouse(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $by = $this->userWith('warehouse_staff');
        $otherWarehouse = Warehouse::factory()->create();
        $otherZone = WarehouseZone::factory()->create([
            'warehouse_id' => $otherWarehouse->id,
            'zone_type' => 'quarantine',
        ]);
        $otherQuarantine = WarehouseLocation::factory()->create(['zone_id' => $otherZone->id, 'is_active' => true]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('same warehouse');
        $this->svc->hold([
            'item_id' => $this->item->id,
            'quantity' => '10',
            'source_location_id' => $this->sourceLoc->id,
            'quarantine_location_id' => $otherQuarantine->id,
        ], $by);
    }

    public function test_release_rejects_good_location_from_another_warehouse(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $by = $this->userWith('warehouse_staff');
        $mrb = $this->svc->hold([
            'item_id' => $this->item->id,
            'quantity' => '10',
            'source_location_id' => $this->sourceLoc->id,
            'quarantine_location_id' => $this->quarantineLoc->id,
        ], $by);

        $otherWarehouse = Warehouse::factory()->create();
        $otherZone = WarehouseZone::factory()->create([
            'warehouse_id' => $otherWarehouse->id,
            'zone_type' => 'finished_goods',
        ]);
        $otherTarget = WarehouseLocation::factory()->create(['zone_id' => $otherZone->id, 'is_active' => true]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('same warehouse');
        $this->svc->release($mrb, 'rework', $this->userWith('warehouse_staff'), $otherTarget->id, null, ['inspected_quantity' => (string) $mrb->quantity, 'evidence_reference' => 'SORT', 'recovery_passed' => true]);
    }

    public function test_replaying_an_mrb_hold_idempotency_key_returns_original_without_moving_again(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $by = $this->userWith('warehouse_staff');
        $data = [
            'item_id' => $this->item->id,
            'quantity' => '30',
            'source_location_id' => $this->sourceLoc->id,
            'quarantine_location_id' => $this->quarantineLoc->id,
        ];

        $first = $this->svc->hold($data, $by, 'mrb-retry-001');
        $replay = $this->svc->hold($data, $by, 'mrb-retry-001');

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, MaterialReviewRecord::query()->count());
        $this->assertSame('70.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->sourceLoc->id)
            ->value('quantity'));

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('different MRB hold');
        $this->svc->hold([...$data, 'quantity' => '20'], $by, 'mrb-retry-001');
    }

    public function test_legacy_hold_retry_preserves_original_without_accepting_a_changed_lot(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $by = $this->userWith('warehouse_staff');
        $data = ['item_id' => $this->item->id, 'quantity' => '30',
            'source_location_id' => $this->sourceLoc->id, 'quarantine_location_id' => $this->quarantineLoc->id];
        $first = $this->svc->hold($data, $by, 'legacy-hold-retry');
        $first->forceFill(['idempotency_fingerprint' => hash('sha256', json_encode([
            'actor_id' => $by->id, 'item_id' => $this->item->id, 'quantity' => '30.000',
            'source_location_id' => $this->sourceLoc->id, 'quarantine_location_id' => $this->quarantineLoc->id,
            'ncr_id' => null, 'inspection_id' => null, 'notes' => '',
        ], JSON_THROW_ON_ERROR))])->save();
        $this->assertSame($first->id, $this->svc->hold($data, $by, 'legacy-hold-retry')->id);
        $this->assertSame(1, MaterialReviewRecord::query()->count());
        $this->assertSame('70.000', (string) StockLevel::where('item_id', $this->item->id)
            ->where('location_id', $this->sourceLoc->id)->value('quantity'));
        $this->expectException(BusinessRuleException::class);
        $this->svc->hold([...$data, 'lot_number' => 'DIFFERENT'], $by, 'legacy-hold-retry');
    }

    public function test_ncr_only_critical_hold_cannot_be_released_use_as_is(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $maker = $this->userWith('warehouse_staff');
        $inspection = Inspection::create(['inspection_number' => 'QC-CRITICAL-'.uniqid(),
            'stage' => 'incoming', 'status' => 'failed', 'item_id' => $this->item->id,
            'batch_quantity' => 20, 'sample_size' => 20, 'accept_count' => 0, 'reject_count' => 1,
            'defect_count' => 1, 'inspector_id' => $maker->id]);
        $inspection->forceFill(['critical_defect' => true])->save();
        $ncr = NonConformanceReport::factory()->create(['inspection_id' => $inspection->id, 'affected_quantity' => 20]);
        $mrb = $this->svc->hold(['item_id' => $this->item->id, 'quantity' => '20',
            'source_location_id' => $this->sourceLoc->id, 'quarantine_location_id' => $this->quarantineLoc->id,
            'ncr_id' => $ncr->id], $maker);
        $this->assertNull($mrb->inspection_id);
        try {
            $this->svc->release($mrb, 'use_as_is', $this->userWith('warehouse_staff'), $this->goodTargetLoc->id, null,
                ['inspected_quantity' => '20', 'evidence_reference' => 'CONCESSION', 'recovery_passed' => true]);
            $this->fail('Critical NCR-only holds cannot bypass the concession guard.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('critical failure', $e->getMessage());
        }
        $this->assertSame(MrbStatus::Held, $mrb->fresh()->status);
    }

    public function test_quality_links_must_be_failed_and_match_item_and_quantity(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $by = $this->userWith('warehouse_staff');
        $product = Product::factory()->create();
        $inspection = Inspection::create([
            'inspection_number' => 'QC-MRB-'.uniqid(),
            'stage' => 'incoming',
            'status' => 'failed',
            'product_id' => $product->id,
            'item_id' => $this->item->id,
            'batch_quantity' => 20,
            'sample_size' => 20,
            'aql_code' => null,
            'accept_count' => 0,
            'reject_count' => 1,
            'defect_count' => 1,
            'inspector_id' => $by->id,
            'completed_at' => now(),
        ]);
        $ncr = NonConformanceReport::factory()->create([
            'product_id' => $product->id,
            'inspection_id' => $inspection->id,
            'affected_quantity' => 20,
            'created_by' => $by->id,
        ]);

        $mrb = $this->svc->hold([
            'item_id' => $this->item->id,
            'quantity' => '10',
            'source_location_id' => $this->sourceLoc->id,
            'quarantine_location_id' => $this->quarantineLoc->id,
            'inspection_id' => $inspection->id,
            'ncr_id' => $ncr->id,
        ], $by);

        $this->assertSame($inspection->id, $mrb->inspection_id);
        $this->assertSame($ncr->id, $mrb->ncr_id);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('smaller than the MRB quantity');
        $this->svc->hold([
            'item_id' => $this->item->id,
            'quantity' => '21',
            'source_location_id' => $this->sourceLoc->id,
            'quarantine_location_id' => $this->quarantineLoc->id,
            'inspection_id' => $inspection->id,
        ], $by);
    }

    // ── 7. permission gate ──────────────────────────────────────────────────────

    public function test_permission_gate_blocks_unauthorized_and_allows_warehouse_staff(): void
    {
        $this->stock($this->sourceLoc->id, '100');

        $payload = [
            'item_id' => $this->item->hash_id,
            'quantity' => '5',
            'source_location_id' => $this->sourceLoc->hash_id,
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

    public function test_hold_creator_cannot_release_own_mrb_through_api(): void
    {
        $this->stock($this->sourceLoc->id, '100');
        $maker = $this->userWith('warehouse_staff');
        $created = $this->actingAs($maker)->postJson('/api/v1/inventory/mrb', [
            'item_id' => $this->item->hash_id,
            'quantity' => '20',
            'source_location_id' => $this->sourceLoc->hash_id,
            'quarantine_location_id' => $this->quarantineLoc->hash_id,
        ])->assertCreated()->assertJsonPath('data.status', 'held');
        $mrb = MaterialReviewRecord::query()->where('mrb_number', $created->json('data.mrb_number'))->firstOrFail();

        $this->actingAs($maker)->postJson('/api/v1/inventory/mrb/'.$mrb->hash_id.'/release', [
            'disposition' => 'use_as_is',
            'target_location_id' => $this->goodTargetLoc->hash_id,
        ])->assertUnprocessable()->assertJsonPath('message', 'A different user must release an MRB hold than the one who placed it.');

        $this->assertSame(MrbStatus::Held, $mrb->fresh()->status);
        $this->assertNull($mrb->fresh()->release_movement_id);
        $this->assertSame('80.000', (string) StockLevel::query()->where('item_id', $this->item->id)->where('location_id', $this->sourceLoc->id)->value('quantity'));
        $this->assertSame('20.000', (string) StockLevel::query()->where('item_id', $this->item->id)->where('location_id', $this->quarantineLoc->id)->value('quantity'));
        $this->assertNull(StockLevel::query()->where('item_id', $this->item->id)->where('location_id', $this->goodTargetLoc->id)->first());
        $this->assertSame(1, StockMovement::query()
            ->where('reference_type', 'material_review_record')
            ->where('reference_id', $mrb->id)
            ->count());
    }
}
