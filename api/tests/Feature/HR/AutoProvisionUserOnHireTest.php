<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Notifications\WelcomeNotification;
use App\Modules\HR\Events\EmployeeCreated;
use App\Modules\HR\Listeners\AutoProvisionUserOnEmployeeHire;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\UserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AutoProvisionUserOnHireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The 'employee' role must exist for default-role resolution.
        Role::firstOrCreate(['slug' => 'employee'], ['name' => 'Employee']);
    }

    public function test_employee_created_event_provisions_user_and_sends_welcome(): void
    {
        Notification::fake();

        $employee = Employee::factory()->create(['email' => 'newhire@ogami.test']);

        app(AutoProvisionUserOnEmployeeHire::class)->handle(new EmployeeCreated($employee));

        $this->assertDatabaseHas('users', [
            'employee_id' => $employee->id,
            'is_active'   => true,
            'must_change_password' => true,
        ]);

        Notification::assertSentTo(
            User::where('employee_id', $employee->id)->first(),
            WelcomeNotification::class,
        );
    }

    public function test_listener_is_idempotent_when_user_already_exists(): void
    {
        Notification::fake();

        $employee = Employee::factory()->create();
        // Pre-existing user account.
        User::factory()->create([
            'employee_id' => $employee->id,
            'email'       => 'existing@ogami.test',
        ]);

        app(AutoProvisionUserOnEmployeeHire::class)->handle(new EmployeeCreated($employee));

        // No duplicate user, no welcome email.
        $this->assertSame(1, User::where('employee_id', $employee->id)->count());
        Notification::assertNothingSent();
    }

    public function test_listener_rethrows_unclassified_domain_failures(): void
    {
        $employee = Employee::factory()->create();
        $provisioning = \Mockery::mock(UserProvisioningService::class);
        $provisioning
            ->shouldReceive('provisionForEmployee')
            ->once()
            ->with($employee)
            ->andThrow(new \DomainException('Provisioning invariant failed.'));
        $this->app->instance(UserProvisioningService::class, $provisioning);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Provisioning invariant failed.');

        app(AutoProvisionUserOnEmployeeHire::class)->handle(new EmployeeCreated($employee));
    }

    public function test_feature_flag_off_disables_provisioning(): void
    {
        Notification::fake();
        app(\App\Common\Services\SettingsService::class)
            ->set('hr.auto_provision_user.enabled', false, 'hr');
        \Illuminate\Support\Facades\Cache::forget('settings:hr.auto_provision_user.enabled');

        $employee = Employee::factory()->create();

        app(AutoProvisionUserOnEmployeeHire::class)->handle(new EmployeeCreated($employee));

        $this->assertSame(0, User::where('employee_id', $employee->id)->count());
        Notification::assertNothingSent();
    }
}
