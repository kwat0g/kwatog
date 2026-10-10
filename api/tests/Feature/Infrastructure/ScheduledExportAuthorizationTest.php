<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Common\Enums\ExportFormat;
use App\Common\Mail\ScheduledExportMail;
use App\Common\Models\ScheduledExport;
use App\Common\Services\Export\ExportRunner;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduledExportAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', config('app.env'));
        self::assertMatchesRegularExpression('/\Aogami_test(?:_|$)/', (string) config('database.connections.pgsql.database'));
        Storage::fake('local');
        config(['mail.default' => 'array']);
    }

    private function owner(): User
    {
        $role = Role::create(['name' => 'Export authorization test', 'slug' => 'export-auth-'.uniqid()]);
        $permission = Permission::firstOrCreate(['slug' => 'hr.employees.export'], ['name' => 'Export employees', 'module' => 'hr']);
        $role->permissions()->attach($permission);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_inactive_owner_with_retained_permission_cannot_build(): void
    {
        $owner = $this->owner();
        $stale = $owner->fresh();
        $owner->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        app(ExportRunner::class)->build('hr.employees', ['employee_no'], [], $stale);
    }

    public function test_inactive_schedule_owner_is_blocked_before_generation(): void
    {
        $owner = $this->owner();
        [$row] = $this->delivery($owner);
        $row->update(['next_run_at' => now()->subMinute()]);
        $owner->update(['is_active' => false]);
        Mail::fake();
        $this->artisan('exports:run-due')->assertExitCode(1);
        self::assertFalse($row->fresh()->is_active);
        self::assertNull($row->fresh()->processing_token);
        self::assertNull($row->fresh()->last_run_at);
        self::assertStringContainsString('inactive', $row->fresh()->last_error);
        Mail::assertNothingQueued();
    }

    private function delivery(User $owner): array
    {
        $row = ScheduledExport::create([
            'owner_id' => $owner->id, 'name' => 'Authorized export', 'module' => 'hr.employees',
            'columns' => ['employee_no'], 'filters' => [], 'format' => 'csv', 'frequency' => 'daily',
            'recipients' => ['recipient@example.test'], 'next_run_at' => now()->addDay(), 'is_active' => true,
        ]);
        $path = 'exports/scheduled/authorization.csv';
        Storage::disk('local')->put($path, 'employee_no\nPRIVATE');
        $mail = new ScheduledExportMail(
            $row->name, $row->module, 'employees.csv', $path, ExportFormat::Csv, $owner->id, true,
            scheduleId: $row->id,
            authorizationContext: app(ExportRunner::class)->authorizationContext($owner, $row->columns, $row->filters),
        );
        $mail->to('recipient@example.test');

        return [$row, $path, new SendQueuedMailable(unserialize(serialize($mail)))];
    }

    public function test_actual_queued_transport_blocks_deactivated_owner(): void
    {
        $owner = $this->owner();
        [$row, $path, $job] = $this->delivery($owner);
        $owner->update(['is_active' => false]);
        $job->handle(Mail::getFacadeRoot());
        self::assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        self::assertFalse($row->fresh()->is_active);
        self::assertNotNull($row->fresh()->last_error);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_actual_queued_transport_blocks_revoked_permission(): void
    {
        $owner = $this->owner();
        [$row, $path, $job] = $this->delivery($owner);
        $owner->role->permissions()->detach();
        $job->handle(Mail::getFacadeRoot());
        self::assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        self::assertFalse($row->fresh()->is_active);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_actual_queued_transport_blocks_changed_employee_scope(): void
    {
        $owner = $this->owner();
        [$row, $path, $job] = $this->delivery($owner);
        $owner->update(['employee_id' => Employee::factory()->create()->id]);
        $job->handle(Mail::getFacadeRoot());
        self::assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_actual_queued_transport_blocks_changed_columns(): void
    {
        [$row, $path, $job] = $this->delivery($this->owner());
        $row->update(['columns' => ['employee_no', 'department']]);
        $job->handle(Mail::getFacadeRoot());
        self::assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_deleted_schedule_never_falls_back_to_system_send(): void
    {
        [$row, $path, $job] = $this->delivery($this->owner());
        $row->delete();
        $job->handle(Mail::getFacadeRoot());
        self::assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_transient_authorization_infrastructure_failure_keeps_schedule_and_artifact(): void
    {
        [$row, $path, $job] = $this->delivery($this->owner());
        $runner = \Mockery::mock(ExportRunner::class);
        $runner->shouldReceive('build')->once()->andThrow(new \RuntimeException('Temporary database outage'));
        $this->app->instance(ExportRunner::class, $runner);
        try {
            $job->handle(Mail::getFacadeRoot());
            self::fail('Transient failure must reach queue retry.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Temporary database outage', $exception->getMessage());
        }
        self::assertTrue($row->fresh()->is_active);
        Storage::disk('local')->assertExists($path);
        self::assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_actual_queued_transport_sends_permitted_owner_once(): void
    {
        [$row, $path, $job] = $this->delivery($this->owner());
        $job->handle(Mail::getFacadeRoot());
        self::assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        self::assertTrue($row->fresh()->is_active);
        Storage::disk('local')->assertExists($path);
    }
}
