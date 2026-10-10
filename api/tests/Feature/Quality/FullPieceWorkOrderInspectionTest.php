<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Quality\Enums\InspectionParameterType;
use App\Modules\Quality\Listeners\TriggerInProcessQC;
use App\Modules\Quality\Listeners\TriggerOutgoingQC;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionDefectivePiece;
use App\Modules\Quality\Models\InspectionPieceReading;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Production\Events\WorkOrderCompleted;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FullPieceWorkOrderInspectionTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        config(['inspection.finish_first_wo_enabled' => true]);
        $this->maker = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
    }

    public function test_completed_work_order_gets_idempotent_version_two_batch_inspections(): void
    {
        $wo = $this->workOrder([100, 100]);
        app(TriggerOutgoingQC::class)->handle(new WorkOrderCompleted($wo));
        app(TriggerOutgoingQC::class)->handle(new WorkOrderCompleted($wo));

        $inspections = Inspection::query()->where('entity_id', $wo->id)->where('stage', 'outgoing')->get();
        $this->assertCount(2, $inspections);
        $this->assertTrue($inspections->every(fn (Inspection $i) => $i->usesFullPieceCapture()));
        $this->assertTrue($inspections->every(fn (Inspection $i) => $i->batch_quantity === 100));
        $this->assertSame(6, $inspections->sum(fn (Inspection $i) => $i->measurements()->count()));
        $this->assertSame(0, InspectionPieceReading::query()->count());

        $inspections[0]->forceFill(['status' => 'passed'])->save();
        $inspections[1]->forceFill(['status' => 'draft'])->save();
        $queue = app(\App\Modules\Quality\Services\InspectionService::class);
        $this->assertCount(1, $queue->list(['work_queue' => 'to_inspect'])->items());
        $this->assertSame(1, $queue->statusCounts([])['to_inspect']);
        $this->assertSame(0, $queue->statusCounts([])['passed'] ?? 0);
    }

    public function test_start_and_read_do_not_create_qc_before_physical_completion(): void
    {
        $wo = $this->workOrder([60]);
        $wo->forceFill(['status' => 'in_progress'])->save();
        app(TriggerInProcessQC::class)->handle(new WorkOrderStatusChanged($wo, 'planned', 'in_progress'));
        $this->assertSame(0, Inspection::query()->where('entity_id', $wo->id)->count());
        try {
            app(\App\Modules\Quality\Services\WorkOrderFinalInspectionService::class)
                ->ensureForCompletedWorkOrder($wo, $this->maker);
            $this->fail('Final QC cannot be opened before the work order is physically complete.');
        } catch (\App\Common\Exceptions\BusinessRuleException $error) {
            $this->assertStringContainsString('Finish production', $error->getMessage());
        }
        $this->actingAs($this->maker)->getJson('/api/v1/quality/work-orders/'.$wo->hash_id.'/final-inspection')
            ->assertSuccessful()->assertJsonPath('data.blocker', 'Final QC handoff pending. Quality must retry inspection creation.');
        $this->assertSame(0, Inspection::query()->where('entity_id', $wo->id)->count());
    }

    public function test_repair_capture_excludes_pieces_scrapped_during_inline_repair(): void
    {
        $wo = $this->workOrder([5]);
        $ncr = NonConformanceReport::factory()->create([
            'product_id' => $wo->product_id,
            'affected_quantity' => 2,
            'defect_description' => 'Inline repair failed on two pieces.',
        ]);
        $repairOutput = WorkOrderOutput::create([
            'work_order_id' => $wo->id,
            'recorded_by' => $this->maker->id,
            'recorded_at' => now(),
            'good_count' => 3,
            'reject_count' => 2,
            'rework_ncr_id' => $ncr->id,
            'batch_code' => 'FF-REPAIR-MIXED',
        ]);

        try {
            app(\App\Modules\Quality\Services\InspectionService::class)->createForOutput($repairOutput, $wo, $this->maker);
            $this->fail('Unfinished inline repair cannot enter outgoing QC.');
        } catch (\App\Common\Exceptions\BusinessRuleException $exception) {
            $this->assertStringContainsString('Finish inline repair', $exception->getMessage());
        }
        $ncr->forceFill(['rework_finished_at' => now()])->save();
        $inspection = app(\App\Modules\Quality\Services\InspectionService::class)
            ->createForOutput($repairOutput, $wo, $this->maker);

        $this->assertSame(5, $repairOutput->physical_count);
        $this->assertSame(3, $inspection->batch_quantity);
        $this->assertSame('3.000', $inspection->source_quantity);
        $this->assertSame(3, $inspection->counted_capture['required_quantity']);

        $requirements = $inspection->measurements()->where('parameter_type', InspectionParameterType::Dimensional->value)->get();
        foreach (range(1, 3) as $pieceNumber) {
            foreach ($requirements as $requirement) {
                InspectionPieceReading::create([
                    'inspection_id' => $inspection->id,
                    'requirement_id' => $requirement->id,
                    'piece_number' => $pieceNumber,
                    'measured_value' => '10.0000',
                    'is_pass' => true,
                    'recorded_by' => $this->maker->id,
                ]);
            }
        }
        $inspection->resultAuthors()->firstOrCreate(['user_id' => $this->maker->id]);
        $inspection->forceFill(['coverage_confirmed_at' => now(), 'inspected_quantity' => 3])->save();
        $submitted = app(\App\Modules\Quality\Services\InspectionService::class)
            ->recordFullPieceOutcome($inspection, $this->maker);
        $this->assertSame('passed', $submitted->recorded_result->value);
    }

    public function test_page_save_and_submit_validate_all_piece_evidence_and_review_is_grouped(): void
    {
        $wo = $this->workOrder([60]);
        app(TriggerOutgoingQC::class)->handle(new WorkOrderCompleted($wo));
        $inspection = Inspection::query()->where('entity_id', $wo->id)->firstOrFail();
        $url = '/api/v1/quality/work-orders/'.$wo->hash_id.'/final-inspection';
        $sizes = $inspection->measurements()->where('parameter_type', InspectionParameterType::Dimensional->value)->get();
        $readings = [];
        foreach (range(1, 50) as $pieceNumber) {
            foreach ($sizes as $size) {
                $readings[] = ['piece_number' => $pieceNumber, 'requirement_id' => $size->hash_id, 'measured_value' => '10.0000'];
            }
        }
        $group = $this->actingAs($this->maker)->getJson($url.'?inspection_id='.$inspection->hash_id.'&page=1&per_page=50')
            ->assertSuccessful()->assertJsonPath('data.evidence_page.total_pieces', 60)->json('data');
        $manifest = $group['manifest'];
        $save = ['manifest' => $manifest, 'inspection_id' => $inspection->hash_id,
            'expected_result_version' => 0, 'defective_quantity' => 1,
            'pieces' => [['piece_number' => 7, 'problem_description' => 'Short shot']], 'readings' => $readings];
        $saved = $this->patchJson($url.'/evidence', $save)->assertSuccessful()
            ->assertJsonPath('data.batches.0.described_defective_quantity', 1)->json('data');
        $this->patchJson($url.'/evidence', $save)->assertUnprocessable();
        $this->postJson($url.'/submit', ['manifest' => $saved['manifest'],
            'batches' => [['inspection_id' => $inspection->hash_id, 'coverage_confirmed' => true, 'inspected_quantity' => 60]]])
            ->assertUnprocessable();
        $this->assertSame(1, InspectionDefectivePiece::query()->count());
    }

    public function test_two_saved_pages_cover_all_sixty_pieces_before_independent_pass_review(): void
    {
        $wo = $this->workOrder([60]);
        app(TriggerOutgoingQC::class)->handle(new WorkOrderCompleted($wo));
        $inspection = Inspection::query()->where('entity_id', $wo->id)->firstOrFail();
        $url = '/api/v1/quality/work-orders/'.$wo->hash_id.'/final-inspection';
        $sizes = $inspection->measurements()->where('parameter_type', InspectionParameterType::Dimensional->value)->get();
        $group = $this->actingAs($this->maker)->getJson($url.'?inspection_id='.$inspection->hash_id.'&page=1&per_page=50')
            ->assertSuccessful()->json('data');
        foreach ([[1, 50], [51, 60]] as [$first, $last]) {
            $readings = [];
            for ($piece = $first; $piece <= $last; $piece++) {
                foreach ($sizes as $size) {
                    $readings[] = ['piece_number' => $piece, 'requirement_id' => $size->hash_id, 'measured_value' => '10.0000'];
                }
            }
            $group = $this->patchJson($url.'/evidence', [
                'manifest' => $group['manifest'], 'inspection_id' => $inspection->hash_id,
                'expected_result_version' => $inspection->fresh()->result_version,
                'defective_quantity' => 0, 'pieces' => [], 'readings' => $readings,
            ])->assertSuccessful()->json('data');
        }
        $this->assertSame(120, InspectionPieceReading::query()->where('inspection_id', $inspection->id)->count());
        $submitted = $this->postJson($url.'/submit', [
            'manifest' => $group['manifest'],
            'batches' => [['inspection_id' => $inspection->hash_id, 'coverage_confirmed' => true, 'inspected_quantity' => 60]],
        ])->assertSuccessful()->assertJsonPath('data.state', 'awaiting_review')->json('data');
        $this->actingAs(User::factory()->create())->postJson($url.'/review', ['manifest' => $submitted['manifest']])
            ->assertForbidden();
        $this->actingAs($this->maker)->postJson($url.'/review', ['manifest' => $submitted['manifest']])
            ->assertForbidden();
        $reviewer = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $this->actingAs($reviewer)->postJson($url.'/review', ['manifest' => $submitted['manifest']])
            ->assertSuccessful()->assertJsonPath('data.state', 'released');
        $this->assertSame('passed', $inspection->fresh()->status->value);
        $this->assertSame(60, $inspection->fresh()->accepted_quantity);
    }

    public function test_five_unique_defect_descriptions_make_failed_full_coverage_result(): void
    {
        $wo = $this->workOrder([60]);
        app(TriggerOutgoingQC::class)->handle(new WorkOrderCompleted($wo));
        $inspection = Inspection::query()->where('entity_id', $wo->id)->firstOrFail();
        $url = '/api/v1/quality/work-orders/'.$wo->hash_id.'/final-inspection';
        $sizes = $inspection->measurements()->where('parameter_type', InspectionParameterType::Dimensional->value)->get();
        $group = $this->actingAs($this->maker)->getJson($url.'?inspection_id='.$inspection->hash_id.'&page=1&per_page=50')->json('data');
        foreach ([[1, 50], [51, 60]] as [$first, $last]) {
            $readings = [];
            for ($piece = $first; $piece <= $last; $piece++) {
                foreach ($sizes as $index => $size) {
                    $value = $piece === 7 ? ($index === 0 ? '11.0000' : '9.0000') : '10.0000';
                    $readings[] = ['piece_number' => $piece, 'requirement_id' => $size->hash_id, 'measured_value' => $value];
                }
            }
            $pieces = $first === 1 ? collect([1, 2, 3, 4, 7])->map(fn ($piece) => [
                'piece_number' => $piece, 'problem_description' => 'Out of specification',
            ])->all() : [];
            $group = $this->patchJson($url.'/evidence', [
                'manifest' => $group['manifest'], 'inspection_id' => $inspection->hash_id,
                'expected_result_version' => $inspection->fresh()->result_version,
                'defective_quantity' => 5, 'pieces' => $pieces, 'readings' => $readings,
            ])->assertSuccessful()->json('data');
        }
        $this->assertSame(5, InspectionDefectivePiece::query()->where('inspection_id', $inspection->id)->count());
        $this->postJson($url.'/submit', ['manifest' => $group['manifest'],
            'batches' => [['inspection_id' => $inspection->hash_id, 'coverage_confirmed' => true, 'inspected_quantity' => 60]]])
            ->assertSuccessful()->assertJsonPath('data.batches.0.proposed_result', 'failed');
        $this->assertSame(2, InspectionPieceReading::query()->where('inspection_id', $inspection->id)->where('piece_number', 7)->where('is_pass', false)->count());
        $this->assertSame(0, $inspection->fresh()->accepted_quantity);
    }

    public function test_a_new_output_makes_old_manifest_stale_without_rewriting_saved_evidence(): void
    {
        $wo = $this->workOrder([60]);
        app(TriggerOutgoingQC::class)->handle(new WorkOrderCompleted($wo));
        $inspection = Inspection::query()->where('entity_id', $wo->id)->firstOrFail();
        $url = '/api/v1/quality/work-orders/'.$wo->hash_id.'/final-inspection';
        $group = $this->actingAs($this->maker)->getJson($url.'?inspection_id='.$inspection->hash_id)->json('data');
        $size = $inspection->measurements()->where('parameter_type', InspectionParameterType::Dimensional->value)->firstOrFail();
        $readings = collect(range(1, 5))->map(fn ($piece) => [
            'piece_number' => $piece, 'requirement_id' => $size->hash_id, 'measured_value' => '10.0000',
        ])->all();
        $this->patchJson($url.'/evidence', [
            'manifest' => $group['manifest'], 'inspection_id' => $inspection->hash_id,
            'expected_result_version' => 0, 'defective_quantity' => 0, 'pieces' => [], 'readings' => $readings,
        ])->assertSuccessful();
        WorkOrderOutput::create(['work_order_id' => $wo->id, 'recorded_by' => $this->maker->id,
            'recorded_at' => now(), 'good_count' => 10, 'reject_count' => 0, 'batch_code' => 'FF-LATE']);
        app(\App\Modules\Quality\Services\WorkOrderFinalInspectionService::class)
            ->ensureForCompletedWorkOrder($wo->fresh(), $this->maker);
        $this->postJson($url.'/submit', ['manifest' => $group['manifest'],
            'batches' => [['inspection_id' => $inspection->hash_id, 'coverage_confirmed' => true, 'inspected_quantity' => 60]]])
            ->assertUnprocessable();
        $this->assertSame(1, $inspection->fresh()->result_version);
        $this->assertSame(5, InspectionPieceReading::query()->where('inspection_id', $inspection->id)->count());
        $this->assertSame('in_progress', $inspection->fresh()->status->value);
    }

    public function test_all_defective_batch_has_zero_release_quantity(): void
    {
        $wo = $this->workOrder([2]);
        app(TriggerOutgoingQC::class)->handle(new WorkOrderCompleted($wo));
        $inspection = Inspection::query()->where('entity_id', $wo->id)->firstOrFail();
        $url = '/api/v1/quality/work-orders/'.$wo->hash_id.'/final-inspection';
        $group = $this->actingAs($this->maker)->getJson($url.'?inspection_id='.$inspection->hash_id.'&page=1&per_page=50')
            ->assertSuccessful()->json('data');
        $sizes = $inspection->measurements()->where('parameter_type', InspectionParameterType::Dimensional->value)->get();
        $readings = [];
        foreach ([1, 2] as $pieceNumber) {
            foreach ($sizes as $size) {
                $readings[] = ['piece_number' => $pieceNumber, 'requirement_id' => $size->hash_id, 'measured_value' => '9.0000'];
            }
        }
        $saved = $this->patchJson($url.'/evidence', [
            'manifest' => $group['manifest'], 'inspection_id' => $inspection->hash_id,
            'expected_result_version' => 0, 'defective_quantity' => 2,
            'pieces' => [
                ['piece_number' => 1, 'problem_description' => 'Out of tolerance.'],
                ['piece_number' => 2, 'problem_description' => 'Out of tolerance.'],
            ],
            'readings' => $readings,
        ])->assertSuccessful()->json('data');
        $submitted = $this->postJson($url.'/submit', ['manifest' => $saved['manifest'],
            'batches' => [['inspection_id' => $inspection->hash_id, 'coverage_confirmed' => true, 'inspected_quantity' => 2]]])
            ->assertSuccessful()->json('data');
        $reviewer = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $result = $this->actingAs($reviewer)->postJson($url.'/review', [
            'manifest' => $submitted['manifest'], 'remarks' => 'All pieces failed dimensional checks.',
        ])
            ->assertSuccessful()->json('data');

        $this->assertSame('failed', $result['state']);
        $this->assertSame(0, $result['accepted_quantity']);
        $this->assertSame(0, $inspection->fresh()->accepted_quantity);
    }

    public function test_new_child_after_approved_snapshot_preserves_terminal_batch(): void
    {
        $wo = $this->workOrder([2]);
        app(TriggerOutgoingQC::class)->handle(new WorkOrderCompleted($wo));
        $inspection = Inspection::query()->where('entity_id', $wo->id)->firstOrFail();
        $url = '/api/v1/quality/work-orders/'.$wo->hash_id.'/final-inspection';
        $group = $this->actingAs($this->maker)->getJson($url.'?inspection_id='.$inspection->hash_id.'&page=1&per_page=50')
            ->assertSuccessful()->json('data');
        $sizes = $inspection->measurements()->where('parameter_type', InspectionParameterType::Dimensional->value)->get();
        $readings = [];
        foreach ([1, 2] as $pieceNumber) {
            foreach ($sizes as $size) {
                $readings[] = ['piece_number' => $pieceNumber, 'requirement_id' => $size->hash_id, 'measured_value' => '10.0000'];
            }
        }
        $saved = $this->patchJson($url.'/evidence', [
            'manifest' => $group['manifest'], 'inspection_id' => $inspection->hash_id,
            'expected_result_version' => 0, 'defective_quantity' => 0, 'pieces' => [], 'readings' => $readings,
        ])->assertSuccessful()->json('data');
        $submitted = $this->postJson($url.'/submit', ['manifest' => $saved['manifest'],
            'batches' => [['inspection_id' => $inspection->hash_id, 'coverage_confirmed' => true, 'inspected_quantity' => 2]]])
            ->assertSuccessful()->json('data');
        $reviewer = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $this->actingAs($reviewer)->postJson($url.'/review', ['manifest' => $submitted['manifest']])
            ->assertSuccessful()->assertJsonPath('data.state', 'released');
        $terminalVersion = (int) $inspection->fresh()->result_version;
        $terminalReadingCount = InspectionPieceReading::query()->where('inspection_id', $inspection->id)->count();

        WorkOrderOutput::create(['work_order_id' => $wo->id, 'recorded_by' => $this->maker->id,
            'recorded_at' => now(), 'good_count' => 1, 'reject_count' => 0, 'batch_code' => 'FF-AFTER-APPROVAL']);
        app(\App\Modules\Quality\Services\WorkOrderFinalInspectionService::class)
            ->ensureForCompletedWorkOrder($wo->fresh(), $this->maker);

        $this->postJson($url.'/submit', ['manifest' => $submitted['manifest'], 'batches' => []])->assertUnprocessable();
        $this->assertSame('passed', $inspection->fresh()->status->value);
        $this->assertSame($terminalVersion, (int) $inspection->fresh()->result_version);
        $this->assertSame($terminalReadingCount, InspectionPieceReading::query()->where('inspection_id', $inspection->id)->count());
        $children = Inspection::query()->where('entity_id', $wo->id)->where('stage', 'outgoing')->orderBy('id')->get();
        $this->assertCount(2, $children);
        $this->assertSame('draft', $children[1]->status->value);
        $this->assertSame(1, $children[1]->batch_quantity);
    }

    public function test_released_repair_is_visible_in_group_and_grouped_list_without_rewriting_failure(): void
    {
        $wo = $this->workOrder([500]);
        $source = app(\App\Modules\Quality\Services\WorkOrderFinalInspectionService::class)
            ->ensureForCompletedWorkOrder($wo, $this->maker)->first();
        $checker = User::factory()->withRole('production_manager')->create(['is_active' => true]);
        $source->forceFill(['status' => 'failed', 'sample_defect_count' => 2, 'defect_count' => 2,
            'accepted_quantity' => 498, 'inspector_id' => $this->maker->id,
            'reviewed_by' => $checker->id, 'reviewed_at' => now(), 'coverage_confirmed_at' => now()])->save();
        $ncr = NonConformanceReport::factory()->create([
            'product_id' => $wo->product_id, 'inspection_id' => $source->id, 'affected_quantity' => 500,
            'disposition' => 'rework', 'sorting_approved_at' => now(), 'sorting_evidence_reference' => 'BOX-1',
            'sorting_inspected_quantity' => 500, 'mrb_accepted_quantity' => 498,
            'previously_released_quantity' => 0, 'sorting_uses_reviewed_qc' => true,
            'sorting_approved_by' => $this->maker->id, 'sorting_performed_by' => $this->maker->id,
            'rework_finished_at' => now(),
        ]);
        $output = WorkOrderOutput::create(['work_order_id' => $wo->id, 'recorded_by' => $this->maker->id,
            'recorded_at' => now(), 'good_count' => 2, 'reject_count' => 0,
            'rework_ncr_id' => $ncr->id, 'batch_code' => 'FF-REPAIRED']);
        $repair = app(\App\Modules\Quality\Services\InspectionService::class)->createForOutput($output, $wo, $this->maker);
        $repair->forceFill(['status' => 'passed', 'accepted_quantity' => 2, 'inspector_id' => $this->maker->id,
            'reviewed_by' => $checker->id, 'reviewed_at' => now(), 'coverage_confirmed_at' => now()])->save();

        $url = '/api/v1/quality/work-orders/'.$wo->hash_id.'/final-inspection';
        $this->actingAs($this->maker)->getJson($url)->assertSuccessful()
            ->assertJsonPath('data.state', 'released')->assertJsonPath('data.accepted_quantity', 500)
            ->assertJsonPath('data.repaired_accepted_quantity', 2)
            ->assertJsonPath('data.batches.0.status', 'failed');
        $this->getJson('/api/v1/quality/inspections?entity_type=work_order&entity_id='.$wo->hash_id)
            ->assertSuccessful()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.work_order_quality.state', 'released')
            ->assertJsonPath('data.0.work_order_quality.accepted_quantity', 500)
            ->assertJsonPath('data.0.work_order_quality.repaired_accepted_quantity', 2);

        $repair->forceFill(['status' => 'awaiting_review', 'accepted_quantity' => 0])->save();
        $this->getJson('/api/v1/quality/inspections?entity_type=work_order&entity_id='.$wo->hash_id)
            ->assertSuccessful()->assertJsonPath('data.0.work_order_quality.state', 'awaiting_review')
            ->assertJsonPath('data.0.work_order_quality.accepted_quantity', 498)
            ->assertJsonPath('data.0.work_order_quality.repaired_accepted_quantity', 0);
    }

    private function workOrder(array $quantities): WorkOrder
    {
        $product = Product::factory()->create(['unit_of_measure' => 'PCS']);
        $wo = WorkOrder::factory()->create(['product_id' => $product->id, 'created_by' => $this->maker->id,
            'quantity_target' => array_sum($quantities), 'quantity_produced' => array_sum($quantities),
            'quantity_good' => array_sum($quantities), 'status' => 'completed']);
        foreach ($quantities as $index => $quantity) {
            WorkOrderOutput::create(['work_order_id' => $wo->id, 'recorded_by' => $this->maker->id,
                'recorded_at' => now(), 'good_count' => $quantity, 'reject_count' => 0, 'batch_code' => 'FF-'.($index + 1)]);
        }
        $spec = InspectionSpec::create(['product_id' => $product->id, 'version' => 1, 'is_active' => true, 'created_by' => $this->maker->id]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Appearance',
            'parameter_type' => 'visual', 'acceptance_criterion' => 'No damage', 'is_critical' => false]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Diameter',
            'parameter_type' => 'dimensional', 'unit_of_measure' => 'mm', 'tolerance_min' => '9.9000',
            'tolerance_max' => '10.1000', 'is_critical' => true]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Length',
            'parameter_type' => 'dimensional', 'unit_of_measure' => 'mm', 'tolerance_min' => '9.9000',
            'tolerance_max' => '10.1000', 'is_critical' => false]);

        return $wo->fresh();
    }
}
