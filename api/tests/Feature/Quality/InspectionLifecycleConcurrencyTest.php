<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\InspectionMode;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectionLifecycleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private InspectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        foreach (['quality.inspections.manage', 'quality.inspections.view'] as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'quality']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $this->actingAs($this->user);
        $this->service = app(InspectionService::class);
    }

    public function test_completion_rechecks_a_terminal_inspection_before_writing(): void
    {
        $inspection = $this->makeInspection(InspectionStatus::InProgress);
        $stale = $inspection->fresh();

        $inspection->forceFill([
            'status' => InspectionStatus::Passed,
            'completed_at' => now(),
        ])->save();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Inspection is already finalised.');

        $this->service->complete($stale, $this->user);
    }

    public function test_measurement_entry_cannot_reopen_a_terminal_inspection(): void
    {
        $inspection = $this->makeInspection(InspectionStatus::InProgress);
        $stale = $inspection->fresh();
        $measurement = $inspection->measurements()->firstOrFail();

        $inspection->forceFill([
            'status' => InspectionStatus::Failed,
            'completed_at' => now(),
        ])->save();

        try {
            $this->service->recordMeasurements($stale, [
                $measurement->id => ['is_pass' => true],
            ], $this->user);
            $this->fail('A terminal inspection must reject stale measurement input.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Inspection is already finalised.', $e->getMessage());
        }

        $this->assertSame(InspectionStatus::Failed, $inspection->fresh()->status);
        $this->assertFalse((bool) $measurement->fresh()->is_pass);
    }

    public function test_legacy_measurement_route_rejects_a_stale_result_version_without_overwriting_evidence(): void
    {
        $inspection = $this->makeInspection(InspectionStatus::InProgress);
        $measurement = $inspection->measurements()->firstOrFail();

        $this->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/measurements', [
            'expected_result_version' => 0,
            'measurements' => [['id' => $measurement->hash_id, 'is_pass' => true]],
        ])->assertSuccessful()->assertJsonPath('data.result_version', 1);

        $this->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/measurements', [
            'expected_result_version' => 0,
            'measurements' => [['id' => $measurement->hash_id, 'is_pass' => false]],
        ])->assertStatus(409)->assertJsonPath('code', 'inspection_state_conflict');

        $this->assertTrue((bool) $measurement->fresh()->is_pass);
        $this->assertSame(1, (int) $inspection->fresh()->result_version);
    }

    public function test_legacy_item_result_route_rejects_a_stale_result_version_without_overwriting_evidence(): void
    {
        $inspection = $this->makeInspection(InspectionStatus::InProgress);
        $measurement = $inspection->measurements()->firstOrFail();
        $measurement->forceFill(['is_pass' => null])->save();

        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/item-results', [
            'expected_result_version' => 0,
            'items' => [['id' => $measurement->hash_id, 'result' => 'passed']],
            'submit' => false,
        ])->assertSuccessful()->assertJsonPath('data.result_version', 1);

        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', [
            'expected_result_version' => 0,
            'result' => 'passed',
            'coverage_confirmed' => true,
            'inspected_quantity' => 1,
            'sample_defect_count' => 0,
            'critical_defect' => false,
            'defects_removed_confirmed' => false,
        ])->assertStatus(409)->assertJsonPath('code', 'inspection_state_conflict');

        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/item-results', [
            'expected_result_version' => 0,
            'items' => [['id' => $measurement->hash_id, 'result' => 'failed', 'problem_description' => 'Stale recheck']],
            'submit' => false,
        ])->assertStatus(409)->assertJsonPath('code', 'inspection_state_conflict');

        $this->assertSame('passed', $measurement->fresh()->item_result);
        $this->assertSame(1, (int) $inspection->fresh()->result_version);
    }

    public function test_legacy_cancel_route_rejects_a_stale_result_version_after_new_evidence_is_saved(): void
    {
        $inspection = $this->makeInspection(InspectionStatus::InProgress);
        $measurement = $inspection->measurements()->firstOrFail();

        $this->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/measurements', [
            'expected_result_version' => 0,
            'measurements' => [['id' => $measurement->hash_id, 'is_pass' => true]],
        ])->assertSuccessful()->assertJsonPath('data.result_version', 1);

        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/cancel', [
            'expected_result_version' => 0,
            'reason' => 'Cancel from stale screen',
        ])->assertStatus(409)->assertJsonPath('code', 'inspection_state_conflict');

        $this->assertSame(InspectionStatus::InProgress, $inspection->fresh()->status);
        $this->assertTrue((bool) $measurement->fresh()->is_pass);
    }

    public function test_legacy_lot_result_route_rejects_stale_measurement_evidence(): void
    {
        $inspection = $this->makeInspection(InspectionStatus::InProgress);
        $inspection->forceFill(['inspection_mode' => InspectionMode::LotChecklist])->save();
        $measurement = $inspection->measurements()->firstOrFail();

        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/lot-result', [
            'expected_result_version' => 0,
            'checklist' => [['id' => $measurement->hash_id, 'is_pass' => true]],
            'measurements' => [],
            'sample_defect_count' => 0,
            'coverage_confirmed' => true,
            'complete' => false,
        ])->assertSuccessful()->assertJsonPath('data.result_version', 1);

        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/lot-result', [
            'expected_result_version' => 0,
            'checklist' => [['id' => $measurement->hash_id, 'is_pass' => false]],
            'measurements' => [],
            'sample_defect_count' => 0,
            'coverage_confirmed' => true,
            'complete' => false,
        ])->assertStatus(409)->assertJsonPath('code', 'inspection_state_conflict');

        $this->assertTrue((bool) $measurement->fresh()->is_pass);
        $this->assertSame(1, (int) $inspection->fresh()->result_version);
    }

    public function test_cancellation_cannot_overwrite_a_terminal_inspection(): void
    {
        $inspection = $this->makeInspection(InspectionStatus::InProgress);
        $stale = $inspection->fresh();

        $inspection->forceFill([
            'status' => InspectionStatus::Passed,
            'completed_at' => now(),
        ])->save();

        try {
            $this->service->cancel($stale, 'stale operator request', $this->user);
            $this->fail('A terminal inspection must reject stale cancellation.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Inspection is already finalised.', $e->getMessage());
        }

        $this->assertSame(InspectionStatus::Passed, $inspection->fresh()->status);
    }

    private function makeInspection(InspectionStatus $status): Inspection
    {
        $product = Product::factory()->create();
        $inspection = Inspection::query()->create([
            'inspection_number' => 'QC-LOCK-'.uniqid(),
            'stage' => InspectionStage::Outgoing,
            'status' => $status,
            'product_id' => $product->id,
            'batch_quantity' => 10,
            'sample_size' => 1,
            'accept_count' => 0,
            'reject_count' => 1,
            'defect_count' => 0,
            'inspector_id' => $this->user->id,
            'started_at' => now(),
        ]);

        InspectionMeasurement::query()->create([
            'inspection_id' => $inspection->id,
            'sample_index' => 1,
            'parameter_name' => 'Visual verdict',
            'parameter_type' => 'visual',
            'is_critical' => true,
            'is_pass' => false,
        ]);

        return $inspection->fresh(['measurements']);
    }
}
