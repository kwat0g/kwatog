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

    public function test_sss_r3_export_excludes_non_filed_periods(): void
    {
        $emp = Employee::factory()->create(['last_name' => 'Draft', 'sss_no' => '34-9999999-9']);
        $draft = PayrollPeriod::factory()->create([
            'status' => 'draft', 'period_start' => '2025-06-01', 'period_end' => '2025-06-15',
            'payroll_date' => '2025-06-15', 'is_first_half' => true, 'is_thirteenth_month' => false,
        ]);
        Payroll::factory()->create([
            'employee_id' => $emp->id, 'payroll_period_id' => $draft->id,
            'basic_pay' => 20000.00, 'sss_ee' => 1000.00, 'sss_er' => 2000.00,
            'gross_pay' => 20000.00, 'net_pay' => 19000.00, 'error_message' => null,
        ]);

        // A draft period is not a filed period — the export must be empty.
        $this->assertCount(0, (new SssR3Export($draft))->collection());
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

    public function test_pagibig_mcrf_lists_per_employee_shares(): void
    {
        $emp = Employee::factory()->create([
            'last_name' => 'Lim', 'first_name' => 'Bert', 'pagibig_no' => '1234-5678-9012',
        ]);
        $period = $this->finalizedPeriod('2025-05-01', '2025-05-15');
        Payroll::factory()->create([
            'employee_id' => $emp->id, 'payroll_period_id' => $period->id,
            'pagibig_ee' => 200.00, 'pagibig_er' => 200.00,
            'gross_pay' => 20000.00, 'net_pay' => 19600.00, 'error_message' => null,
        ]);

        $user = \App\Modules\Auth\Models\User::create([
            'name' => 'T', 'email' => 't_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
            'role_id' => \App\Modules\Auth\Models\Role::query()->orderBy('id')->value('id'),
        ]);

        $csv = $this->actingAs($user)
            ->get('/api/v1/payroll/statutory/mcrf?year=2025&month=5')
            ->assertStatus(200)->getContent();

        $this->assertStringContainsString('LIM', $csv);
        $this->assertStringContainsString('1234-5678-9012', $csv);
        $this->assertStringContainsString('400.00', $csv); // total
    }

    public function test_bir_1604cf_aggregates_year_totals(): void
    {
        $emp = Employee::factory()->create(['last_name' => 'Cruz']);
        $p1 = $this->finalizedPeriod('2025-01-01', '2025-01-15');
        $p2 = $this->finalizedPeriod('2025-02-01', '2025-02-15');
        foreach ([$p1, $p2] as $period) {
            Payroll::factory()->create([
                'employee_id' => $emp->id, 'payroll_period_id' => $period->id,
                'gross_pay' => 25000.00, 'withholding_tax' => 1000.00,
                'net_pay' => 24000.00, 'error_message' => null,
            ]);
        }

        $user = \App\Modules\Auth\Models\User::create([
            'name' => 'T', 'email' => 't_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
            'role_id' => \App\Modules\Auth\Models\Role::query()->orderBy('id')->value('id'),
        ]);

        $csv = $this->actingAs($user)
            ->get('/api/v1/payroll/statutory/1604cf?year=2025')
            ->assertStatus(200)->getContent();

        $this->assertStringContainsString('50000.00', $csv); // 2 periods x 25000
        $this->assertStringContainsString('2000.00', $csv);  // 2 periods x 1000
    }

    // ─── REC-06 additions ────────────────────────────────────────────────

    private function userWithRole(string $slug): \App\Modules\Auth\Models\User
    {
        return \App\Modules\Auth\Models\User::create([
            'name' => 'T', 'email' => 't_'.substr(uniqid(), -8).'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id' => \App\Modules\Auth\Models\Role::query()->where('slug', $slug)->value('id'),
        ]);
    }

    public function test_sss_r3_route_downloads_for_a_finalized_period(): void
    {
        $emp = Employee::factory()->create(['last_name' => 'Dela Cruz', 'sss_no' => '34-1234567-8']);
        $period = $this->finalizedPeriod('2025-07-01', '2025-07-15');
        Payroll::factory()->create([
            'employee_id' => $emp->id, 'payroll_period_id' => $period->id,
            'sss_ee' => 1000.00, 'sss_er' => 2000.00,
            'gross_pay' => 20000.00, 'net_pay' => 19000.00, 'error_message' => null,
        ]);

        $res = $this->actingAs($this->userWithRole('finance_officer'))
            ->get("/api/v1/payroll/statutory/sss-r3/{$period->hash_id}")
            ->assertStatus(200);

        $this->assertStringContainsString('attachment', (string) $res->headers->get('content-disposition'));
        $this->assertStringContainsString('SSS-R3', (string) $res->headers->get('content-disposition'));
    }

    public function test_sss_r3_route_requires_authentication(): void
    {
        $period = $this->finalizedPeriod('2025-08-01', '2025-08-15');

        // Unauthenticated → 401. NOTE: like the other statutory routes, once
        // authenticated this is gated only by `payroll.view`, which selfService()
        // grants to every role — see the REC-06 security follow-up (statutory
        // exports expose company-wide PII to any staff member).
        $this->get("/api/v1/payroll/statutory/sss-r3/{$period->hash_id}")
            ->assertStatus(401);
    }

    public function test_1601c_splits_taxable_and_exempt_and_reconciles(): void
    {
        $emp = Employee::factory()->create(['last_name' => 'Aquino']);
        $period = $this->finalizedPeriod('2025-09-01', '2025-09-15');
        // gross 30000; exempt statutory EE = 900+400+200 = 1500; taxable = 28500.
        Payroll::factory()->create([
            'employee_id' => $emp->id, 'payroll_period_id' => $period->id,
            'gross_pay' => 30000.00, 'withholding_tax' => 1500.00,
            'sss_ee' => 900.00, 'philhealth_ee' => 400.00, 'pagibig_ee' => 200.00,
            'net_pay' => 27000.00, 'total_deductions' => 3000.00, 'error_message' => null,
        ]);

        $data = app(\App\Modules\Payroll\Services\Statutory\Bir1601CService::class)->generate(2025, 9);

        $this->assertSame(30000.00, $data['total_compensation']);
        $this->assertSame(1500.00, $data['non_taxable_compensation']);
        $this->assertSame(28500.00, $data['taxable_compensation']);
        // Reconciliation identity: taxable + exempt == total.
        $this->assertSame(
            round($data['taxable_compensation'] + $data['non_taxable_compensation'], 2),
            $data['total_compensation'],
        );
        // Monthly tax_due mirrors withheld (annualization deferred to 1604-CF).
        $this->assertSame($data['total_withheld'], $data['tax_due']);
    }

    public function test_1601c_csv_includes_the_split_columns(): void
    {
        $emp = Employee::factory()->create(['last_name' => 'Bautista']);
        $period = $this->finalizedPeriod('2025-10-01', '2025-10-15');
        Payroll::factory()->create([
            'employee_id' => $emp->id, 'payroll_period_id' => $period->id,
            'gross_pay' => 30000.00, 'withholding_tax' => 1500.00,
            'sss_ee' => 900.00, 'philhealth_ee' => 400.00, 'pagibig_ee' => 200.00,
            'net_pay' => 27000.00, 'error_message' => null,
        ]);

        $csv = $this->actingAs($this->userWithRole('finance_officer'))
            ->get('/api/v1/payroll/statutory/1601c?year=2025&month=10')
            ->assertStatus(200)->getContent();

        $this->assertStringContainsString('Taxable Compensation', $csv);
        $this->assertStringContainsString('Non-Taxable Compensation', $csv);
        $this->assertStringContainsString('28500.00', $csv);
        $this->assertStringContainsString('1500.00', $csv);
    }
}
