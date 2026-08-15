<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollAdjustmentStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollAdjustmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $approver = User::factory()->create();

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
}
