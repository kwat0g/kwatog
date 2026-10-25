<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Payroll\Services\EmploymentProrationService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HR-02 — the shared employment-window fraction, tested directly.
 *
 * Both PayrollCalculatorService and FinalPayService delegate here, so these
 * numbers are the contract: calendar-day based, attendance-agnostic, both
 * employment ends prorated, scale-4 bcmath strings.
 */
class EmploymentProrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmploymentProrationService $proration;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->proration = app(EmploymentProrationService::class);
        $this->dept = Department::create(['name' => 'Production', 'code' => 'PRD']);
    }

    private function employee(string $dateHired = '2025-01-01'): Employee
    {
        $pos = Position::create(['title' => 'Operator', 'department_id' => $this->dept->id]);

        return Employee::factory()->create([
            'department_id' => $this->dept->id,
            'position_id' => $pos->id,
            'employment_type' => 'regular',
            'pay_type' => 'semi_monthly',
            'basic_monthly_salary' => null,
            'semi_monthly_rate' => '9460.00',
            'date_hired' => $dateHired,
            'status' => 'active',
        ]);
    }

    private function separate(Employee $employee, string $separationDate): void
    {
        DB::table('clearances')->insert([
            'clearance_no' => 'CLR-T-'.substr(uniqid(), -5),
            'employee_id' => $employee->id,
            'separation_date' => $separationDate,
            'separation_reason' => 'resignation',
            'clearance_items' => json_encode([]),
            'status' => 'in_progress',
            'initiated_by' => User::factory()->create([
                'role_id' => Role::where('slug', 'hr_officer')->value('id'),
            ])->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fraction(Employee $employee, string $start, string $end): string
    {
        return $this->proration->employedDayFraction($employee, Carbon::parse($start), Carbon::parse($end));
    }

    // Oct 2026 first-half cutoff: 15 calendar days.

    public function test_full_period_returns_one(): void
    {
        $employee = $this->employee();

        $this->assertSame('1.0000', $this->fraction($employee, '2026-10-01', '2026-10-15'));
    }

    public function test_separation_on_the_last_day_returns_one(): void
    {
        $employee = $this->employee();
        $this->separate($employee, '2026-10-15');

        $this->assertSame('1.0000', $this->fraction($employee, '2026-10-01', '2026-10-15'));
    }

    public function test_mid_period_hire_covers_only_days_from_hire(): void
    {
        $employee = $this->employee('2026-10-13');

        $this->assertSame('0.2000', $this->fraction($employee, '2026-10-01', '2026-10-15'));
    }

    public function test_mid_period_separation_covers_only_days_to_separation(): void
    {
        $employee = $this->employee();
        $this->separate($employee, '2026-10-03');

        $this->assertSame('0.2000', $this->fraction($employee, '2026-10-01', '2026-10-15'));
    }

    public function test_both_ends_prorate(): void
    {
        $employee = $this->employee('2026-10-05');
        $this->separate($employee, '2026-10-09');

        $this->assertSame('0.3333', $this->fraction($employee, '2026-10-01', '2026-10-15'));
    }

    public function test_no_overlap_returns_zero(): void
    {
        $employee = $this->employee();
        $this->separate($employee, '2026-09-20');

        $this->assertSame('0.0000', $this->fraction($employee, '2026-10-01', '2026-10-15'));
    }

    public function test_the_earliest_separation_date_on_record_wins(): void
    {
        $employee = $this->employee();
        $this->separate($employee, '2026-10-03');
        $this->separate($employee, '2026-10-14');

        $this->assertSame('0.2000', $this->fraction($employee, '2026-10-01', '2026-10-15'));
    }
}
