<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Models\Position;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecruitmentPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $hrUser;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $hrRole = Role::where('slug', 'hr_officer')->firstOrFail();
        $this->hrUser = User::factory()->create(['role_id' => $hrRole->id, 'is_active' => true]);

        $empRole = Role::where('slug', 'employee')->firstOrFail();
        $this->employee = User::factory()->create(['role_id' => $empRole->id, 'is_active' => true]);
    }

    public function test_hr_can_create_job_posting(): void
    {
        $dept = Department::factory()->create();
        $position = Position::factory()->create(['department_id' => $dept->id]);

        $response = $this->actingAs($this->hrUser)->postJson('/api/v1/hr/recruitment/postings', [
            'title'           => 'Injection Molding Operator',
            'department_id'   => $dept->id,
            'position_id'     => $position->id,
            'description'     => 'Operate injection molding machines.',
            'requirements'    => 'At least 1 year experience.',
            'employment_type' => 'regular',
            'slots'           => 2,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.title', 'Injection Molding Operator');
        $response->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('job_postings', [
            'title'  => 'Injection Molding Operator',
            'status' => 'draft',
            'slots'  => 2,
        ]);
    }

    public function test_hr_can_list_postings(): void
    {
        $dept = Department::factory()->create();
        $posting = new JobPosting();
        $posting->fill([
            'posting_number'  => 'JP-T-' . substr(uniqid(), -5),
            'title'           => 'Test Position',
            'department_id'   => $dept->id,
            'description'     => 'Test description',
            'requirements'    => 'Test requirements',
            'employment_type' => 'regular',
            'created_by'      => $this->hrUser->id,
        ]);
        $posting->status = JobPostingStatus::Open;
        $posting->save();

        $response = $this->actingAs($this->hrUser)->getJson('/api/v1/hr/recruitment/postings');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_position_must_belong_to_selected_department(): void
    {
        $selectedDepartment = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $position = Position::factory()->create(['department_id' => $otherDepartment->id]);

        $response = $this->actingAs($this->hrUser)->postJson('/api/v1/hr/recruitment/postings', [
            'title'           => 'Mismatched Position',
            'department_id'   => $selectedDepartment->id,
            'position_id'     => $position->id,
            'description'     => 'Description.',
            'requirements'    => 'Requirements.',
            'employment_type' => 'regular',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['position_id']);
    }

    public function test_hr_can_filter_archived_postings_and_restore_them(): void
    {
        $posting = $this->makePosting('Archived Draft', JobPostingStatus::Draft);

        $this->actingAs($this->hrUser)
            ->deleteJson("/api/v1/hr/recruitment/postings/{$posting->hash_id}")
            ->assertNoContent();

        $archivedList = $this->actingAs($this->hrUser)
            ->getJson('/api/v1/hr/recruitment/postings?trashed=only&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertNotNull($archivedList->json('data.0.deleted_at'));

        $archivedDetail = $this->actingAs($this->hrUser)
            ->getJson("/api/v1/hr/recruitment/postings/{$posting->hash_id}")
            ->assertOk();
        $this->assertNotNull($archivedDetail->json('data.deleted_at'));

        $this->actingAs($this->hrUser)
            ->patchJson("/api/v1/hr/recruitment/postings/{$posting->hash_id}/restore")
            ->assertOk();

        $this->assertDatabaseHas('job_postings', [
            'id' => $posting->id,
            'deleted_at' => null,
        ]);
    }

    public function test_posting_with_applications_cannot_be_archived(): void
    {
        $posting = $this->makePosting('Posting With Application', JobPostingStatus::Draft);
        JobApplication::create([
            'application_number' => 'JA-ARCHIVE-'.substr(uniqid(), -8),
            'job_posting_id' => $posting->id,
            // tracking_code is varchar(10) — production builds exactly
            // 'RCT-' + 6 chars, so a fixture may not exceed that either.
            'tracking_code' => 'RCT-A'.substr(uniqid(), -5),
            'first_name' => 'Applicant',
            'last_name' => 'Candidate',
            'email' => 'archive@example.com',
            'phone' => '09170000000',
            'resume_path' => 'resume.pdf',
            'resume_original_name' => 'resume.pdf',
            'applied_at' => now(),
        ]);

        $this->actingAs($this->hrUser)
            ->deleteJson("/api/v1/hr/recruitment/postings/{$posting->hash_id}")
            ->assertUnprocessable();

        $this->assertNull($posting->fresh()->deleted_at);
    }

    private function makePosting(string $title, JobPostingStatus $status): JobPosting
    {
        $department = Department::factory()->create();
        $posting = new JobPosting();
        $posting->fill([
            'posting_number'  => 'JP-T-' . substr(uniqid(), -5),
            'title'           => $title,
            'department_id'   => $department->id,
            'description'     => 'Test description',
            'requirements'    => 'Test requirements',
            'employment_type' => 'regular',
            'created_by'      => $this->hrUser->id,
        ]);
        $posting->status = $status;
        $posting->save();

        return $posting;
    }

    public function test_hr_can_change_posting_status_to_open(): void
    {
        $dept = Department::factory()->create();
        $posting = new JobPosting();
        $posting->fill([
            'posting_number'  => 'JP-T-' . substr(uniqid(), -5),
            'title'           => 'QC Inspector',
            'department_id'   => $dept->id,
            'description'     => 'Quality control.',
            'requirements'    => 'Experience required.',
            'employment_type' => 'regular',
            'created_by'      => $this->hrUser->id,
        ]);
        $posting->status = JobPostingStatus::Draft;
        $posting->save();

        $response = $this->actingAs($this->hrUser)->patchJson("/api/v1/hr/recruitment/postings/{$posting->hash_id}/status", [
            'status' => 'open',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'open');
        $this->assertNotNull($posting->fresh()->posted_at);
    }

    public function test_employee_cannot_access_recruitment(): void
    {
        $response = $this->actingAs($this->employee)->getJson('/api/v1/hr/recruitment/postings');
        $response->assertStatus(403);
    }
}
