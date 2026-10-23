<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Listeners\NotifyEmployeesOnPayrollFinalized;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotifyEmployeesOnPayrollFinalizedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_failed_payroll_rows_do_not_receive_payslip_ready_notifications(): void
    {
        $period = PayrollPeriod::factory()->create(['status' => 'finalized']);
        $failedEmployee = Employee::factory()->create();
        $validEmployee = Employee::factory()->create();
        $revokedEmployee = Employee::factory()->create();
        $failedUser = User::factory()->create(['employee_id' => $failedEmployee->id, 'is_active' => true]);
        $validUser = User::factory()->create(['employee_id' => $validEmployee->id, 'is_active' => true]);
        $revokedUser = User::factory()->create(['employee_id' => $revokedEmployee->id, 'is_active' => true]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $revokedUser->id,
            'permission_id' => Permission::query()->where('slug', 'payroll.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $validUser->id,
            'reason' => 'Payslip access removed for notification audience test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $failedEmployee->id,
            'error_message' => 'Unable to compute government deductions.',
            'net_pay' => '0.00',
        ]);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $validEmployee->id,
            'error_message' => null,
            'net_pay' => '1000.00',
        ]);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $revokedEmployee->id,
            'error_message' => null,
            'net_pay' => '900.00',
        ]);

        app(NotifyEmployeesOnPayrollFinalized::class)->handle(new PayrollPeriodFinalized($period));

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $failedUser->id,
            'type' => 'chain.payslip_ready',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $validUser->id,
            'type' => 'chain.payslip_ready',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $revokedUser->id,
            'type' => 'chain.payslip_ready',
        ]);
    }

    public function test_delayed_finalization_notice_does_not_publish_a_voided_period(): void
    {
        $period = PayrollPeriod::factory()->create(['status' => 'finalized']);
        $employee = Employee::factory()->create();
        $user = User::factory()->create(['employee_id' => $employee->id, 'is_active' => true]);
        Payroll::factory()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'error_message' => null]);
        $event = new PayrollPeriodFinalized($period);
        $period->newQuery()->whereKey($period->id)->update(['status' => 'voided']);

        app(NotifyEmployeesOnPayrollFinalized::class)->handle($event);

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $user->id, 'type' => 'chain.payslip_ready']);
    }
}
