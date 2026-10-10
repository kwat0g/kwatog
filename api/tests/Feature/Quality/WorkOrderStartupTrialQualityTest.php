<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Resources\InspectionResource;
use App\Modules\Quality\Resources\NcrResource;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkOrderStartupTrialQualityTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    private User $approver;

    private User $sorter;

    private WorkOrder $wo;

    private Inspection $inspection;

    private InspectionMeasurement $row;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->maker = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $this->approver = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $this->sorter = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $product = Product::factory()->create();
        $this->wo = WorkOrder::factory()->create(['product_id' => $product->id, 'status' => 'in_progress', 'actual_start' => now(), 'quantity_target' => 100, 'quantity_produced' => 0, 'quantity_good' => 0, 'quantity_rejected' => 0, 'created_by' => $this->maker->id]);
        $this->inspection = Inspection::create(['inspection_number' => 'QC-STARTUP-TEST', 'stage' => 'in_process', 'status' => 'draft', 'entity_type' => 'work_order', 'entity_id' => $this->wo->id, 'product_id' => $product->id, 'batch_quantity' => 100, 'sample_size' => 3, 'accept_count' => 0, 'reject_count' => 1, 'inspector_id' => $this->maker->id, 'inspection_mode' => 'lot_checklist']);
        $this->inspection->forceFill(['startup_trial_required' => true])->save();
        $this->row = InspectionMeasurement::create(['inspection_id' => $this->inspection->id, 'sample_index' => 1, 'parameter_name' => 'Surface', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No flash', 'is_critical' => false]);
    }

    private function capture(): WorkOrderOutput
    {
        $output = WorkOrderOutput::create(['work_order_id' => $this->wo->id, 'recorded_by' => $this->maker->id, 'recorded_at' => now(), 'good_count' => 0, 'reject_count' => 0, 'batch_code' => 'TRIAL-TEST', 'trial_inspection_id' => $this->inspection->id, 'trial_quantity' => 3]);
        $this->wo->forceFill(['quantity_produced' => 3])->save();

        return $output;
    }

    private function batch(int $quantity = 3, string $result = 'passed'): array
    {
        return ['coverage_confirmed' => true, 'inspected_quantity' => $quantity, 'sample_defect_count' => 0, 'result' => $result, 'critical_defect' => false, 'problem_description' => $result === 'failed' ? 'Flash found' : null];
    }

    private function invoke(string $path): void
    {
        $service = app(InspectionService::class);
        match ($path) {
            'measurements' => $service->recordMeasurements($this->inspection, [$this->row->id => ['is_pass' => true]], $this->maker),
            'lot' => $service->recordLotResult($this->inspection, ['checklist' => [['_id' => $this->row->id, 'is_pass' => true]], 'inspected_quantity' => 3, 'sample_defect_count' => 0], $this->maker),
            'items' => $service->recordItemResults($this->inspection, ['items' => [['id' => $this->row->id, 'result' => 'passed']]], $this->maker),
            'batch' => $service->recordBatchResult($this->inspection, $this->batch(), $this->maker),
            'complete' => $service->complete($this->inspection, $this->maker),
            'review' => $service->review($this->inspection, 'passed', null, $this->approver),
        };
    }

    public static function evidencePaths(): array
    {
        return array_map(fn ($path) => [$path], ['measurements', 'lot', 'items', 'batch', 'complete', 'review']);
    }

    #[DataProvider('evidencePaths')]
    public function test_every_measurement_write_boundary_requires_physical_trial(string $path): void
    {
        if ($path === 'batch') {
            $this->row->forceFill(['item_result' => 'passed'])->save();
        }
        if ($path === 'complete') {
            $this->row->forceFill(['is_pass' => true])->save();
            $this->inspection->forceFill(['inspected_quantity' => 3, 'sample_defect_count' => 0])->save();
        }
        if ($path === 'review') {
            $this->row->forceFill(['item_result' => 'passed'])->save();
            $this->inspection->forceFill(['status' => 'awaiting_review', 'proposed_result' => 'passed', 'inspected_quantity' => 3, 'sample_defect_count' => 0])->save();
        }
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('trial');
        $this->invoke($path);
    }

    public function test_checklist_entry_requires_physical_trial_before_saving_rows(): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $this->inspection->forceFill(['capture_method' => 'checklist', 'sampling_basis' => 'piece', 'source_quantity' => '100', 'source_uom_code' => 'PCS'])->save();
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('trial');
        $this->invoke('items');
    }

    public function test_declared_final_coverage_must_equal_actual_trial(): void
    {
        $this->capture();
        $this->row->forceFill(['item_result' => 'passed'])->save();
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('trial');
        app(InspectionService::class)->recordBatchResult($this->inspection, $this->batch(100), $this->maker);
    }

    public function test_trial_pass_settles_same_physical_pieces_once(): void
    {
        $output = $this->capture();
        app(InspectionService::class)->recordItemResults($this->inspection, ['items' => [['id' => $this->row->id, 'result' => 'passed']]], $this->maker);
        $result = app(InspectionService::class)->recordBatchResult($this->inspection->fresh(), $this->batch(), $this->maker);
        $this->assertSame(InspectionStatus::Passed, $result->status);
        $this->assertSame(3, (int) $output->fresh()->good_count);
        $this->assertNotNull($output->fresh()->trial_settled_at);
        $this->assertSame(3, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame(3, (int) $this->wo->fresh()->quantity_good);
        $this->assertSame(1, $this->wo->outputs()->count());
        $this->wo->fresh()->assertProcessQualityReleased();
    }

    public function test_trial_fail_holds_actual_sample_without_inventing_scrap(): void
    {
        $output = $this->capture();
        app(InspectionService::class)->recordItemResults($this->inspection, ['items' => [['id' => $this->row->id, 'result' => 'failed', 'problem_description' => 'Flash found']]], $this->maker);
        $failed = app(InspectionService::class)->recordBatchResult($this->inspection->fresh(), $this->batch(3, 'failed'), $this->maker);
        $this->assertSame(InspectionStatus::Failed, $failed->status);
        $this->assertSame(3, $failed->ncr->affected_quantity);
        $this->assertSame(3, (int) $failed->ncr->recovery_batch_quantity);
        $this->assertSame(0, (int) $output->fresh()->reject_count);
        $this->assertNull($output->fresh()->trial_settled_at);
    }

    public function test_captured_samples_cannot_be_cancelled_or_replaced(): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $this->capture();
        foreach (['cancel', 'replace'] as $action) {
            try {
                $action === 'cancel'
                    ? app(InspectionService::class)->cancel($this->inspection->fresh(), 'Retry QC', $this->maker)
                    : app(InspectionService::class)->replaceWithChecklist($this->inspection->fresh(), 'New checklist', 0, $this->maker);
                $this->fail('Captured trial cannot release its inspection slot.');
            } catch (BusinessRuleException $error) {
                $this->assertStringContainsString('trial', $error->getMessage());
            }
        }
        $this->assertSame(InspectionStatus::Draft, $this->inspection->fresh()->status);
    }

    public function test_new_root_startup_inspection_requires_trial(): void
    {
        $this->inspection->delete();
        $spec = InspectionSpec::create(['product_id' => $this->wo->product_id, 'version' => 1, 'is_active' => true, 'created_by' => $this->maker->id]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Surface', 'parameter_type' => 'visual', 'is_critical' => false]);
        $spec->ensureCurrentRevision();
        $created = app(InspectionService::class)->create(['stage' => 'in_process', 'product_id' => $this->wo->product_id, 'batch_quantity' => 100, 'entity_type' => 'work_order', 'entity_id' => $this->wo->id], $this->maker);
        $this->assertTrue($created->startup_trial_required);
    }

    private function failedNcr(): NonConformanceReport
    {
        $this->capture();
        $this->inspection->forceFill(['status' => 'failed', 'inspected_quantity' => 3, 'sample_defect_count' => 0, 'recorded_result' => 'failed', 'problem_description' => 'Surface flash', 'completed_at' => now()])->save();
        $this->inspection->resultAuthors()->create(['user_id' => $this->maker->id]);

        return app(NcrService::class)->openFromInspectionFailure($this->inspection->fresh(), $this->maker);
    }

    private function recoveryEvidence(array $extra = []): array
    {
        return array_replace(['inspected_quantity' => '3', 'evidence_reference' => 'TRIAL-SORT-3', 'performed_by' => $this->sorter->id, 'recovery_passed' => true], $extra);
    }

    public function test_trial_recovery_classifies_explicit_good_and_actual_defect_scrap(): void
    {
        $ncr = $this->failedNcr();
        $defect = DefectType::create(['code' => 'TRFLASH', 'name' => 'Trial flash', 'is_active' => true]);
        $resolved = app(NcrService::class)->setDisposition($ncr, 'rework', 'Process corrected', 'Remove flash and reinspect', $this->approver, '2', $this->recoveryEvidence(['trial_scrapped_quantity' => 1, 'trial_defects' => [['defect_type_id' => $defect->id, 'count' => 1]]]));
        $this->assertSame(2, (int) $resolved->mrb_accepted_quantity);
        $this->assertSame(2, (int) $this->wo->fresh()->quantity_good);
        $this->assertSame(1, (int) $this->wo->fresh()->quantity_rejected);
        $this->assertSame(3, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame(InspectionStatus::Failed, $this->inspection->fresh()->status);
        $this->wo->fresh()->assertProcessQualityReleased();
    }

    public function test_trial_recovery_never_infers_scrap_from_missing_counts(): void
    {
        $ncr = $this->failedNcr();
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('scrap');
        app(NcrService::class)->setDisposition($ncr, 'rework', 'Corrected', 'Reinspect', $this->approver, '3', $this->recoveryEvidence());
    }

    public function test_lot_completion_rejects_declared_extra_physical_coverage(): void
    {
        $this->capture();
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('trial');
        app(InspectionService::class)->recordLotResult($this->inspection, ['checklist' => [['_id' => $this->row->id, 'is_pass' => true]], 'inspected_quantity' => 100, 'sample_defect_count' => 0, 'coverage_confirmed' => true, 'complete' => true], $this->maker);
    }

    public function test_inactive_checker_cannot_approve_trial(): void
    {
        $this->capture();
        $this->row->forceFill(['item_result' => 'passed'])->save();
        $this->inspection->forceFill(['status' => 'awaiting_review', 'proposed_result' => 'passed', 'inspected_quantity' => 3, 'sample_defect_count' => 0])->save();
        $this->approver->forceFill(['is_active' => false])->save();
        $this->expectException(ForbiddenActionException::class);
        app(InspectionService::class)->review($this->inspection, 'passed', null, $this->approver);
    }

    public function test_trial_recovery_requires_independent_verifier(): void
    {
        $ncr = $this->failedNcr();
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('other than');
        app(NcrService::class)->setDisposition($ncr, 'rework', 'Corrected', 'Reinspect', $this->maker, '3', $this->recoveryEvidence(['trial_scrapped_quantity' => 0, 'trial_defects' => []]));
    }

    public function test_failed_trial_disposition_cannot_be_changed_after_settlement(): void
    {
        $ncr = $this->failedNcr();
        $resolved = app(NcrService::class)->setDisposition($ncr, 'rework', 'Corrected', 'Reinspect', $this->approver, '3', $this->recoveryEvidence(['trial_scrapped_quantity' => 0, 'trial_defects' => []]));
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('final');
        app(NcrService::class)->setDisposition($resolved, 'scrap', 'Corrected', 'Discard', $this->approver);
    }

    public function test_trial_recovery_http_decodes_actual_scrap_defect_types(): void
    {
        $ncr = $this->failedNcr();
        $defect = DefectType::create(['code' => 'HTTPFLASH', 'name' => 'Surface flash', 'is_active' => true]);
        $this->actingAs($this->approver)->patchJson('/api/v1/quality/ncrs/'.$ncr->hash_id.'/disposition', [
            'disposition' => 'rework', 'root_cause' => 'Process corrected', 'corrective_action' => 'Remove flash and reinspect',
            'mrb_accepted_quantity' => '2', 'sorting_evidence' => [
                'inspected_quantity' => '3', 'evidence_reference' => 'HTTP-TRIAL-3', 'performed_by' => $this->sorter->hash_id,
                'recovery_passed' => true, 'trial_scrapped_quantity' => 1,
                'trial_defects' => [['defect_type_id' => $defect->hash_id, 'count' => 1]],
            ],
        ])->assertOk()->assertJsonPath('data.startup_trial', true)->assertJsonPath('data.process_recovery_quantity', 3);
        $this->assertSame(2, (int) $this->wo->fresh()->quantity_good);
        $this->assertSame(1, (int) $this->wo->fresh()->quantity_rejected);
    }

    public function test_checklist_failure_and_all_scrap_recovery_preserve_physical_count(): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $this->inspection->forceFill(['capture_method' => 'checklist', 'sampling_basis' => 'piece', 'source_quantity' => '100', 'source_uom_code' => 'PCS'])->save();
        $output = $this->capture();
        $failed = app(InspectionService::class)->recordBatchResult($this->inspection, [
            'items' => [['id' => $this->row->id, 'result' => 'failed', 'problem_description' => 'Flash found']],
            'expected_result_version' => 0, 'inspected_quantity' => 3, 'coverage_confirmed' => true,
            'physical_defects_confirmed' => false, 'sample_defect_count' => 0,
        ], $this->maker);
        $this->assertSame(3, $failed->ncr->affected_quantity);
        $defect = DefectType::create(['code' => 'ALLFLASH', 'name' => 'Unrecoverable flash', 'is_active' => true]);
        app(NcrService::class)->setDisposition($failed->ncr, 'rework', 'Wrong process setting', 'Setting corrected and trial reinspected', $this->approver, '0', $this->recoveryEvidence(['trial_scrapped_quantity' => 3, 'trial_defects' => [['defect_type_id' => $defect->id, 'count' => 3]]]));
        $this->assertSame(0, (int) $output->fresh()->good_count);
        $this->assertSame(3, (int) $output->fresh()->reject_count);
        $this->assertSame(3, (int) $this->wo->fresh()->quantity_produced);
        $this->wo->fresh()->assertProcessQualityReleased();
    }

    public function test_startup_size_check_requires_actual_numeric_reading(): void
    {
        $this->capture();
        $size = InspectionMeasurement::create(['inspection_id' => $this->inspection->id, 'sample_index' => 1, 'parameter_name' => 'Width', 'parameter_type' => 'dimensional', 'nominal_value' => '10', 'tolerance_min' => '9', 'tolerance_max' => '11', 'is_critical' => false]);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('measured value');
        app(InspectionService::class)->recordMeasurements($this->inspection, [$size->id => ['is_pass' => true]], $this->maker);
    }

    public function test_operation_checklist_uses_actual_three_pieces_not_parent_target(): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $operation = WoOperation::create(['work_order_id' => $this->wo->id, 'sequence' => 1, 'operation_name' => 'Molding', 'status' => 'in_progress', 'qty_planned' => 100, 'qty_completed' => 3, 'qty_scrapped' => 0]);
        $spec = InspectionSpec::create(['product_id' => $this->wo->product_id, 'version' => 1, 'is_active' => true, 'created_by' => $this->maker->id]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Surface', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No flash', 'is_critical' => false]);
        $spec->ensureCurrentRevision();
        $created = app(InspectionService::class)->create(['stage' => 'in_process', 'product_id' => $this->wo->product_id, 'batch_quantity' => 100, 'entity_type' => 'work_order', 'entity_id' => $this->wo->id, 'wo_operation_id' => $operation->id], $this->maker);
        $this->assertSame(3, (int) $created->batch_quantity);
        $this->assertSame('3.000', (string) $created->source_quantity);
        $this->assertLessThanOrEqual(3, (int) $created->sample_size);
        $this->assertFalse($created->startup_trial_required);
        $result = app(InspectionService::class)->recordBatchResult($created, [
            'items' => $created->measurements->map(fn ($row) => ['id' => $row->id, 'result' => 'passed'])->all(),
            'expected_result_version' => 0, 'inspected_quantity' => 3, 'coverage_confirmed' => true,
            'physical_defects_confirmed' => false, 'sample_defect_count' => 0,
        ], $this->maker);
        $this->assertSame(InspectionStatus::Passed, $result->status);
    }

    public static function invalidOperationQuantities(): array
    {
        return [['0'], ['2.5']];
    }

    #[DataProvider('invalidOperationQuantities')]
    public function test_operation_inspection_requires_positive_whole_physical_count(string $quantity): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $operation = WoOperation::create(['work_order_id' => $this->wo->id, 'sequence' => 1, 'operation_name' => 'Molding', 'status' => 'in_progress', 'qty_planned' => 100, 'qty_completed' => $quantity, 'qty_scrapped' => 0]);
        $spec = InspectionSpec::create(['product_id' => $this->wo->product_id, 'version' => 1, 'is_active' => true, 'created_by' => $this->maker->id]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Surface', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No flash', 'is_critical' => false]);
        $spec->ensureCurrentRevision();
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('actual');
        app(InspectionService::class)->create(['stage' => 'in_process', 'product_id' => $this->wo->product_id, 'batch_quantity' => 100, 'entity_type' => 'work_order', 'entity_id' => $this->wo->id, 'wo_operation_id' => $operation->id], $this->maker);
    }

    public function test_resources_expose_actual_trial_and_recovery_quantity(): void
    {
        $this->capture();
        $inspection = (new InspectionResource(app(InspectionService::class)->show($this->inspection->fresh())))->resolve();
        $this->assertTrue($inspection['startup_trial_required']);
        $this->assertSame(3, $inspection['startup_trial']['recorded_quantity']);
        $this->assertSame(3, $inspection['startup_trial']['required_quantity']);
        $ncr = (new NcrResource($this->failedNcrWithoutCapture()))->resolve();
        $this->assertTrue($ncr['startup_trial']);
        $this->assertSame(3, $ncr['process_recovery_quantity']);
    }

    public function test_trial_final_result_cannot_claim_smaller_coverage_than_captured_sample(): void
    {
        $this->capture();
        $this->expectException(BusinessRuleException::class);
        app(InspectionService::class)->recordLotResult($this->inspection, [
            'checklist' => [['_id' => $this->row->id, 'is_pass' => true]],
            'inspected_quantity' => 2, 'sample_defect_count' => 0, 'coverage_confirmed' => true, 'complete' => true,
        ], $this->maker);
    }

    private function failedNcrWithoutCapture(): NonConformanceReport
    {
        $this->inspection->forceFill(['status' => 'failed', 'inspected_quantity' => 3, 'sample_defect_count' => 0])->save();

        return app(NcrService::class)->openFromInspectionFailure($this->inspection->fresh(), $this->maker);
    }
}
