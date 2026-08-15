<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\SeparationReason;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Services\FinalPayService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinalPayCentPrecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);

        foreach ([
            ['code' => '6010', 'name' => 'Salaries & Wages Expense', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '1020', 'name' => 'Cash in Bank', 'type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '2100', 'name' => 'Loans Payable', 'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '2070', 'name' => 'Accrued Expenses', 'type' => 'liability', 'normal_balance' => 'credit'],
        ] as $account) {
            DB::table('accounts')->updateOrInsert(
                ['code' => $account['code']],
                [...$account, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function test_cent_level_breakdown_and_posted_journal_reconcile_exactly(): void
    {
        $department = Department::firstOrCreate(['code' => 'PRD'], ['name' => 'Production']);
        $position = Position::firstOrCreate(['title' => 'Operator', 'department_id' => $department->id]);
        $employee = Employee::create([
            'employee_no' => 'CENT-'.random_int(1000, 9999),
            'first_name' => 'Cent',
            'last_name' => 'Precision',
            'birth_date' => '1990-01-01',
            'gender' => 'male',
            'civil_status' => 'single',
            'nationality' => 'Filipino',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employment_type' => 'regular',
            'pay_type' => 'monthly',
            'basic_monthly_salary' => '2200.22',
            'date_hired' => '2024-01-01',
            'status' => 'active',
        ]);
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $clearance = Clearance::create([
            'clearance_no' => 'CLR-CENT-'.random_int(1000, 9999),
            'employee_id' => $employee->id,
            'separation_date' => '2026-05-31',
            'separation_reason' => SeparationReason::Resigned->value,
            'clearance_items' => [],
            'status' => ClearanceStatus::InProgress->value,
            'initiated_by' => $user->id,
        ]);

        $periodId = DB::table('payroll_periods')->insertGetId([
            'period_start' => '2026-05-16',
            'period_end' => '2026-05-31',
            'payroll_date' => '2026-06-05',
            'is_first_half' => false,
            'is_thirteenth_month' => false,
            'status' => 'computed',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payrolls')->insert([
            'payroll_period_id' => $periodId,
            'employee_id' => $employee->id,
            'pay_type' => 'monthly',
            'basic_pay' => '100.11',
            'leave_pay' => '0.01',
            'tardiness_deduction' => '0.01',
            'undertime_deduction' => '0.00',
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('thirteenth_month_accruals')->insert([
            'employee_id' => $employee->id,
            'year' => 2026,
            'total_basic_earned' => '0.01',
            'accrued_amount' => '0.01',
            'is_paid' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_loans')->insert([
            'loan_no' => 'LN-CENT-'.random_int(1000, 9999),
            'employee_id' => $employee->id,
            'loan_type' => 'company_loan',
            'principal' => '33.34',
            'monthly_amortization' => '1.00',
            'total_paid' => '0.00',
            'balance' => '33.34',
            'pay_periods_total' => 34,
            'pay_periods_remaining' => 34,
            'status' => 'active',
            'is_final_pay_deduction' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_loans')->insert([
            'loan_no' => 'CA-CENT-'.random_int(1000, 9999),
            'employee_id' => $employee->id,
            'loan_type' => 'cash_advance',
            'principal' => '0.01',
            'monthly_amortization' => '0.01',
            'total_paid' => '0.00',
            'balance' => '0.01',
            'pay_periods_total' => 1,
            'pay_periods_remaining' => 1,
            'status' => 'active',
            'is_final_pay_deduction' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_property')->insert([
            'employee_id' => $employee->id,
            'item_name' => 'Badge',
            'quantity' => 1,
            'replacement_unit_cost' => '0.01',
            'date_issued' => '2025-01-01',
            'status' => 'lost',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(FinalPayService::class);
        $computed = $service->compute($clearance, $user);
        $breakdown = $computed->final_pay_breakdown;

        $this->assertSame('100.11', $breakdown['last_salary_pro_rated']);
        $this->assertSame('0.00', $breakdown['unused_convertible_leave_value']);
        $this->assertSame('0.01', $breakdown['pro_rated_13th_month']);
        $this->assertSame('100.12', $breakdown['gross_plus']);
        $this->assertSame('33.34', $breakdown['less_loan_balance']);
        $this->assertSame('0.01', $breakdown['less_advance']);
        $this->assertSame('0.01', $breakdown['less_unreturned_property_value']);
        $this->assertSame('33.36', $breakdown['gross_less']);
        $this->assertSame('66.76', $breakdown['net']);

        $journal = $service->postJournalEntry($computed, $user);
        $this->assertSame(JournalEntryStatus::Posted, $journal->status);

        $debit = '0.00';
        $credit = '0.00';
        foreach ($journal->fresh('lines')->lines as $line) {
            $debit = bcadd($debit, (string) $line->debit, 2);
            $credit = bcadd($credit, (string) $line->credit, 2);
        }
        $this->assertSame('100.12', $debit);
        $this->assertSame('100.12', $credit);
        $this->assertSame($debit, (string) $journal->fresh()->total_debit);
        $this->assertSame($credit, (string) $journal->fresh()->total_credit);
    }
}
