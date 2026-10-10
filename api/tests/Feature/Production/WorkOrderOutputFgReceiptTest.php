<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Common\Services\OutboxEventCodec;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\ProductionReceiptHandoffStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\ProductionReceiptRequested;
use App\Modules\Production\Listeners\CreateProductionReceiptOnOutputRequested;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\ProductionResourceService;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Quality\Services\InspectionSpecService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\HasPassedInProcessQc;
use Tests\TestCase;
use Tests\Support\HasAssignedProductionCrew;

class WorkOrderOutputFgReceiptTest extends TestCase
{
    use HasPassedInProcessQc;
    use RefreshDatabase;
    use HasAssignedProductionCrew;

    private WorkOrderOutputService $service;

    private User $user;

    private Product $product;

    private WorkOrder $wo;

    private Machine $machine;

    private Mold $productionMold;

    private WarehouseLocation $fgLocation;

    private Item $fgItem;

    private DefectType $defectType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->product = Product::create([
            'part_number' => 'FG-PTEST-01',
            'name' => 'FG Product',
            'unit_of_measure' => 'pcs',
            'standard_cost' => 50.00,
            'is_active' => true,
        ]);
        Uom::firstOrCreate(['code' => 'PCS'], ['name' => 'Pieces']);

        // Create a FinishedGoods-zone location.
        $warehouse = Warehouse::factory()->create();
        $zone = WarehouseZone::factory()->create([
            'warehouse_id' => $warehouse->id,
            'zone_type' => WarehouseZoneType::FinishedGoods->value,
            'code' => 'FGZ',
            'name' => 'Finished Goods Zone',
        ]);
        $this->fgLocation = WarehouseLocation::factory()->create([
            'zone_id' => $zone->id,
            'code' => 'FG-LOC-01',
            'is_active' => true,
        ]);

        // FG Item whose code matches the product part_number (convention).
        $this->fgItem = Item::factory()->create([
            'code' => $this->product->part_number,
            'item_type' => ItemType::FinishedGood->value,
            'name' => 'FG Item for '.$this->product->part_number,
        ]);

        $this->wo = WorkOrder::create([
            'wo_number' => 'WO-FG-'.substr(uniqid(), -5),
            'product_id' => $this->product->id,
            'status' => WorkOrderStatus::Confirmed->value,
            'quantity_target' => 100,
            'quantity_produced' => 0,
            'quantity_good' => 0,
            'quantity_rejected' => 0,
            'planned_start' => now(),
            'planned_end' => now()->addDay(),
            'actual_start' => now()->subHour(),
            'machine_id' => null,
            'created_by' => $this->user->id,
        ]);

        $this->machine = Machine::factory()->create();
        $this->productionMold = $this->mold();
        $this->productionMold->compatibleMachines()->sync([$this->machine->id]);
        $this->wo->forceFill([
            'machine_id' => $this->machine->id,
            'mold_id' => $this->productionMold->id,
        ])->save();
        ProductionSchedule::create([
            'work_order_id' => $this->wo->id,
            'machine_id' => $this->machine->id,
            'mold_id' => $this->productionMold->id,
            'scheduled_start' => now()->subMinute(),
            'scheduled_end' => now()->addHours(2),
            'priority_order' => 0,
            'status' => 'confirmed',
            'is_confirmed' => true,
            'confirmed_by' => $this->user->id,
            'confirmed_at' => now(),
            'reserves_capacity' => true,
        ]);
        $this->wo->forceFill([
            'status' => WorkOrderStatus::InProgress->value,
            'actual_start' => now()->subHour(),
        ])->save();
        app(ProductionResourceService::class)->acquire($this->wo, $this->machine, $this->productionMold);
        $this->assignProductionCrew($this->wo, $this->user);
        $this->passInProcessQc($this->wo);

        $this->service = app(WorkOrderOutputService::class);

        $this->defectType = DefectType::create([
            'code' => 'DT-FG-TEST',
            'name' => 'Test Defect for FG',
            'description' => null,
            'is_active' => true,
        ]);
    }

    public function test_record_output_creates_production_receipt_movement(): void
    {
        $output = $this->service->record($this->wo, $this->outputForCrew($this->wo, [
            'good_count' => 10,
            'reject_count' => 2,
            'defects' => [
                ['defect_type_id' => $this->defectType->id, 'count' => 2],
            ],
        ], $this->user), $this->user->id);

        $this->assertNotNull($output->id);

        /** @var StockMovement $movement */
        $movement = StockMovement::query()
            ->where('item_id', $this->fgItem->id)
            ->where('to_location_id', $this->fgLocation->id)
            ->where('movement_type', StockMovementType::ProductionReceipt->value)
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->first();

        $this->assertNotNull($movement, 'A ProductionReceipt movement must exist after WO output recording');
        $this->assertSame('10.000', (string) $movement->quantity);
        $this->assertSame('50.0000', (string) $movement->unit_cost);
        $this->assertSame($output->batch_code, $movement->lot_number, 'The finished-goods stock lot must preserve its unique output batch code.');
        $this->assertSame(ProductionReceiptHandoffStatus::Generated, $output->fresh()->production_receipt_handoff_status);
        $this->assertSame($movement->id, $output->fresh()->production_receipt_movement_id);
    }

    public function test_work_orders_for_same_product_reuse_finished_goods_item_and_accumulate_stock(): void
    {
        $this->service->record($this->wo, $this->outputForCrew($this->wo, [
            'good_count' => 4,
            'reject_count' => 0,
        ], $this->user), $this->user->id);

        $secondMachine = Machine::factory()->create();
        $secondMold = $this->mold();
        $secondMold->compatibleMachines()->sync([$secondMachine->id]);
        $secondWo = WorkOrder::create([
            'wo_number' => $this->wo->wo_number.'-SECOND',
            'product_id' => $this->product->id,
            'status' => WorkOrderStatus::InProgress->value,
            'quantity_target' => 100,
            'quantity_produced' => 0,
            'quantity_good' => 0,
            'quantity_rejected' => 0,
            'planned_start' => now(),
            'planned_end' => now()->addDay(),
            'actual_start' => now()->subHour(),
            'machine_id' => $secondMachine->id,
            'mold_id' => $secondMold->id,
            'created_by' => $this->user->id,
        ]);
        ProductionSchedule::create([
            'work_order_id' => $secondWo->id,
            'machine_id' => $secondMachine->id,
            'mold_id' => $secondMold->id,
            'scheduled_start' => now()->subMinute(),
            'scheduled_end' => now()->addHours(2),
            'priority_order' => 0,
            'status' => 'confirmed',
            'is_confirmed' => true,
            'confirmed_by' => $this->user->id,
            'confirmed_at' => now(),
            'reserves_capacity' => true,
        ]);
        app(ProductionResourceService::class)->acquire($secondWo, $secondMachine, $secondMold);
        $secondRecorder = User::factory()->create();
        $this->assignProductionCrew($secondWo, $secondRecorder);
        $this->passInProcessQc($secondWo);

        $this->service->record($secondWo, $this->outputForCrew($secondWo, [
            'good_count' => 6,
            'reject_count' => 0,
        ], $secondRecorder), $secondRecorder->id);

        $movements = StockMovement::query()
            ->where('movement_type', StockMovementType::ProductionReceipt->value)
            ->where('reference_type', 'work_order_output')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $movements);
        $this->assertSame([$this->fgItem->id, $this->fgItem->id], $movements->pluck('item_id')->all());
        $this->assertSame(1, Item::query()
            ->where('code', $this->product->part_number)
            ->where('item_type', ItemType::FinishedGood)
            ->count());
        $this->assertSame('10.000', (string) DB::table('stock_levels')
            ->where('item_id', $this->fgItem->id)
            ->where('location_id', $this->fgLocation->id)
            ->value('quantity'));
    }

    public function test_work_order_cannot_complete_while_finished_goods_gl_handoff_is_manual(): void
    {
        $this->seed([SettingsSeeder::class, ChartOfAccountsSeeder::class]);
        app(SettingsService::class)->set('modules.accounting', true, 'modules');
        app(SettingsService::class)->set('accounting.accounts.material_consumption_code', '999999', 'accounting');
        app(InspectionSpecService::class)->upsertForProduct($this->product->id, [[
            'parameter_name' => 'Surface condition',
            'parameter_type' => 'visual',
            'is_critical' => false,
        ]], $this->user->id, 'Work order GL gate test');

        $output = $this->service->record($this->wo, $this->outputForCrew($this->wo, [
            'good_count' => 10,
            'reject_count' => 0,
        ], $this->user), $this->user->id);
        $movement = StockMovement::query()->findOrFail($output->production_receipt_movement_id);

        $this->assertSame(MovementGlHandoffStatus::ManualRequired, $movement->gl_handoff_status);
        $this->assertSame('10.000', (string) $movement->quantity);
        $auditCount = AuditLog::query()
            ->where('model_type', $this->wo->getMorphClass())
            ->where('model_id', $this->wo->id)
            ->count();
        $this->seed(RolePermissionSeeder::class);
        $productionActor = User::factory()->withRole('production_manager')->create();
        $this->assertTrue($productionActor->hasPermission('production.work_orders.lifecycle'));
        $missingReason = $this->actingAs($productionActor)
            ->postJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/complete');
        $missingReason->assertUnprocessable();
        $this->assertStringContainsString('short-completion reason', (string) $missingReason->json('message'));

        $blocked = $this->actingAs($productionActor)
            ->postJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/complete', [
                'short_completion_reason' => 'Production run stopped for the GL handoff regression.',
            ]);
        $blocked->assertUnprocessable();
        $this->assertStringContainsString('General Ledger', (string) $blocked->json('message'));

        $this->assertSame(WorkOrderStatus::InProgress, $this->wo->fresh()->status);
        $this->assertNull($this->wo->fresh()->actual_end);
        $this->assertSame(1, $this->wo->outputs()->where('id', $output->id)->count());
        $this->assertSame($auditCount, AuditLog::query()
            ->where('model_type', $this->wo->getMorphClass())
            ->where('model_id', $this->wo->id)
            ->count());
        $this->assertSame(0, DB::table('event_outbox')->where('event_type', \App\Modules\Production\Events\WorkOrderCompleted::class)->count());
        $this->assertSame(MovementGlHandoffStatus::ManualRequired, $movement->fresh()->gl_handoff_status);
        $this->assertSame('10.000', (string) DB::table('stock_levels')
            ->where('item_id', $this->fgItem->id)
            ->where('location_id', $this->fgLocation->id)
            ->value('quantity'));
        $this->assertTrue($productionActor->hasPermission('production.work_orders.view'));
        $this->actingAs($productionActor)
            ->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id)
            ->assertOk()
            ->assertJsonPath('data.outputs.0.general_ledger_handoff.status', MovementGlHandoffStatus::ManualRequired->value)
            ->assertJsonPath('data.outputs.0.general_ledger_handoff.message', $movement->fresh()->gl_handoff_message)
            ->assertJsonPath('data.outputs.0.general_ledger_handoff.movement_id', $movement->hash_id);

        $accountingActor = User::factory()->withRole('finance_officer')->create();
        $this->assertTrue($accountingActor->hasPermission('accounting.journal.post'));
        app(SettingsService::class)->set('accounting.accounts.material_consumption_code', '5010', 'accounting');
        $this->actingAs($accountingActor)
            ->postJson('/api/v1/inventory/stock-movements/'.$movement->hash_id.'/retry-gl')
            ->assertOk()
            ->assertJsonPath('data.gl_handoff.status', MovementGlHandoffStatus::Generated->value);

        $posted = $movement->fresh();
        $this->assertSame(MovementGlHandoffStatus::Generated, $posted->gl_handoff_status);
        $this->assertNotNull($posted->journal_entry_id);
        $journal = DB::table('journal_entries')->where('id', $posted->journal_entry_id)->first();
        $this->assertSame('posted', $journal?->status);
        $debits = (string) DB::table('journal_entry_lines')
            ->where('journal_entry_id', $posted->journal_entry_id)
            ->sum('debit');
        $credits = (string) DB::table('journal_entry_lines')
            ->where('journal_entry_id', $posted->journal_entry_id)
            ->sum('credit');
        $this->assertSame('500.00', bcadd($debits, '0', 2));
        $this->assertSame('500.00', bcadd($credits, '0', 2));

        $completed = $this->actingAs($productionActor)
            ->postJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/complete', [
                'short_completion_reason' => 'Production run stopped for the GL handoff regression.',
            ]);
        $completed->assertOk()->assertJsonPath('data.status', WorkOrderStatus::Completed->value);
        $this->assertSame(WorkOrderStatus::Completed, $this->wo->fresh()->status);
    }

    public function test_standard_output_is_blocked_when_started_work_order_has_no_equipment_owner(): void
    {
        $recorder = $this->recorder();
        $equipmentCases = [
            ['machine_id' => null, 'mold_id' => $this->productionMold->id],
            ['machine_id' => $this->machine->id, 'mold_id' => null],
            ['machine_id' => null, 'mold_id' => null],
        ];

        foreach ($equipmentCases as $case => $equipment) {
            $legacyWo = WorkOrder::create([
                'wo_number' => 'WO-FG-LEGACY-'.substr(uniqid(), -5).'-'.$case,
                'product_id' => $this->product->id,
                'status' => WorkOrderStatus::InProgress->value,
                'quantity_target' => 100,
                'quantity_produced' => 0,
                'quantity_good' => 0,
                'quantity_rejected' => 0,
                'planned_start' => now(),
                'planned_end' => now()->addDay(),
                'actual_start' => now()->subHour(),
                'machine_id' => $equipment['machine_id'],
                'mold_id' => $equipment['mold_id'],
                'created_by' => $this->user->id,
            ]);
            $crew = $this->assignProductionCrew($legacyWo, $this->user);
            $this->passInProcessQc($legacyWo);
            $auditCount = AuditLog::query()
                ->where('model_type', $legacyWo->getMorphClass())
                ->where('model_id', $legacyWo->id)
                ->count();
            $movementCount = StockMovement::query()->where('reference_type', 'work_order_output')->count();
            $outboxCount = DB::table('event_outbox')->where('event_type', ProductionReceiptRequested::class)->count();

            $response = $this->actingAs($recorder)->postJson(
                "/api/v1/production/work-orders/{$legacyWo->hash_id}/outputs",
                [
                    'crew_id' => app('hashids')->encode($crew->id),
                    'recording_reason' => 'Reconcile missing production equipment first.',
                    'good_count' => 2,
                    'reject_count' => 0,
                ],
            );

            $response->assertUnprocessable();
            $this->assertStringContainsString('machine and mold', strtolower((string) $response->json('message')));
            $this->assertSame(0, $legacyWo->outputs()->count());
            $this->assertSame(0, (int) $legacyWo->fresh()->quantity_produced);
            $this->assertSame(0, (int) $legacyWo->fresh()->quantity_good);
            $this->assertSame(WorkOrderStatus::InProgress, $legacyWo->fresh()->status);
            $this->assertSame($equipment['machine_id'], $legacyWo->fresh()->machine_id);
            $this->assertSame($equipment['mold_id'], $legacyWo->fresh()->mold_id);
            $this->assertSame($auditCount, AuditLog::query()
                ->where('model_type', $legacyWo->getMorphClass())
                ->where('model_id', $legacyWo->id)
                ->count());
            $this->assertSame($movementCount, StockMovement::query()->where('reference_type', 'work_order_output')->count());
            $this->assertSame($outboxCount, DB::table('event_outbox')
                ->where('event_type', ProductionReceiptRequested::class)
                ->count());
        }
    }

    public function test_new_output_uses_frozen_work_order_cost_after_product_cost_changes(): void
    {
        $this->wo->forceFill(['unit_standard_cost' => '50.0000', 'cost_source' => 'product_standard_cost', 'cost_snapshot_at' => now()])->save();
        $this->product->update(['standard_cost' => '75.00']);
        $output = $this->service->record($this->wo, $this->outputForCrew($this->wo, ['good_count' => 10, 'reject_count' => 0], $this->user), $this->user->id);
        $movement = StockMovement::findOrFail($output->production_receipt_movement_id);
        $this->assertSame('50.0000', (string) $movement->unit_cost);
        $this->assertSame('50.0000', (string) $output->receipt_unit_cost);
    }

    public function test_zero_product_cost_requires_recovery_without_posting_zero_value_stock(): void
    {
        $this->product->update(['standard_cost' => '0.00']);
        $output = $this->service->record($this->wo, $this->outputForCrew($this->wo, ['good_count' => 5, 'reject_count' => 0], $this->user), $this->user->id);
        $this->assertSame(ProductionReceiptHandoffStatus::ManualRequired, $output->production_receipt_handoff_status);
        $this->assertNull($output->production_receipt_movement_id);
        $this->product->update(['standard_cost' => '60.00']);
        $recovered = $this->service->retryProductionReceipt($output, $this->user);
        $this->assertSame('60.0000', (string) StockMovement::findOrFail($recovered->production_receipt_movement_id)->unit_cost);
    }

    public function test_output_rejects_inventory_unit_mismatch_without_posting_stock(): void
    {
        $this->fgItem->update(['unit_of_measure' => 'KG']);
        $output = $this->service->record($this->wo, $this->outputForCrew($this->wo, ['good_count' => 5, 'reject_count' => 0], $this->user), $this->user->id);
        $this->assertSame(ProductionReceiptHandoffStatus::ManualRequired, $output->production_receipt_handoff_status);
        $this->assertNull($output->production_receipt_movement_id);
    }

    public function test_reject_only_output_does_not_create_movement(): void
    {
        $output = $this->service->record($this->wo, $this->outputForCrew($this->wo, [
            'good_count' => 0,
            'reject_count' => 3,
            'defects' => [
                ['defect_type_id' => $this->defectType->id, 'count' => 3],
            ],
        ], $this->user), $this->user->id);

        $this->assertNotNull($output->id);
        $this->assertSame(ProductionReceiptHandoffStatus::NotRequired, $output->production_receipt_handoff_status);

        $count = StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->count();

        $this->assertSame(0, $count, 'No stock movement for reject-only output');
    }

    public function test_defect_rows_require_a_positive_reject_count(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('positive Reject count');

        $this->service->record($this->wo, $this->outputForCrew($this->wo, [
            'good_count' => 5,
            'reject_count' => 0,
            'defects' => [
                ['defect_type_id' => $this->defectType->id, 'count' => 1],
            ],
        ], $this->user), $this->user->id);
    }

    public function test_missing_fg_item_is_provisioned_when_recording_output(): void
    {
        $this->fgItem->forceDelete();

        $output = $this->service->record($this->wo, $this->outputForCrew($this->wo, [
            'good_count' => 5,
            'reject_count' => 0,
        ], $this->user), $this->user->id);

        $this->assertNotNull($output->id);
        $provisioned = Item::query()
            ->where('code', $this->product->part_number)
            ->where('item_type', ItemType::FinishedGood)
            ->sole();
        $this->assertSame('PCS', $provisioned->unit_of_measure);
        $this->assertSame(ProductionReceiptHandoffStatus::Generated, $output->fresh()->production_receipt_handoff_status);

        $movement = StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->sole();
        $this->assertSame($provisioned->id, $movement->item_id);
        $this->assertSame('5.000', (string) $movement->quantity);
        $this->assertSame($movement->id, $output->fresh()->production_receipt_movement_id);

        $nextOutput = $this->service->record($this->wo, $this->outputForCrew($this->wo, [
            'good_count' => 3,
            'reject_count' => 0,
        ], $this->user), $this->user->id);
        $this->assertSame(1, Item::query()
            ->where('code', $this->product->part_number)
            ->where('item_type', ItemType::FinishedGood)
            ->count());
        $this->assertSame($provisioned->id, (int) StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $nextOutput->id)
            ->value('item_id'));
        $this->assertSame('8.000', (string) DB::table('stock_levels')
            ->where('item_id', $provisioned->id)
            ->where('location_id', $this->fgLocation->id)
            ->value('quantity'));
        $this->assertDatabaseMissing('event_outbox', [
            'event_type' => ProductionReceiptRequested::class,
            'dedupe_key' => 'production-receipt-request:'.$output->id,
        ]);
    }

    public function test_missing_fg_location_commits_output_and_creates_durable_manual_handoff(): void
    {
        // Remove all FG-zone locations.
        WarehouseLocation::query()->where('zone_id', $this->fgLocation->zone_id)->delete();
        WarehouseZone::query()->where('zone_type', WarehouseZoneType::FinishedGoods->value)->delete();

        $output = $this->service->record($this->wo, $this->outputForCrew($this->wo, [
            'good_count' => 7,
            'reject_count' => 0,
        ], $this->user), $this->user->id);

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
        $this->fgItem->update(['is_active' => false]);

        $output = $this->service->record($this->wo, $this->outputForCrew($this->wo, [
            'good_count' => 5,
            'reject_count' => 0,
        ], $this->user), $this->user->id);

        $request = DB::table('event_outbox')
            ->where('event_type', ProductionReceiptRequested::class)
            ->where('dedupe_key', 'production-receipt-request:'.$output->id)
            ->firstOrFail();
        $this->assertSame(ProductionReceiptHandoffStatus::ManualRequired, $output->fresh()->production_receipt_handoff_status);
        $this->assertSame(0, StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->count());

        $event = app(OutboxEventCodec::class)->decode(
            (string) $request->event_type,
            json_decode((string) $request->payload, true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertInstanceOf(ProductionReceiptRequested::class, $event);
        $listener = app(CreateProductionReceiptOnOutputRequested::class);
        $this->fgItem->update(['is_active' => true]);
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
        $this->assertSame($this->fgItem->id, (int) StockMovement::query()
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
        $modulePermission = Permission::firstOrCreate(
            ['slug' => 'production.view'],
            ['name' => 'View Production', 'module' => 'production'],
        );
        $role->permissions()->sync([$permission->id, $modulePermission->id]);
        $this->user->update(['role_id' => $role->id]);
        $this->user->refresh();

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
        $mold = $this->productionMold;

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
        // output is a fact" — and the response carries the durable recovery
        // state written by markProductionReceiptManual().
        $this->actingAs($this->recorder())
            ->postJson("/api/v1/production/work-orders/{$this->wo->hash_id}/outputs", [
                'crew_id' => app('hashids')->encode($this->assignProductionCrew($this->wo, $this->user)->id),
                'recording_reason' => 'Production regression recording.',
                'good_count' => 6,
                'reject_count' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('data.production_receipt_handoff.status', 'manual_required');

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
        $mold = $this->productionMold;

        $this->mock(StockMovementService::class, function ($mock): void {
            $mock->shouldReceive('move')->once()->andThrow(new RuntimeException('Connection lost mid-write.'));
        });

        $this->actingAs($this->recorder())
            ->postJson("/api/v1/production/work-orders/{$this->wo->hash_id}/outputs", [
                'crew_id' => app('hashids')->encode($this->assignProductionCrew($this->wo, $this->user)->id),
                'recording_reason' => 'Production regression recording.',
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
        $mold = $this->productionMold;

        $this->mock(StockMovementService::class, function ($mock): void {
            $mock->shouldReceive('move')->once()->andThrow(new ClosedPeriodException(2026, 7, '2026-07-15'));
        });

        $response = $this->actingAs($this->recorder())
            ->postJson("/api/v1/production/work-orders/{$this->wo->hash_id}/outputs", [
                'crew_id' => app('hashids')->encode($this->assignProductionCrew($this->wo, $this->user)->id),
                'recording_reason' => 'Production regression recording.',
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
        $modulePermission = Permission::firstOrCreate(
            ['slug' => 'production.view'],
            ['name' => 'View Production', 'module' => 'production'],
        );
        $role->permissions()->sync([$permission->id, $modulePermission->id]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function mold(): Mold
    {
        return Mold::create([
            'mold_code' => 'MD-'.substr(uniqid(), -5),
            'name' => 'FG Receipt Mold',
            'product_id' => $this->product->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
    }
}
