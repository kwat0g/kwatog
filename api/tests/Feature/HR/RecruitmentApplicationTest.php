<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ApplicationStage;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Mail\ApplicationStatusUpdatedMail;
use App\Modules\HR\Mail\InterviewDetailsUpdatedMail;
use App\Modules\HR\Mail\InterviewScheduledMail;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
        Mail::fake();

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
        Mail::assertQueued(ApplicationStatusUpdatedMail::class, fn (ApplicationStatusUpdatedMail $mail): bool =>
            $mail->hasTo('juan@test.com')
            && $mail->previousStage === 'new'
            && $mail->currentStage === 'screening'
        );
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
        Mail::assertQueued(ApplicationStatusUpdatedMail::class, fn (ApplicationStatusUpdatedMail $mail): bool =>
            $mail->hasTo('juan@test.com')
            && $mail->currentStage === 'rejected'
        );
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

        $response->assertStatus(201);
        $response->assertJsonPath('data.interviewer_name', 'Maria Santos');
        $this->assertDatabaseCount('application_interviews', 1);
        Mail::assertQueued(InterviewScheduledMail::class, fn (InterviewScheduledMail $mail): bool =>
            $mail->hasTo('juan@test.com')
            && $mail->interview->interviewer_name === 'Maria Santos'
        );
    }

    public function test_hr_can_reschedule_interview_and_candidate_update_email_is_queued(): void
    {
        $this->application->stage = ApplicationStage::Interview;
        $this->application->save();
        $interview = ApplicationInterview::create([
            'job_application_id' => $this->application->id,
            'scheduled_at' => now()->addDays(2),
            'location' => 'HR Office',
            'interviewer_name' => 'Maria Santos',
            'created_by' => $this->hrUser->id,
        ]);

        $response = $this->actingAs($this->hrUser)
            ->patchJson("/api/v1/hr/recruitment/interviews/{$interview->hash_id}", [
                'scheduled_at' => now()->addDays(4)->toIso8601String(),
                'location' => 'Zoom interview',
                'interviewer_name' => 'Ana Reyes',
                'outcome' => 'passed',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.location', 'Zoom interview');
        $response->assertJsonPath('data.interviewer_name', 'Ana Reyes');
        Mail::assertQueued(InterviewDetailsUpdatedMail::class, fn (InterviewDetailsUpdatedMail $mail): bool =>
            $mail->hasTo('juan@test.com')
            && $mail->interview->location === 'Zoom interview'
            && $mail->interview->outcome?->value === 'passed'
        );
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

        // Advancing past a terminal stage is a business-rule violation, so the
        // SPA gets a 422 with the message — not an opaque 500.
        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Cannot advance from terminal stage: hired');
    }
}
