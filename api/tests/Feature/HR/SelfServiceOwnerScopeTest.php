<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M024 — owner-only reads must remain owner-only for department heads and
 * users who hold approval permissions. The shared back-office lists are not
 * exercised here; these assertions pin the separate self-service contract.
 */
class SelfServiceOwnerScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_service_reads_and_downloads_cannot_cross_employee_boundaries(): void
    {
        $owner = Employee::factory()->create();
        $other = Employee::factory()->create();
        $user = User::factory()->create(['employee_id' => $owner->id]);

        $ownerAttendance = $this->attendance($owner, '2026-08-01');
        $otherAttendance = $this->attendance($other, '2026-08-02');

        $ownerLeave = LeaveRequest::factory()->create(['employee_id' => $owner->id]);
        $otherLeave = LeaveRequest::factory()->create(['employee_id' => $other->id]);

        $ownerPayroll = $this->payroll($owner, '2026-08-01', '2026-08-14');
        $otherPayroll = $this->payroll($other, '2026-08-15', '2026-08-28');

        $this->actingAs($user)
            ->getJson('/api/v1/hr/self-service/attendance?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownerAttendance->hash_id)
            ->assertJsonMissing(['id' => $otherAttendance->hash_id]);

        $this->actingAs($user)
            ->getJson('/api/v1/hr/self-service/leave-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownerLeave->hash_id)
            ->assertJsonMissing(['id' => $otherLeave->hash_id]);

        $this->actingAs($user)
            ->getJson('/api/v1/hr/self-service/payslips')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownerPayroll->hash_id)
            ->assertJsonMissing(['id' => $otherPayroll->hash_id]);

        // A known department payroll hash is still denied by the dedicated
        // download route before PDF generation begins.
        $this->actingAs($user)
            ->get('/api/v1/hr/self-service/payslips/'.$otherPayroll->hash_id.'/download')
            ->assertNotFound();
    }

    private function attendance(Employee $employee, string $date): Attendance
    {
        return Attendance::query()->create([
            'employee_id' => $employee->id,
            'date' => $date,
            'regular_hours' => '8.00',
            'overtime_hours' => '0.00',
            'night_diff_hours' => '0.00',
            'status' => 'present',
        ]);
    }

    private function payroll(Employee $employee, string $start, string $end): Payroll
    {
        $period = PayrollPeriod::factory()->create([
            'period_start' => $start,
            'period_end' => $end,
        ]);
        $period->forceFill(['status' => 'finalized'])->saveQuietly();

        return Payroll::factory()->create([
            'employee_id' => $employee->id,
            'payroll_period_id' => $period->id,
            'gross_pay' => '1000.10',
            'net_pay' => '900.10',
        ]);
    }
}
