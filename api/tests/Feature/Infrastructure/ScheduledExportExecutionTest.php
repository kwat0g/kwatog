<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Common\Mail\ScheduledExportMail;
use App\Common\Models\ScheduledExport;
use App\Common\Services\Export\ScheduledExportArtifactService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScheduledExportExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_export_is_non_zero_and_releases_its_lease_for_retry(): void
    {
        $row = $this->dueExport(['module' => 'missing.exporter']);

        $this->artisan('exports:run-due')
            ->assertExitCode(1);

        $fresh = $row->fresh();
        $this->assertNull($fresh->processing_token);
        $this->assertNull($fresh->processing_until);
        $this->assertNotNull($fresh->last_attempt_at);
        $this->assertStringContainsString('No export class registered', (string) $fresh->last_error);
        $this->assertTrue($fresh->next_run_at->equalTo($row->next_run_at));
        $this->assertNull($fresh->last_run_at);
    }

    public function test_a_live_lease_cannot_be_claimed_by_a_second_scheduler(): void
    {
        $row = $this->dueExport([
            'processing_token' => 'runner-a',
            'processing_started_at' => now()->subMinute(),
            'processing_until' => now()->addMinutes(10),
        ]);

        $this->artisan('exports:run-due')
            ->assertExitCode(0);

        $fresh = $row->fresh();
        $this->assertSame('runner-a', $fresh->processing_token);
        $this->assertNull($fresh->last_attempt_at);
        $this->assertNull($fresh->last_run_at);
    }

    public function test_a_due_export_queues_a_durable_artifact_and_advances_its_next_run(): void
    {
        Storage::fake('local');
        Mail::fake();
        $owner = $this->ownerWith('hr.employees.export');
        $row = $this->dueExport(['owner_id' => $owner->id, 'recipients' => [$owner->email]]);

        $this->artisan('exports:run-due')->assertExitCode(0);

        $fresh = $row->fresh();
        $this->assertNull($fresh->last_error);
        $this->assertNull($fresh->processing_token);
        $this->assertNull($fresh->processing_until);
        $this->assertNotNull($fresh->last_run_at);
        // A completed run must move the window forward or the next tick
        // re-sends the same export every five minutes.
        $this->assertTrue($fresh->next_run_at->greaterThan($row->next_run_at));

        Mail::assertQueued(ScheduledExportMail::class);

        // The workbook is handed to the queue as a private path, never as an
        // unbounded base64 blob inside the Redis job payload.
        $artifacts = Storage::disk(ScheduledExportArtifactService::DISK)->allFiles('exports/scheduled');
        $this->assertCount(1, $artifacts);
        $this->assertNotSame('', (string) Storage::disk(ScheduledExportArtifactService::DISK)->get($artifacts[0]));
    }

    public function test_a_schedule_whose_owner_lost_the_export_grant_does_not_run(): void
    {
        Storage::fake('local');
        Mail::fake();
        // Owner holds no export permission — i.e. the grant that allowed the
        // schedule to be created has since been revoked.
        $row = $this->dueExport();

        $this->artisan('exports:run-due')->assertExitCode(1);

        $fresh = $row->fresh();
        $this->assertStringContainsString('hr.employees.export', (string) $fresh->last_error);
        $this->assertNull($fresh->last_run_at);
        $this->assertNull($fresh->processing_token);
        Mail::assertNothingQueued();
    }

    private function ownerWith(string $permissionSlug): User
    {
        $role = Role::create([
            'name' => 'Scheduled export owner '.uniqid(),
            'slug' => 'sched-export-'.uniqid(),
            'description' => 'Scheduled export execution test role',
        ]);
        $permission = Permission::firstOrCreate(
            ['slug' => $permissionSlug],
            ['name' => $permissionSlug, 'module' => 'test'],
        );
        $role->permissions()->attach($permission);

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @param array<string, mixed> $overrides */
    private function dueExport(array $overrides = []): ScheduledExport
    {
        $owner = User::factory()->create();

        return ScheduledExport::query()->create(array_merge([
            'owner_id' => $owner->id,
            'name' => 'Nightly test export',
            'module' => 'hr.employees',
            'columns' => ['employee_no'],
            'filters' => [],
            'format' => 'csv',
            'frequency' => 'daily',
            'day_of_week' => null,
            'day_of_month' => null,
            'time_of_day' => '06:00',
            'recipients' => [$owner->email],
            'next_run_at' => Carbon::now()->subMinute(),
            'is_active' => true,
        ], $overrides));
    }
}
