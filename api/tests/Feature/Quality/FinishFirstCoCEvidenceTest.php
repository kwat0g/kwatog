<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Exceptions\InspectionCertificateException;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionPieceReading;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\CoCService;
use App\Modules\Quality\Services\WorkOrderFinalInspectionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FinishFirstCoCEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        config(['inspection.finish_first_wo_enabled' => true, 'inspection.outgoing_confirmed_checks_enabled' => false]);
    }

    public function test_full_piece_certificate_uses_actual_readings_after_work_order_closes(): void
    {
        $inspection = $this->passedInspection();
        $inspection->workOrderOutput->workOrder->forceFill(['status' => 'closed'])->save();
        $service = app(CoCService::class);
        $this->assertNull($service->unavailableReason($inspection->fresh()));
        $method = new \ReflectionMethod(CoCService::class, 'buildPayload');
        [, $payload] = $method->invoke($service, $inspection->fresh(), null);
        $this->assertSame(2, $payload['critical_measurements'][0]['sample_n']);
        $this->assertSame('9.9500', $payload['critical_measurements'][0]['min_actual']);
        $this->assertSame('10.0500', $payload['critical_measurements'][0]['max_actual']);
        $this->assertTrue($payload['critical_measurements'][0]['pass']);
        $this->assertStringStartsWith('%PDF', $service->buildBinaryForInspection($inspection->fresh())['contents']);
    }

    public function test_confirmed_checks_certificate_shows_targets_as_references_without_numeric_results(): void
    {
        $inspection = $this->passedInspection(true);
        $inspection->workOrderOutput->workOrder->forceFill(['status' => 'closed'])->save();
        $service = app(CoCService::class);
        $this->assertNull($service->unavailableReason($inspection->fresh()));
        $method = new \ReflectionMethod(CoCService::class, 'buildPayload');
        [, $payload] = $method->invoke($service, $inspection->fresh(), null);

        $this->assertSame('confirmed_checks', $payload['evidence_method']);
        $this->assertSame('Numeric measurements were not recorded for this inspection.', $payload['measurement_disclosure']);
        $this->assertSame([], $payload['critical_measurements']);
        $this->assertSame('Diameter', $payload['confirmed_requirements'][0]['parameter']);
        $this->assertSame('10.0000', $payload['confirmed_requirements'][0]['nominal']);
        $this->assertSame('9.9000', $payload['confirmed_requirements'][0]['minimum']);
        $this->assertSame('10.1000', $payload['confirmed_requirements'][0]['maximum']);

        $rendered = view('pdf.coc', $payload)->render();
        $this->assertStringContainsString($payload['measurement_disclosure'], $rendered);
        $this->assertStringContainsString('10.0000', $rendered);
        $this->assertStringContainsString($payload['inspector_name'], $rendered);
        $this->assertStringContainsString($payload['reviewer_name'], $rendered);
        $this->assertStringNotContainsString('Actual Range', $rendered);
        $this->assertStringNotContainsString('9.9500', $rendered);
        $this->assertStringNotContainsString('10.0500', $rendered);
        $this->assertStringNotContainsString('ANSI/ASQ Z1.4', $rendered);
        $this->assertStringNotContainsString('Sample Plan', $rendered);
    }

    public function test_confirmed_checks_certificate_still_requires_released_output(): void
    {
        $inspection = $this->passedInspection(true);
        $inspection->forceFill(['accepted_quantity' => 0])->save();

        $this->assertSame('CoC is unavailable while the output batch is on QC hold.',
            app(CoCService::class)->unavailableReason($inspection->fresh()));
    }

    public function test_missing_piece_reading_blocks_certificate(): void
    {
        $inspection = $this->passedInspection();
        InspectionPieceReading::query()->where('inspection_id', $inspection->id)->where('piece_number', 2)->delete();
        $this->expectException(InspectionCertificateException::class);
        $this->expectExceptionMessage('Enter every required size reading');
        app(CoCService::class)->buildBinaryForInspection($inspection->fresh());
    }

    public function test_contradictory_piece_reading_blocks_certificate(): void
    {
        $inspection = $this->passedInspection();
        InspectionPieceReading::query()->where('inspection_id', $inspection->id)->where('piece_number', 2)
            ->update(['measured_value' => '20.0000', 'is_pass' => true]);
        $this->expectException(InspectionCertificateException::class);
        $this->expectExceptionMessage('saved size reading no longer matches');
        app(CoCService::class)->buildBinaryForInspection($inspection->fresh());
    }

    private function passedInspection(bool $confirmedChecks = false): Inspection
    {
        config(['inspection.outgoing_confirmed_checks_enabled' => $confirmedChecks]);
        $maker = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $checker = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $product = Product::factory()->create(['unit_of_measure' => 'PCS']);
        $wo = WorkOrder::factory()->create(['product_id' => $product->id, 'created_by' => $maker->id,
            'quantity_target' => 2, 'quantity_produced' => 2, 'quantity_good' => 2, 'status' => 'completed']);
        WorkOrderOutput::create(['work_order_id' => $wo->id, 'recorded_by' => $maker->id,
            'recorded_at' => now(), 'good_count' => 2, 'reject_count' => 0, 'batch_code' => 'FF-COC']);
        $spec = InspectionSpec::create(['product_id' => $product->id, 'version' => 1,
            'is_active' => true, 'created_by' => $maker->id]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Diameter',
            'parameter_type' => 'dimensional', 'unit_of_measure' => 'mm', 'nominal_value' => '10.0000', 'tolerance_min' => '9.9000',
            'tolerance_max' => '10.1000', 'is_critical' => true]);
        $service = app(WorkOrderFinalInspectionService::class);
        $inspection = $service->ensureForCompletedWorkOrder($wo, $maker)->first();
        $requirement = $inspection->measurements()->firstOrFail();
        $url = '/api/v1/quality/work-orders/'.$wo->hash_id.'/final-inspection';
        $group = $this->actingAs($maker)->getJson($url)->assertSuccessful()->json('data');
        $group = $this->patchJson($url.'/evidence', [
            'manifest' => $group['manifest'], 'inspection_id' => $inspection->hash_id,
            'expected_result_version' => 0, 'defective_quantity' => 0, 'pieces' => [],
            ...($confirmedChecks ? ['readings' => []] : ['readings' => [
                ['piece_number' => 1, 'requirement_id' => $requirement->hash_id, 'measured_value' => '9.9500'],
                ['piece_number' => 2, 'requirement_id' => $requirement->hash_id, 'measured_value' => '10.0500'],
            ]]),
        ])->assertSuccessful()->json('data');
        $group = $this->postJson($url.'/submit', ['manifest' => $group['manifest'],
            'batches' => [['inspection_id' => $inspection->hash_id, 'coverage_confirmed' => true, 'inspected_quantity' => 2,
                ...($confirmedChecks ? ['checks_confirmed' => true] : [])]],
        ])->assertSuccessful()->json('data');
        $this->actingAs($checker)->postJson($url.'/review', ['manifest' => $group['manifest']])->assertSuccessful();

        return $inspection->fresh();
    }
}
