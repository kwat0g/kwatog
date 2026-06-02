<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Listeners\TriggerOutgoingQC;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3.7 — Race-condition guard: TriggerOutgoingQC must be idempotent.
 *
 * Strategy: call handle() twice on the same WorkOrderCompleted event (same WO).
 * The second call must be a no-op — only one outgoing inspection must exist.
 *
 * Covers the fix at two levels:
 *   1. Application layer — firstOrCreate on guard columns in fallback path +
 *      QueryException catch in the InspectionService path.
 *   2. DB layer — unique index on (stage, entity_type, entity_id) in migration 0169.
 *
 * We intentionally have NO active InspectionSpec so the listener falls through
 * to the fallback Inspection::firstOrCreate() path — this avoids needing
 * InspectionSpec/InspectionSpecItem fixtures while still exercising the
 * race-safe insert logic.
 *
 * Test inventory:
 *  1. test_handling_work_order_completed_twice_creates_one_outgoing_inspection
 *     — core idempotency: double handle() → exactly 1 row.
 *  2. test_wo_without_sales_order_id_creates_no_inspection
 *     — early-exit guard for internal rework WOs.
 *  3. test_db_unique_index_rejects_duplicate_outgoing_inspection_for_same_wo
 *     — DB-layer guard independent of listener fix.
 *  4. test_in_process_and_outgoing_inspections_coexist_for_same_wo
 *     — invariant not over-constrained: both stages allowed per WO.
 */
class OutgoingQcIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;
    private WorkOrder $workOrder;
    private TriggerOutgoingQC $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->product = Product::create([
            'part_number'     => 'P3-7-TEST-001',
            'name'            => 'Idempotency Test Part',
            'unit_of_measure' => 'pcs',
            'standard_cost'   => '5.00',
            'is_active'       => true,
        ]);

        // A minimal SalesOrder so the listener's $wo->sales_order_id guard passes.
        $so = SalesOrder::factory()->create();

        // Build a minimal WorkOrder row directly — no WO lifecycle needed.
        $this->workOrder = WorkOrder::create([
            'wo_number'         => 'WO-TEST-P37-0001',
            'product_id'        => $this->product->id,
            'sales_order_id'    => $so->id,
            'quantity_target'   => 100,
            'quantity_produced' => 100,
            'quantity_good'     => 98,
            'quantity_rejected' => 2,
            'planned_start'     => now()->subDay(),
            'planned_end'       => now(),
            'status'            => 'completed',
            'created_by'        => $this->user->id,
        ]);

        $this->listener = app(TriggerOutgoingQC::class);
    }

    // ─── Test 1: Core idempotency ─────────────────────────────────────────────

    /**
     * Calling handle() twice for the same WO must produce exactly ONE outgoing
     * inspection row. The second call must silently no-op.
     *
     * No active InspectionSpec exists → listener always falls through to the
     * firstOrCreate fallback path — exercises the race-safe bare-insert logic.
     */
    public function test_handling_work_order_completed_twice_creates_one_outgoing_inspection(): void
    {
        $event = new WorkOrderCompleted($this->workOrder);

        // First call — must create one outgoing inspection.
        $this->listener->handle($event);

        $countAfterFirst = Inspection::query()
            ->where('stage', InspectionStage::Outgoing->value)
            ->where('entity_type', InspectionEntityType::WorkOrder->value)
            ->where('entity_id', $this->workOrder->id)
            ->count();

        $this->assertSame(
            1,
            $countAfterFirst,
            'First handle() call must create exactly one outgoing inspection.'
        );

        // Second call — must be a no-op.
        $this->listener->handle($event);

        $countAfterSecond = Inspection::query()
            ->where('stage', InspectionStage::Outgoing->value)
            ->where('entity_type', InspectionEntityType::WorkOrder->value)
            ->where('entity_id', $this->workOrder->id)
            ->count();

        $this->assertSame(
            1,
            $countAfterSecond,
            'Second handle() call must NOT create a duplicate outgoing inspection. ' .
            'The unique constraint + firstOrCreate guard must suppress the duplicate.'
        );
    }

    // ─── Test 2: WO without sales_order_id skipped ───────────────────────────

    /**
     * The listener skips internal/rework WOs (no sales_order_id).
     * Calling handle() twice on such a WO must create zero inspections.
     */
    public function test_wo_without_sales_order_id_creates_no_inspection(): void
    {
        $internalWo = WorkOrder::create([
            'wo_number'         => 'WO-TEST-P37-INTERNAL',
            'product_id'        => $this->product->id,
            'sales_order_id'    => null,
            'quantity_target'   => 50,
            'quantity_produced' => 50,
            'quantity_good'     => 48,
            'quantity_rejected' => 2,
            'planned_start'     => now()->subDay(),
            'planned_end'       => now(),
            'status'            => 'completed',
            'created_by'        => $this->user->id,
        ]);

        $event = new WorkOrderCompleted($internalWo);

        $this->listener->handle($event);
        $this->listener->handle($event);

        $this->assertSame(
            0,
            Inspection::query()
                ->where('entity_type', InspectionEntityType::WorkOrder->value)
                ->where('entity_id', $internalWo->id)
                ->count(),
            'Internal WO (no sales_order_id) must never get an outgoing inspection.'
        );
    }

    // ─── Test 3: DB unique index enforcement ─────────────────────────────────

    /**
     * The unique index on (stage, entity_type, entity_id) must reject a direct
     * duplicate Inspection::create() with the same guard columns.
     * Pins the DB-layer guarantee independently of the listener fix.
     */
    public function test_db_unique_index_rejects_duplicate_outgoing_inspection_for_same_wo(): void
    {
        $base = [
            'inspection_number' => 'QC-P37-IDX-001',
            'stage'             => InspectionStage::Outgoing->value,
            'status'            => 'draft',
            'product_id'        => $this->product->id,
            'entity_type'       => InspectionEntityType::WorkOrder->value,
            'entity_id'         => $this->workOrder->id,
            'batch_quantity'    => 100,
            'sample_size'       => 13,
            'accept_count'      => 0,
            'reject_count'      => 1,
            'defect_count'      => 0,
        ];

        Inspection::create($base);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Same stage + entity_type + entity_id but different inspection_number.
        Inspection::create(array_merge($base, [
            'inspection_number' => 'QC-P37-IDX-002',
        ]));
    }

    // ─── Test 4: in_process + outgoing coexist per WO ────────────────────────

    /**
     * A WO legitimately has two inspections: one in_process and one outgoing.
     * The unique index must NOT prevent this — different stage = different key.
     */
    public function test_in_process_and_outgoing_inspections_coexist_for_same_wo(): void
    {
        $base = [
            'status'         => 'draft',
            'product_id'     => $this->product->id,
            'entity_type'    => InspectionEntityType::WorkOrder->value,
            'entity_id'      => $this->workOrder->id,
            'batch_quantity' => 100,
            'sample_size'    => 13,
            'accept_count'   => 0,
            'reject_count'   => 1,
            'defect_count'   => 0,
        ];

        $inProcess = Inspection::create(array_merge($base, [
            'inspection_number' => 'QC-P37-IP-001',
            'stage'             => InspectionStage::InProcess->value,
        ]));

        // Must NOT throw — different stage value = different composite key.
        $outgoing = Inspection::create(array_merge($base, [
            'inspection_number' => 'QC-P37-OG-001',
            'stage'             => InspectionStage::Outgoing->value,
        ]));

        $this->assertNotNull($inProcess->id);
        $this->assertNotNull($outgoing->id);

        $this->assertSame(
            2,
            Inspection::query()
                ->where('entity_type', InspectionEntityType::WorkOrder->value)
                ->where('entity_id', $this->workOrder->id)
                ->count(),
            'One in_process + one outgoing inspection for the same WO must both be storable.'
        );
    }
}
