<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Notifications\PasswordResetNotification;
use App\Modules\Auth\Notifications\WelcomeNotification;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Services\UserProvisioningService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * U1 — UserProvisioningService unit + integration coverage.
 */
class UserProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
    }

    private function makeEmployee(string $first = 'Juan', string $last = 'Cruz'): Employee
    {
        $dept = Department::firstOrCreate(['code' => 'PRD'], ['name' => 'Production']);
        $pos  = Position::firstOrCreate(['title' => 'Operator', 'department_id' => $dept->id]);

        return Employee::create([
            'employee_no' => 'OGM-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'first_name'  => $first, 'last_name' => $last,
            'birth_date'  => '1990-01-01', 'gender' => 'male', 'civil_status' => 'single',
            'nationality' => 'Filipino',
            'department_id' => $dept->id, 'position_id' => $pos->id,
            'employment_type' => 'regular', 'pay_type' => 'monthly',
            'date_hired' => '2025-01-01', 'basic_monthly_salary' => '20000.00',
            'status' => 'active',
        ]);
    }

    public function test_provision_creates_linked_user_and_sends_welcome(): void
    {
        /** @var UserProvisioningService $svc */
        $svc = app(UserProvisioningService::class);
        $employee = $this->makeEmployee();

        $user = $svc->provisionForEmployee($employee);

        $this->assertNotNull($user);
        $this->assertSame($employee->id, $user->employee_id);
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertTrue((bool) $user->is_active);
        $this->assertNotEmpty($user->email);
        Notification::assertSentTo($user, WelcomeNotification::class);

        // Reverse relation works.
        $this->assertNotNull($employee->fresh()->user);
        $this->assertSame($user->id, $employee->fresh()->user->id);
    }

    public function test_provision_twice_throws_domain_exception(): void
    {
        /** @var UserProvisioningService $svc */
        $svc = app(UserProvisioningService::class);
        $employee = $this->makeEmployee();
        $svc->provisionForEmployee($employee);

        $this->expectException(\DomainException::class);
        $svc->provisionForEmployee($employee->fresh());
    }

    public function test_email_collision_appends_numeric_suffix(): void
    {
        /** @var UserProvisioningService $svc */
        $svc = app(UserProvisioningService::class);

        $emp1 = $this->makeEmployee('Juan', 'Cruz');
        $emp2 = $this->makeEmployee('Juan', 'Cruz');

        $u1 = $svc->provisionForEmployee($emp1);
        $u2 = $svc->provisionForEmployee($emp2);

        $this->assertSame('juan.cruz@ogami.ph', $u1->email);
        $this->assertSame('juan.cruz1@ogami.ph', $u2->email);
    }

    public function test_send_welcome_can_be_disabled(): void
    {
        /** @var UserProvisioningService $svc */
        $svc = app(UserProvisioningService::class);
        $employee = $this->makeEmployee();
        $user = $svc->provisionForEmployee($employee, ['send_welcome' => false]);
        Notification::assertNothingSentTo($user);
    }

    public function test_deactivate_revokes_sessions_and_flips_active(): void
    {
        /** @var UserProvisioningService $svc */
        $svc = app(UserProvisioningService::class);
        $employee = $this->makeEmployee();
        $user = $svc->provisionForEmployee($employee);

        // Insert a fake session row.
        \DB::table('sessions')->insert([
            'id'            => 'sess-'.uniqid(),
            'user_id'       => $user->id,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'phpunit',
            'payload'       => '',
            'last_activity' => time(),
        ]);
        $this->assertSame(1, \DB::table('sessions')->where('user_id', $user->id)->count());

        $svc->deactivateForEmployee($employee->fresh());

        $this->assertFalse((bool) $user->fresh()->is_active);
        $this->assertSame(0, \DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_reset_password_force_change_and_notifies(): void
    {
        /** @var UserProvisioningService $svc */
        $svc = app(UserProvisioningService::class);
        $employee = $this->makeEmployee();
        $user = $svc->provisionForEmployee($employee);

        $oldHash = $user->password;
        $temp = $svc->resetPasswordForEmployee($employee->fresh());

        $this->assertNotEmpty($temp);
        $fresh = $user->fresh();
        $this->assertNotSame($oldHash, $fresh->password);
        $this->assertTrue((bool) $fresh->must_change_password);
        $this->assertTrue(Hash::check($temp, $fresh->password));
        Notification::assertSentTo($fresh, PasswordResetNotification::class);
    }

    public function test_account_status_when_no_account(): void
    {
        /** @var UserProvisioningService $svc */
        $svc = app(UserProvisioningService::class);
        $employee = $this->makeEmployee();
        $status = $svc->accountStatusForEmployee($employee);
        $this->assertFalse($status['account_exists']);
        $this->assertNull($status['user_id']);
    }

    public function test_bulk_provision_returns_per_employee_results(): void
    {
        /** @var UserProvisioningService $svc */
        $svc = app(UserProvisioningService::class);
        $emp1 = $this->makeEmployee('Maria', 'Santos');
        $emp2 = $this->makeEmployee('Pedro', 'Garcia');

        // Pre-provision emp2 so its row in the batch yields "skipped".
        $svc->provisionForEmployee($emp2);

        $results = $svc->bulkProvision([$emp1->id, $emp2->id], ['send_welcome' => false]);

        $this->assertCount(2, $results);
        $statuses = array_column($results, 'status');
        $this->assertContains('success', $statuses);
        $this->assertContains('skipped', $statuses);
    }
}
