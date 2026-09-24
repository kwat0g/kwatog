<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\CalibrationStatus;
use App\Modules\Quality\Enums\InspectionParameterType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Models\CalibrationRecord;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Resources\InspectionResource;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectionCalibrationEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private User $inspector;
    private Product $product;
    private InspectionSpec $spec;
    private InspectionSpecItem $item;
    private InspectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        $this->inspector = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->product = Product::factory()->create(['part_number' => 'CAL-TEST-001']);
        $this->spec = InspectionSpec::create([
            'product_id' => $this->product->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $this->inspector->id,
        ]);
        $this->item = InspectionSpecItem::create([
            'inspection_spec_id' => $this->spec->id,
            'parameter_name' => 'Bore Diameter',
            'parameter_type' => InspectionParameterType::Dimensional->value,
            'unit_of_measure' => 'mm',
            'nominal_value' => '25.0000',
            'tolerance_min' => '24.9500',
            'tolerance_max' => '25.0500',
            'is_critical' => true,
            'sort_order' => 1,
        ]);

        $this->service = app(InspectionService::class);
    }

    public function test_inspection_can_record_and_expose_active_calibration_equipment(): void
    {
        $cal = CalibrationRecord::create([
            'equipment_code' => 'CALIPER-001',
            'name' => 'Digital Vernier Caliper',
            'last_calibration_date' => now()->subMonth()->toDateString(),
            'next_calibration_date' => now()->addMonths(5)->toDateString(),
            'frequency_days' => 180,
            'status' => CalibrationStatus::Active,
        ]);

        $inspection = $this->service->create([
            'stage' => InspectionStage::InProcess->value,
            'product_id' => $this->product->id,
            'batch_quantity' => 10,
            'calibration_record_id' => $cal->id,
        ], $this->inspector);

        $this->assertSame($cal->id, $inspection->calibration_record_id);

        $payload = (new InspectionResource($this->service->show($inspection)))->resolve();
        $this->assertNotNull($payload['calibration_record']);
        $this->assertSame($cal->hash_id, $payload['calibration_record']['id']);
        $this->assertSame('CALIPER-001', $payload['calibration_record']['equipment_code']);
        $this->assertSame('active', $payload['calibration_record']['status']);
    }

    public function test_cannot_create_inspection_with_overdue_equipment(): void
    {
        $overdue = CalibrationRecord::create([
            'equipment_code' => 'MICROMETER-999',
            'name' => 'Outside Micrometer',
            'last_calibration_date' => now()->subYear()->toDateString(),
            'next_calibration_date' => now()->subMonth()->toDateString(),
            'frequency_days' => 180,
            'status' => CalibrationStatus::Overdue,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('overdue and cannot be used');

        $this->service->create([
            'stage' => InspectionStage::InProcess->value,
            'product_id' => $this->product->id,
            'batch_quantity' => 10,
            'calibration_record_id' => $overdue->id,
        ], $this->inspector);
    }

    public function test_completion_is_blocked_if_linked_equipment_became_overdue(): void
    {
        $cal = CalibrationRecord::create([
            'equipment_code' => 'BORE-GAUGE-002',
            'name' => 'Bore Gauge',
            'last_calibration_date' => now()->subMonth()->toDateString(),
            'next_calibration_date' => now()->addDays(5)->toDateString(),
            'frequency_days' => 180,
            'status' => CalibrationStatus::Active,
        ]);

        $inspection = $this->service->create([
            'stage' => InspectionStage::InProcess->value,
            'product_id' => $this->product->id,
            'batch_quantity' => 1,
            'calibration_record_id' => $cal->id,
        ], $this->inspector);

        $m = $inspection->measurements()->firstOrFail();
        $this->service->recordMeasurements($inspection, [
            $m->id => ['measured_value' => '25.0000'],
        ], $this->inspector);

        // Equipment becomes overdue before completion
        $cal->update(['status' => CalibrationStatus::Overdue]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('measuring equipment BORE-GAUGE-002 is overdue');

        $this->service->complete($inspection->fresh(), $this->inspector);
    }
}
