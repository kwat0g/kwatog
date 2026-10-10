<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HistoricalHeldRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_defect_historical_batch_still_requires_independent_process_recovery(): void
    {
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->withRole('production_manager')->create(['is_active' => true]);
        $maker = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $wo = WorkOrder::factory()->create(['status' => 'in_progress', 'quantity_target' => 200, 'quantity_produced' => 200]);
        $spec = InspectionSpec::create(['product_id' => $wo->product_id, 'version' => 1, 'is_active' => true, 'created_by' => $maker->id]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Appearance',
            'parameter_type' => 'visual', 'acceptance_criterion' => 'No cracks', 'is_critical' => false]);
        $output = WorkOrderOutput::create(['work_order_id' => $wo->id, 'recorded_by' => $manager->id,
            'recorded_at' => now(), 'production_started_at' => now()->subHours(3), 'production_finished_at' => now()->subHours(2),
            'good_count' => 0, 'reject_count' => 0, 'held_quantity' => 200]);
        $inspection = app(InspectionService::class)->createForHeldOutput($output, $wo, $manager);
        $this->actingAs($maker)->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result',
            ['expected_result_version' => 0, 'coverage_confirmed' => true, 'inspected_quantity' => 200,
                'sample_defect_count' => 0, 'problem_description' => 'Production preceded required QC release.', 'measurements' => []])
            ->assertSuccessful()->assertJsonPath('data.recorded_result', 'failed')->assertJsonPath('data.status', 'awaiting_review');
        $this->assertSame(200, $output->fresh()->held_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_full_historical_batch_stays_held_until_independent_sorting_and_never_counts_twice(): void
    {
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->withRole('production_manager')->create(['is_active' => true]);
        $maker = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $checker = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $sorter = User::factory()->create(['is_active' => true]);
        $wo = WorkOrder::factory()->create(['status' => 'in_progress', 'quantity_target' => 200,
            'quantity_produced' => 200, 'quantity_good' => 0, 'quantity_rejected' => 0]);
        $spec = InspectionSpec::create(['product_id' => $wo->product_id, 'version' => 1, 'is_active' => true, 'created_by' => $maker->id]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Appearance',
            'parameter_type' => 'visual', 'acceptance_criterion' => 'No cracks', 'is_critical' => false]);
        $startup = Inspection::create(['inspection_number' => 'QC-HISTORY-STARTUP', 'stage' => 'in_process', 'status' => 'draft',
            'entity_type' => 'work_order', 'entity_id' => $wo->id, 'product_id' => $wo->product_id,
            'batch_quantity' => 200, 'sample_size' => 5, 'startup_trial_required' => true]);
        $output = WorkOrderOutput::create(['work_order_id' => $wo->id, 'recorded_by' => $manager->id,
            'recorded_at' => now(), 'production_started_at' => now()->subHours(3), 'production_finished_at' => now()->subHours(2),
            'good_count' => 0, 'reject_count' => 0, 'held_quantity' => 200]);
        $inspection = app(InspectionService::class)->createForHeldOutput($output, $wo, $manager);
        $this->assertSame(200, $inspection->counted_capture['required_quantity']);
        $this->assertSame('draft', $startup->fresh()->status->value);
        $this->actingAs($maker)->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result',
            ['expected_result_version' => 0, 'coverage_confirmed' => true, 'inspected_quantity' => 200,
                'sample_defect_count' => 3, 'problem_description' => 'Three cracked pieces after full sorting', 'measurements' => []])
            ->assertSuccessful()->assertJsonPath('data.status', 'awaiting_review')->assertJsonPath('data.recorded_result', 'failed');
        $this->assertSame(200, $output->fresh()->held_quantity);
        $this->assertSame(0, $output->fresh()->good_count);
        $inspection = app(InspectionService::class)->review($inspection->fresh(), 'failed', 'Confirmed the deviation and three defective pieces.', $checker, 1);
        $this->assertSame(200, $inspection->processRecoveryQuantity());
        $defect = DefectType::create(['code' => 'HIST-CRACK', 'name' => 'Crack', 'is_active' => true]);
        $ncr = $inspection->ncr()->firstOrFail();
        app(NcrService::class)->setDisposition($ncr, 'rework', 'Setup was not verified before batch production.',
            'Corrected setup and reinspected all pieces.', $checker, '197', [
                'inspected_quantity' => '200', 'evidence_reference' => 'SORT-HISTORY-200', 'performed_by' => $sorter->id,
                'recovery_passed' => true, 'trial_scrapped_quantity' => 3,
                'trial_defects' => [['defect_type_id' => $defect->id, 'count' => 3]],
            ]);
        $this->assertSame(0, $output->fresh()->held_quantity);
        $this->assertSame(197, $output->fresh()->good_count);
        $this->assertSame(3, $output->fresh()->reject_count);
        $this->assertSame(200, $output->fresh()->physical_count);
        $this->assertSame(200, (int) $wo->fresh()->quantity_produced);
        $this->assertSame('cancelled', $startup->fresh()->status->value);
        $wo->fresh()->assertProcessQualityReleased();
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
