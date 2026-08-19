<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ChainListenerRun;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\ProductionReceiptHandoffStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\ProductionReceiptRequested;
use App\Modules\Production\Listeners\CreateProductionReceiptOnOutputRequested;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Production\Services\WorkOrderOutputService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class WorkOrderOutputFgReceiptTest extends TestCase
{
    use RefreshDatabase;

    private WorkOrderOutputService $service;
    private User $user;
    private Product $product;
    private WorkOrder $wo;
    private WarehouseLocation $fgLocation;
    private Item $fgItem;
    private DefectType $defectType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->product = Product::create([
            'part_number'     => 'FG-PTEST-01',
            'name'            => 'FG Product',
            'unit_of_measure' => 'pcs',
            'standard_cost'   => 50.00,
            'is_active'       => true,
        ]);

        // Create a FinishedGoods-zone location.
        $warehouse = Warehouse::factory()->create();
        $zone = WarehouseZone::factory()->create([
            'warehouse_id' => $warehouse->id,
            'zone_type'    => WarehouseZoneType::FinishedGoods->value,
            'code'         => 'FGZ',
            'name'         => 'Finished Goods Zone',
        ]);
        $this->fgLocation = WarehouseLocation::factory()->create([
            'zone_id'   => $zone->id,
            'code'      => 'FG-LOC-01',
            'is_active' => true,
        ]);

        // FG Item whose code matches the product part_number (convention).
        $this->fgItem = Item::factory()->create([
            'code'      => $this->product->part_number,
            'item_type' => ItemType::FinishedGood->value,
            'name'      => 'FG Item for ' . $this->product->part_number,
        ]);

        $this->wo = WorkOrder::create([
            'wo_number'      => 'WO-FG-' . substr(uniqid(), -5),
            'product_id'     => $this->product->id,
            'status'         => WorkOrderStatus::InProgress->value,
            'quantity_target' => 100,
            'quantity_produced' => 0,
            'quantity_good'  => 0,
            'quantity_rejected' => 0,
            'planned_start'  => now(),
            'planned_end'    => now()->addDay(),
            'actual_start'   => now()->subHour(),
            'machine_id'     => null,
            'created_by'     => $this->user->id,
        ]);

        $this->service = app(WorkOrderOutputService::class);

        $this->defectType = DefectType::create([
            'code'        => 'DT-FG-TEST',
            'name'        => 'Test Defect for FG',
            'description' => null,
            'is_active'   => true,
        ]);
    }

    public function test_record_output_creates_production_receipt_movement(): void
    {
        $output = $this->service->record($this->wo, [
            'good_count'   => 10,
            'reject_count' => 2,
            'defects' => [
                ['defect_type_id' => $this->defectType->id, 'count' => 2],
            ],
        ], $this->user->id);

        $this->assertNotNull($output->id);

        /** @var \App\Modules\Inventory\Models\StockMovement $movement */
        $movement = \App\Modules\Inventory\Models\StockMovement::query()
            ->where('item_id', $this->fgItem->id)
            ->where('to_location_id', $this->fgLocation->id)
            ->where('movement_type', StockMovementType::ProductionReceipt->value)
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->first();

        $this->assertNotNull($movement, 'A ProductionReceipt movement must exist after WO output recording');
        $this->assertSame('10.000', (string) $movement->quantity);
        $this->assertSame(ProductionReceiptHandoffStatus::Generated, $output->fresh()->production_receipt_handoff_status);
        $this->assertSame($movement->id, $output->fresh()->production_receipt_movement_id);
    }

    public function test_reject_only_output_does_not_create_movement(): void
    {
        $output = $this->service->record($this->wo, [
            'good_count'   => 0,
            'reject_count' => 3,
            'defects' => [
                ['defect_type_id' => $this->defectType->id, 'count' => 3],
            ],
        ], $this->user->id);

        $this->assertNotNull($output->id);
        $this->assertSame(ProductionReceiptHandoffStatus::NotRequired, $output->production_receipt_handoff_status);

        $count = StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->count();

        $this->assertSame(0, $count, 'No stock movement for reject-only output');
    }

    public function test_missing_fg_item_commits_output_and_creates_durable_manual_handoff(): void
    {
        // Item codes are globally unique even across soft-deleted rows; this
        // test deliberately removes the prerequisite before restoring it.
        $this->fgItem->forceDelete();

        $output = $this->service->record($this->wo, [
            'good_count'   => 5,
            'reject_count' => 0,
        ], $this->user->id);

        $this->assertNotNull($output->id);
        $this->assertSame(ProductionReceiptHandoffStatus::ManualRequired, $output->fresh()->production_receipt_handoff_status);
        $this->assertNotEmpty($output->fresh()->production_receipt_handoff_message);

        $count = StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->count();

        $this->assertSame(0, $count, 'No movement is posted until the inventory prerequisite is fixed');
        $request = DB::table('event_outbox')
            ->where('event_type', ProductionReceiptRequested::class)
            ->where('dedupe_key', 'production-receipt-request:'.$output->id)
            ->first();
        $this->assertNotNull($request, 'A missing FG item must create a durable recovery request.');
        $this->assertSame('published', $request->status);
        $this->assertDatabaseHas('chain_step_runs', [
            'outbox_id' => $request->id,
            'entity_type' => 'work_order',
            'entity_id' => $this->wo->id,
            'step' => 'production_receipt',
        ]);
    }

    public function test_missing_fg_location_commits_output_and_creates_durable_manual_handoff(): void
    {
        // Remove all FG-zone locations.
        WarehouseLocation::query()->where('zone_id', $this->fgLocation->zone_id)->delete();
        WarehouseZone::query()->where('zone_type', WarehouseZoneType::FinishedGoods->value)->delete();

        $output = $this->service->record($this->wo, [
            'good_count'   => 7,
            'reject_count' => 0,
        ], $this->user->id);

        $this->assertNotNull($output->id);
        $this->assertSame(ProductionReceiptHandoffStatus::ManualRequired, $output->fresh()->production_receipt_handoff_status);

        $count = StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->count();

        $this->assertSame(0, $count, 'No movement is posted until the inventory prerequisite is fixed');
    }

    public function test_receipt_replay_after_fixing_item_is_idempotent(): void
    {
        $this->fgItem->forceDelete();

        $output = $this->service->record($this->wo, [
            'good_count' => 5,
            'reject_count' => 0,
        ], $this->user->id);

        $request = DB::table('event_outbox')
            ->where('event_type', ProductionReceiptRequested::class)
            ->where('dedupe_key', 'production-receipt-request:'.$output->id)
            ->firstOrFail();

        $replacement = Item::factory()->create([
            'code' => $this->product->part_number,
            'item_type' => ItemType::FinishedGood->value,
            'name' => 'Recovered FG Item',
        ]);

        $event = app(OutboxEventCodec::class)->decode(
            (string) $request->event_type,
            json_decode((string) $request->payload, true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertInstanceOf(ProductionReceiptRequested::class, $event);
        $listener = app(CreateProductionReceiptOnOutputRequested::class);
        $listener->handle($event);

        $recovered = $output->fresh();
        $this->assertSame(ProductionReceiptHandoffStatus::Generated, $recovered->production_receipt_handoff_status);
        $this->assertNotNull($recovered->production_receipt_movement_id);
        $movementCount = StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->count();
        $this->assertSame(1, $movementCount);

        // A duplicate event/replay observes the exact output-level receipt and
        // must not add stock a second time.
        $listener->handle($event);
        $this->assertSame($movementCount, StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->count());
        $this->assertSame(5, (int) StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->sum('quantity'));
        $this->assertSame($replacement->id, (int) StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->value('item_id'));

        $role = Role::create([
            'name' => 'Production Receipt Retry '.uniqid(),
            'slug' => 'production_receipt_retry_'.uniqid(),
            'description' => 'Test role',
        ]);
        $permission = Permission::firstOrCreate(
            ['slug' => 'production.wo.record'],
            ['name' => 'Record Production Output', 'module' => 'production'],
        );
        $role->permissions()->sync([$permission->id]);
        $this->user->update(['role_id' => $role->id]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/production/work-orders/{$this->wo->hash_id}/outputs/{$output->hash_id}/retry-receipt",
        );
        $response->assertOk()->assertJsonPath('data.production_receipt_handoff.status', 'generated');
        $this->assertSame($movementCount, StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->count());
    }

    // ─────────────────────────────────────────────────────────────
    // What the degrade arm covers, and what it must not
    // ─────────────────────────────────────────────────────────────

    /**
     * F-04 treats physical output as a fact: an expected failure in the
     * inventory handoff must not un-produce parts that exist on the floor.
     *
     * The three tests above cover the ProductionReceiptHandoffException half of
     * `catch (ProductionReceiptHandoffException|BusinessRuleException|
     * InvalidMovementException)`. Nothing covered the BusinessRuleException half,
     * and 62f25e14 moved throws into it — StockMovementService's rules are now
     * that class — so a future edit narrowing that arm would revert the
     * behaviour silently: the whole output would roll back, the WO counters and
     * mold shots would unwind, and the operator would be told to re-key a batch
     * they had already made.
     *
     * A container double is the only way to raise a chosen class from inside the
     * handoff deterministically. The message is the real one from
     * StockMovementService::assertLocationsNotFrozen.
     */
    public function test_a_business_rule_inside_the_receipt_handoff_still_commits_the_output(): void
    {
        $this->seed(SettingsSeeder::class);
        $mold = $this->mold();
        $this->wo->forceFill(['mold_id' => $mold->id])->save();

        $this->mock(StockMovementService::class, function ($mock): void {
            // atLeast(once), not once: the degraded handoff publishes a recovery
            // request whose listener runs inline under the sync queue driver, so
            // the retry attempts the movement a second time and fails the same
            // way. Both attempts are part of the behaviour being pinned.
            $mock->shouldReceive('move')->atLeast()->once()->andThrow(new BusinessRuleException(
                'Warehouse location 7 is frozen by stock count SC-202608-0001.',
            ));
        });

        // The request succeeds — that is the user-visible half of "physical
        // output is a fact". (The response body's handoff status is stale here:
        // markProductionReceiptManual() writes through a separately loaded row,
        // so the returned instance still reads not_started until a refetch. The
        // database is authoritative and is asserted below.)
        $this->actingAs($this->recorder())
            ->postJson("/api/v1/production/work-orders/{$this->wo->hash_id}/outputs", [
                'good_count' => 6,
                'reject_count' => 0,
            ])
            ->assertCreated();

        $output = $this->wo->outputs()->firstOrFail();
        $this->assertSame(6, (int) $output->good_count);

        $fresh = $this->wo->fresh();
        $this->assertSame(6, (int) $fresh->quantity_produced, 'The WO counter must keep the parts that were made.');
        $this->assertSame(6, (int) $fresh->quantity_good);
        $this->assertSame(6, (int) $mold->fresh()->current_shot_count, 'Mold shots are tool wear that happened.');

        $this->assertSame(
            ProductionReceiptHandoffStatus::ManualRequired,
            $output->production_receipt_handoff_status,
        );
        $this->assertNotNull(
            DB::table('event_outbox')
                ->where('event_type', ProductionReceiptRequested::class)
                ->where('dedupe_key', 'production-receipt-request:'.$output->id)
                ->first(),
            'The degraded handoff must leave a durable, replayable recovery request.',
        );
        $this->assertSame(0, StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->count());
    }

    /**
     * The control. Without it, the test above would also pass if the arm caught
     * everything — and an arm that swallows a deadlock would keep an output row
     * whose stock movement can never be replayed.
     */
    public function test_an_unexpected_failure_in_the_receipt_handoff_rolls_the_whole_output_back(): void
    {
        $this->seed(SettingsSeeder::class);
        config(['app.debug' => false]);
        $mold = $this->mold();
        $this->wo->forceFill(['mold_id' => $mold->id])->save();

        $this->mock(StockMovementService::class, function ($mock): void {
            $mock->shouldReceive('move')->once()->andThrow(new RuntimeException('Connection lost mid-write.'));
        });

        $this->actingAs($this->recorder())
            ->postJson("/api/v1/production/work-orders/{$this->wo->hash_id}/outputs", [
                'good_count' => 6,
                'reject_count' => 0,
            ])
            ->assertStatus(500);

        $this->assertSame(0, $this->wo->outputs()->count(), 'An unexpected fault must not leave a half-recorded output.');
        $this->assertSame(0, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame(0, (int) $mold->fresh()->current_shot_count);
    }

    /**
     * ClosedPeriodException does NOT extend BusinessRuleException, so it is not
     * covered by the arm above — it escapes record() entirely and lands in
     * WorkOrderController::recordOutput, whose docblock says exactly that.
     *
     * This test exists to make that a decision instead of an accident: it is what
     * fails if ClosedPeriodException is ever reparented onto
     * BusinessRuleException, which would otherwise be an invisible change (both
     * are RuntimeException subclasses, so every `expectException` assertion in
     * the suite keeps passing).
     *
     * Note what the current behaviour costs, because it is not obviously right:
     * the output rolls back, so a closed accounting period refuses production
     * output that physically exists — which reads against F-04's "physical output
     * is a fact", and against the arms in MovementGlPostingService and
     * PostStockMovementToGlOnRequested whose comments name a closed posting
     * period as something to degrade to manual. Whoever settles that policy will
     * have to change this test on purpose. That is the point of it.
     */
    public function test_a_closed_period_in_the_receipt_handoff_is_not_absorbed_by_the_degrade_arm(): void
    {
        $this->seed(SettingsSeeder::class);
        $mold = $this->mold();
        $this->wo->forceFill(['mold_id' => $mold->id])->save();

        $this->mock(StockMovementService::class, function ($mock): void {
            $mock->shouldReceive('move')->once()->andThrow(new ClosedPeriodException(2026, 7, '2026-07-15'));
        });

        $response = $this->actingAs($this->recorder())
            ->postJson("/api/v1/production/work-orders/{$this->wo->hash_id}/outputs", [
                'good_count' => 6,
                'reject_count' => 0,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('2026-07 is closed', (string) $response->json('message'));
        $this->assertStringContainsString('Reopen the period first', (string) $response->json('message'));

        $this->assertSame(0, $this->wo->outputs()->count());
        $this->assertSame(0, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame(0, (int) $mold->fresh()->current_shot_count);
    }

    /** A user holding only the permission the record-output route requires. */
    private function recorder(): User
    {
        $role = Role::create([
            'name' => 'Output Recorder '.uniqid(),
            'slug' => 'output_recorder_'.uniqid(),
            'description' => 'Test role',
        ]);
        $permission = Permission::firstOrCreate(
            ['slug' => 'production.wo.record'],
            ['name' => 'Record Production Output', 'module' => 'production'],
        );
        $role->permissions()->sync([$permission->id]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function mold(): Mold
    {
        return Mold::create([
            'mold_code'                    => 'MD-'.substr(uniqid(), -5),
            'name'                         => 'FG Receipt Mold',
            'product_id'                   => $this->product->id,
            'cavity_count'                 => 1,
            'cycle_time_seconds'           => 30,
            'output_rate_per_hour'         => 100,
            'setup_time_minutes'           => 10,
            'current_shot_count'           => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots'           => 1000000,
            'status'                       => 'available',
        ]);
    }
}
