<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Listeners\TriggerOutgoingQC;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REC-07 — Re-inspection after rework / replacement WO (IATF §8.7.1.4).
 *
 * A rework or replacement WO is created by NcrService::close() carrying a
 * parent_ncr_id but NO sales_order_id. Previously TriggerOutgoingQC skipped
 * any WO without sales_order_id, so reworked parts shipped with an auto-CoC
 * and zero actual re-measurement — the most audit-exposed hole in the quality
 * spine. The listener now also triggers for WOs carrying parent_ncr_id.
 */
class QualityReworkReinspectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;
    private TriggerOutgoingQC $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $this->product = Product::factory()->create();
        $this->listener = app(TriggerOutgoingQC::class);
    }

    private function outgoingInspectionCount(WorkOrder $wo): int
    {
        return Inspection::query()
            ->where('stage', InspectionStage::Outgoing->value)
            ->where('entity_type', InspectionEntityType::WorkOrder->value)
            ->where('entity_id', $wo->id)
            ->count();
    }

    public function test_completed_rework_wo_triggers_outgoing_reinspection(): void
    {
        // A rework WO as NcrService::close() builds it: parent_ncr_id set,
        // sales_order_id null (it re-verifies existing affected units).
        $ncr = NonConformanceReport::factory()->create([
            'product_id'        => $this->product->id,
            'affected_quantity' => 50,
        ]);

        $reworkWo = WorkOrder::create([
            'wo_number'         => 'WO-T-'.substr(uniqid(), -8),
            'product_id'        => $this->product->id,
            'sales_order_id'    => null,
            'parent_ncr_id'     => $ncr->id,
            'quantity_target'   => 50,
            'quantity_produced' => 50,
            'quantity_good'     => 50,
            'quantity_rejected' => 0,
            'planned_start'     => now()->subDay(),
            'planned_end'       => now(),
            'status'            => 'completed',
            'created_by'        => $this->user->id,
        ]);

        $this->listener->handle(new WorkOrderCompleted($reworkWo));

        $this->assertSame(
            1,
            $this->outgoingInspectionCount($reworkWo),
            'A completed rework WO (parent_ncr_id set) must get an outgoing re-inspection.'
        );

        // Idempotent — a second delivery of the event must not duplicate it.
        $this->listener->handle(new WorkOrderCompleted($reworkWo));
        $this->assertSame(1, $this->outgoingInspectionCount($reworkWo));
    }

    public function test_internal_wo_without_so_or_ncr_is_still_skipped(): void
    {
        // A genuinely internal WO — no customer order and not born from an NCR.
        // The guard must still skip it so we don't over-trigger outgoing QC.
        $internalWo = WorkOrder::create([
            'wo_number'         => 'WO-T-'.substr(uniqid(), -8),
            'product_id'        => $this->product->id,
            'sales_order_id'    => null,
            'parent_ncr_id'     => null,
            'quantity_target'   => 20,
            'quantity_produced' => 20,
            'quantity_good'     => 20,
            'quantity_rejected' => 0,
            'planned_start'     => now()->subDay(),
            'planned_end'       => now(),
            'status'            => 'completed',
            'created_by'        => $this->user->id,
        ]);

        $this->listener->handle(new WorkOrderCompleted($internalWo));

        $this->assertSame(
            0,
            $this->outgoingInspectionCount($internalWo),
            'A WO with neither sales_order_id nor parent_ncr_id must not be inspected.'
        );
    }
}
