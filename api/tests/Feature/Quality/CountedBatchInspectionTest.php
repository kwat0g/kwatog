<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\CoCService;
use App\Modules\Quality\Services\DefectParetoService;
use App\Modules\Quality\Services\InspectionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\HasPassedInProcessQc;
use Tests\TestCase;

class CountedBatchInspectionTest extends TestCase
{
    use HasPassedInProcessQc;
    use RefreshDatabase;

    private User $maker;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->maker = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
    }

    public function test_counted_contract_is_stored_separately_from_legacy_piece_results(): void
    {
        $this->assertTrue(Schema::hasColumn('inspections', 'counted_capture'));
    }

    private function inspection(): Inspection
    {
        $inspection = Inspection::create([
            'inspection_number' => 'COUNT-'.substr(uniqid(), -6), 'stage' => 'in_process', 'status' => 'draft',
            'product_id' => Product::factory()->create()->id, 'batch_quantity' => 200, 'sample_size' => 5,
            'accept_count' => 1, 'reject_count' => 2, 'inspection_mode' => 'lot_checklist',
            'inspector_id' => $this->maker->id, 'capture_method' => 'measurement',
        ]);
        $inspection->forceFill(['counted_capture' => ['version' => 1, 'required_quantity' => 5, 'result_policy' => 'zero_defects']])->save();
        InspectionMeasurement::create(['inspection_id' => $inspection->id, 'sample_index' => 1,
            'parameter_name' => 'Appearance', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No cracks', 'is_critical' => false]);

        return $inspection;
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['expected_result_version' => 0, 'coverage_confirmed' => true,
            'inspected_quantity' => 5, 'sample_defect_count' => 0, 'measurements' => []], $extra);
    }

    public function test_zero_defects_pass_without_manufacturing_attribute_answers(): void
    {
        $inspection = $this->inspection();
        $this->actingAs($this->maker)->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $this->payload())
            ->assertSuccessful()->assertJsonPath('data.recorded_result', 'passed');
        $this->assertNull($inspection->measurements()->sole()->item_result);
        $this->assertNull($inspection->measurements()->sole()->is_pass);
    }

    public function test_one_observed_defect_fails_even_with_legacy_acceptance_allowance(): void
    {
        $inspection = $this->inspection();
        $this->actingAs($this->maker)->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result',
            $this->payload(['sample_defect_count' => 1, 'problem_description' => 'One cracked sample']))
            ->assertSuccessful()->assertJsonPath('data.recorded_result', 'failed')->assertJsonPath('data.accepted_quantity', 0);
        $this->assertSame(1, $inspection->fresh()->sample_defect_count);
    }

    public function test_defect_count_requires_problem_and_cannot_exceed_checked_quantity(): void
    {
        $inspection = $this->inspection();
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result';
        $this->actingAs($this->maker)->postJson($url, $this->payload(['sample_defect_count' => 1]))->assertUnprocessable();
        $this->postJson($url, $this->payload(['sample_defect_count' => 6, 'problem_description' => 'Cracks']))->assertUnprocessable();
        $this->assertSame('draft', $inspection->fresh()->status->value);
    }

    public function test_required_size_cannot_be_omitted_and_out_of_limit_size_cannot_pass(): void
    {
        $inspection = $this->inspection();
        $size = InspectionMeasurement::create(['inspection_id' => $inspection->id, 'sample_index' => 1,
            'parameter_name' => 'Size', 'parameter_type' => 'dimensional', 'unit_of_measure' => 'mm',
            'acceptance_criterion' => 'Within drawing', 'tolerance_min' => '9.9000', 'tolerance_max' => '10.1000', 'is_critical' => true]);
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result';
        $this->actingAs($this->maker)->postJson($url, $this->payload())->assertUnprocessable();
        $this->postJson($url, $this->payload(['measurements' => [['id' => $size->hash_id, 'measured_value' => '11.0000']]]))->assertUnprocessable();
        $this->postJson($url, $this->payload(['measurements' => [['id' => $size->hash_id, 'measured_value' => '11.0000']],
            'sample_defect_count' => 1, 'problem_description' => 'Diameter too large']))
            ->assertSuccessful()->assertJsonPath('data.recorded_result', 'failed');
    }

    public function test_counted_contract_rejects_client_result_and_stale_version(): void
    {
        $inspection = $this->inspection();
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result';
        $this->actingAs($this->maker)->postJson($url, $this->payload(['result' => 'passed']))->assertUnprocessable();
        $this->postJson($url, $this->payload(['expected_result_version' => 9]))->assertUnprocessable();
    }

    public function test_requirement_failure_at_zero_defects_and_incomplete_coverage(): void
    {
        $inspection = $this->inspection();
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result';
        $this->actingAs($this->maker)->postJson($url, $this->payload(['inspected_quantity' => 4]))->assertUnprocessable();
        $this->postJson($url, $this->payload(['requirement_problem' => true, 'problem_description' => 'Missing drawing reference']))
            ->assertSuccessful()->assertJsonPath('data.recorded_result', 'failed');
    }

    public function test_pinned_policy_and_legacy_submission_cannot_bypass_counted_evidence(): void
    {
        $inspection = $this->inspection();
        $row = $inspection->measurements()->sole();
        $this->actingAs($this->maker)->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/measurements',
            ['measurements' => [['id' => $row->hash_id, 'is_pass' => true]]])->assertUnprocessable();
        $this->expectException(BusinessRuleException::class);
        $inspection->forceFill(['counted_capture' => ['version' => 1, 'required_quantity' => 1]])->save();
    }

    public function test_counted_source_cannot_be_reassigned_after_coverage_is_pinned(): void
    {
        $inspection = $this->inspection();
        foreach (['product_id', 'item_id', 'item_quality_plan_id', 'return_request_item_id', 'work_order_output_id', 'grn_item_id', 'inspection_spec_revision_id'] as $field) {
            try {
                $inspection->fresh()->forceFill([$field => 999999])->save();
                $this->fail("Pinned source field {$field} was changed.");
            } catch (BusinessRuleException $error) {
                $this->assertSame('Pinned counted inspection coverage and source cannot change.', $error->getMessage());
            }
        }
    }

    public function test_material_lot_uses_affected_lot_confirmation_and_preserves_source_units(): void
    {
        $inspection = Inspection::create([
            'inspection_number' => 'COUNT-LOT-'.substr(uniqid(), -6), 'stage' => 'in_process', 'status' => 'draft',
            'product_id' => Product::factory()->create()->id, 'batch_quantity' => 1, 'sample_size' => 1,
            'accept_count' => 0, 'reject_count' => 1, 'inspection_mode' => 'lot_checklist',
            'inspector_id' => $this->maker->id, 'capture_method' => 'checklist', 'sampling_basis' => 'lot',
            'source_quantity' => '200.500', 'source_uom_code' => 'kg',
            'counted_capture' => ['version' => 1, 'required_quantity' => 1, 'result_policy' => 'zero_defects'],
        ]);
        InspectionMeasurement::create(['inspection_id' => $inspection->id, 'sample_index' => 1,
            'parameter_name' => 'Material appearance', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No contamination']);
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result';
        $payload = ['expected_result_version' => 0, 'coverage_confirmed' => true, 'inspected_quantity' => 1,
            'physical_defects_confirmed' => true, 'problem_description' => 'Contaminated resin lot', 'measurements' => []];
        $this->actingAs($this->maker)->postJson($url, $payload + ['sample_defect_count' => 1])->assertUnprocessable();
        $this->postJson($url, $payload)->assertSuccessful()->assertJsonPath('data.recorded_result', 'failed')
            ->assertJsonPath('data.sample_defect_count', 1)->assertJsonPath('data.source_quantity', '200.500')
            ->assertJsonPath('data.source_uom_code', 'kg');
    }

    public function test_new_outgoing_contract_pins_full_coverage_and_bounded_dimensional_sample(): void
    {
        config(['inspection.counted_capture_enabled' => true]);
        $product = Product::factory()->create(['unit_of_measure' => 'PCS']);
        $spec = InspectionSpec::create(['product_id' => $product->id, 'version' => 1, 'is_active' => true, 'created_by' => $this->maker->id]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id,
            'parameter_name' => 'Appearance', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No cracks', 'is_critical' => false]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id,
            'parameter_name' => 'Size', 'parameter_type' => 'dimensional', 'tolerance_min' => '9.9000', 'tolerance_max' => '10.1000', 'is_critical' => false]);
        $wo = WorkOrder::factory()->create(['product_id' => $product->id, 'status' => 'in_progress']);
        $this->passInProcessQc($wo);
        $output = WorkOrderOutput::create(['work_order_id' => $wo->id,
            'recorded_by' => $this->maker->id, 'recorded_at' => now(), 'good_count' => 200, 'reject_count' => 0]);
        $inspection = app(InspectionService::class)->createForOutput($output, $wo, $this->maker);
        $this->assertSame(200, $inspection->counted_capture['required_quantity']);
        $this->assertLessThan(20, $inspection->measurements()->count());
        $readings = $inspection->measurements()->get()->filter(fn ($row) => $row->requiresSizeReading())
            ->map(fn ($row) => ['id' => $row->hash_id, 'measured_value' => '10.0000'])->values()->all();
        $this->actingAs($this->maker)->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result',
            $this->payload(['inspected_quantity' => 200, 'measurements' => $readings]))
            ->assertSuccessful()->assertJsonPath('data.status', 'awaiting_review');
        $checker = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $inspection = app(InspectionService::class)->review($inspection->fresh(), 'passed', 'Checked full coverage and size readings.', $checker, 1);
        $certificate = app(CoCService::class)->buildBinaryForInspection($inspection);
        $this->assertStringStartsWith('%PDF', $certificate['contents']);
        DB::table('inspections')->where('id', $inspection->id)->update(['sample_defect_count' => 1]);
        $this->assertNotNull(app(CoCService::class)->unavailableReason($inspection->fresh()));
    }

    public function test_counted_pareto_counts_defective_pieces_once_despite_multiple_failed_readings(): void
    {
        $inspection = $this->inspection();
        foreach (['Diameter', 'Length'] as $parameter) {
            InspectionMeasurement::create(['inspection_id' => $inspection->id, 'sample_index' => 1,
                'parameter_name' => $parameter, 'parameter_type' => 'dimensional', 'is_pass' => false]);
        }
        $inspection->forceFill(['status' => 'failed', 'sample_defect_count' => 3, 'completed_at' => now()])->save();
        $result = app(DefectParetoService::class)->run(['from' => now()->toDateString(), 'to' => now()->toDateString()]);
        $this->assertSame(3, $result['total_defects']);
        $this->assertSame('Counted batch defects', $result['rows'][0]['parameter_name']);
    }
}
