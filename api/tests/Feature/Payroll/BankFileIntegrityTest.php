<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Payroll\Enums\BankFileGenerationStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\BankFileService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The bank file is the last thing that happens before money leaves the company,
 * so its failure modes are all silent-money failures.
 *
 *   - Every format builder skips a payroll row whose employee has no
 *     bank_account_no. That skip was silent: the file came out short, its total
 *     no longer matched the approved payroll, and the GL still posted the full
 *     amount — the difference being money recognised as paid but never sent.
 *
 *   - Employee names reach this CSV, and the employee CSV importer applies no
 *     character validation, so a name beginning = + - @ executes as a formula in
 *     the spreadsheet finance opens it with.
 */
class BankFileIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private BankFileService $svc;
    private Department $dept;
    private PayrollPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
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

    private function payrollFor(string $netPay, ?string $bankAccount = '001234567890', array $employeeOverrides = []): Payroll
    {
        $pos = Position::create(['title' => 'Op '.uniqid(), 'department_id' => $this->dept->id]);

        $employee = Employee::factory()->create(array_merge([
            'department_id'        => $this->dept->id,
            'position_id'          => $pos->id,
            'pay_type'             => 'monthly',
            'basic_monthly_salary' => '20000.00',
            'bank_name'            => 'BDO Unibank',
            'bank_account_no'      => $bankAccount,
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

    // ─── Nobody is silently dropped ────────────────────────────

    public function test_a_fully_bankable_period_generates_normally(): void
    {
        $this->payrollFor('10000.00');
        $this->payrollFor('12000.00');

        $record = $this->svc->generate($this->period, $this->actor(), 'generic');

        $this->assertSame(2, $record->record_count);
        $this->assertSame('22000.00', (string) $record->total_amount);
    }

    public function test_generation_is_refused_when_an_employee_has_no_bank_account(): void
    {
        $this->payrollFor('10000.00');
        $this->payrollFor('7500.00', bankAccount: null);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('no bank account on file');

        $this->svc->generate($this->period, $this->actor(), 'generic');
    }

    public function test_the_refusal_names_the_shortfall_so_hr_can_act(): void
    {
        $this->payrollFor('10000.00');
        $this->payrollFor('7500.00', bankAccount: null, employeeOverrides: [
            'employee_no' => 'OGM-9999',
            'first_name'  => 'Unbanked',
            'last_name'   => 'Worker',
        ]);

        try {
            $this->svc->generate($this->period, $this->actor(), 'generic');
            $this->fail('Expected the unbankable guard to refuse.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('7,500.00', $e->getMessage(), 'the amount at risk must be stated');
            $this->assertStringContainsString('OGM-9999', $e->getMessage(), 'the affected employee must be named');
        }
    }

    public function test_no_file_is_written_when_generation_is_refused(): void
    {
        $this->payrollFor('10000.00');
        $this->payrollFor('7500.00', bankAccount: null);

        try {
            $this->svc->generate($this->period, $this->actor(), 'generic');
        } catch (BusinessRuleException) {
            // expected
        }

        $this->assertSame(0, $this->period->bankFileRecords()->count(), 'no audit row for a refused file');
        $this->assertEmpty(Storage::disk('local')->files('bank-files'), 'no CSV left on disk');
    }

    public function test_preview_reports_the_shortfall_before_the_user_downloads(): void
    {
        $this->payrollFor('10000.00');
        $this->payrollFor('7500.00', bankAccount: null);

        $preview = $this->svc->preview($this->period, 'generic');

        $this->assertSame(1, $preview['unbankable_count']);
        $this->assertSame('7500.00', $preview['unbankable_amount']);
        $this->assertSame('7500.00', $preview['unbankable_sample'][0]['net_pay']);
    }

    public function test_preview_is_clean_when_everyone_is_bankable(): void
    {
        $this->payrollFor('10000.00');

        $preview = $this->svc->preview($this->period, 'generic');

        $this->assertSame(0, $preview['unbankable_count']);
        $this->assertSame('0.00', $preview['unbankable_amount']);
    }

    // ─── The file totals what the period owes ──────────────────

    #[\PHPUnit\Framework\Attributes\DataProvider('formats')]
    public function test_every_format_totals_the_full_period(string $format): void
    {
        $this->payrollFor('10000.00');
        $this->payrollFor('12345.67');

        $record = $this->svc->generate($this->period, $this->actor(), $format);

        $expected = Payroll::where('payroll_period_id', $this->period->id)->sum('net_pay');
        $this->assertSame(
            number_format((float) $expected, 2, '.', ''),
            (string) $record->total_amount,
            "{$format} must disburse exactly what the period owes",
        );
    }

    /** @return array<string, array{string}> */
    public static function formats(): array
    {
        return [
            'generic'   => ['generic'],
            'bdo'       => ['bdo'],
            'bpi'       => ['bpi'],
            'metrobank' => ['metrobank'],
        ];
    }

    // ─── CSV formula injection ─────────────────────────────────

    /**
     * A name beginning = + - @ executes as a formula when the bank file is
     * opened in Excel or LibreOffice — on the workstation of someone who can
     * move money. The employee CSV importer applies no character validation, so
     * such a name reaches this file unfiltered.
     */
    public function test_a_formula_leading_name_is_neutralised_in_the_csv(): void
    {
        $this->payrollFor('10000.00', employeeOverrides: [
            'first_name' => '=cmd',
            'last_name'  => 'Injected',
        ]);

        $record = $this->svc->generate($this->period, $this->actor(), 'generic');
        $csv = Storage::disk('local')->get($record->file_path);

        $this->assertStringNotContainsString(',=cmd', $csv, 'a bare formula must never reach the file');
        $this->assertStringContainsString("'=cmd", $csv, 'the value is prefixed so it renders as literal text');
    }

    public function test_ordinary_names_are_not_mangled(): void
    {
        $this->payrollFor('10000.00', employeeOverrides: [
            'first_name' => 'Juan',
            'last_name'  => 'Dela Cruz',
        ]);

        $record = $this->svc->generate($this->period, $this->actor(), 'generic');
        $csv = Storage::disk('local')->get($record->file_path);

        $this->assertStringContainsString('Juan Dela Cruz', $csv);
        $this->assertStringNotContainsString("'Juan", $csv, 'a normal name must not gain a quote prefix');
    }

    public function test_a_name_containing_a_comma_stays_one_field(): void
    {
        $this->payrollFor('10000.00', employeeOverrides: [
            'first_name' => 'Juan',
            'last_name'  => 'Cruz, Jr',
        ]);

        $record = $this->svc->generate($this->period, $this->actor(), 'generic');
        $csv = Storage::disk('local')->get($record->file_path);

        $this->assertStringContainsString('"Juan Cruz, Jr"', $csv, 'commas must be quoted, not split the row');
    }

    // ─── Existing guarantees still hold ────────────────────────

    public function test_an_unfinalized_period_is_still_refused(): void
    {
        $this->payrollFor('10000.00');
        $this->period->forceFill(['status' => 'approved'])->save();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('finalized');

        $this->svc->generate($this->period->fresh(), $this->actor(), 'generic');
    }

    public function test_a_failed_payroll_row_is_excluded_without_blocking_the_file(): void
    {
        $this->payrollFor('10000.00');

        // An error row is a ₱0 diagnostic marker: net_pay = 0, so it is neither
        // paid nor counted as an unbankable shortfall.
        $failed = $this->payrollFor('0.00', bankAccount: null);
        $failed->forceFill(['error_message' => 'computation failed'])->save();

        $record = $this->svc->generate($this->period, $this->actor(), 'generic');

        $this->assertSame(1, $record->record_count);
        $this->assertSame('10000.00', (string) $record->total_amount);
    }

    public function test_a_false_storage_write_fails_closed_before_success_is_persisted(): void
    {
        $this->payrollFor('10000.00');
        $generator = $this->actor();

        $disk = \Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldReceive('put')->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        try {
            $this->svc->generate($this->period, $generator, 'generic');
            $this->fail('A false storage write must be treated as a generation failure.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('write', strtolower($e->getMessage()));
        }

        $period = $this->period->fresh();
        $this->assertSame(0, $period->bankFileRecords()->count());
        $this->assertSame(BankFileGenerationStatus::ManualRequired, $period->bank_file_status);
        $this->assertSame('finalized', $period->status->value);
    }
}
