<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Exports\Government\SssR3Export;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatutoryExportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function finalizedPeriod(string $start = '2025-01-01', string $end = '2025-01-15'): PayrollPeriod
    {
        return PayrollPeriod::factory()->create([
            'status' => 'finalized', 'period_start' => $start, 'period_end' => $end,
            'payroll_date' => $end, 'is_first_half' => true, 'is_thirteenth_month' => false,
        ]);
    }

    public function test_sss_r3_export_reads_real_columns(): void
    {
        $emp = Employee::factory()->create([
            'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'sss_no' => '34-1234567-8',
        ]);
        $period = $this->finalizedPeriod();
        Payroll::factory()->create([
            'employee_id' => $emp->id, 'payroll_period_id' => $period->id,
            'basic_pay' => 20000.00, 'sss_ee' => 1000.00, 'sss_er' => 2000.00,
            'gross_pay' => 20000.00, 'net_pay' => 19000.00, 'error_message' => null,
        ]);

        $rows = (new SssR3Export($period))->collection();
        $this->assertCount(1, $rows);

        $mapped = (new SssR3Export($period))->map($rows->first());
        // Headings: [SS No, Last, First, Middle, Monthly, EE, ER, EC, Total, Remarks]
        $this->assertSame('1000.00', $mapped[5]); // EE share from sss_ee
        $this->assertSame('2000.00', $mapped[6]); // ER share from sss_er
        $this->assertSame('3000.00', $mapped[8]); // total EE+ER+EC
    }

    public function test_bir_1601c_aggregates_month_totals(): void
    {
        $emp = Employee::factory()->create(['last_name' => 'Santos']);
        $period = $this->finalizedPeriod('2025-03-01', '2025-03-15');
        Payroll::factory()->create([
            'employee_id' => $emp->id, 'payroll_period_id' => $period->id,
            'gross_pay' => 25000.00, 'withholding_tax' => 1200.00,
            'net_pay' => 23000.00, 'total_deductions' => 2000.00, 'error_message' => null,
        ]);

        $user = \App\Modules\Auth\Models\User::create([
            'name' => 'T', 'email' => 't_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => \App\Modules\Auth\Models\Role::query()->orderBy('id')->value('id'),
        ]);

        $csv = $this->actingAs($user)
            ->get('/api/v1/payroll/statutory/1601c?year=2025&month=3')
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('25000.00', $csv); // total compensation
        $this->assertStringContainsString('1200.00', $csv);  // total tax withheld
        $this->assertStringContainsString('2025-03', $csv);  // period label
    }

    public function test_statutory_export_requires_auth(): void
    {
        $this->get('/api/v1/payroll/statutory/1601c?year=2025&month=3')->assertStatus(401);
    }

    public function test_philhealth_rf1_lists_per_employee_shares(): void
    {
        $emp = Employee::factory()->create([
            'last_name' => 'Reyes', 'first_name' => 'Ana', 'philhealth_no' => '11-222222222-3',
        ]);
        $period = $this->finalizedPeriod('2025-04-01', '2025-04-15');
        Payroll::factory()->create([
            'employee_id' => $emp->id, 'payroll_period_id' => $period->id,
            'philhealth_ee' => 250.00, 'philhealth_er' => 250.00,
            'gross_pay' => 20000.00, 'net_pay' => 19500.00, 'error_message' => null,
        ]);

        $user = \App\Modules\Auth\Models\User::create([
            'name' => 'T', 'email' => 't_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
            'role_id' => \App\Modules\Auth\Models\Role::query()->orderBy('id')->value('id'),
        ]);

        $csv = $this->actingAs($user)
            ->get('/api/v1/payroll/statutory/rf1?year=2025&month=4')
            ->assertStatus(200)->getContent();

        $this->assertStringContainsString('REYES', $csv);
        $this->assertStringContainsString('11-222222222-3', $csv);
        $this->assertStringContainsString('250.00', $csv);
        $this->assertStringContainsString('500.00', $csv); // ee + er total
    }
}
