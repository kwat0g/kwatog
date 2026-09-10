<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Events\EmployeeCreated;
use App\Modules\HR\Listeners\InitializeLeaveBalances;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LV-02 — mid-year hires must receive pro-rated first-year leave credits.
 *
 * The synchronous create path used to updateOrInsert FULL default_balance
 * rows, so the queued InitializeLeaveBalances listener (insertOrIgnore,
 * pro-rated) always no-opped and a July hire banked the full annual grant.
 * Both paths now delegate to LeaveBalanceService::seedProratedFor():
 *
 *   total_credits = round(default_balance × remaining_days / days_in_year, 1)
 *
 * remaining = hire date through Dec 31 inclusive. Pinned to 2026 (365 days)
 * so a 2026-07-01 hire has 184 remaining days.
 */
class LeaveBalanceProrationTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $vl;

    private LeaveType $sl;

    private LeaveType $sil;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-06 09:00:00'));

        $this->vl = LeaveType::create([
            'name' => 'Vacation Leave', 'code' => 'VL', 'default_balance' => 15.0,
            'is_paid' => true, 'is_active' => true, 'is_convertible_on_separation' => true,
        ]);
        $this->sl = LeaveType::create([
            'name' => 'Sick Leave', 'code' => 'SL', 'default_balance' => 10.0,
            'is_paid' => true, 'is_active' => true,
        ]);
        $this->sil = LeaveType::create([
            'name' => 'Service Incentive Leave', 'code' => 'SIL', 'default_balance' => 5.0,
            'is_paid' => true, 'is_active' => true, 'is_convertible_on_separation' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(string $dateHired): array
    {
        $dept = Department::firstOrCreate(['code' => 'PRD'], ['name' => 'Production']);
        $pos = Position::firstOrCreate(['title' => 'Operator', 'department_id' => $dept->id]);

        return [
            'first_name' => 'Juan', 'last_name' => 'Cruz',
            'birth_date' => '1990-01-01', 'gender' => 'male', 'civil_status' => 'single',
            'nationality' => 'Filipino',
            'department_id' => $dept->id, 'position_id' => $pos->id,
            'employment_type' => 'regular', 'pay_type' => 'monthly',
            'date_hired' => $dateHired, 'basic_monthly_salary' => '20000.00',
            'status' => 'active',
        ];
    }

    private function assertBalance(int $employeeId, LeaveType $type, string $total, string $used = '0.0', ?string $remaining = null, int $year = 2026): void
    {
        $row = DB::table('employee_leave_balances')
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $type->id)
            ->where('year', $year)
            ->first();

        $this->assertNotNull($row, "Expected a {$year} balance row for leave type {$type->code}.");
        $this->assertSame($total, (string) $row->total_credits, "total_credits for {$type->code}");
        $this->assertSame($used, (string) $row->used, "used for {$type->code}");
        $this->assertSame($remaining ?? $total, (string) $row->remaining, "remaining for {$type->code}");
    }

    public function test_mid_year_hire_receives_pro_rated_credits(): void
    {
        $emp = app(EmployeeService::class)->create($this->payload('2026-07-01'));

        // 184 of 365 days remain: 15×184/365=7.56→7.6, 10×…=5.04→5.0, 5×…=2.52→2.5
        $this->assertBalance($emp->id, $this->vl, '7.6');
        $this->assertBalance($emp->id, $this->sl, '5.0');
        $this->assertBalance($emp->id, $this->sil, '2.5');
    }

    public function test_january_first_hire_receives_full_credits(): void
    {
        $emp = app(EmployeeService::class)->create($this->payload('2026-01-01'));

        $this->assertBalance($emp->id, $this->vl, '15.0');
        $this->assertBalance($emp->id, $this->sl, '10.0');
        $this->assertBalance($emp->id, $this->sil, '5.0');
    }

    public function test_hire_dated_in_prior_year_gets_full_current_year_credits(): void
    {
        // Historical data entry: no retroactive pro-ration for prior years.
        $emp = app(EmployeeService::class)->create($this->payload('2025-07-01'));

        $this->assertBalance($emp->id, $this->vl, '15.0');
        $this->assertBalance($emp->id, $this->sl, '10.0');
        $this->assertBalance($emp->id, $this->sil, '5.0');
    }

    public function test_rehire_reseed_does_not_reset_consumed_balances(): void
    {
        $emp = app(EmployeeService::class)->create($this->payload('2026-07-01'));
        app(LeaveBalanceService::class)->consume($emp->id, $this->vl->id, 2026, 2.0);

        // Archive, then rehire restores the SAME employee row; the create-time
        // seed re-running must not reset used/total_credits on existing rows.
        $emp->delete();
        $emp->restore();

        $created = app(LeaveBalanceService::class)->seedProratedFor($emp->fresh());

        $this->assertSame(0, $created, 're-seeding must insert nothing when the year rows exist');
        $this->assertBalance($emp->id, $this->vl, '7.6', used: '2.0', remaining: '5.6');
        $this->assertSame(3, DB::table('employee_leave_balances')->where('employee_id', $emp->id)->count());
    }

    public function test_listener_double_fire_does_not_duplicate_or_clobber(): void
    {
        $emp = app(EmployeeService::class)->create($this->payload('2026-07-01'));
        app(LeaveBalanceService::class)->consume($emp->id, $this->sl->id, 2026, 1.0);

        app(InitializeLeaveBalances::class)->handle(new EmployeeCreated($emp->fresh()));
        app(InitializeLeaveBalances::class)->handle(new EmployeeCreated($emp->fresh()));

        $this->assertSame(3, DB::table('employee_leave_balances')->where('employee_id', $emp->id)->count());
        $this->assertBalance($emp->id, $this->vl, '7.6');
        $this->assertBalance($emp->id, $this->sl, '5.0', used: '1.0', remaining: '4.0');
        $this->assertBalance($emp->id, $this->sil, '2.5');
    }

    public function test_listener_initialises_missing_balances_for_pre_existing_employee(): void
    {
        // Employees created before the synchronous seed existed still get
        // pro-rated balances when the queued event is processed.
        $emp = Employee::factory()->create(['date_hired' => '2026-07-01']);

        app(InitializeLeaveBalances::class)->handle(new EmployeeCreated($emp));

        $this->assertBalance($emp->id, $this->vl, '7.6');
        $this->assertBalance($emp->id, $this->sl, '5.0');
        $this->assertBalance($emp->id, $this->sil, '2.5');
    }
}
