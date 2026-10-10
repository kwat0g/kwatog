<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinishFirstDefectRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_qc_inspector_can_release_reviewed_conforming_output_after_separating_defects(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $qc = User::factory()->withRole('qc_inspector')->create();
        $reviewer = User::factory()->withRole('production_manager')->create();
        config(['inspection.finish_first_wo_enabled' => true]);
        $wo = WorkOrder::factory()->create(['status' => 'completed', 'quantity_target' => 500, 'quantity_produced' => 500, 'quantity_good' => 500]);
        $output = WorkOrderOutput::create(['work_order_id' => $wo->id, 'recorded_by' => $reviewer->id,
            'recorded_at' => now(), 'good_count' => 500, 'reject_count' => 0, 'batch_code' => 'QC-SEPARATION-500']);
        $inspection = Inspection::create(['inspection_number' => 'QC-SEPARATION-500', 'stage' => 'outgoing', 'status' => 'failed',
            'entity_type' => 'work_order', 'entity_id' => $wo->id, 'work_order_output_id' => $output->id,
            'product_id' => $wo->product_id, 'batch_quantity' => 500, 'sample_size' => 500, 'accepted_quantity' => 0,
            'inspector_id' => $qc->id, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(),
            'counted_capture' => ['version' => 3, 'workflow' => 'finish_first_v1', 'evidence_method' => 'confirmed_checks'],
            'defect_count' => 2]);
        $inspection->forceFill(['sample_defect_count' => 2, 'inspected_quantity' => 500])->save();
        $ncr = app(NcrService::class)->create(['source' => 'inspection_fail', 'severity' => 'high',
            'inspection_id' => $inspection->id, 'affected_quantity' => 500, 'defect_description' => 'Two surface defects.'], $qc);
        $payload = ['disposition' => 'rework', 'mrb_accepted_quantity' => '498', 'sorting_evidence' => [
            'performed_by' => $qc->hash_id, 'inspected_quantity' => '500', 'evidence_reference' => 'Box 1 - NCR '.$ncr->ncr_number,
            'recovery_passed' => true, 'defects_segregated' => true,
        ]];
        $inspection->forceFill(['reviewed_at' => null])->save();
        $this->actingAs($qc)->patchJson('/api/v1/quality/ncrs/'.$ncr->hash_id.'/disposition', $payload)->assertStatus(422);
        $this->assertNull($ncr->fresh()->sorting_approved_at);
        $inspection->forceFill(['reviewed_at' => now()])->save();
        $this->assertFalse($inspection->canConfirmReviewedSeparation($reviewer));
        $inspection->forceFill(['reviewed_by' => $qc->id])->save();
        $this->patchJson('/api/v1/quality/ncrs/'.$ncr->hash_id.'/disposition', $payload)->assertStatus(422);
        $inspection->forceFill(['reviewed_by' => $reviewer->id])->save();
        $tooMany = $payload;
        $tooMany['mrb_accepted_quantity'] = '500';
        $this->patchJson('/api/v1/quality/ncrs/'.$ncr->hash_id.'/disposition', $tooMany)->assertStatus(422);
        $missingSeparation = $payload;
        $missingSeparation['sorting_evidence']['defects_segregated'] = false;
        $this->patchJson('/api/v1/quality/ncrs/'.$ncr->hash_id.'/disposition', $missingSeparation)->assertStatus(422);
        $this->patchJson('/api/v1/quality/ncrs/'.$ncr->hash_id.'/disposition', $payload)->assertOk();
        $settled = $ncr->fresh();
        $this->assertSame($qc->id, (int) $settled->sorting_approved_by);
        $this->assertSame($qc->id, (int) $settled->sorting_performed_by);
        $this->assertTrue($settled->sorting_uses_reviewed_qc);
        $this->assertSame(2, (int) $settled->confirmed_rework_quantity);
        $this->assertSame($wo->id, $settled->rework_work_order_id);
        $this->assertSame(498, (int) $inspection->fresh()->accepted_quantity);
        $this->assertSame($reviewer->id, (int) $inspection->fresh()->reviewed_by);
        $this->assertTrue($inspection->fresh()->canReleaseOutgoing());
        $this->assertSame('failed', $inspection->fresh()->status->value);
        $migration = require database_path('migrations/2026_10_09_000500_allow_reviewed_wo_qc_separation.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve QC separation evidence.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Preserve these records', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('non_conformance_reports', 'sorting_uses_reviewed_qc'));
        $this->assertTrue($ncr->fresh()->sorting_uses_reviewed_qc);
        $this->assertSame(498, (int) $inspection->fresh()->accepted_quantity);
    }

    public function test_qc_separation_migration_round_trip_preserves_existing_ncr(): void
    {
        $ncr = NonConformanceReport::factory()->create(['affected_quantity' => 500, 'defect_description' => 'Two surface defects.']);
        $migration = require database_path('migrations/2026_10_09_000500_allow_reviewed_wo_qc_separation.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('non_conformance_reports', 'sorting_uses_reviewed_qc'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('non_conformance_reports', 'sorting_uses_reviewed_qc'));
        $this->assertFalse($ncr->fresh()->sorting_uses_reviewed_qc);
        $this->assertSame(500, $ncr->fresh()->affected_quantity);
        $this->assertSame('Two surface defects.', $ncr->fresh()->defect_description);
    }

    public function test_independent_segregation_releases_only_the_195_conforming_pieces(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $maker = User::factory()->withRole('qc_inspector')->create();
        $checker = User::factory()->withRole('qc_inspector')->create();
        config(['inspection.finish_first_wo_enabled' => true]);
        $wo = WorkOrder::factory()->create(['status' => 'completed', 'quantity_target' => 200, 'quantity_produced' => 200, 'quantity_good' => 200]);
        $output = WorkOrderOutput::create(['work_order_id' => $wo->id, 'recorded_by' => $maker->id,
            'recorded_at' => now(), 'good_count' => 200, 'reject_count' => 0, 'batch_code' => 'FULL-RELEASE-200']);
        $inspection = Inspection::create(['inspection_number' => 'FULL-RELEASE-QC', 'stage' => 'outgoing', 'status' => 'failed',
            'entity_type' => 'work_order', 'entity_id' => $wo->id, 'work_order_output_id' => $output->id,
            'product_id' => $wo->product_id, 'batch_quantity' => 200, 'sample_size' => 200, 'accepted_quantity' => 0,
            'inspector_id' => $maker->id, 'reviewed_by' => $checker->id, 'reviewed_at' => now(),
            'counted_capture' => ['version' => 2, 'workflow' => 'finish_first_v1', 'required_quantity' => 200, 'result_policy' => 'zero_defects'],
            'defect_count' => 5]);
        $inspection->forceFill(['sample_defect_count' => 5, 'inspected_quantity' => 200])->save();
        $this->assertFalse($inspection->canReleaseOutgoing());
        $service = app(NcrService::class);
        $ncr = $service->create(['source' => 'inspection_fail', 'severity' => 'high', 'inspection_id' => $inspection->id,
            'affected_quantity' => 200, 'defect_description' => 'Five damaged pieces.'], $maker);
        $sorted = $service->setDisposition($ncr, 'rework', 'Mold damage.', 'Repair the five held pieces.', $checker, '195', [
            'performed_by' => $maker->id, 'inspected_quantity' => '200', 'evidence_reference' => 'SEGREGATION-200',
            'recovery_passed' => true, 'defects_segregated' => true,
        ]);
        $this->assertSame(195, (int) $inspection->fresh()->accepted_quantity);
        $this->assertTrue($inspection->fresh()->canReleaseOutgoing());
        $this->assertSame(5, (int) $sorted->confirmed_rework_quantity);
        $this->assertSame($wo->id, $sorted->rework_work_order_id);
        $this->assertSame(200, (int) $wo->fresh()->quantity_produced);
        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertSame('failed', $inspection->fresh()->status->value);
    }

    public function test_sorting_cannot_release_a_recorded_defective_piece_as_conforming(): void
    {
        $actor = User::factory()->create();
        config(['inspection.finish_first_wo_enabled' => true]);
        $wo = WorkOrder::factory()->create(['status' => 'completed']);
        $output = WorkOrderOutput::create(['work_order_id' => $wo->id, 'recorded_by' => $actor->id,
            'recorded_at' => now(), 'good_count' => 200, 'reject_count' => 0, 'batch_code' => 'FULL-200']);
        $inspection = Inspection::create(['inspection_number' => 'FULL-QC', 'stage' => 'outgoing', 'status' => 'failed',
            'entity_type' => 'work_order', 'entity_id' => $wo->id, 'work_order_output_id' => $output->id,
            'product_id' => $wo->product_id, 'batch_quantity' => 200, 'sample_size' => 200,
            'counted_capture' => ['version' => 2, 'workflow' => 'finish_first_v1', 'required_quantity' => 200, 'result_policy' => 'zero_defects'],
            'defect_count' => 5]);
        $inspection->forceFill(['sample_defect_count' => 5])->save();
        $method = new \ReflectionMethod(NcrService::class, 'validateOutgoingRecovery');
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Repair defective pieces before releasing them. At most 195 conforming pieces can release.');
        $method->invoke(app(NcrService::class), $inspection, NcrDisposition::Rework, '200');
    }

    public function test_full_inspection_evidence_still_requires_physical_segregation_confirmation(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $approver = User::factory()->create();
        $inspection = Inspection::create(['inspection_number' => 'FULL-SORT', 'stage' => 'outgoing', 'status' => 'failed',
            'batch_quantity' => 200, 'sample_size' => 200,
            'counted_capture' => ['version' => 2, 'workflow' => 'finish_first_v1', 'required_quantity' => 200, 'result_policy' => 'zero_defects'],
            'inspector_id' => $maker->id, 'reviewed_by' => $checker->id, 'reviewed_at' => now()]);
        $inspection->forceFill(['inspected_quantity' => 200, 'sample_defect_count' => 5])->save();
        $method = new \ReflectionMethod(NcrService::class, 'validateSortingEvidence');
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Confirm defective pieces were physically separated before release.');
        $method->invoke(app(NcrService::class), $inspection, '200', $approver, [
            'performed_by' => $maker->id, 'inspected_quantity' => '200', 'evidence_reference' => 'FULL-SORT-200',
            'recovery_passed' => true,
        ]);
    }
}
