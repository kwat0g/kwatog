<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use App\Modules\Payroll\Services\PayrollGlPostingService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\PayrollChartAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollGlPostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
        $this->seed(PayrollChartAccountsSeeder::class);
    }

    private function fullySetup(): array
    {
        $roleId = Role::query()->orderBy('id')->value('id');
        $user = User::create([
            'name'     => 'Tester '.uniqid(),
            'email'    => 't_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ]);

        $dept = Department::create(['name' => 'Production', 'code' => 'PRD']);
        $pos  = Position::create(['title' => 'Operator', 'department_id' => $dept->id]);
        $emp = Employee::create([
            'employee_no' => 'OGM-2026-0001',
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'birth_date' => '1990-01-01', 'gender' => 'male', 'civil_status' => 'single',
            'nationality' => 'Filipino',
            'street_address' => '123 Main', 'city' => 'Dasmariñas', 'province' => 'Cavite',
            'mobile_number' => '09171234567', 'email' => 'jdc@example.com',
            'emergency_contact_name' => 'Maria', 'emergency_contact_phone' => '09181234567',
            'department_id' => $dept->id, 'position_id' => $pos->id,
            'employment_type' => 'regular', 'pay_type' => 'monthly',
            'date_hired' => '2025-01-01', 'basic_monthly_salary' => '20000.00',
        ]);

        $period = PayrollPeriod::create([
            'period_start' => '2026-04-01', 'period_end' => '2026-04-15',
            'payroll_date' => '2026-04-15', 'is_first_half' => true,
            'is_thirteenth_month' => false,
            'created_by' => $user->id,
        ]);
        $period->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();

        // Single attendance row, 8h regular work day for simplicity.
        \App\Modules\Attendance\Models\Attendance::create([
            'employee_id' => $emp->id, 'date' => '2026-04-01',
            'time_in' => '2026-04-01 08:00:00', 'time_out' => '2026-04-01 17:00:00',
            'regular_hours' => 8, 'overtime_hours' => 0, 'night_diff_hours' => 0,
            'tardiness_minutes' => 0, 'undertime_minutes' => 0,
            'is_rest_day' => false, 'day_type_rate' => 1.00, 'status' => 'present',
        ]);

        app(PayrollCalculatorService::class)->computeForEmployee($period, $emp);
        $period->forceFill(['status' => PayrollPeriodStatus::Approved->value])->save();
        $period->forceFill(['status' => PayrollPeriodStatus::Finalized->value])->save();

        return [$user, $period];
    }

    public function test_balanced_journal_entry_created_when_accounting_enabled(): void
    {
        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('modules.accounting', true, 'modules');

        [, $period] = $this->fullySetup();

        $entryId = app(PayrollGlPostingService::class)->post($period->fresh());

        $this->assertNotNull($entryId);
        $entry = DB::table('journal_entries')->where('id', $entryId)->first();
        $this->assertSame((string) $entry->total_debit, (string) $entry->total_credit, 'Journal entry must balance');
        $this->assertSame('posted', $entry->status);
        $this->assertSame('payroll_period', $entry->reference_type);
        $this->assertSame((int) $period->id, (int) $entry->reference_id);

        $period->refresh();
        $this->assertSame((int) $entryId, (int) $period->journal_entry_id);
    }

    public function test_payroll_wages_are_classified_by_production_department(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('modules.accounting', true, 'modules');
        $settings->set('accounting.payroll.direct_labor_department_codes', ['PRD'], 'accounting');
        $settings->set('accounting.accounts.salary_expense_code', '6010', 'accounting');
        $settings->set('accounting.accounts.overtime_expense_code', '6015', 'accounting');
        $settings->set('accounting.accounts.thirteenth_month_expense_code', '6020', 'accounting');
        $settings->set('accounting.accounts.production_salary_expense_code', '5020', 'accounting');
        $settings->set('accounting.accounts.production_overtime_expense_code', '5020', 'accounting');
        $settings->set('accounting.accounts.production_thirteenth_month_expense_code', '5070', 'accounting');

        foreach ([
            ['code' => '5020', 'name' => 'Direct Labor', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '6010', 'name' => 'Salaries & Wages Expense', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '6015', 'name' => 'Overtime Expense', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '6020', 'name' => 'Employee Benefits Expense', 'type' => 'expense', 'normal_balance' => 'debit'],
        ] as $account) {
            DB::table('accounts')->updateOrInsert(
                ['code' => $account['code']],
                $account + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        [$user, $period] = $this->fullySetup();
        $adminDepartment = Department::create(['name' => 'Administration', 'code' => 'ADMIN']);
        $adminPosition = Position::create(['title' => 'Finance Clerk', 'department_id' => $adminDepartment->id]);
        $adminEmployee = Employee::factory()->create([
            'department_id' => $adminDepartment->id,
            'position_id' => $adminPosition->id,
            'pay_type' => 'monthly',
            'basic_monthly_salary' => '6000.00',
        ]);
        Payroll::create([
            'payroll_period_id' => $period->id,
            'employee_id' => $adminEmployee->id,
            'pay_type' => 'monthly',
            'basic_pay' => '3000.00',
            'gross_pay' => '3000.00',
            'net_pay' => '3000.00',
            'computed_at' => now(),
        ]);

        $entryId = app(PayrollGlPostingService::class)->post($period->fresh());
        $lines = DB::table('journal_entry_lines as line')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->where('line.journal_entry_id', $entryId)
            ->get(['account.code', 'line.debit', 'line.credit']);

        $this->assertGreaterThan('0.00', (string) $lines->firstWhere('code', '5020')->debit);
        $this->assertSame('3000.00', (string) $lines->firstWhere('code', '6010')->debit);
        $this->assertSame('0', (string) $lines->where('code', '5050')->sum('debit'));
    }

    public function test_idempotent_returns_existing_entry(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('modules.accounting', true, 'modules');

        [, $period] = $this->fullySetup();
        $service = app(PayrollGlPostingService::class);

        $first  = $service->post($period->fresh());
        $second = $service->post($period->fresh());

        $this->assertSame((int) $first, (int) $second);
        $this->assertSame(1, DB::table('journal_entries')->where('reference_id', $period->id)->count());
    }

    public function test_skips_when_accounting_module_disabled(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('modules.accounting', false, 'modules');

        [, $period] = $this->fullySetup();
        $entryId = app(PayrollGlPostingService::class)->post($period->fresh());

        $this->assertNull($entryId);
        $this->assertNull($period->fresh()->journal_entry_id);
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_only_finalized_period_can_post(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('modules.accounting', true, 'modules');

        $roleId = Role::query()->orderBy('id')->value('id');
        $user = User::create(['name' => 'X', 'email' => 'x_'.uniqid().'@x.test', 'password' => bcrypt('p'), 'role_id' => $roleId]);
        $period = PayrollPeriod::create([
            'period_start' => '2026-04-01', 'period_end' => '2026-04-15',
            'payroll_date' => '2026-04-15', 'is_first_half' => true,
            'created_by' => $user->id,
        ]);
        $period->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();

        $this->expectException(\RuntimeException::class);
        app(PayrollGlPostingService::class)->post($period);
    }

    /**
     * Regression: the entry must balance when employees were LATE.
     *
     * Earnings are debited at their full gross while net pay is credited after
     * the lateness has been withheld, so without a contra-credit for
     * tardiness/undertime the entry could never balance and post() threw
     * "unbalanced" every time. The existing happy-path tests all used
     * tardiness_minutes = 0, so this went unnoticed — in the dev database not a
     * single one of five finalized periods had ever reached the ledger.
     */
    public function test_journal_entry_balances_when_employees_were_late(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('modules.accounting', true, 'modules');

        $roleId = Role::query()->orderBy('id')->value('id');
        $user = User::create([
            'name' => 'Tester', 'email' => 't_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'), 'role_id' => $roleId,
        ]);

        $dept = Department::create(['name' => 'Production', 'code' => 'PRD']);
        $pos  = Position::create(['title' => 'Operator', 'department_id' => $dept->id]);
        $emp = Employee::create([
            'employee_no' => 'OGM-2026-0777',
            'first_name' => 'Late', 'last_name' => 'Riser',
            'birth_date' => '1990-01-01', 'gender' => 'male', 'civil_status' => 'single',
            'nationality' => 'Filipino',
            'street_address' => '123 Main', 'city' => 'Dasmariñas', 'province' => 'Cavite',
            'mobile_number' => '09171234567', 'email' => 'late@example.com',
            'emergency_contact_name' => 'Maria', 'emergency_contact_phone' => '09181234567',
            'department_id' => $dept->id, 'position_id' => $pos->id,
            'employment_type' => 'regular', 'pay_type' => 'monthly',
            'date_hired' => '2025-01-01', 'basic_monthly_salary' => '20000.00',
        ]);

        $period = PayrollPeriod::create([
            'period_start' => '2026-04-01', 'period_end' => '2026-04-15',
            'payroll_date' => '2026-04-15', 'is_first_half' => true,
            'is_thirteenth_month' => false, 'created_by' => $user->id,
        ]);
        $period->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();

        // 45 minutes late plus 30 minutes undertime — both reduce gross.
        \App\Modules\Attendance\Models\Attendance::create([
            'employee_id' => $emp->id, 'date' => '2026-04-01',
            'time_in' => '2026-04-01 08:45:00', 'time_out' => '2026-04-01 16:30:00',
            'regular_hours' => 8, 'overtime_hours' => 0, 'night_diff_hours' => 0,
            'tardiness_minutes' => 45, 'undertime_minutes' => 30,
            'is_rest_day' => false, 'day_type_rate' => 1.00, 'status' => 'present',
        ]);

        $payroll = app(PayrollCalculatorService::class)->computeForEmployee($period, $emp);
        $this->assertTrue((float) $payroll->tardiness_deduction > 0);

        $period->forceFill(['status' => PayrollPeriodStatus::Approved->value])->save();
        $period->forceFill(['status' => PayrollPeriodStatus::Finalized->value])->save();

        $entryId = app(PayrollGlPostingService::class)->post($period->fresh());

        $this->assertNotNull($entryId, 'A period with late employees must still post');
        $entry = DB::table('journal_entries')->where('id', $entryId)->first();
        $this->assertSame(
            (string) $entry->total_debit,
            (string) $entry->total_credit,
            'Journal entry must balance even when pay was withheld for lateness',
        );

        // And the line sums must independently agree with the header totals.
        $lines = DB::table('journal_entry_lines')->where('journal_entry_id', $entryId)->get();
        $this->assertEqualsWithDelta(
            (float) $lines->sum('debit'),
            (float) $lines->sum('credit'),
            0.01,
        );
    }

    public function test_thirteenth_month_gl_posts_gross_expense_and_withholding_payable(): void
    {
        app(SettingsService::class)->set('modules.accounting', true, 'modules');

        [, $period] = $this->fullySetup();
        $period->forceFill(['is_thirteenth_month' => true])->save();
        $period->payrolls()->firstOrFail()->forceFill([
            'basic_pay'        => '0.00',
            'gross_pay'        => '1000.00',
            'withholding_tax'  => '100.00',
            'total_deductions' => '100.00',
            'net_pay'          => '900.00',
        ])->save();

        $entryId = app(PayrollGlPostingService::class)->post($period->fresh());
        $lines = DB::table('journal_entry_lines as jel')
            ->join('accounts as a', 'a.id', '=', 'jel.account_id')
            ->where('jel.journal_entry_id', $entryId)
            ->get(['a.code', 'jel.debit', 'jel.credit']);

        $this->assertSame('1000.00', (string) $lines->firstWhere('code', '5070')->debit);
        $this->assertSame('100.00', (string) $lines->firstWhere('code', '2050')->credit);
        $this->assertSame('900.00', (string) $lines->firstWhere('code', '2080')->credit);
        $this->assertSame((string) DB::table('journal_entries')->where('id', $entryId)->value('total_debit'),
            (string) DB::table('journal_entries')->where('id', $entryId)->value('total_credit'));
    }

    public function test_negative_13th_month_tax_correction_debits_withholding_payable(): void
    {
        app(SettingsService::class)->set('modules.accounting', true, 'modules');
        [, $period] = $this->fullySetup();
        $period->forceFill(['is_thirteenth_month' => true])->save();
        $period->payrolls()->firstOrFail()->forceFill([
            'basic_pay' => '0.00',
            'gross_pay' => '1000.00',
            'withholding_tax' => '-100.00',
            'total_deductions' => '-100.00',
            'net_pay' => '1100.00',
        ])->save();

        $entryId = app(PayrollGlPostingService::class)->post($period->fresh());
        $lines = DB::table('journal_entry_lines as jel')
            ->join('accounts as a', 'a.id', '=', 'jel.account_id')
            ->where('jel.journal_entry_id', $entryId)
            ->get(['a.code', 'jel.debit', 'jel.credit']);

        $this->assertSame('100.00', (string) $lines->firstWhere('code', '2050')->debit);
        $this->assertSame('1100.00', (string) $lines->firstWhere('code', '2080')->credit);
        $this->assertSame(
            (string) DB::table('journal_entries')->where('id', $entryId)->value('total_debit'),
            (string) DB::table('journal_entries')->where('id', $entryId)->value('total_credit'),
        );
    }
}
