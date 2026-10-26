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
use App\Modules\HR\Mail\ApplicationReceivedMail;
use App\Modules\HR\Mail\ApplicationStatusUpdatedMail;
use App\Modules\HR\Mail\InterviewDetailsUpdatedMail;
use App\Modules\HR\Mail\InterviewScheduledMail;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\DB;
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

    public function test_conversion_prefills_posting_department_position_and_employment_type(): void
    {
        $position = Position::factory()->create(['department_id' => $this->posting->department_id]);
        $this->posting->update(['position_id' => $position->id, 'employment_type' => 'probationary']);
        $this->application->forceFill(['stage' => ApplicationStage::Hired])->save();

        $this->actingAs($this->hrUser)
            ->getJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/convert")
            ->assertOk()
            ->assertJsonPath('data.department_id', Department::findOrFail($this->posting->department_id)->hash_id)
            ->assertJsonPath('data.position_id', $position->hash_id)
            ->assertJsonPath('data.employment_type', 'probationary');
    }

    public function test_hiring_fills_one_slot_posting_before_employee_conversion(): void
    {
        $this->posting->update(['slots' => 1]);
        $this->application->forceFill(['stage' => ApplicationStage::Offer])->save();

        $this->actingAs($this->hrUser)
            ->patchJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/stage", ['action' => 'advance'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'hired');

        $this->assertSame(JobPostingStatus::Filled, $this->posting->fresh()->status);
        $this->assertNull($this->application->fresh()->converted_employee_id);
        $this->getJson('/api/v1/public/recruitment/job-postings')
            ->assertOk()
            ->assertJsonMissing(['posting_number' => $this->posting->posting_number]);
    }

    public function test_hiring_keeps_posting_open_until_all_slots_are_filled(): void
    {
        $this->posting->update(['slots' => 2]);
        $this->application->forceFill(['stage' => ApplicationStage::Offer])->save();
        $this->actingAs($this->hrUser)
            ->patchJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/stage", ['action' => 'advance'])
            ->assertOk();
        $this->assertSame(JobPostingStatus::Open, $this->posting->fresh()->status);

        $other = $this->application->replicate();
        $other->forceFill([
            'application_number' => 'JA-T-SECOND', 'tracking_code' => 'RCT-SECOND',
            'email' => 'second@test.com', 'stage' => ApplicationStage::Offer,
        ])->save();
        $this->actingAs($this->hrUser)
            ->patchJson("/api/v1/hr/recruitment/applications/{$other->hash_id}/stage", ['action' => 'advance'])
            ->assertOk();
        $this->assertSame(JobPostingStatus::Filled, $this->posting->fresh()->status);
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
        Mail::assertQueued(ApplicationStatusUpdatedMail::class, function (ApplicationStatusUpdatedMail $mail): bool {
            $html = $mail->render();
            $mail->assertSeeInText('Application Received');
            $mail->assertSeeInText('Under Review');

            return $mail->hasTo('juan@test.com')
                && $mail->previousStage === 'new'
                && $mail->currentStage === 'screening'
                && str_contains($html, '<title>Application status updated</title>')
                && str_contains($html, 'Check application status')
                && ! str_contains($html, 'screening');
        });
    }

    public function test_application_received_email_keeps_submission_time_values(): void
    {
        $mail = new ApplicationReceivedMail($this->application, $this->posting);
        Mail::to($this->application->email)->queue($mail);

        $this->application->update(['first_name' => 'Changed', 'last_name' => 'Candidate']);
        $this->posting->update(['title' => 'Changed Position']);

        Mail::assertQueued(ApplicationReceivedMail::class, function (ApplicationReceivedMail $queued): bool {
            $html = $queued->render();

            return $queued->hasTo('juan@test.com')
                && $queued->snapshot['applicant_name'] === 'Juan Test'
                && str_contains($html, 'Juan Test')
                && str_contains($html, 'Test Position')
                && str_contains($html, 'RCT-TEST01')
                && ! str_contains($html, 'Changed Candidate')
                && ! str_contains($html, 'Changed Position');
        });
    }

    public function test_interview_scheduled_email_keeps_scheduled_values(): void
    {
        $interview = ApplicationInterview::create([
            'job_application_id' => $this->application->id,
            'scheduled_at' => '2026-11-02 09:30:00',
            'location' => 'Cavite interview room',
            'interviewer_name' => 'Original Interviewer',
            'created_by' => $this->hrUser->id,
        ]);
        $this->application->load('jobPosting');
        Mail::to($this->application->email)->queue(new InterviewScheduledMail($this->application, $interview));

        $interview->update([
            'scheduled_at' => '2026-11-05 14:00:00',
            'location' => 'Online meeting',
            'interviewer_name' => 'Changed Interviewer',
        ]);
        $this->posting->update(['title' => 'Changed Position']);

        Mail::assertQueued(InterviewScheduledMail::class, function (InterviewScheduledMail $queued): bool {
            $html = $queued->render();

            return str_contains($html, 'November 2, 2026 9:30 AM')
                && str_contains($html, 'Cavite interview room')
                && str_contains($html, 'Original Interviewer')
                && str_contains($html, 'Test Position')
                && ! str_contains($html, 'Online meeting')
                && ! str_contains($html, 'Changed Interviewer')
                && ! str_contains($html, 'Changed Position');
        });
    }

    public function test_interview_email_failures_use_the_application_snapshot_for_fallback(): void
    {
        $interview = ApplicationInterview::create([
            'job_application_id' => $this->application->id,
            'scheduled_at' => '2026-11-02 09:30:00',
            'location' => 'Cavite interview room',
            'interviewer_name' => 'Original Interviewer',
            'created_by' => $this->hrUser->id,
        ]);
        $scheduled = new InterviewScheduledMail($this->application, $interview, [$this->hrUser->id]);
        $updated = new InterviewDetailsUpdatedMail($this->application, $interview, [$this->hrUser->id]);

        $scheduled->failed(new \RuntimeException('Mail provider rejected message.'));
        $updated->failed(new \RuntimeException('Mail provider rejected message.'));

        $notices = DB::table('notifications')
            ->where('notifiable_id', $this->hrUser->id)
            ->where('type', 'email.delivery_failed')
            ->get();
        $this->assertCount(2, $notices);
        foreach ($notices as $notice) {
            $data = json_decode($notice->data, true);
            $this->assertSame('/hr/recruitment/applications/'.$this->application->hash_id, $data['link_to']);
            $this->assertStringContainsString('Juan Test', $data['message']);
        }
    }

    public function test_candidate_mail_templates_escape_untrusted_text_and_render_plain_text(): void
    {
        $this->application->forceFill(['first_name' => 'Juan **spoof** <img src=x>', 'last_name' => 'Test'])->save();
        $this->posting->update(['title' => 'Engineer **spoof** <img src=x>']);
        $interview = ApplicationInterview::create([
            'job_application_id' => $this->application->id,
            'scheduled_at' => '2026-11-02 09:30:00',
            'location' => 'Room **spoof** <img src=x>',
            'interviewer_name' => 'Ana Reyes',
            'created_by' => $this->hrUser->id,
        ]);
        $this->application->load('jobPosting');

        $mails = [
            new ApplicationReceivedMail($this->application, $this->posting),
            new ApplicationStatusUpdatedMail('Juan **spoof** <img src=x>', 'Engineer **spoof** <img src=x>', 'new', 'screening', 'RCT-TEST01', $this->application->hash_id),
            new InterviewScheduledMail($this->application, $interview),
            new InterviewDetailsUpdatedMail($this->application, $interview),
        ];

        foreach ($mails as $mail) {
            $content = $mail->content();
            $html = $mail->render();
            $text = (string) app(Markdown::class)->renderText($content->markdown, $content->with);

            $this->assertNotSame('', trim(strip_tags($text)));
            $this->assertStringContainsString('/careers/track', $html);
            $this->assertStringContainsString('/careers/track', $text);
            $this->assertStringNotContainsString('<img src=x>', $html);
            $this->assertStringNotContainsString('<strong>spoof</strong>', $html);
        }

        $statusMail = $mails[1];
        $statusHtml = $statusMail->render();
        $this->assertStringContainsString('Application Received', $statusHtml);
        $this->assertStringContainsString('Under Review', $statusHtml);
        $this->assertStringNotContainsString('>screening<', $statusHtml);
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
        Mail::assertQueued(ApplicationStatusUpdatedMail::class, function (ApplicationStatusUpdatedMail $mail): bool {
            $mail->assertSeeInText('Not Selected');
            $mail->assertSeeInText('move forward with other candidates');

            return $mail->hasTo('juan@test.com')
                && $mail->currentStage === 'rejected';
        });
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
        $this->application->forceFill(['first_name' => 'Changed'])->save();
        $this->posting->update(['title' => 'Changed title']);
        $interview = ApplicationInterview::query()->firstOrFail();
        $expectedScheduledAt = $interview->scheduled_at->copy()
            ->setTimezone((string) config('app.timezone'))
            ->format('F j, Y g:i A');
        $interview->forceFill([
            'location' => 'Changed after queue',
            'interviewer_name' => 'Changed interviewer',
            'scheduled_at' => now()->addDays(6),
        ])->save();
        Mail::assertQueued(InterviewScheduledMail::class, function (InterviewScheduledMail $mail) use ($expectedScheduledAt): bool {
            $html = $mail->render();
            $mail->assertSeeInText('HR Office, 2nd Floor');
            $mail->assertSeeInText('Maria Santos');
            $mail->assertSeeInText('RCT-TEST01');

            return $mail->hasTo('juan@test.com')
                && $mail->applicantName === 'Juan Test'
                && $mail->positionTitle === 'Test Position'
                && $mail->interviewerName === 'Maria Santos'
                && $mail->location === 'HR Office, 2nd Floor'
                && $mail->scheduledAt === $expectedScheduledAt
                && str_contains($html, '<title>Interview scheduled</title>')
                && str_contains($html, 'Check application status');
        });
    }

    public function test_interview_email_renders_when_company_address_is_missing(): void
    {
        app(\App\Common\Services\SettingsService::class)->set('company.address', '');

        $this->application->stage = ApplicationStage::Interview;
        $this->application->save();

        $this->actingAs($this->hrUser)
            ->postJson("/api/v1/hr/recruitment/applications/{$this->application->hash_id}/interviews", [
                'scheduled_at'     => now()->addDays(3)->toIso8601String(),
                'location'         => 'HR Office',
                'interviewer_name' => 'Maria Santos',
            ])
            ->assertStatus(201);

        Mail::assertQueued(InterviewScheduledMail::class, fn (InterviewScheduledMail $mail): bool =>
            $mail->hasTo('juan@test.com')
            && str_contains((string) $mail->render(), 'FCIE Complex')
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
        $scheduledAt = $interview->fresh()->scheduled_at->toIso8601String();
        $interview->update([
            'scheduled_at' => now()->addDays(8),
            'location' => 'Changed location',
            'interviewer_name' => 'Changed interviewer',
        ]);
        $this->application->update(['first_name' => 'Changed']);
        $this->posting->update(['title' => 'Changed Position']);

        Mail::assertQueued(InterviewDetailsUpdatedMail::class, function (InterviewDetailsUpdatedMail $mail) use ($scheduledAt): bool {
            $html = $mail->render();

            return $mail->hasTo('juan@test.com')
                && $mail->snapshot['scheduled_at'] === $scheduledAt
                && str_contains($html, 'Zoom interview')
                && str_contains($html, 'Ana Reyes')
                && str_contains($html, 'Test Position')
                && ! str_contains($html, 'Changed location')
                && ! str_contains($html, 'Changed interviewer')
                && ! str_contains($html, 'Changed Position')
                && ! str_contains($html, 'Passed');
        });
    }

    public function test_internal_interview_outcome_change_does_not_email_candidate(): void
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
            ->patchJson("/api/v1/hr/recruitment/interviews/{$interview->hash_id}", ['outcome' => 'failed']);

        $response->assertOk();
        Mail::assertNothingQueued();
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
