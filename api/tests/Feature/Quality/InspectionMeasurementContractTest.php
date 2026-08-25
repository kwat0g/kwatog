<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Exceptions\InspectionCertificateException;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Services\CoCService;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectionMeasurementContractTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private InspectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        $this->service = app(InspectionService::class);
    }

    public function test_tolerance_measurement_cannot_be_marked_pass_without_a_reading(): void
    {
        $inspection = $this->makeInspection();
        $measurement = $this->makeMeasurement($inspection, [
            'parameter_type' => 'dimensional',
            'tolerance_min' => '9.90',
            'tolerance_max' => '10.10',
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('requires a result derived from its measured value');

        $this->service->recordMeasurements($inspection, [
            $measurement->id => ['is_pass' => true],
        ], $this->user);
    }

    public function test_tolerance_measurement_rejects_a_contradictory_explicit_result(): void
    {
        $inspection = $this->makeInspection();
        $measurement = $this->makeMeasurement($inspection, [
            'parameter_type' => 'dimensional',
            'tolerance_min' => '9.90',
            'tolerance_max' => '10.10',
        ]);

        $this->expectException(BusinessRuleException::class);

        $this->service->recordMeasurements($inspection, [
            $measurement->id => [
                'measured_value' => '8.50',
                'is_pass' => true,
            ],
        ], $this->user);
    }

    public function test_foreign_measurement_ids_are_rejected_instead_of_ignored(): void
    {
        $inspection = $this->makeInspection();
        $foreignInspection = $this->makeInspection();
        $foreignMeasurement = $this->makeMeasurement($foreignInspection);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('do not belong to this inspection');

        $this->service->recordMeasurements($inspection, [
            $foreignMeasurement->id => ['is_pass' => true],
        ], $this->user);
    }

    public function test_functional_measurements_without_tolerances_use_manual_verdicts(): void
    {
        $inspection = $this->makeInspection();
        $measurement = $this->makeMeasurement($inspection, [
            'parameter_type' => 'functional',
            'tolerance_min' => null,
            'tolerance_max' => null,
        ]);

        $result = $this->service->recordMeasurements($inspection, [
            $measurement->id => ['is_pass' => true, 'notes' => 'Cycle test passed.'],
        ], $this->user);

        $this->assertSame(InspectionStatus::InProgress, $result->status);
        $this->assertTrue((bool) $measurement->fresh()->is_pass);
        $this->assertSame('Cycle test passed.', $measurement->fresh()->notes);
    }

    public function test_coc_eligibility_errors_have_stable_codes(): void
    {
        $inspection = $this->makeInspection();

        try {
            app(CoCService::class)->generateForInspection($inspection);
            $this->fail('A non-outgoing inspection must not generate a CoC.');
        } catch (InspectionCertificateException $exception) {
            $this->assertSame('COC_STAGE_INVALID', $exception->errorCode());
        }
    }

    private function makeInspection(InspectionStatus $status = InspectionStatus::Draft): Inspection
    {
        return Inspection::query()->create([
            'inspection_number' => 'QC-CONTRACT-'.uniqid(),
            'stage' => InspectionStage::InProcess,
            'status' => $status,
            'product_id' => Product::factory()->create()->id,
            'batch_quantity' => 10,
            'sample_size' => 1,
            'accept_count' => 0,
            'reject_count' => 1,
            'defect_count' => 0,
            'inspector_id' => $this->user->id,
            'started_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeMeasurement(Inspection $inspection, array $overrides = []): InspectionMeasurement
    {
        return InspectionMeasurement::query()->create(array_merge([
            'inspection_id' => $inspection->id,
            'sample_index' => 1,
            'parameter_name' => 'Contract parameter',
            'parameter_type' => 'visual',
            'is_critical' => false,
            'is_pass' => null,
        ], $overrides));
    }
}
