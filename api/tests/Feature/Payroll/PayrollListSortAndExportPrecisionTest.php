<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\Money;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\BirAlphalistService;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Three M021 audit findings, pinned.
 *
 *  1. Both payroll list endpoints whitelisted the sort COLUMN but passed the
 *     caller's `direction` straight to Eloquent's orderBy(), which throws
 *     InvalidArgumentException on anything but asc/desc — so `?direction=x`
 *     reached the browser as a 500 with a stack trace instead of a list.
 *  2. The BIR 2316 Alphalist put money through round((float) …) and computed
 *     taxable_income as a float subtraction of two float-cast sums. That is
 *     binary floating-point arithmetic inside a filed tax return, on the one
 *     figure that has to reconcile against the payroll rows.
 *  3. PayrollCalculatorService held a second, private copy of the
 *     monthly/semi_monthly reconciliation that CLAUDE.md names
 *     Employee::monthlyEquivalentSalary() as the single home of. The copies
 *     agreed, which is exactly why the drift would have gone unnoticed.
 */
class PayrollListSortAndExportPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private PayrollCalculatorService $calc;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-08-30 09:00:00'));
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
        $this->calc = app(PayrollCalculatorService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(array $overrides = []): Employee
    {
        $dept = Department::firstOrCreate(['code' => 'PRD'], ['name' => 'Production']);
        $pos = Position::firstOrCreate(['title' => 'Operator', 'department_id' => $dept->id]);

        return Employee::factory()->create(array_merge([
            'department_id' => $dept->id,
            'position_id' => $pos->id,
            'employment_type' => 'regular',
            'pay_type' => 'monthly',
            'basic_monthly_salary' => '20000.00',
            'semi_monthly_rate' => null,
            'date_hired' => '2025-01-01',
            'status' => 'active',
        ], $overrides));
    }

    private function period(string $start = '2026-04-01', string $end = '2026-04-15'): PayrollPeriod
    {
        $p = PayrollPeriod::factory()->create([
            'period_start' => $start,
            'period_end' => $end,
            'payroll_date' => $end,
            'is_first_half' => PayrollPeriod::deriveIsFirstHalf($start),
            'is_thirteenth_month' => false,
        ]);
        $p->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();

        return $p->fresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'system_admin')->value('id')]);
    }

    // ─── 1 · sort direction ─────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function badDirections(): array
    {
        return [
            'nonsense word' => ['sideways'],
            'sql fragment' => ['asc; drop table payrolls'],
            'empty' => [''],
            'mixed case injection' => ['DESC--'],
        ];
    }

    #[DataProvider('badDirections')]
    public function test_period_list_normalises_an_unusable_sort_direction(string $direction): void
    {
        $this->period();
        $this->actingAs($this->admin());

        $response = $this->getJson('/api/v1/payroll-periods?sort=period_start&direction='.urlencode($direction));

        $this->assertSame(200, $response->status(), 'got HTTP '.$response->status().' for direction='.$direction);
        $this->assertCount(1, $response->json('data'));
    }

    #[DataProvider('badDirections')]
    public function test_payroll_list_normalises_an_unusable_sort_direction(string $direction): void
    {
        $this->actingAs($this->admin());

        $response = $this->getJson('/api/v1/payrolls?sort=net_pay&direction='.urlencode($direction));

        $this->assertSame(200, $response->status(), 'got HTTP '.$response->status().' for direction='.$direction);
    }

    public function test_an_explicit_ascending_direction_is_still_honoured(): void
    {
        $early = $this->period('2026-04-01', '2026-04-15');
        $late = $this->period('2026-05-01', '2026-05-15');
        $this->actingAs($this->admin());

        $asc = $this->getJson('/api/v1/payroll-periods?sort=period_start&direction=asc');
        $this->assertSame(
            [$early->hash_id, $late->hash_id],
            collect($asc->json('data'))->pluck('id')->all(),
        );

        $desc = $this->getJson('/api/v1/payroll-periods?sort=period_start&direction=DESC');
        $this->assertSame(
            [$late->hash_id, $early->hash_id],
            collect($desc->json('data'))->pluck('id')->all(),
            'direction matching must be case-insensitive',
        );
    }

    // ─── 2 · statutory export precision ─────────────────────────

    public function test_alphalist_figures_are_decimal_strings_that_reconcile_exactly(): void
    {
        // Amounts chosen so a float round-trip of gross - deductions is the
        // interesting case rather than a clean number.
        $employee = $this->employee(['basic_monthly_salary' => '20000.03']);
        $period = $this->period();
        $payroll = $this->calc->computeForEmployee($period, $employee);
        $period->forceFill(['status' => PayrollPeriodStatus::Finalized->value])->save();

        $rows = app(BirAlphalistService::class)->generate(2026);
        $this->assertCount(1, $rows);
        $row = $rows[0];

        foreach (['total_gross', 'total_deductions', 'taxable_income', 'total_withheld_tax'] as $key) {
            $this->assertIsString($row[$key], "{$key} must stay a decimal string, never a float");
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $row[$key], "{$key} must carry exactly two decimals");
        }

        // The reported figures are the persisted payroll row, verbatim.
        $this->assertSame(Money::round2((string) $payroll->gross_pay), $row['total_gross']);
        $this->assertSame(Money::round2((string) $payroll->total_deductions), $row['total_deductions']);
        $this->assertSame(Money::round2((string) $payroll->withholding_tax), $row['total_withheld_tax']);

        // taxable_income is derived with BCMath, so it reconciles to the cent.
        $this->assertSame(
            Money::sub($row['total_gross'], $row['total_deductions']),
            $row['taxable_income'],
        );

        // …and the CSV carries those same strings without a second conversion.
        $csv = app(BirAlphalistService::class)->toCsv($rows);
        $this->assertStringContainsString(
            implode(',', [$row['total_gross'], $row['total_deductions'], $row['taxable_income'], $row['total_withheld_tax']]),
            $csv,
        );
    }

    public function test_alphalist_never_reports_a_negative_taxable_income(): void
    {
        $employee = $this->employee();
        $period = $this->period();
        $this->calc->computeForEmployee($period, $employee);
        // Deductions exceeding gross is possible on a clamped-net row.
        DB::table('payrolls')
            ->where('payroll_period_id', $period->id)
            ->update(['total_deductions' => '99999.99']);
        $period->forceFill(['status' => PayrollPeriodStatus::Finalized->value])->save();

        $rows = app(BirAlphalistService::class)->generate(2026);

        $this->assertSame('0.00', $rows[0]['taxable_income']);
    }

    // ─── 3 · one definition of the monthly equivalent ───────────

    public function test_flat_basic_is_the_monthly_equivalent_halved_for_both_pay_types(): void
    {
        $monthly = $this->employee(['basic_monthly_salary' => '21000.00', 'semi_monthly_rate' => null]);
        $semi = $this->employee([
            'pay_type' => 'semi_monthly',
            'basic_monthly_salary' => null,
            'semi_monthly_rate' => '9460.00',
        ]);
        $period = $this->period();

        // No attendance rows at all: basic pay is FLAT per cutoff and must not
        // depend on days worked (migration 0437 retired the daily pay type).
        $monthlyPayroll = $this->calc->computeForEmployee($period, $monthly);
        $semiPayroll = $this->calc->computeForEmployee($period, $semi);

        $this->assertSame(
            bcdiv((string) $monthly->monthlyEquivalentSalary(), '2', 2),
            (string) $monthlyPayroll->basic_pay,
        );
        $this->assertSame(
            bcdiv((string) $semi->monthlyEquivalentSalary(), '2', 2),
            (string) $semiPayroll->basic_pay,
        );
        $this->assertSame('18920.00', $semi->monthlyEquivalentSalary(), 'semi_monthly_rate × 2');
        $this->assertSame('0.0', (string) $monthlyPayroll->days_worked);
    }

    public function test_a_missing_rate_names_the_pay_type_it_is_missing_for(): void
    {
        $noRate = $this->employee([
            'pay_type' => 'semi_monthly',
            'basic_monthly_salary' => null,
            'semi_monthly_rate' => null,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('has no semi-monthly rate for payroll calculation');

        $this->calc->computeForEmployee($this->period(), $noRate);
    }

    public function test_a_missing_monthly_salary_names_the_monthly_pay_type(): void
    {
        $noRate = $this->employee(['basic_monthly_salary' => null, 'semi_monthly_rate' => null]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('has no monthly salary for payroll calculation');

        $this->calc->computeForEmployee($this->period(), $noRate);
    }
}
