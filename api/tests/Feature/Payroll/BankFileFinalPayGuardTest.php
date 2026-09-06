<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\BankFileService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * HR-01 defense-in-depth — the bank file must not pay days a posted final-pay
 * journal entry has already paid.
 *
 * FinalPayService refuses to compute final pay while the covering period is
 * undisbursed, so this state is unreachable for new data. The check catches
 * rows computed before that guard existed, where the last salary was booked
 * into final pay while the period was still open.
 */
class BankFileFinalPayGuardTest extends TestCase
{
    use RefreshDatabase;

    private BankFileService $svc;
    private Department $dept;
    private PayrollPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->svc  = app(BankFileService::class);
        $this->dept = Department::create(['name' => 'Production', 'code' => 'PRD']);

        $this->period = PayrollPeriod::factory()->create([
            'period_start' => '2026-08-01',
            'period_end'   => '2026-08-15',
            'payroll_date' => '2026-08-15',
            'is_first_half' => true,
        ]);
        $this->period->forceFill(['status' => 'finalized'])->save();
        $this->period = $this->period->fresh();
    }

    private function actor(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'hr_officer')->value('id')]);
    }

    private function payrollFor(string $netPay, array $employeeOverrides = []): Payroll
    {
        $pos = Position::create(['title' => 'Op '.uniqid(), 'department_id' => $this->dept->id]);

        $employee = Employee::factory()->create(array_merge([
            'department_id'        => $this->dept->id,
            'position_id'          => $pos->id,
            'pay_type'             => 'monthly',
            'basic_monthly_salary' => '20000.00',
            'bank_name'            => 'BDO Unibank',
            'bank_account_no'      => '00'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
            'status'               => 'active',
        ], $employeeOverrides));

        return Payroll::create([
            'payroll_period_id' => $this->period->id,
            'employee_id'       => $employee->id,
            'pay_type'          => 'monthly',
            'basic_pay'         => $netPay,
            'gross_pay'         => $netPay,
            'total_deductions'  => '0.00',
            'net_pay'           => $netPay,
            'computed_at'       => now(),
        ]);
    }

    /**
     * A finalized clearance whose posted final-pay JE already paid
     * $lastSalary of this period's days.
     */
    private function seedConsumingFinalPay(Employee $employee, string $lastSalary, string $separationDate, string $jeStatus = 'posted'): void
    {
        $actor = $this->actor();

        $jeId = DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JE-T-'.substr(uniqid(), -5),
            'date'         => $separationDate,
            'description'  => 'Final pay',
            'total_debit'  => $lastSalary,
            'total_credit' => $lastSalary,
            'status'       => $jeStatus,
            'posted_at'    => $jeStatus === 'posted' ? now() : null,
            'posted_by'    => $actor->id,
            'created_by'   => $actor->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('clearances')->insert([
            'clearance_no'        => 'CLR-T-'.substr(uniqid(), -5),
            'employee_id'         => $employee->id,
            'separation_date'     => $separationDate,
            'separation_reason'   => 'resigned',
            'clearance_items'     => json_encode([]),
            'final_pay_computed'  => true,
            'final_pay_amount'    => $lastSalary,
            'final_pay_breakdown' => json_encode([
                'last_salary_pro_rated'          => $lastSalary,
                'unused_convertible_leave_value' => '0.00',
                'pro_rated_13th_month'           => '0.00',
                'less_loan_balance'              => '0.00',
                'less_advance'                   => '0.00',
                'less_unreturned_property_value' => '0.00',
                'gross_plus'                     => $lastSalary,
                'gross_less'                     => '0.00',
                'net'                            => $lastSalary,
            ]),
            'journal_entry_id'    => $jeId,
            'status'              => 'finalized',
            'initiated_by'        => $actor->id,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    // ─── Rows already paid through final pay are refused ───────

    public function test_generation_is_refused_when_final_pay_already_paid_the_period_days(): void
    {
        $consumed = $this->payrollFor('9460.00', ['employee_no' => 'OGM-7777', 'first_name' => 'Paid', 'last_name' => 'Twice']);
        $this->payrollFor('10000.00');

        $this->seedConsumingFinalPay($consumed->employee, '4730.00', '2026-08-05');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('already been paid');

        $this->svc->generate($this->period, $this->actor(), 'generic');
    }

    public function test_the_refusal_names_the_employee_and_clearance(): void
    {
        $consumed = $this->payrollFor('9460.00', ['employee_no' => 'OGM-7777', 'first_name' => 'Paid', 'last_name' => 'Twice']);

        $this->seedConsumingFinalPay($consumed->employee, '4730.00', '2026-08-05');

        try {
            $this->svc->generate($this->period, $this->actor(), 'generic');
            $this->fail('Expected the final-pay guard to refuse.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('OGM-7777', $e->getMessage());
            $this->assertMatchesRegularExpression('/CLR-T-[a-z0-9]+/', $e->getMessage());
        }

        $this->assertSame(0, $this->period->bankFileRecords()->count(), 'no audit row for a refused file');
        $this->assertEmpty(Storage::disk('local')->files('bank-files'), 'no CSV left on disk');
    }

    // ─── Safe lookalikes are not blocked ───────────────────────

    public function test_final_pay_that_booked_no_last_salary_does_not_block_the_file(): void
    {
        $employee = $this->payrollFor('9460.00');
        $this->payrollFor('10000.00');

        $this->seedConsumingFinalPay($employee->employee, '0.00', '2026-08-05');

        $record = $this->svc->generate($this->period, $this->actor(), 'generic');

        $this->assertSame(2, $record->record_count);
        $this->assertSame('19460.00', (string) $record->total_amount);
    }

    public function test_final_pay_outside_the_period_window_does_not_block_the_file(): void
    {
        $employee = $this->payrollFor('9460.00');
        $this->payrollFor('10000.00');

        $this->seedConsumingFinalPay($employee->employee, '4730.00', '2026-07-15');

        $record = $this->svc->generate($this->period, $this->actor(), 'generic');

        $this->assertSame(2, $record->record_count);
    }

    public function test_a_reversed_final_pay_entry_does_not_block_the_file(): void
    {
        $employee = $this->payrollFor('9460.00');
        $this->payrollFor('10000.00');

        $this->seedConsumingFinalPay($employee->employee, '4730.00', '2026-08-05', 'reversed');

        $record = $this->svc->generate($this->period, $this->actor(), 'generic');

        $this->assertSame(2, $record->record_count);
    }
}
