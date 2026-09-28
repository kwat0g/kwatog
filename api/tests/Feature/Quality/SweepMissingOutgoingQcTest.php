<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Console\Commands\SweepMissingOutgoingQc;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * qc:sweep-missing-outgoing — the recovery surface for a stranded outgoing QC
 * handoff (lost queue event, or a stateful refusal whose cause was later
 * fixed). The sweep must repair what it can with the same creation contract
 * as TriggerOutgoingQC, escalate what it cannot, and distinguish
 * nothing-to-do from everything-failed in its exit code.
 */
class SweepMissingOutgoingQcTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->product = Product::create([
            'part_number' => 'SWEEP-001',
            'name' => 'Sweep test part',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '5.00',
            'is_active' => true,
        ]);

        $spec = InspectionSpec::create([
            'product_id' => $this->product->id,
            'is_active' => true,
            'version' => 1,
            'created_by' => $this->user->id,
        ]);
        InspectionSpecItem::create([
            'inspection_spec_id' => $spec->id,
            'parameter_name' => 'Outer diameter',
            'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm',
            'nominal_value' => '10.0000',
            'tolerance_min' => '9.9000',
            'tolerance_max' => '10.1000',
            'is_critical' => true,
        ]);
    }

    private function completedWoWithOutput(): WorkOrder
    {
        // The sweep's scope mirrors TriggerOutgoingQC's creation contract:
        // SO-linked (or NCR-rework) work orders only.
        $so = SalesOrder::factory()->create();
        $wo = WorkOrder::factory()->create([
            'product_id' => $this->product->id,
            'sales_order_id' => $so->id,
            'quantity_target' => 100,
            'quantity_produced' => 100,
            'quantity_good' => 100,
            'status' => 'completed',
            'planned_start' => now()->subDay(),
            'planned_end' => now(),
            'created_by' => $this->user->id,
        ]);
        WorkOrderOutput::create([
            'work_order_id' => $wo->id,
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'good_count' => 100,
            'reject_count' => 0,
        ]);

        return $wo;
    }

    public function test_repairs_a_completed_wo_whose_output_has_no_outgoing_inspection(): void
    {
        $wo = $this->completedWoWithOutput();

        $this->artisan(SweepMissingOutgoingQc::class)->assertSuccessful();

        $inspection = Inspection::query()
            ->where('stage', InspectionStage::Outgoing->value)
            ->where('entity_type', InspectionEntityType::WorkOrder->value)
            ->where('entity_id', $wo->id)
            ->first();
        $this->assertNotNull($inspection, 'the sweep must create the missing outgoing inspection');
        $this->assertSame(100, (int) $inspection->batch_quantity);
    }

    public function test_does_not_touch_a_wo_whose_output_already_has_outgoing_qc(): void
    {
        $wo = $this->completedWoWithOutput();
        $output = WorkOrderOutput::query()->where('work_order_id', $wo->id)->first();
        Inspection::query()->create([
            'inspection_number' => 'QC-SW-'.substr(uniqid(), -5),
            'stage' => InspectionStage::Outgoing->value,
            'status' => 'awaiting_review',
            'inspection_mode' => 'lot_checklist',
            'product_id' => $this->product->id,
            'entity_type' => InspectionEntityType::WorkOrder->value,
            'entity_id' => $wo->id,
            'work_order_output_id' => $output->id,
            'batch_quantity' => 100,
            'sample_size' => 5,
            'accept_count' => 1,
            'reject_count' => 2,
        ]);

        $this->artisan(SweepMissingOutgoingQc::class)->assertSuccessful();

        $this->assertSame(1, Inspection::query()
            ->where('stage', InspectionStage::Outgoing->value)
            ->where('entity_id', $wo->id)
            ->count(), 'the sweep must not duplicate covered output batches');
    }

    public function test_escalates_a_wo_that_cannot_be_repaired_and_notifies_the_qc_roles(): void
    {
        // No active spec: the stateful refusal the sweep must escalate, not swallow.
        InspectionSpec::query()->where('product_id', $this->product->id)->update(['is_active' => false]);
        $wo = $this->completedWoWithOutput();

        $this->artisan(SweepMissingOutgoingQc::class)->assertSuccessful();

        $this->assertSame(0, Inspection::query()
            ->where('stage', InspectionStage::Outgoing->value)
            ->where('entity_id', $wo->id)
            ->count());

        // NotificationService writes the database channel directly.
        $this->assertDatabaseHas('notifications', [
            'type' => 'chain.outgoing_qc_missing',
            'notifiable_id' => $this->user->id,
        ]);
    }

    public function test_in_progress_work_orders_are_out_of_scope(): void
    {
        $wo = $this->completedWoWithOutput();
        $wo->forceFill(['status' => 'in_progress'])->save();

        $this->artisan(SweepMissingOutgoingQc::class)->assertSuccessful();

        $this->assertSame(0, Inspection::query()
            ->where('stage', InspectionStage::Outgoing->value)
            ->where('entity_id', $wo->id)
            ->count());
    }

    public function test_a_clean_database_reports_success(): void
    {
        $this->artisan(SweepMissingOutgoingQc::class)->assertSuccessful();
        $this->assertSame(0, Inspection::query()->where('stage', InspectionStage::Outgoing->value)->count());
    }
}
