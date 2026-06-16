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
use App\Modules\Quality\Listeners\TriggerInProcessQC;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OGAMI-020 — In-process QC auto-trigger fires when a WO enters production,
 * seeds a spec-scaffolded inspection, and is idempotent per WO.
 */
class InProcessQcTriggerTest extends TestCase
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
            'part_number'     => 'IP-TEST-001',
            'name'            => 'In-Process Test Part',
            'unit_of_measure' => 'pcs',
            'standard_cost'   => '5.00',
            'is_active'       => true,
        ]);

        // Active spec with one critical dimension so the InspectionService path
        // (not just the fallback) seeds measurement rows.
        $spec = InspectionSpec::create([
            'product_id' => $this->product->id,
            'is_active'  => true,
            'version'    => 1,
            'created_by' => $this->user->id,
        ]);
        InspectionSpecItem::create([
            'inspection_spec_id' => $spec->id,
            'parameter_name'     => 'Outer Diameter',
            'parameter_type'     => 'dimensional',
            'unit_of_measure'    => 'mm',
            'nominal_value'      => '10.0000',
            'tolerance_min'      => '9.9000',
            'tolerance_max'      => '10.1000',
            'is_critical'        => true,
        ]);

        $this->wo = WorkOrder::create([
            'wo_number'         => 'WO-IP-0001',
            'product_id'        => $this->product->id,
            'quantity_target'   => 100,
            'quantity_produced' => 0,
            'quantity_good'     => 0,
            'quantity_rejected' => 0,
            'planned_start'     => now()->subDay(),
            'planned_end'       => now(),
            'status'            => 'in_progress',
            'created_by'        => $this->user->id,
        ]);

        $this->listener = app(TriggerInProcessQC::class);
    }

    private function event(string $to = 'in_progress'): WorkOrderStatusChanged
    {
        return new WorkOrderStatusChanged($this->wo, 'confirmed', $to);
    }

    public function test_transition_to_in_progress_creates_in_process_inspection(): void
    {
        $this->listener->handle($this->event());

        $insp = Inspection::query()
            ->where('stage', InspectionStage::InProcess->value)
            ->where('entity_type', InspectionEntityType::WorkOrder->value)
            ->where('entity_id', $this->wo->id)
            ->first();

        $this->assertNotNull($insp, 'in-process inspection must be created on WO start');
        $this->assertGreaterThan(0, $insp->measurements()->count(), 'spec must seed measurement rows');
    }

    public function test_non_in_progress_transition_does_nothing(): void
    {
        $this->listener->handle($this->event('paused'));

        $this->assertSame(0, Inspection::query()
            ->where('stage', InspectionStage::InProcess->value)
            ->where('entity_id', $this->wo->id)
            ->count());
    }

    public function test_idempotent_double_handle_creates_one(): void
    {
        $this->listener->handle($this->event());
        $this->listener->handle($this->event());

        $this->assertSame(1, Inspection::query()
            ->where('stage', InspectionStage::InProcess->value)
            ->where('entity_type', InspectionEntityType::WorkOrder->value)
            ->where('entity_id', $this->wo->id)
            ->count());
    }
}
