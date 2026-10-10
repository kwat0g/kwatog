<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChecklistSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        foreach (['quality.inspections.manage', 'quality.inspections.view', 'quality.inspections.review'] as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'quality']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->maker = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $this->actingAs($this->maker);
    }

    private function inspection(InspectionStage $stage): Inspection
    {
        $inspection = Inspection::create([
            'inspection_number' => 'QC-C-'.substr(uniqid(), -7), 'stage' => $stage, 'status' => 'draft',
            'capture_method' => 'checklist', 'inspection_mode' => 'lot_checklist',
            'source_quantity' => '100.000', 'source_uom_code' => 'PCS', 'sampling_basis' => 'piece',
            'product_id' => Product::factory()->create()->id, 'batch_quantity' => 100, 'sample_size' => 5,
            'accept_count' => 0, 'reject_count' => 1, 'inspector_id' => $this->maker->id,
        ]);
        foreach (['Surface', 'Color', 'Fit'] as $name) {
            InspectionMeasurement::create(['inspection_id' => $inspection->id, 'sample_index' => 1,
                'parameter_name' => $name, 'parameter_type' => null, 'acceptance_criterion' => 'Matches approved sample.',
                'is_critical' => $name === 'Fit']);
        }

        return $inspection;
    }

    private function payload(Inspection $inspection): array
    {
        return ['expected_result_version' => 0, 'coverage_confirmed' => true,
            'items' => $inspection->measurements()->get()->map(fn ($row) => ['id' => $row->hash_id, 'result' => 'passed'])->all()];
    }

    public function test_all_five_stages_pass_without_measurements_or_count_input(): void
    {
        foreach (InspectionStage::cases() as $stage) {
            $inspection = $this->inspection($stage);
            $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $this->payload($inspection))
                ->assertSuccessful()->assertJsonPath('data.recorded_result', 'passed')
                ->assertJsonPath('data.result_version', 1);
            $this->assertSame(0, $inspection->fresh()->sample_defect_count);
            $this->assertSame(5, $inspection->fresh()->inspected_quantity);
            $this->assertSame(0, $inspection->measurements()->whereNotNull('measured_value')->count());
        }
    }

    public function test_validated_string_numbers_are_normalized_at_the_http_boundary(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', [
            ...$this->payload($inspection), 'expected_result_version' => '0',
            'coverage_confirmed' => '1', 'inspected_quantity' => '5',
        ])->assertSuccessful()->assertJsonPath('data.result_version', 1);
    }

    public function test_incomplete_or_stale_submission_does_not_partially_save(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $payload = $this->payload($inspection);
        array_pop($payload['items']);
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $payload)->assertUnprocessable();
        $this->assertSame(0, $inspection->measurements()->whereNotNull('item_result')->count());
        $payload = $this->payload($inspection);
        $payload['expected_result_version'] = 2;
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $payload)->assertUnprocessable();
        $this->assertSame(0, $inspection->fresh()->result_version);
    }

    public function test_one_piece_with_two_failed_checks_stays_one_defect_and_fail(): void
    {
        $inspection = $this->inspection(InspectionStage::Incoming);
        $payload = $this->payload($inspection);
        foreach ([0, 2] as $index) {
            $payload['items'][$index]['result'] = 'failed';
            $payload['items'][$index]['problem_description'] = 'One scratched piece does not fit.';
        }
        $payload['physical_defects_confirmed'] = true;
        $payload['sample_defect_count'] = 1;
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $payload)
            ->assertSuccessful()->assertJsonPath('data.recorded_result', 'failed');
        $this->assertSame(1, $inspection->fresh()->sample_defect_count);
    }

    public function test_document_failure_needs_no_fabricated_piece_count(): void
    {
        $inspection = $this->inspection(InspectionStage::Incoming);
        $payload = $this->payload($inspection);
        $payload['items'][0]['result'] = 'failed';
        $payload['items'][0]['problem_description'] = 'Required certificate missing.';
        $payload['physical_defects_confirmed'] = false;
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $payload)
            ->assertSuccessful()->assertJsonPath('data.recorded_result', 'failed');
        $this->assertSame(0, $inspection->fresh()->sample_defect_count);
    }

    public function test_prohibited_measurement_and_foreign_check_leave_no_partial_results(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $other = $this->inspection(InspectionStage::Incoming);
        $payload = $this->payload($inspection);
        $payload['items'][1]['id'] = $other->measurements()->first()->hash_id;
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $payload)->assertUnprocessable();
        $this->assertSame(0, $inspection->measurements()->whereNotNull('item_result')->count());
        $payload = $this->payload($inspection);
        $payload['items'][0]['measured_value'] = 10;
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $payload)->assertUnprocessable();
        $this->assertSame(0, $inspection->fresh()->result_version);
    }

    public function test_batch_only_problem_cannot_be_hidden_as_all_pass(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $payload = [...$this->payload($inspection), 'physical_defects_confirmed' => false,
            'problem_description' => 'Physical damage outside checklist.'];
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $payload)->assertUnprocessable();
        $this->assertSame(0, $inspection->measurements()->whereNotNull('item_result')->count());
    }

    public function test_draft_advances_version_and_stale_cancel_is_refused(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $payload = $this->payload($inspection);
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/item-results', [
            'items' => [$payload['items'][0]], 'expected_result_version' => 0, 'submit' => false])
            ->assertSuccessful()->assertJsonPath('data.result_version', 1);
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/cancel', [
            'reason' => 'Stale cancellation', 'expected_result_version' => 0])->assertUnprocessable();
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/cancel', [
            'reason' => 'Current cancellation', 'expected_result_version' => 1])
            ->assertSuccessful()->assertJsonPath('data.result_version', 2);
    }

    public function test_size_readings_follow_pinned_limits_for_every_stage(): void
    {
        foreach (InspectionStage::cases() as $stage) {
            $inspection = $this->inspection($stage);
            $row = $inspection->measurements()->first();
            DB::table('inspection_measurements')->where('id', $row->id)->update(['parameter_type' => 'dimensional', 'unit_of_measure' => 'mm',
                'nominal_value' => '10.0000', 'tolerance_min' => '9.9000', 'tolerance_max' => '10.1000']);
            $payload = $this->payload($inspection);
            $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $payload)->assertUnprocessable();
            $this->assertSame(0, $inspection->fresh()->result_version);
            $payload['items'][0]['measured_value'] = '10.1';
            $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $payload)
                ->assertSuccessful()->assertJsonPath('data.recorded_result', 'passed');
            $this->assertSame('10.1000', $row->fresh()->measured_value);
            $this->assertTrue($row->fresh()->is_pass);
            $this->assertSame(1, $inspection->measurements()->whereNotNull('measured_value')->count());
        }
    }

    public function test_invalid_or_contradictory_size_readings_never_partially_save(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $row = $inspection->measurements()->first();
        DB::table('inspection_measurements')->where('id', $row->id)->update(['parameter_type' => 'dimensional', 'nominal_value' => '10.0000',
            'tolerance_min' => '9.9000', 'tolerance_max' => '10.1000']);
        foreach (['10.10001', '1e20', '999999999', '10.2'] as $value) {
            $payload = $this->payload($inspection);
            $payload['items'][0]['measured_value'] = $value;
            $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $payload)->assertUnprocessable();
            $this->assertNull($row->fresh()->measured_value);
            $this->assertSame(0, $inspection->fresh()->result_version);
        }
        $payload['items'][0]['result'] = 'failed';
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $payload)->assertUnprocessable();
        $payload['items'][0]['problem_description'] = 'Diameter exceeds the approved limit.';
        $payload['physical_defects_confirmed'] = false;
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $payload)->assertUnprocessable();
        $payload['physical_defects_confirmed'] = true;
        $payload['sample_defect_count'] = 1;
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $payload)
            ->assertSuccessful()->assertJsonPath('data.recorded_result', 'failed');
        $this->assertSame('10.2000', $row->fresh()->measured_value);
        $this->assertFalse($row->fresh()->is_pass);
    }

    public function test_size_draft_retains_reading_and_rejects_measurements_on_attribute_checks(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $row = $inspection->measurements()->first();
        DB::table('inspection_measurements')->where('id', $row->id)->update(['parameter_type' => 'dimensional', 'nominal_value' => '10.0000',
            'tolerance_min' => '9.9000', 'tolerance_max' => '10.1000']);
        $payload = $this->payload($inspection);
        $payload['items'][0]['measured_value'] = '10';
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/item-results', [
            'expected_result_version' => 0, 'submit' => false, 'items' => [$payload['items'][0]],
        ])->assertSuccessful()->assertJsonPath('data.result_version', 1);
        $this->assertSame('10.0000', $row->fresh()->measured_value);
        $payload['expected_result_version'] = 1;
        $payload['items'][1]['measured_value'] = '10';
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $payload)->assertUnprocessable();
        $this->assertSame(1, $inspection->fresh()->result_version);
    }

    public function test_employee_cannot_author_inspection_results(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $role = Role::firstOrCreate(['slug' => 'employee'], ['name' => 'Employee']);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $this->payload($inspection))->assertForbidden();
    }
}
