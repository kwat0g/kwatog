<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollAdjustmentStatus;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollAdjustmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * P01-01 shape on payroll adjustments (money): approve()/reject() evaluated the
 * `Pending` guard on the *passed* model outside any transaction. A stale reject
 * landing after a concurrent approve flips an approved adjustment back to
 * Rejected — the adjustment silently drops out of the next payroll compute.
 */
class PayrollAdjustmentStaleRejectRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Mail::fake();
    }

    private function pendingAdjustment(): array
    {
        $period = PayrollPeriod::factory()->create();
        $employee = Employee::factory()->create();
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id'       => $employee->id,
        ]);
        $requester = User::factory()->create();

        $adjustment = PayrollAdjustment::create([
            'payroll_period_id'   => $period->id,
            'employee_id'         => $employee->id,
            'original_payroll_id' => $payroll->id,
            'type'                => 'underpayment',
            'amount'              => '500.00',
            'reason'              => 'Missed overtime line',
            'created_by'          => $requester->id,
        ]);
        $adjustment->forceFill(['status' => PayrollAdjustmentStatus::Pending->value])->save();

        return [$adjustment, $requester];
    }

    public function test_stale_reject_cannot_flip_an_approved_adjustment(): void
    {
        [$adjustment, $requester] = $this->pendingAdjustment();
        $approver = User::factory()->withRole('system_admin')->create();

        // Approver and rejecter each fetched the row while it was pending.
        $approveSnapshot = PayrollAdjustment::find($adjustment->id);
        $rejectSnapshot = PayrollAdjustment::find($adjustment->id);

        // Approver commits first.
        app(PayrollAdjustmentService::class)->approve($approveSnapshot, $approver);
        $this->assertSame(PayrollAdjustmentStatus::Approved, $adjustment->refresh()->status);

        // Concurrent stale rejecter still sees `pending` in memory.
        try {
            app(PayrollAdjustmentService::class)->reject($rejectSnapshot, $approver, 'typo');
            $this->fail('A stale reject must not flip an approved adjustment.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('pending', strtolower($e->getMessage()));
        }

        $this->assertSame(PayrollAdjustmentStatus::Approved, $adjustment->refresh()->status);
    }

    public function test_create_and_approve_notify_payroll_adjustment_actors(): void
    {
        $period = PayrollPeriod::factory()->create();
        $period->forceFill(['status' => PayrollPeriodStatus::Finalized])->save();
        $employee = Employee::factory()->create();
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);
        $requester = User::factory()->withRole('hr_officer')->create(['employee_id' => $employee->id]);
        $approver = User::factory()->withRole('system_admin')->create();
        $restrictedApprover = User::factory()->withRole('employee')->create();
        UserPermissionOverride::create([
            'user_id' => $restrictedApprover->id,
            'permission_id' => Permission::query()->where('slug', 'payroll.adjustments.approve')->firstOrFail()->id,
            'type' => 'grant',
            'granted_by' => $approver->id,
            'reason' => 'Notification route access test.',
        ]);
        $restrictedApprover->flushPermissionsCache();
        $this->assertFalse($restrictedApprover->hasPermission('payroll.adjustments.view'));
        $service = app(PayrollAdjustmentService::class);

        $adjustment = $service->create([
            'original_payroll_id' => $payroll->id,
            'type' => 'underpayment',
            'amount' => '500.00',
            'reason' => 'Missed overtime line',
        ], $requester);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $approver->id,
            'type' => 'approval.action_required',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $restrictedApprover->id,
            'type' => 'approval.action_required',
        ]);

        UserPermissionOverride::create([
            'user_id' => $requester->id,
            'permission_id' => Permission::query()->where('slug', 'payroll.adjustments.view')->firstOrFail()->id,
            'type' => 'revoke',
            'granted_by' => $approver->id,
            'reason' => 'Outcome notification link access test.',
        ]);
        $requester->flushPermissionsCache();

        $service->approve($adjustment, $approver);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $requester->id,
            'type' => 'approval.completed',
        ]);
        $outcome = DB::table('notifications')->where('notifiable_id', $requester->id)
            ->where('type', 'approval.completed')->first();
        $this->assertNotNull($outcome);
        $this->assertNull(json_decode((string) $outcome->data, true, flags: JSON_THROW_ON_ERROR)['link_to']);
    }

    public function test_rejecting_payroll_adjustment_notifies_requester(): void
    {
        $period = PayrollPeriod::factory()->create();
        $period->forceFill(['status' => PayrollPeriodStatus::Finalized])->save();
        $employee = Employee::factory()->create();
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);
        $requester = User::factory()->withRole('system_admin')->create();
        $approver = User::factory()->withRole('system_admin')->create();
        $service = app(PayrollAdjustmentService::class);
        $adjustment = $service->create([
            'original_payroll_id' => $payroll->id,
            'type' => 'underpayment',
            'amount' => '500.00',
            'reason' => 'Missed overtime line',
        ], $requester);

        $service->reject($adjustment, $approver, 'Supporting record is missing.');

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $requester->id,
            'type' => 'approval.rejected',
        ]);
    }
}
