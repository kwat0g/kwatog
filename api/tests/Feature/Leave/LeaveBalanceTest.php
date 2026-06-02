<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Exceptions\InsufficientLeaveBalanceException;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2.11 — Pin leave-balance entitlement integrity.
 *
 * Real API:
 *   consume(int $employeeId, int $leaveTypeId, int $year, float $days): EmployeeLeaveBalance
 *   restore(int $employeeId, int $leaveTypeId, int $year, float $days): void
 *
 * Balance columns: total_credits | used | remaining  (decimal:1)
 *
 * Bugs found and fixed:
 *   - consume() had NO over-consume guard; remaining could go negative.
 *     Fixed: throws InsufficientLeaveBalanceException before mutating.
 *
 * restore() behaviour:
 *   - uses max(0, used - days) so used never goes below zero → remaining
 *     is always clamped to [0, total_credits]. No over-credit bug found.
 */
class LeaveBalanceTest extends TestCase
{
    use RefreshDatabase;

    private LeaveBalanceService $svc;
    private Employee $employee;
    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(LeaveBalanceService::class);

        // Build the minimum graph: employee + leave type.
        $this->employee  = Employee::factory()->create();
        $this->leaveType = LeaveType::create([
            'name'            => 'Vacation Leave',
            'code'            => 'VL',
            'default_balance' => 5.0,
            'is_paid'         => true,
            'is_active'       => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper: insert a balance row directly (bypassing seedFor side-effects).
    // ─────────────────────────────────────────────────────────────────────────
    private function seedBalance(float $total, float $used = 0.0, int $year = 2026): EmployeeLeaveBalance
    {
        return EmployeeLeaveBalance::create([
            'employee_id'   => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'year'          => $year,
            'total_credits' => $total,
            'used'          => $used,
            'remaining'     => $total - $used,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. consume() within available balance → used goes up, remaining goes down
    // ─────────────────────────────────────────────────────────────────────────

    public function test_consume_within_balance_decrements_remaining_and_increments_used(): void
    {
        $this->seedBalance(5.0);

        $bal = $this->svc->consume($this->employee->id, $this->leaveType->id, 2026, 2.0);

        $this->assertSame('2.0', $bal->used,      'used must increase by consumed days');
        $this->assertSame('3.0', $bal->remaining, 'remaining must decrease by consumed days');

        // DB row agrees (decimal:1 cast returns strings like '2.0').
        $fresh = EmployeeLeaveBalance::query()
            ->where('employee_id', $this->employee->id)
            ->where('leave_type_id', $this->leaveType->id)
            ->where('year', 2026)
            ->firstOrFail();

        $this->assertSame('2.0', (string) $fresh->used);
        $this->assertSame('3.0', (string) $fresh->remaining);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. consume() beyond available balance → throws; balance unchanged
    // ─────────────────────────────────────────────────────────────────────────

    public function test_consume_beyond_available_throws_insufficient_exception(): void
    {
        $this->seedBalance(3.0); // only 3 days available

        $this->expectException(InsufficientLeaveBalanceException::class);

        $this->svc->consume($this->employee->id, $this->leaveType->id, 2026, 4.0); // 4 > 3
    }

    public function test_consume_beyond_available_leaves_balance_unchanged(): void
    {
        $this->seedBalance(3.0);

        try {
            $this->svc->consume($this->employee->id, $this->leaveType->id, 2026, 4.0);
        } catch (InsufficientLeaveBalanceException) {
            // expected
        }

        $fresh = EmployeeLeaveBalance::query()
            ->where('employee_id', $this->employee->id)
            ->where('leave_type_id', $this->leaveType->id)
            ->where('year', 2026)
            ->firstOrFail();

        // Row must be pristine — no partial write.
        $this->assertSame('0.0', (string) $fresh->used,      'used must not change on failed consume');
        $this->assertSame('3.0', (string) $fresh->remaining, 'remaining must not change on failed consume');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. restore() increments remaining back (e.g. leave cancellation)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_restore_increments_remaining_after_consume(): void
    {
        $this->seedBalance(5.0);

        // First consume 3 days.
        $this->svc->consume($this->employee->id, $this->leaveType->id, 2026, 3.0);

        // Cancel → restore 3 days back.
        $this->svc->restore($this->employee->id, $this->leaveType->id, 2026, 3.0);

        $fresh = EmployeeLeaveBalance::query()
            ->where('employee_id', $this->employee->id)
            ->where('leave_type_id', $this->leaveType->id)
            ->where('year', 2026)
            ->firstOrFail();

        $this->assertSame('0.0', (string) $fresh->used,      'used must be back to 0 after restore');
        $this->assertSame('5.0', (string) $fresh->remaining, 'remaining must be back to full credit after restore');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Balance cannot go below zero — over-consume guard
    // ─────────────────────────────────────────────────────────────────────────

    public function test_remaining_never_goes_negative(): void
    {
        $this->seedBalance(2.0); // only 2 days

        $this->expectException(InsufficientLeaveBalanceException::class);

        // Attempting to consume more than available must throw, not persist −1.
        $this->svc->consume($this->employee->id, $this->leaveType->id, 2026, 3.0);
    }

    public function test_remaining_is_zero_when_all_credits_consumed(): void
    {
        $this->seedBalance(2.0);

        $bal = $this->svc->consume($this->employee->id, $this->leaveType->id, 2026, 2.0);

        $this->assertSame('2.0', $bal->used);
        $this->assertSame('0.0', $bal->remaining, 'remaining must be exactly 0 when all credits consumed');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. restore() does not over-credit beyond total_credits
    //    (guards: max(0, used - days) → remaining = total_credits - used)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_restore_does_not_exceed_total_credits(): void
    {
        // Start with used=0 (remaining already at full). Restore anyway.
        $this->seedBalance(5.0, 0.0);

        // Calling restore when used=0 should be a no-op (max(0, 0 - 2) = 0).
        $this->svc->restore($this->employee->id, $this->leaveType->id, 2026, 2.0);

        $fresh = EmployeeLeaveBalance::query()
            ->where('employee_id', $this->employee->id)
            ->where('leave_type_id', $this->leaveType->id)
            ->where('year', 2026)
            ->firstOrFail();

        $this->assertSame('5.0', (string) $fresh->remaining,
            'remaining must never exceed total_credits even if restore is called on a zero-used balance');
        $this->assertSame('0.0', (string) $fresh->used,
            'used must clamp at 0, not go negative');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. restore() on missing balance row is a silent no-op (not an error)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_restore_on_missing_balance_row_is_noop(): void
    {
        // No balance row created for employee 999.
        $this->expectNotToPerformAssertions();

        // Should not throw.
        $this->svc->restore($this->employee->id, $this->leaveType->id, 1990, 1.0);
    }
}
