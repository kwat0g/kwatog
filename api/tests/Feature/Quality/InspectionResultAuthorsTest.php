<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\ForbiddenActionException;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\InspectionMode;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Resources\InspectionResource;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class InspectionResultAuthorsTest extends TestCase
{
    use RefreshDatabase;

    private InspectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InspectionService::class);
    }

    public function test_user_who_records_a_result_cannot_review_it_when_another_user_was_assigned_inspector(): void
    {
        $warehouseInspector = User::factory()->create(['is_active' => true]);
        $qcAuthor = User::factory()->create(['is_active' => true]);
        $checker = User::factory()->create(['is_active' => true]);
        $this->grantReviewPermission($qcAuthor, $checker);

        $inspection = $this->makeIncomingInspection($warehouseInspector);
        $measurement = $this->makeChecklistRow($inspection);
        $this->service->recordMeasurements($inspection, [$measurement->id => []], $warehouseInspector);
        $this->assertDatabaseMissing('inspection_result_authors', [
            'inspection_id' => $inspection->id,
            'user_id' => $warehouseInspector->id,
        ]);

        $awaitingReview = $this->service->recordLotResult($inspection->fresh(), [
            'checklist' => [['id' => $measurement->hash_id, 'is_pass' => true]],
            'measurements' => [],
            'sample_defect_count' => 0,
            'complete' => true,
        ], $qcAuthor);

        $this->assertSame(InspectionStatus::AwaitingReview, $awaitingReview->status);
        $this->assertSame($warehouseInspector->id, $awaitingReview->inspector_id);
        $this->assertReviewDenied($awaitingReview, $qcAuthor);
        $this->assertDatabaseHas('inspection_result_authors', [
            'inspection_id' => $inspection->id,
            'user_id' => $qcAuthor->id,
        ]);
        $this->assertDatabaseMissing('inspection_result_authors', [
            'inspection_id' => $inspection->id,
            'user_id' => $warehouseInspector->id,
        ]);
        $this->assertFalse($this->canReview($awaitingReview, $qcAuthor));
        $this->assertTrue($this->canReview($awaitingReview, $checker));

        $reviewed = $this->service->review($awaitingReview, 'passed', null, $checker);

        $this->assertSame(InspectionStatus::Passed, $reviewed->status);
        $this->assertSame($checker->id, $reviewed->reviewed_by);
        $this->assertTrue($reviewed->isMakerChecked());
    }

    public function test_all_result_editors_are_blocked_from_review_after_a_second_editor_completes(): void
    {
        $warehouseInspector = User::factory()->create(['is_active' => true]);
        $firstEditor = User::factory()->create(['is_active' => true]);
        $secondEditor = User::factory()->create(['is_active' => true]);
        $checker = User::factory()->create(['is_active' => true]);
        $this->grantReviewPermission($firstEditor, $secondEditor, $checker);

        $inspection = $this->makeIncomingInspection($warehouseInspector);
        $measurement = $this->makeChecklistRow($inspection);

        $inspection = $this->service->recordLotResult($inspection, [
            'checklist' => [['id' => $measurement->hash_id, 'is_pass' => true]],
            'measurements' => [],
            'sample_defect_count' => 0,
            'complete' => false,
        ], $firstEditor);
        $inspection = $this->service->recordLotResult($inspection, [
            'checklist' => [[
                'id' => $measurement->hash_id,
                'is_pass' => true,
                'notes' => 'Second editor verified the appearance.',
            ]],
            'measurements' => [],
            'sample_defect_count' => 0,
            'complete' => false,
        ], $secondEditor);
        $awaitingReview = $this->service->complete($inspection, $secondEditor);

        $this->assertSame(InspectionStatus::AwaitingReview, $awaitingReview->status);
        $this->assertDatabaseHas('inspection_result_authors', [
            'inspection_id' => $inspection->id,
            'user_id' => $firstEditor->id,
        ]);
        $this->assertDatabaseHas('inspection_result_authors', [
            'inspection_id' => $inspection->id,
            'user_id' => $secondEditor->id,
        ]);
        $this->assertFalse($this->canReview($awaitingReview, $firstEditor));
        $this->assertFalse($this->canReview($awaitingReview, $secondEditor));
        $this->assertTrue($this->canReview($awaitingReview, $checker));

        $this->assertReviewDenied($awaitingReview, $firstEditor);
        $this->assertReviewDenied($awaitingReview, $secondEditor);

        $reviewed = $this->service->review($awaitingReview, 'passed', null, $checker);
        $this->assertSame(InspectionStatus::Passed, $reviewed->status);
        $this->assertSame($checker->id, $reviewed->reviewed_by);
    }

    private function makeIncomingInspection(User $assignedInspector): Inspection
    {
        return Inspection::query()->create([
            'inspection_number' => 'QC-AUTHOR-'.uniqid(),
            'stage' => InspectionStage::Incoming,
            'status' => InspectionStatus::Draft,
            'inspection_mode' => InspectionMode::LotChecklist,
            'entity_type' => 'grn',
            'entity_id' => 1,
            'batch_quantity' => 1,
            'sample_size' => 1,
            'accept_count' => 0,
            'reject_count' => 1,
            'defect_count' => 0,
            'inspector_id' => $assignedInspector->id,
            'started_at' => now(),
        ]);
    }

    private function makeChecklistRow(Inspection $inspection): InspectionMeasurement
    {
        return InspectionMeasurement::query()->create([
            'inspection_id' => $inspection->id,
            'sample_index' => 1,
            'parameter_name' => 'Appearance',
            'parameter_type' => 'visual',
            'is_critical' => true,
            'is_pass' => null,
        ]);
    }

    private function grantReviewPermission(User ...$users): void
    {
        $role = Role::create([
            'name' => 'QC Review '.uniqid(),
            'slug' => 'qc-review-'.uniqid(),
            'is_system' => false,
        ]);
        $permission = Permission::firstOrCreate(
            ['slug' => 'quality.inspections.review'],
            ['name' => 'Review quality inspections', 'module' => 'quality'],
        );
        $role->permissions()->attach($permission->id);

        foreach ($users as $user) {
            $user->forceFill(['role_id' => $role->id])->save();
        }
    }

    private function canReview(Inspection $inspection, User $user): bool
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        return (new InspectionResource($inspection))->toArray($request)['can_review'];
    }

    private function assertReviewDenied(Inspection $inspection, User $user): void
    {
        try {
            $this->service->review($inspection, 'passed', null, $user);
            $this->fail('An inspection result author must not review their own evidence.');
        } catch (ForbiddenActionException $exception) {
            $this->assertStringContainsString('cannot review an inspection you performed', $exception->getMessage());
        }
    }
}
