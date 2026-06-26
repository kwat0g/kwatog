<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ApplicationStage;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecruitmentApplicationTest extends TestCase
{
    use RefreshDatabase;

    private User $hrUser;
    private JobPosting $posting;
    private JobApplication $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $hrRole = Role::where('slug', 'hr_officer')->firstOrFail();
        $this->hrUser = User::factory()->create(['role_id' => $hrRole->id, 'is_active' => true]);

        $dept = Department::factory()->create();
        $this->posting = new JobPosting();
        $this->posting->fill([
            'posting_number'  => 'JP-T-' . substr(uniqid(), -5),
            'title'           => 'Test Position',
            'department_id'   => $dept->id,
            'description'     => 'Desc',
            'requirements'    => 'Reqs',
            'employment_type' => 'regular',
            'created_by'      => $this->hrUser->id,
            'posted_at'       => now(),
        ]);
        $this->posting->status = JobPostingStatus::Open;
        $this->posting->save();

        $this->application = new JobApplication();
        $this->application->fill([
            'application_number'   => 'JA-T-' . substr(uniqid(), -5),
            'job_posting_id'       => $this->posting->id,
            'tracking_code'        => 'RCT-TEST01',
            'first_name'           => 'Juan',
            'last_name'            => 'Test',
            'email'                => 'juan@test.com',
            'phone'                => '09170000000',
            'resume_path'          => 'recruitment/resumes/test.pdf',
            'resume_original_name' => 'test.pdf',
            'applied_at'           => now(),
        ]);
        $this->application->stage = ApplicationStage::New;
        $this->application->save();
    }

    public function test_hr_can_list_applications(): void
    {
        $response = $this->actingAs($this->hrUser)->getJson('/api/v1/hr/recruitment/applications');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_hr_can_advance_application_stage(): void
    {
        $response = $this->actingAs($this->hrUser)
            ->patchJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/stage", [
                'action' => 'advance',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.stage', 'screening');
    }

    public function test_hr_can_reject_application(): void
    {
        $response = $this->actingAs($this->hrUser)
            ->patchJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/stage", [
                'action' => 'reject',
                'rejection_reason' => 'Does not meet qualifications.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.stage', 'rejected');
        $this->assertDatabaseHas('job_applications', [
            'id'                => $this->application->id,
            'rejected_at_stage' => 'new',
        ]);
    }

    public function test_hr_can_schedule_interview(): void
    {
        $this->application->stage = ApplicationStage::Interview;
        $this->application->save();

        $response = $this->actingAs($this->hrUser)
            ->postJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/interviews", [
                'scheduled_at'     => now()->addDays(3)->toIso8601String(),
                'location'         => 'HR Office, 2nd Floor',
                'interviewer_name' => 'Maria Santos',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.interviewer_name', 'Maria Santos');
        $this->assertDatabaseCount('application_interviews', 1);
    }

    public function test_hr_can_add_note(): void
    {
        $response = $this->actingAs($this->hrUser)
            ->postJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/notes", [
                'body' => 'Strong candidate, proceed to screening.',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('application_notes', [
            'body'    => 'Strong candidate, proceed to screening.',
            'user_id' => $this->hrUser->id,
        ]);
    }

    public function test_cannot_advance_terminal_stage(): void
    {
        $this->application->stage = ApplicationStage::Hired;
        $this->application->save();

        $response = $this->actingAs($this->hrUser)
            ->patchJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/stage", [
                'action' => 'advance',
            ]);

        $response->assertStatus(500);
    }
}
