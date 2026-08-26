<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ApplicationStage;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RecruitmentBottleneckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_application_and_expired_posting_raise_deduplicated_hr_alerts(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        // An empty persisted setting must fall back to the protected HR role
        // audience instead of silently disabling recovery alerts.
        app(\App\Common\Services\SettingsService::class)->set(
            'hr.recruitment.notification_roles',
            [],
            'hr',
        );
        $role = Role::where('slug', 'hr_officer')->firstOrFail();
        $hr = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $department = Department::factory()->create();
        $posting = JobPosting::create([
            'posting_number' => 'JP-BOT-'.substr(uniqid(), -10),
            'title' => 'Stale Position',
            'department_id' => $department->id,
            'description' => 'Description',
            'requirements' => 'Requirements',
            'employment_type' => 'regular',
            'slots' => 1,
            'closes_at' => now()->subDay(),
            'created_by' => $hr->id,
        ]);
        $posting->forceFill(['status' => JobPostingStatus::Open])->save();
        $application = JobApplication::create([
            'application_number' => 'JA-BOT-'.substr(uniqid(), -10),
            'job_posting_id' => $posting->id,
            'tracking_code' => 'RCT-BOTTL1',
            'first_name' => 'Stale',
            'last_name' => 'Candidate',
            'email' => 'stale@example.com',
            'phone' => '09170000000',
            'resume_path' => 'resume.pdf',
            'resume_original_name' => 'resume.pdf',
            'applied_at' => now()->subDays(5),
        ]);
        $application->forceFill([
            'stage' => ApplicationStage::Screening,
            'updated_at' => now()->subDays(5),
        ])->save();

        Artisan::call('recruitment:check-bottlenecks');

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $hr->id,
            'type' => 'recruitment.bottleneck',
        ]);
        $count = (int) \DB::table('notifications')
            ->where('notifiable_id', $hr->id)
            ->where('type', 'recruitment.bottleneck')
            ->count();

        Artisan::call('recruitment:check-bottlenecks');

        $this->assertSame($count, (int) \DB::table('notifications')
            ->where('notifiable_id', $hr->id)
            ->where('type', 'recruitment.bottleneck')
            ->count());
    }

    public function test_missing_recipient_audience_is_visible_to_scheduler_and_dry_run(): void
    {
        $dryRunExitCode = Artisan::call('recruitment:check-bottlenecks', ['--dry-run' => true]);

        $this->assertSame(0, $dryRunExitCode);
        $this->assertStringContainsString('Dry run has no active HR recipients', Artisan::output());

        $liveExitCode = Artisan::call('recruitment:check-bottlenecks');

        $this->assertSame(1, $liveExitCode);
    }

    public function test_converted_hired_application_does_not_raise_a_bottleneck(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        $role = Role::where('slug', 'hr_officer')->firstOrFail();
        $hr = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $department = Department::factory()->create();
        $posting = JobPosting::create([
            // posting_number is varchar(20); 'JP-BOT-HIRED-' + 8 was 21.
            'posting_number' => 'JP-BH-'.substr(uniqid(), -8),
            'title' => 'Hired Position',
            'department_id' => $department->id,
            'description' => 'Description',
            'requirements' => 'Requirements',
            'employment_type' => 'regular',
            'slots' => 2,
            'created_by' => $hr->id,
        ]);
        $employee = Employee::factory()->create();

        foreach ([false, true] as $converted) {
            $application = JobApplication::create([
                'application_number' => 'JA-BOT-HIRED-'.($converted ? 'C' : 'U').substr(uniqid(), -6),
                'job_posting_id' => $posting->id,
                // tracking_code is varchar(10); 'RCT-H' + 'CONV|OPEN' + 2 was 11.
                // The C/U letter keeps the two loop rows distinct under UNIQUE.
                'tracking_code' => 'RCT-'.($converted ? 'C' : 'U').substr(uniqid(), -5),
                'first_name' => $converted ? 'Converted' : 'Pending',
                'last_name' => 'Candidate',
                'email' => ($converted ? 'converted' : 'pending').'@example.com',
                'phone' => '09170000000',
                'resume_path' => 'resume.pdf',
                'resume_original_name' => 'resume.pdf',
                'applied_at' => now()->subDays(5),
            ]);
            $application->forceFill([
                'stage' => ApplicationStage::Hired,
                'converted_employee_id' => $converted ? $employee->id : null,
                'updated_at' => now()->subDays(5),
            ])->save();
        }

        Artisan::call('recruitment:check-bottlenecks');

        $this->assertSame(1, (int) \DB::table('notifications')
            ->where('notifiable_id', $hr->id)
            ->where('type', 'recruitment.bottleneck')
            ->count());
    }

    public function test_configured_recruitment_recipients_without_view_permission_are_excluded(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        $role = Role::create([
            'name' => 'Recruitment Applications Role',
            'slug' => 'recruitment_applications_role',
            'is_system' => false,
        ]);
        $role->permissions()->sync([
            Permission::where('slug', 'hr.recruitment.applications')->firstOrFail()->id,
        ]);
        $recipient = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        app(\App\Common\Services\SettingsService::class)->set(
            'hr.recruitment.notification_roles',
            [$role->slug],
            'hr',
        );

        $exitCode = Artisan::call('recruitment:check-bottlenecks');

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $recipient->id,
            'type' => 'recruitment.bottleneck',
        ]);
    }
}
