<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Listeners\TriggerInProcessQC;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cancelled inspection slots are reusable (migration 0565 + guard updates).
 *
 * The pre-0565 partial unique indexes counted CANCELLED rows, so a WO whose
 * in-process inspection was cancelled (wrong spec opened in error, duplicate
 * keyed by mistake) could never receive in-process QC again: the listener's
 * exists() check found the cancelled row and skipped, and create()'s reuse
 * check returned it. A cancelled inspection is a decision, not an open gate —
 * its slot must be releasable while the audit row survives.
 */
class CancelledInspectionSlotReuseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private WorkOrder $wo;

    private TriggerInProcessQC $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->product = Product::create([
            'part_number' => 'CSR-TEST-001',
            'name' => 'Cancelled slot test part',
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
            'parameter_name' => 'Outer Diameter',
            'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm',
            'nominal_value' => '10.0000',
            'tolerance_min' => '9.9000',
            'tolerance_max' => '10.1000',
            'is_critical' => true,
        ]);

        $this->wo = WorkOrder::create([
            'wo_number' => 'WO-CSR-0001',
            'product_id' => $this->product->id,
            'quantity_target' => 100,
            'quantity_produced' => 0,
            'quantity_good' => 0,
            'quantity_rejected' => 0,
            'planned_start' => now()->subDay(),
            'planned_end' => now(),
            'status' => 'in_progress',
            'created_by' => $this->user->id,
        ]);

        $this->listener = app(TriggerInProcessQC::class);
    }

    private function cancelInProcess(): Inspection
    {
        $this->listener->handle(new WorkOrderStatusChanged($this->wo, 'confirmed', 'in_progress'));

        $created = Inspection::query()
            ->where('stage', InspectionStage::InProcess->value)
            ->where('entity_id', $this->wo->id)
            ->firstOrFail();

        return app(InspectionService::class)->cancel(
            $created,
            'Opened against the wrong spec revision.',
            $this->user,
        );
    }

    public function test_listener_recreates_in_process_after_the_previous_was_cancelled(): void
    {
        $cancelled = $this->cancelInProcess();
        $this->wo->refresh();
        $this->wo->forceFill(['status' => 'in_progress'])->save();

        // Reset the idempotency memory: the WO re-enters in_progress (e.g. the
        // pause/resume replay), and the listener runs again.
        $this->listener->handle(new WorkOrderStatusChanged($this->wo, 'paused', 'in_progress'));

        $active = Inspection::query()
            ->where('stage', InspectionStage::InProcess->value)
            ->where('entity_id', $this->wo->id)
            ->where('status', '!=', InspectionStatus::Cancelled->value)
            ->get();

        $this->assertCount(1, $active, 'exactly one live in-process inspection after re-trigger');
        $this->assertNotSame($cancelled->id, $active->first()->id, 'the replacement must be a new row, not the cancelled one');
    }

    public function test_create_returns_a_fresh_inspection_not_the_cancelled_row(): void
    {
        $cancelled = $this->cancelInProcess();

        $fresh = app(InspectionService::class)->create([
            'stage' => InspectionStage::InProcess->value,
            'product_id' => $this->product->id,
            'batch_quantity' => 100,
            'entity_type' => InspectionEntityType::WorkOrder->value,
            'entity_id' => $this->wo->id,
        ], $this->user);

        $this->assertNotSame($cancelled->id, $fresh->id);
        $this->assertSame(InspectionStatus::Draft->value, $fresh->status->value);
    }

    public function test_cancelled_row_survives_for_the_audit_trail(): void
    {
        $cancelled = $this->cancelInProcess();

        $this->listener->handle(new WorkOrderStatusChanged($this->wo, 'paused', 'in_progress'));

        $this->assertDatabaseHas('inspections', [
            'id' => $cancelled->id,
            'status' => InspectionStatus::Cancelled->value,
        ]);
    }
}
