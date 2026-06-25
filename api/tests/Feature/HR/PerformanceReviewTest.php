<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ReviewCycleStatus;
use App\Modules\HR\Enums\ReviewStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\ReviewCycle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $hrUser;
    private User $reviewerUser;
    private User $employeeUser;
    private Employee $reviewerEmp;
    private Employee $subjectEmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $hrRole = Role::where('slug', 'hr_officer')->firstOrFail();
        $this->hrUser = User::factory()->create(['role_id' => $hrRole->id, 'is_active' => true]);

        $empRole = Role::where('slug', 'employee')->firstOrFail();

        $this->reviewerEmp = Employee::factory()->create();
        $this->reviewerUser = User::factory()->create([
            'role_id'     => $empRole->id,
            'is_active'   => true,
            'employee_id' => $this->reviewerEmp->id,
        ]);

        $this->subjectEmp = Employee::factory()->create();
        $this->employeeUser = User::factory()->create([
            'role_id'     => $empRole->id,
            'is_active'   => true,
            'employee_id' => $this->subjectEmp->id,
        ]);
    }

    public function test_hr_can_create_review_cycle(): void
    {
        $response = $this->actingAs($this->hrUser)->postJson('/api/v1/hr/performance-reviews/cycles', [
            'name'       => '2026 Annual Review',
            'cycle_type' => 'annual',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('review_cycles', [
            'name'   => '2026 Annual Review',
            'status' => 'draft',
        ]);
    }

    public function test_hr_can_activate_cycle(): void
    {
        $cycle = new ReviewCycle();
        $cycle->fill([
            'name'       => 'Q2 Review',
            'cycle_type' => 'quarterly',
            'start_date' => '2026-04-01',
            'end_date'   => '2026-06-30',
            'created_by' => $this->hrUser->id,
        ]);
        $cycle->forceFill(['status' => ReviewCycleStatus::Draft->value])->save();

        $response = $this->actingAs($this->hrUser)->postJson("/api/v1/hr/performance-reviews/cycles/{$cycle->hash_id}/activate");
        $response->assertOk();
        $this->assertDatabaseHas('review_cycles', ['id' => $cycle->id, 'status' => 'active']);
    }

    public function test_hr_can_create_review_assignment(): void
    {
        $cycle = new ReviewCycle();
        $cycle->fill(['name' => 'Test Cycle', 'cycle_type' => 'annual', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'created_by' => $this->hrUser->id]);
        $cycle->forceFill(['status' => ReviewCycleStatus::Active->value])->save();

        $response = $this->actingAs($this->hrUser)->postJson('/api/v1/hr/performance-reviews', [
            'review_cycle_id' => $cycle->id,
            'employee_id'     => $this->subjectEmp->id,
            'reviewer_id'     => $this->reviewerEmp->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('performance_reviews', [
            'review_cycle_id' => $cycle->id,
            'employee_id'     => $this->subjectEmp->id,
            'reviewer_id'     => $this->reviewerEmp->id,
            'status'          => 'pending',
        ]);
    }

    public function test_reviewer_can_submit_review(): void
    {
        $cycle = $this->makeCycle(ReviewCycleStatus::Active);
        $review = $this->makeReview($cycle, ReviewStatus::Pending);

        $response = $this->actingAs($this->reviewerUser)->postJson("/api/v1/hr/performance-reviews/{$review->hash_id}/submit", [
            'ratings'        => ['teamwork' => 4, 'quality' => 5, 'productivity' => 4],
            'strengths'      => 'Strong technical skills',
            'improvements'   => 'Communication could improve',
            'goals'          => 'Lead a project by Q4',
            'overall_score'  => '4.33',
            'overall_rating' => 'exceeds_expectations',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('performance_reviews', [
            'id'     => $review->id,
            'status' => 'submitted',
        ]);
    }

    public function test_non_reviewer_cannot_submit(): void
    {
        $cycle = $this->makeCycle(ReviewCycleStatus::Active);
        $review = $this->makeReview($cycle, ReviewStatus::Pending);

        $response = $this->actingAs($this->employeeUser)->postJson("/api/v1/hr/performance-reviews/{$review->hash_id}/submit", [
            'ratings' => ['teamwork' => 3], 'overall_score' => '3.00', 'overall_rating' => 'meets',
        ]);

        $response->assertStatus(403);
    }

    public function test_employee_can_acknowledge_their_review(): void
    {
        $cycle = $this->makeCycle(ReviewCycleStatus::Active);
        $review = $this->makeReview($cycle, ReviewStatus::Submitted);

        $response = $this->actingAs($this->employeeUser)->postJson("/api/v1/hr/performance-reviews/{$review->hash_id}/acknowledge");

        $response->assertOk();
        $this->assertDatabaseHas('performance_reviews', ['id' => $review->id, 'status' => 'acknowledged']);
    }

    public function test_non_subject_cannot_acknowledge(): void
    {
        $cycle = $this->makeCycle(ReviewCycleStatus::Active);
        $review = $this->makeReview($cycle, ReviewStatus::Submitted);

        $response = $this->actingAs($this->reviewerUser)->postJson("/api/v1/hr/performance-reviews/{$review->hash_id}/acknowledge");
        $response->assertStatus(403);
    }

    private function makeCycle(ReviewCycleStatus $status): ReviewCycle
    {
        $cycle = new ReviewCycle();
        $cycle->fill(['name' => 'C', 'cycle_type' => 'annual', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'created_by' => $this->hrUser->id]);
        $cycle->forceFill(['status' => $status->value])->save();
        return $cycle;
    }

    private function makeReview(ReviewCycle $cycle, ReviewStatus $status): PerformanceReview
    {
        $review = new PerformanceReview();
        $review->fill(['review_cycle_id' => $cycle->id, 'employee_id' => $this->subjectEmp->id, 'reviewer_id' => $this->reviewerEmp->id]);
        $review->forceFill(['status' => $status->value])->save();
        return $review;
    }
}
