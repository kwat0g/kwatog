<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Services\NotificationService;
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

/**
 * P01-03 (ARGUED → PROVEN here) — the payslip-ready notification has no
 * cross-call dedupe. A redelivered finalize event (listener failed after the
 * insert committed) stacks a second inbox row per employee. The listener must
 * skip recipients who already hold this period's notice.
 */
class PayslipNotificationDedupeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_redelivered_finalize_event_does_not_stack_duplicate_payslip_notices(): void
    {
        $period   = PayrollPeriod::factory()->create();
        $employee = Employee::factory()->create();
        $user     = User::factory()->create();
        $user->forceFill(['employee_id' => $employee->id])->save();

        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id'       => $employee->id,
        ]);

        $listener = new NotifyEmployeesOnPayrollFinalized(app(NotificationService::class));
        $event    = new PayrollPeriodFinalized($period->fresh());

        // First delivery, then a redelivery after a simulated partial failure.
        $listener->handle($event);
        $listener->handle($event);

        $count = DB::table('notifications')
            ->where('type', 'chain.payslip_ready')
            ->where('notifiable_id', $user->id)
            ->count();

        $this->assertSame(1, $count, 'A redelivered finalize must not stack a second payslip-ready notice.');
    }
}
