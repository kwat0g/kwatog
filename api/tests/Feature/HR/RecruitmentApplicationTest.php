<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ApplicationStage;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Models\Position;
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

    public function test_hr_application_filters_and_pagination_are_server_side(): void
    {
        $other = $this->application->replicate();
        $other->application_number = 'JA-T-' . substr(uniqid(), -5);
        $other->tracking_code = 'RCT-OTHER1';
        $other->first_name = 'Ana';
        $other->last_name = 'Other';
        $other->email = 'ana@test.com';
        $other->save();

        $response = $this->actingAs($this->hrUser)->getJson(
            '/api/v1/hr/recruitment/applications?search=Juan&sort=full_name&direction=asc&per_page=1'
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.first_name', 'Juan');
        $response->assertJsonPath('meta.per_page', 1);
    }

    public function test_employee_create_permission_cannot_convert_without_recruitment_hire(): void
    {
        $role = Role::create([
            'name' => 'Recruitment Conversion Test',
            'slug' => 'recruitment_conversion_test',
            'is_system' => false,
        ]);
        $role->permissions()->sync([
            Permission::where('slug', 'hr.employees.create')->firstOrFail()->id,
        ]);
        $employeeCreator = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->application->stage = ApplicationStage::Hired;
        $this->application->save();
        $department = Department::findOrFail($this->posting->department_id);
        $position = Position::factory()->create(['department_id' => $department->id]);

        $response = $this->actingAs($employeeCreator)->postJson('/api/v1/hr/employees', [
            'first_name' => 'Unauthorized',
            'last_name' => 'Conversion',
            'birth_date' => '1990-01-01',
            'gender' => 'male',
            'civil_status' => 'single',
            'street_address' => 'Test address',
            'city' => 'Dasmarinas',
            'province' => 'Cavite',
            'mobile_number' => '09170000001',
            'email' => 'unauthorized-conversion@example.com',
            'emergency_contact_name' => 'Emergency Contact',
            'emergency_contact_phone' => '09170000002',
            'department_id' => $department->hash_id,
            'position_id' => $position->hash_id,
            'employment_type' => 'regular',
            'pay_type' => 'monthly',
            'date_hired' => now()->subDay()->toDateString(),
            'basic_monthly_salary' => '25000.00',
            'from_application' => $this->application->hash_id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('employees', [
            'email' => 'unauthorized-conversion@example.com',
        ]);
    }

    public function test_applications_only_actor_cannot_advance_offer_to_hired(): void
    {
        $role = Role::create([
            'name' => 'Recruitment Applications Only',
            'slug' => 'recruitment_applications_only',
            'is_system' => false,
        ]);
        $role->permissions()->sync([
            Permission::where('slug', 'hr.recruitment.applications')->firstOrFail()->id,
        ]);
        $applicationsOnly = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->application->forceFill(['stage' => ApplicationStage::Offer])->save();

        $this->actingAs($applicationsOnly)
            ->patchJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/stage", [
                'action' => 'advance',
            ])
            ->assertForbidden();

        $this->assertSame(ApplicationStage::Offer, $this->application->fresh()->stage);
    }

    public function test_hire_permission_can_advance_offer_to_hired(): void
    {
        $role = Role::create([
            'name' => 'Recruitment Hire Only',
            'slug' => 'recruitment_hire_only',
            'is_system' => false,
        ]);
        $role->permissions()->sync([
            Permission::where('slug', 'hr.recruitment.hire')->firstOrFail()->id,
        ]);
        $hireActor = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->application->forceFill(['stage' => ApplicationStage::Offer])->save();

        $this->actingAs($hireActor)
            ->patchJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/stage", [
                'action' => 'advance',
            ])
            ->assertOk()
            ->assertJsonPath('data.stage', ApplicationStage::Hired->value);
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

    public function test_offer_requires_a_passed_interview_and_records_decision_history(): void
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

        $this->actingAs($this->hrUser)
            ->patchJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/stage", [
                'action' => 'advance',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'At least one interview must be marked passed before advancing to offer.');

        $this->actingAs($this->hrUser)
            ->patchJson("/api/v1/hr/recruitment/interviews/{$interview->hash_id}", [
                'outcome' => 'passed',
            ])
            ->assertOk();

        $this->actingAs($this->hrUser)
            ->patchJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/stage", [
                'action' => 'advance',
            ])
            ->assertOk()
            ->assertJsonPath('data.stage', 'offer');

        $this->assertDatabaseHas('recruitment_application_events', [
            'job_application_id' => $this->application->id,
            'event_type' => 'stage.advanced',
            'from_stage' => 'interview',
            'to_stage' => 'offer',
            'actor_user_id' => $this->hrUser->id,
        ]);

        $history = $this->actingAs($this->hrUser)
            ->getJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/history");

        $history->assertOk();
        $history->assertJsonFragment(['event_type' => 'interview.updated']);
        $history->assertJsonFragment(['event_type' => 'stage.advanced', 'to_stage' => 'offer']);
        $history->assertJsonMissing(['body' => 'Strong candidate, proceed to screening.']);
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
