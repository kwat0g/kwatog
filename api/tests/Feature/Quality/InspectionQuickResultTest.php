<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Exceptions\InspectionCertificateException;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\CoCService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectionQuickResultTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        foreach (['quality.inspections.manage', 'quality.inspections.review', 'quality.inspections.view'] as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'quality']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->maker = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $this->checker = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    public function test_inspection_list_includes_ncr_sorting_state_without_lazy_loading(): void
    {
        $inspections = [
            $this->inspection(InspectionStage::Incoming),
            $this->inspection(InspectionStage::InProcess),
        ];

        $this->actingAs($this->maker)
            ->getJson('/api/v1/quality/inspections?per_page=10')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $inspections[0]->hash_id, 'sorting_approved_at' => null])
            ->assertJsonFragment(['id' => $inspections[1]->hash_id, 'sorting_approved_at' => null]);
    }

    public function test_batch_requires_sample_coverage_and_records_zero_without_fabricating_readings(): void
    {
        foreach (InspectionStage::cases() as $stage) {
            $inspection = $this->inspection($stage);
            $row = $this->measurement($inspection);
            $row->forceFill(['is_critical' => false])->save();
            $this->recordChecklistResults($inspection);
            $url = '/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result';
            $this->actingAs($this->maker)->postJson($url, ['result' => 'passed'])->assertUnprocessable();
            $this->postJson($url, $this->batch())->assertSuccessful()->assertJsonPath('data.recorded_result', 'passed');
            $this->assertSame(0, $inspection->fresh()->sample_defect_count);
            $this->assertSame(1, $inspection->fresh()->inspected_quantity);
            $this->assertNull($row->fresh()->is_pass);
            $this->assertNull($row->fresh()->measured_value);
        }
    }

    public function test_batch_result_cannot_skip_inspection_requirements(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $row = $this->measurement($inspection);

        $this->actingAs($this->maker)->postJson(
            '/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result',
            $this->batch(),
        )->assertUnprocessable();

        $this->assertSame(InspectionStatus::Draft, $inspection->fresh()->status);
        $this->assertNull($inspection->fresh()->recorded_result);
        $this->assertNull($row->fresh()->item_result);
        $this->assertDatabaseMissing('inspection_result_authors', [
            'inspection_id' => $inspection->id,
            'user_id' => $this->maker->id,
        ]);
    }

    public function test_batch_uses_the_same_first_sample_for_checklist_decisions(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $inspection->forceFill(['inspection_mode' => 'lot_checklist', 'sample_size' => 2])->save();
        $secondSample = $this->measurement($inspection);
        $secondSample->forceFill([
            'sample_index' => 2,
            'measured_value' => '10.0000',
            'is_pass' => true,
        ])->save();
        $firstSample = $secondSample->replicate();
        $firstSample->forceFill(['sample_index' => 1])->save();
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id;

        $this->actingAs($this->maker)->postJson($url.'/item-results', [
            'items' => [['id' => $firstSample->hash_id, 'result' => 'passed']],
            'submit' => false,
        ])->assertSuccessful();

        $this->assertSame('passed', $firstSample->fresh()->item_result);
        $this->assertNull($secondSample->fresh()->item_result);
        $this->postJson($url.'/batch-result', $this->batch(['inspected_quantity' => 2]))
            ->assertSuccessful()
            ->assertJsonPath('data.recorded_result', 'passed');
    }

    public function test_noncritical_failed_requirement_cannot_be_accepted_within_aql(): void
    {
        $inspection = $this->inspection(InspectionStage::Incoming);
        $inspection->forceFill(['accept_count' => 1])->save();
        $row = $this->measurement($inspection);
        $row->forceFill(['is_critical' => false])->save();
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id;

        $this->actingAs($this->maker)->postJson($url.'/item-results', [
            'items' => [[
                'id' => $row->hash_id,
                'result' => 'failed',
                'problem_description' => 'Surface finish is scratched.',
            ]],
        ])->assertSuccessful();

        $this->postJson($url.'/batch-result', $this->batch([
            'result' => 'failed',
            'sample_defect_count' => 0,
            'problem_description' => 'Surface finish is scratched.',
        ]))->assertSuccessful()
            ->assertJsonPath('data.proposed_result', 'failed')
            ->assertJsonPath('data.sample_defect_count', 0)
            ->assertJsonPath('data.problem_description', 'Outer diameter: Surface finish is scratched.');

        $this->actingAs($this->checker)->patchJson($url.'/review', [
            'decision' => 'passed',
            'remarks' => 'Override',
        ])->assertUnprocessable();
        $this->assertSame(0, $inspection->fresh()->accepted_quantity);
    }

    public function test_unique_defective_pieces_hold_whole_batch_and_critical_problem_sets_ncr_severity(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $this->measurement($inspection);
        $this->recordChecklistResults($inspection);
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result';
        $this->actingAs($this->maker)->postJson($url, $this->batch(['result' => 'failed']))->assertUnprocessable();
        $this->postJson($url, $this->batch(['result' => 'failed', 'sample_defect_count' => 1,
            'problem_description' => 'One piece has diameter and surface problems', 'critical_defect' => true]))
            ->assertSuccessful()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.defect_count', 1);
        $ncr = NonConformanceReport::where('inspection_id', $inspection->id)->sole();
        $this->assertSame('critical', $ncr->severity->value);
        $this->assertSame(10, $ncr->affected_quantity);
        $this->assertSame(0, $inspection->fresh()->accepted_quantity);
    }

    public function test_noncritical_count_within_acceptance_excludes_bad_pieces_from_release(): void
    {
        $inspection = $this->inspection(InspectionStage::Outgoing);
        $inspection->forceFill(['accept_count' => 1, 'reject_count' => 2])->save();
        $row = $this->measurement($inspection);
        $row->forceFill(['is_critical' => false])->save();
        $this->recordChecklistResults($inspection);
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result';
        $payload = $this->batch(['result' => 'failed', 'sample_defect_count' => 1, 'problem_description' => 'One scratched piece']);
        $this->actingAs($this->maker)->postJson($url, $payload)->assertUnprocessable();
        $payload['defects_removed_confirmed'] = true;
        $this->postJson($url, $payload)->assertSuccessful()->assertJsonPath('data.proposed_result', 'passed');
        $this->actingAs($this->checker)->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/review', ['decision' => 'passed'])
            ->assertSuccessful()->assertJsonPath('data.accepted_quantity', 9);
    }

    public function test_checker_cannot_override_failed_batch_to_pass(): void
    {
        $inspection = $this->inspection(InspectionStage::Outgoing);
        $this->measurement($inspection);
        $this->recordChecklistResults($inspection);
        $this->actingAs($this->maker)->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result',
            $this->batch(['result' => 'failed', 'sample_defect_count' => 1, 'problem_description' => 'Defect']))->assertSuccessful();
        $this->actingAs($this->checker)->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/review',
            ['decision' => 'passed', 'remarks' => 'Override'])->assertUnprocessable();
        $this->assertSame(0, $inspection->fresh()->accepted_quantity);
    }

    public function test_legacy_per_unit_failure_excludes_each_bad_piece_once(): void
    {
        $inspection = $this->inspection(InspectionStage::Outgoing);
        $inspection->forceFill(['sample_size' => 2, 'accept_count' => 1, 'reject_count' => 2,
            'defects_removed_confirmed' => true])->save();
        $row = $this->measurement($inspection);
        $row->forceFill(['is_critical' => false, 'is_pass' => false, 'measured_value' => '12.0000'])->save();
        $secondProblem = $row->replicate();
        $secondProblem->parameter_name = 'Second defect on same piece';
        $secondProblem->save();
        $good = $row->replicate();
        $good->sample_index = 2;
        $good->is_pass = true;
        $good->measured_value = '10.0000';
        $good->save();
        $this->actingAs($this->maker)->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/complete')->assertSuccessful();
        $this->actingAs($this->checker)->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/review', ['decision' => 'passed'])
            ->assertSuccessful()->assertJsonPath('data.accepted_quantity', 9)->assertJsonPath('data.sample_defect_count', 1);
    }

    public function test_valid_integer_and_boolean_strings_are_normalized_at_the_api_boundary(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $row = $this->measurement($inspection);
        $row->forceFill(['is_critical' => false])->save();
        $this->recordChecklistResults($inspection);
        $this->actingAs($this->maker)->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result',
            $this->batch(['inspected_quantity' => '1', 'sample_defect_count' => '0', 'critical_defect' => '0',
                'defects_removed_confirmed' => '0', 'coverage_confirmed' => '1']))->assertSuccessful();
    }

    private function batch(array $overrides = []): array
    {
        return array_replace(['result' => 'passed', 'coverage_confirmed' => true, 'inspected_quantity' => 1,
            'sample_defect_count' => 0, 'critical_defect' => false, 'defects_removed_confirmed' => false], $overrides);
    }

    private function recordChecklistResults(Inspection $inspection): void
    {
        $items = $inspection->measurements()
            ->orderBy('sample_index')
            ->orderBy('id')
            ->get()
            ->groupBy(static fn (InspectionMeasurement $row): string => $row->checklistKey())
            ->map(static fn ($group): array => [
                'id' => $group->first()->hash_id,
                'result' => 'passed',
            ])
            ->values()
            ->all();

        $this->actingAs($this->maker)->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/item-results', [
            'items' => $items,
        ])->assertSuccessful();
    }

    public function test_item_drafts_are_partial_but_foreign_and_duplicate_items_are_rejected(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $row = $this->measurement($inspection);
        $other = $this->measurement($this->inspection(InspectionStage::InProcess));
        $piece = $row->replicate();
        $piece->sample_index = 2;
        $piece->save();
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id.'/item-results';
        $this->actingAs($this->maker)->postJson($url, ['items' => [['id' => $other->hash_id, 'result' => 'passed']]])->assertUnprocessable();
        $this->postJson($url, ['items' => [
            ['id' => $row->hash_id, 'result' => 'passed'], ['id' => $piece->hash_id, 'result' => 'passed'],
        ]])->assertUnprocessable();
        $this->postJson($url, ['items' => [['id' => $row->hash_id, 'result' => 'passed']]])->assertSuccessful();
        $this->assertSame(InspectionStatus::InProgress, $inspection->fresh()->status);
        $this->assertNull($inspection->fresh()->recorded_result);
        $this->assertSame('passed', $row->fresh()->item_result);
        $this->assertNull($row->fresh()->is_pass);
        $this->assertDatabaseHas('inspection_result_authors', ['inspection_id' => $inspection->id, 'user_id' => $this->maker->id]);
    }

    public function test_item_result_submission_requires_every_requirement_then_enters_checker_gate(): void
    {
        $inspection = $this->inspection(InspectionStage::Outgoing);
        $diameter = $this->measurement($inspection);
        $surface = $this->measurement($inspection);
        $surface->forceFill([
            'parameter_name' => 'Surface finish',
            'parameter_type' => 'visual',
            'tolerance_min' => null,
            'tolerance_max' => null,
            'is_critical' => false,
        ])->save();
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id.'/item-results';

        $this->actingAs($this->maker)->postJson($url, [
            'items' => [['id' => $surface->hash_id, 'result' => 'passed']],
            'submit' => true,
        ])->assertUnprocessable();

        $this->assertSame(InspectionStatus::Draft, $inspection->fresh()->status);
        $this->assertNull($diameter->fresh()->item_result);
        $this->assertNull($surface->fresh()->item_result);
        $this->assertDatabaseMissing('inspection_result_authors', [
            'inspection_id' => $inspection->id,
            'user_id' => $this->maker->id,
        ]);

        $this->actingAs($this->maker)->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/measurements', [
            'measurements' => [['id' => $diameter->hash_id, 'measured_value' => '10.0000']],
        ])->assertSuccessful();

        $this->postJson($url, [
            'items' => [
                ['id' => $diameter->hash_id, 'result' => 'passed'],
                ['id' => $surface->hash_id, 'result' => 'passed'],
            ],
            'submit' => true,
        ])->assertSuccessful()
            ->assertJsonPath('data.status', InspectionStatus::AwaitingReview->value)
            ->assertJsonPath('data.proposed_result', 'passed')
            ->assertJsonPath('data.sample_defect_count', null);

        $this->assertSame('passed', $diameter->fresh()->item_result);
        $this->assertSame('passed', $surface->fresh()->item_result);
        $this->assertNull($inspection->fresh()->inspected_quantity);
        $this->assertNull($inspection->fresh()->sample_defect_count);
        $this->assertDatabaseHas('inspection_result_authors', [
            'inspection_id' => $inspection->id,
            'user_id' => $this->maker->id,
        ]);
    }

    public function test_lot_checklist_cannot_enter_review_without_sample_coverage(): void
    {
        $inspection = $this->inspection(InspectionStage::Outgoing);
        $inspection->forceFill(['inspection_mode' => 'lot_checklist'])->save();
        $row = $this->measurement($inspection);
        $row->forceFill(['is_critical' => false])->save();

        $this->actingAs($this->maker)->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/item-results', [
            'items' => [['id' => $row->hash_id, 'result' => 'passed']],
            'submit' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Lot-checklist inspections require sample coverage and a defective-piece count before submission.');

        $this->assertSame(InspectionStatus::Draft, $inspection->fresh()->status);
        $this->assertNull($inspection->fresh()->proposed_result);
        $this->assertNull($inspection->fresh()->inspected_quantity);
        $this->assertNull($inspection->fresh()->sample_defect_count);
        $this->assertNull($row->fresh()->item_result);
        $this->assertDatabaseMissing('inspection_result_authors', [
            'inspection_id' => $inspection->id,
            'user_id' => $this->maker->id,
        ]);
    }

    public function test_checker_cannot_pass_a_lot_checklist_without_sample_coverage(): void
    {
        $inspection = $this->inspection(InspectionStage::Outgoing);
        $inspection->forceFill([
            'inspection_mode' => 'lot_checklist',
            'status' => InspectionStatus::AwaitingReview,
            'proposed_result' => 'passed',
        ])->save();
        $row = $this->measurement($inspection);
        $row->forceFill(['is_critical' => false, 'item_result' => 'passed'])->save();
        $inspection->resultAuthors()->create(['user_id' => $this->maker->id]);

        $this->actingAs($this->checker)->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/review', [
            'decision' => 'passed',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Lot-checklist inspections cannot pass without sample coverage and a defective-piece count.');

        $this->assertSame(InspectionStatus::AwaitingReview, $inspection->fresh()->status);
        $this->assertNull($inspection->fresh()->reviewed_by);
        $this->assertNull($inspection->fresh()->reviewed_at);
        $this->assertNull($inspection->fresh()->completed_at);
        $this->assertSame(0, $inspection->fresh()->accepted_quantity);
    }

    public function test_critical_dimension_cannot_pass_without_a_recorded_measurement(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $row = $this->measurement($inspection);

        $this->actingAs($this->maker)->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/item-results', [
            'items' => [['id' => $row->hash_id, 'result' => 'passed']],
            'submit' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Measure every critical characteristic before passing the inspection.');

        $this->assertSame(InspectionStatus::Draft, $inspection->fresh()->status);
        $this->assertNull($row->fresh()->item_result);
        $this->assertNull($row->fresh()->is_pass);
        $this->assertNull($row->fresh()->measured_value);
        $this->assertDatabaseMissing('inspection_result_authors', [
            'inspection_id' => $inspection->id,
            'user_id' => $this->maker->id,
        ]);
    }

    public function test_batch_result_cannot_pass_a_saved_critical_decision_without_measurement(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $row = $this->measurement($inspection);
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id;

        $this->actingAs($this->maker)->postJson($url.'/item-results', [
            'items' => [['id' => $row->hash_id, 'result' => 'passed']],
        ])->assertSuccessful();
        $this->assertSame(InspectionStatus::InProgress, $inspection->fresh()->status);
        $this->assertSame('passed', $row->fresh()->item_result);
        $authorsBeforeBatch = $inspection->resultAuthors()->count();

        $this->postJson($url.'/batch-result', $this->batch())->assertUnprocessable()
            ->assertJsonPath('message', 'Measure every critical characteristic before passing the inspection.');

        $this->assertSame(InspectionStatus::InProgress, $inspection->fresh()->status);
        $this->assertNull($inspection->fresh()->completed_at);
        $this->assertSame(0, $inspection->fresh()->accepted_quantity);
        $this->assertSame('passed', $row->fresh()->item_result);
        $this->assertNull($row->fresh()->measured_value);
        $this->assertSame($authorsBeforeBatch, $inspection->resultAuthors()->count());
    }

    public function test_checker_cannot_pass_after_critical_measurement_evidence_is_removed(): void
    {
        $inspection = $this->inspection(InspectionStage::Outgoing);
        $row = $this->measurement($inspection);
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id;

        $this->actingAs($this->maker)->patchJson($url.'/measurements', [
            'measurements' => [['id' => $row->hash_id, 'measured_value' => '10.0000']],
        ])->assertSuccessful();
        $this->recordChecklistResults($inspection);
        $this->postJson($url.'/batch-result', $this->batch())->assertSuccessful()
            ->assertJsonPath('data.status', InspectionStatus::AwaitingReview->value);

        $this->patchJson($url.'/measurements', [
            'measurements' => [['id' => $row->hash_id, 'measured_value' => null]],
        ])->assertUnprocessable();
        $this->assertSame('10.0000', $row->fresh()->measured_value);

        // Simulate a damaged legacy row after it entered the real review queue.
        $row->forceFill(['measured_value' => null, 'is_pass' => null])->save();
        $this->actingAs($this->checker)->patchJson($url.'/review', ['decision' => 'passed'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Measure every critical characteristic before passing the inspection.');

        $this->assertSame(InspectionStatus::AwaitingReview, $inspection->fresh()->status);
        $this->assertNull($inspection->fresh()->reviewed_by);
        $this->assertNull($inspection->fresh()->reviewed_at);
        $this->assertNull($inspection->fresh()->completed_at);
        $this->assertSame(0, $inspection->fresh()->accepted_quantity);
    }

    public function test_batch_decision_cannot_bypass_the_checklist(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $this->actingAs($this->maker)->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/result', ['result' => 'passed'])->assertNotFound();
        $this->assertSame(InspectionStatus::Draft, $inspection->fresh()->status);
    }

    public function test_submission_rechecks_saved_passes_against_later_failed_evidence(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $inspection->forceFill(['accept_count' => 2])->save();
        $first = $this->measurement($inspection);
        $first->forceFill(['is_critical' => false])->save();
        $second = $this->measurement($inspection);
        $second->forceFill(['parameter_name' => 'Color'])->save();
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id.'/item-results';
        $this->actingAs($this->maker)->postJson($url, ['items' => [['id' => $first->hash_id, 'result' => 'passed']]])->assertSuccessful();
        $first->forceFill(['is_pass' => false, 'measured_value' => '12.0000'])->save();
        $this->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/measurements', [
            'measurements' => [['id' => $second->hash_id, 'measured_value' => '10.0000']],
        ])->assertSuccessful();
        $this->postJson($url, ['items' => [['id' => $second->hash_id, 'result' => 'passed']], 'submit' => true])->assertUnprocessable();
        $this->assertSame(InspectionStatus::InProgress, $inspection->fresh()->status);
        $this->assertNull($second->fresh()->item_result);
    }

    public function test_outgoing_checklist_requires_independent_review_and_actual_certificate_evidence(): void
    {
        $inspection = $this->inspection(InspectionStage::Outgoing);
        $row = $this->measurement($inspection);
        $row->forceFill(['is_critical' => false])->save();
        $this->recordChecklistResults($inspection);
        $this->actingAs($this->maker)->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', $this->batch())->assertSuccessful()->assertJsonPath('data.status', 'awaiting_review');
        $reviewUrl = '/api/v1/quality/inspections/'.$inspection->hash_id.'/review';
        $this->patchJson($reviewUrl, ['decision' => 'passed'])->assertForbidden();
        $this->actingAs($this->checker)->patchJson($reviewUrl, ['decision' => 'passed'])->assertSuccessful();
        $this->getJson('/api/v1/quality/inspections/'.$inspection->hash_id)
            ->assertOk()
            ->assertJsonPath('data.coc_unavailable_reason', 'CoC requires every sampled measurement to be resolved; 1 have no pass/fail recorded.');
        try {
            app(CoCService::class)->generateForInspection($inspection->fresh());
            $this->fail('Checklist answers cannot substitute for measured certificate evidence.');
        } catch (InspectionCertificateException $exception) {
            $this->assertSame('COC_EVIDENCE_INCOMPLETE', $exception->errorCode());
        }
    }

    public function test_view_only_user_cannot_save_checklist_decisions(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $row = $this->measurement($inspection);
        $this->actingAs(User::factory()->create(['is_active' => true]))->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/item-results', [
            'items' => [['id' => $row->hash_id, 'result' => 'passed']],
        ])->assertForbidden();
    }

    public function test_legacy_completion_cannot_override_saved_checklist_failure(): void
    {
        $inspection = $this->inspection(InspectionStage::InProcess);
        $row = $this->measurement($inspection);
        $row->forceFill(['sample_index' => 2])->save();
        $firstSample = $row->replicate();
        $firstSample->sample_index = 1;
        $firstSample->save();
        $this->actingAs($this->maker)->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/item-results', [
            'items' => [['id' => $row->hash_id, 'result' => 'failed', 'problem_description' => 'Damaged housing']],
        ])->assertSuccessful();
        $row->forceFill(['measured_value' => '10.0000', 'is_pass' => true])->save();
        $firstSample->forceFill(['measured_value' => '10.0000', 'is_pass' => true])->save();
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/complete')->assertUnprocessable();
        $this->assertSame(InspectionStatus::InProgress, $inspection->fresh()->status);
    }

    private function inspection(InspectionStage $stage): Inspection
    {
        return Inspection::query()->create([
            'inspection_number' => 'QC-QUICK-'.uniqid(), 'stage' => $stage, 'status' => InspectionStatus::Draft,
            'entity_type' => $stage === InspectionStage::Incoming ? 'grn' : ($stage === InspectionStage::Outgoing ? 'work_order' : null),
            'entity_id' => in_array($stage, [InspectionStage::Incoming, InspectionStage::Outgoing], true) ? 1 : null,
            'product_id' => Product::factory()->create()->id, 'batch_quantity' => 10, 'sample_size' => 1,
            'accept_count' => 0, 'reject_count' => 1, 'defect_count' => 0, 'inspector_id' => $this->maker->id,
        ]);
    }

    private function measurement(Inspection $inspection): InspectionMeasurement
    {
        return InspectionMeasurement::query()->create([
            'inspection_id' => $inspection->id, 'sample_index' => 1, 'parameter_name' => 'Outer diameter',
            'parameter_type' => 'dimensional', 'tolerance_min' => '9.9000', 'tolerance_max' => '10.1000',
            'is_critical' => true, 'is_pass' => null,
        ]);
    }
}
