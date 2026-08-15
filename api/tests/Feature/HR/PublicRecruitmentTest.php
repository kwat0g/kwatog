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
use App\Modules\HR\Services\RecruitmentService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicRecruitmentTest extends TestCase
{
    use RefreshDatabase;

    private JobPosting $posting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $hrRole = Role::where('slug', 'hr_officer')->firstOrFail();
        $user = User::factory()->create(['role_id' => $hrRole->id, 'is_active' => true]);

        $dept = Department::factory()->create();
        $this->posting = new JobPosting();
        $this->posting->fill([
            'posting_number'  => 'JP-T-' . substr(uniqid(), -5),
            'title'           => 'Molding Operator',
            'department_id'   => $dept->id,
            'description'     => 'Operate machines.',
            'requirements'    => '1 year exp.',
            'employment_type' => 'regular',
            'created_by'      => $user->id,
            'posted_at'       => now(),
        ]);
        $this->posting->status = JobPostingStatus::Open;
        $this->posting->save();
    }

    public function test_public_can_list_open_postings(): void
    {
        $response = $this->getJson('/api/v1/public/recruitment/job-postings');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'Molding Operator');
    }

    public function test_public_can_view_single_posting(): void
    {
        $response = $this->getJson("/api/v1/public/recruitment/job-postings/{$this->posting->hash_id}");
        $response->assertOk();
        $response->assertJsonPath('data.title', 'Molding Operator');
    }

    public function test_public_can_submit_application(): void
    {
        Storage::fake('local');
        Mail::fake();

        $response = $this->postJson("/api/v1/public/recruitment/job-postings/{$this->posting->hash_id}/apply", [
            'first_name'   => 'Juan',
            'last_name'    => 'Dela Cruz',
            'email'        => 'juan@example.com',
            'phone'        => '09171234567',
            'resume'       => UploadedFile::fake()->create('resume.pdf', 1024, 'application/pdf'),
            'cover_letter' => 'I am very interested.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['tracking_code', 'message']);
        $this->assertDatabaseHas('job_applications', [
            'email' => 'juan@example.com',
            'stage' => 'new',
        ]);
    }

    public function test_public_cannot_submit_duplicate_email_for_same_position(): void
    {
        Storage::fake('local');
        Mail::fake();

        $payload = [
            'first_name' => 'Juan',
            'last_name'  => 'Dela Cruz',
            'email'      => ' Candidate@Example.com ',
            'phone'      => '09171234567',
            'resume'     => UploadedFile::fake()->create('resume.pdf', 1024, 'application/pdf'),
        ];

        $this->postJson("/api/v1/public/recruitment/job-postings/{$this->posting->hash_id}/apply", $payload)
            ->assertCreated();

        $duplicate = $this->postJson("/api/v1/public/recruitment/job-postings/{$this->posting->hash_id}/apply", [
            ...$payload,
            'email' => 'candidate@example.com',
            'resume' => UploadedFile::fake()->create('resume-copy.pdf', 1024, 'application/pdf'),
        ]);

        $duplicate
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', 'You have already applied for this position.');

        $this->assertDatabaseCount('job_applications', 1);
        $this->assertDatabaseHas('job_applications', ['email' => 'candidate@example.com']);
    }

    public function test_public_cannot_apply_to_closed_posting(): void
    {
        $this->posting->status = JobPostingStatus::Closed;
        $this->posting->save();

        Storage::fake('local');

        $response = $this->postJson("/api/v1/public/recruitment/job-postings/{$this->posting->hash_id}/apply", [
            'first_name' => 'Test',
            'last_name'  => 'User',
            'email'      => 'test@example.com',
            'phone'      => '09170000000',
            'resume'     => UploadedFile::fake()->create('resume.pdf', 1024, 'application/pdf'),
        ]);

        $response->assertStatus(422);
    }

    public function test_public_can_track_application(): void
    {
        Storage::fake('local');
        Mail::fake();

        $applyResponse = $this->postJson("/api/v1/public/recruitment/job-postings/{$this->posting->hash_id}/apply", [
            'first_name' => 'Maria',
            'last_name'  => 'Santos',
            'email'      => 'maria@example.com',
            'phone'      => '09171111111',
            'resume'     => UploadedFile::fake()->create('cv.pdf', 512, 'application/pdf'),
        ]);

        $code = $applyResponse->json('tracking_code');

        $trackResponse = $this->getJson("/api/v1/public/recruitment/applications/track/{$code}");
        $trackResponse->assertOk();
        $trackResponse->assertJsonPath('data.status', 'Application Received');
        $trackResponse->assertJsonPath('data.position', 'Molding Operator');
    }

    public function test_invalid_tracking_code_returns_404(): void
    {
        $response = $this->getJson('/api/v1/public/recruitment/applications/track/INVALID');
        $response->assertStatus(404);
    }

    public function test_expired_open_posting_is_not_publicly_listed_or_viewable(): void
    {
        $this->posting->closes_at = now()->subMinute();
        $this->posting->save();

        $this->getJson('/api/v1/public/recruitment/job-postings')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/public/recruitment/job-postings/{$this->posting->hash_id}")
            ->assertNotFound();
    }

    public function test_hr_is_notified_when_candidate_confirmation_email_fails(): void
    {
        Storage::fake('local');
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new \RuntimeException('Brevo rejected the recipient'));

        app(RecruitmentService::class)->submitApplication($this->posting, [
            'first_name' => 'NoEmail',
            'last_name' => 'Candidate',
            'email' => 'unreachable@example.test',
            'phone' => '09170000000',
        ], UploadedFile::fake()->create('resume.pdf', 512, 'application/pdf'));

        $hrUser = User::where('role_id', Role::where('slug', 'hr_officer')->value('id'))->firstOrFail();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $hrUser->id,
            'type' => 'recruitment.new_application',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $hrUser->id,
            'type' => 'email.delivery_failed',
        ]);
    }
}
