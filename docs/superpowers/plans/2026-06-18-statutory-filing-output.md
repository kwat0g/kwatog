# Statutory Filing Output Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the Philippine payroll → government-remittance *filing* process: make contribution/tax selection effective-date-aware, refresh the government tables to the current (2025) schedule, fix the broken SSS R-3 export, add the missing remittance forms (BIR 1601-C, PhilHealth RF-1, Pag-IBIG MCRF, BIR 1604-CF), and surface them all on a Statutory Exports screen.

**Architecture:** Backend = Laravel modular monolith. Government rates live in `government_contribution_tables` (effective-dated rows). A new `bracketsEffectiveOn(agency, date)` selector makes the four computation services pick the schedule in force on the pay date. Each statutory form is a single-responsibility service (`generate()` returns a typed array, `toCsv()` renders an agency-format CSV string) behind a thin controller method that streams the CSV — mirroring the existing `BirAlphalistService`/`BirAlphalistController` pattern. The SPA adds one Statutory Exports page that triggers browser downloads via a transient `<a download>` (cookie travels), mirroring `exportsApi.download`.

**Tech Stack:** PHP 8.3 / Laravel 11, PostgreSQL 16, `maatwebsite/excel ^3.1` (already installed, used by the SSS R-3 Excel export), PHPUnit; React 18 + TypeScript + Vite, TanStack Query, axios (cookie auth).

**Spec source:** `docs/REBUILD-AUDIT-2026-06-18.md` REC-02 (statutory remittance breadth) + REC-03 (refresh government tables); backlog `docs/REBUILD-AUDIT-2026-06-18-BACKLOG.md` OGAMI-101 / 102 / 103. This plan covers those three tickets as one coherent Payroll-module subsystem. (Supplier-side EWT forms — 2306/2307/1604-E — depend on the Accounting/AP bill layer and belong to a separate plan.)

## Global Constraints

- `declare(strict_types=1);` at the top of every PHP file.
- Money is `decimal` in DB and computed with `bcmath` strings; never float arithmetic on money.
- Every Eloquent model uses the `HasHashId` trait; API Resources return `hash_id`, never raw `id`. (The statutory exports return CSV files, not model JSON, so this applies only if you touch a Resource.)
- Business logic lives in Services; controllers are thin and delegate.
- Multi-row writes wrap in `DB::transaction()`.
- Permission slug for all statutory downloads: `payroll.view` (existing — matches `BirAlphalistController`). Do NOT invent a new slug.
- Test base class: `Tests\TestCase` with `RefreshDatabase`. HTTP tests seed `RolePermissionSeeder`; computation tests seed `GovernmentTableSeeder` and call `Cache::flush()` in `setUp()` (the gov-table service caches for 5 min).
- There is **no** `GovernmentContributionTableFactory` — create gov rows in tests with `GovernmentContributionTable::create([...])`.
- `Payroll` money columns (verified): `basic_pay, gross_pay, net_pay, total_deductions, leave_pay, sss_ee, sss_er, philhealth_ee, philhealth_er, pagibig_ee, pagibig_er, withholding_tax, error_message`; FK `payroll_period_id`; relations `period()`, `employee()`. **There is no `period_id`, `status`, `sss_employee`, `sss_ec` column on `payrolls`.**
- `PayrollPeriod` columns: `period_start, period_end, payroll_date, is_first_half, is_thirteenth_month, status` (enum cast `PayrollPeriodStatus`). Finalized/filed periods have `status` in `['finalized','disbursed']`.
- `Employee` PII (`tin`, `sss_no`, `philhealth_no`, `pagibig_no`) uses Laravel `encrypted` cast — load via Eloquent so it decrypts; never read via raw SQL.
- Test runner (one-time per container): `docker compose exec -T -u root api bash -c "echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/zz-mem.ini"`. Then run a single test with `docker compose exec -T api php artisan test --filter=ClassName`.
- New migration numbering: highest is `0220`; use `0221`, `0222`, … in order.
- Commit after every task with the message shown in its final step.

## File Structure

**Backend — create:**
- `api/database/migrations/0221_index_government_tables_agency_effective_date.php` — composite index for effective-date lookups (Task 1).
- `api/database/seeders/GovernmentTable2025Seeder.php` — 2025 SSS/PhilHealth/Pag-IBIG schedule (Task 4).
- `api/app/Modules/Payroll/Services/Statutory/Bir1601CService.php` (Task 6).
- `api/app/Modules/Payroll/Services/Statutory/PhilhealthRf1Service.php` (Task 7).
- `api/app/Modules/Payroll/Services/Statutory/PagibigMcrfService.php` (Task 8).
- `api/app/Modules/Payroll/Services/Statutory/Bir1604CfService.php` (Task 9).
- `api/app/Modules/Payroll/Controllers/StatutoryExportController.php` (Tasks 6–9).
- Tests: `api/tests/Feature/Payroll/EffectiveDatedBracketsTest.php` (Tasks 1–4), `.../StatutoryExportsTest.php` (Tasks 5–9).

**Backend — modify:**
- `api/app/Modules/Payroll/Services/GovernmentContributionTableService.php` — add `bracketsEffectiveOn()`, versioned cache (Task 1).
- `api/app/Modules/Payroll/Services/Government/{Sss,Philhealth,Pagibig}ComputationService.php` + `BirTaxComputationService.php` — date-aware `compute()` (Task 2).
- `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:151-162` — pass `$period->payroll_date` (Task 3).
- `api/app/Modules/Payroll/Exports/Government/SssR3Export.php` — fix wrong column names (Task 5).
- `api/app/Modules/Payroll/routes.php` — register statutory routes (Tasks 6–9).
- `api/database/seeders/DatabaseSeeder.php` — call `GovernmentTable2025Seeder` after `GovernmentTableSeeder` (Task 4).

**SPA — create:**
- `spa/src/api/payroll/statutory.ts` — download client (Task 10).
- `spa/src/pages/payroll/statutory/index.tsx` — Statutory Exports page (Task 10).

**SPA — modify:**
- `spa/src/routes/payrollRoutes.tsx` — register the page (Task 10).
- `spa/src/components/layout/Sidebar.tsx:183` — add a Statutory nav item (Task 10).

---

### Task 1: Effective-dated bracket selection

**Files:**
- Create: `api/database/migrations/0221_index_government_tables_agency_effective_date.php`
- Modify: `api/app/Modules/Payroll/Services/GovernmentContributionTableService.php`
- Test: `api/tests/Feature/Payroll/EffectiveDatedBracketsTest.php`

**Interfaces:**
- Consumes: `GovernmentContributionTable` model (`agency`, `bracket_min`, `bracket_max`, `ee_amount`, `er_amount`, `effective_date`, `is_active`; scopes `agency()`, `active()`); `ContributionAgency` enum (`Sss|Philhealth|Pagibig|Bir`).
- Produces: `GovernmentContributionTableService::bracketsEffectiveOn(string|ContributionAgency $agency, \Carbon\Carbon|string $date): \Illuminate\Support\Collection` — returns the bracket rows whose `effective_date` is the latest `<= $date` for that agency, ordered by `bracket_min`; falls back to the active set when no dated rows exist. Cache invalidation: `bust()` now bumps a per-agency version so all effective-date entries clear on any table change.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Feature/Payroll/EffectiveDatedBracketsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Enums\ContributionAgency;
use App\Modules\Payroll\Models\GovernmentContributionTable;
use App\Modules\Payroll\Services\GovernmentContributionTableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EffectiveDatedBracketsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function sssRow(string $effective, float $ee, float $er): void
    {
        GovernmentContributionTable::create([
            'agency'         => 'sss',
            'bracket_min'    => 0.00,
            'bracket_max'    => 999999.99,
            'ee_amount'      => $ee,
            'er_amount'      => $er,
            'effective_date' => $effective,
            'is_active'      => true,
        ]);
    }

    public function test_picks_latest_schedule_on_or_before_the_date(): void
    {
        $this->sssRow('2024-01-01', 100.00, 200.00);
        $this->sssRow('2025-01-01', 150.00, 300.00);

        $svc = app(GovernmentContributionTableService::class);

        $in2024 = $svc->bracketsEffectiveOn(ContributionAgency::Sss, '2024-06-15');
        $this->assertSame('100.0000', (string) $in2024->first()->ee_amount);

        $in2025 = $svc->bracketsEffectiveOn(ContributionAgency::Sss, '2025-06-15');
        $this->assertSame('150.0000', (string) $in2025->first()->ee_amount);
    }

    public function test_falls_back_to_active_set_when_no_dated_rows_match(): void
    {
        $this->sssRow('2025-01-01', 150.00, 300.00);

        $svc = app(GovernmentContributionTableService::class);
        // A date before any effective_date → fall back to active set, not empty.
        $rows = $svc->bracketsEffectiveOn(ContributionAgency::Sss, '2020-01-01');
        $this->assertCount(1, $rows);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test --filter=EffectiveDatedBracketsTest`
Expected: FAIL with `Call to undefined method ...GovernmentContributionTableService::bracketsEffectiveOn()`.

- [ ] **Step 3: Add the index migration**

Create `api/database/migrations/0221_index_government_tables_agency_effective_date.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('government_contribution_tables', function (Blueprint $table) {
            $table->index(['agency', 'effective_date'], 'gct_agency_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::table('government_contribution_tables', function (Blueprint $table) {
            $table->dropIndex('gct_agency_effective_idx');
        });
    }
};
```

- [ ] **Step 4: Implement `bracketsEffectiveOn()` + versioned cache**

In `api/app/Modules/Payroll/Services/GovernmentContributionTableService.php`, add the `Carbon` import below the existing `use` lines:

```php
use Illuminate\Support\Carbon;
```

Add this method after `activeBrackets()`:

```php
    /**
     * Brackets in force on a given date: the rows whose effective_date is the
     * latest <= $date for the agency. Falls back to the active set when no
     * dated rows are on/before $date (preserves legacy behaviour for agencies
     * seeded without history).
     *
     * @return Collection<int, GovernmentContributionTable>
     */
    public function bracketsEffectiveOn(string|ContributionAgency $agency, Carbon|string $date): Collection
    {
        $key = $agency instanceof ContributionAgency ? $agency->value : $agency;
        $on  = $date instanceof Carbon ? $date->toDateString() : (string) $date;
        $ver = (int) Cache::get("gov_table:{$key}:ver", 1);

        return Cache::remember(
            "gov_table:{$key}:v{$ver}:eff:{$on}",
            self::CACHE_TTL,
            function () use ($key, $on) {
                $effective = GovernmentContributionTable::query()
                    ->agency($key)
                    ->whereDate('effective_date', '<=', $on)
                    ->max('effective_date');

                if ($effective === null) {
                    return GovernmentContributionTable::query()
                        ->agency($key)->active()->orderBy('bracket_min')->get();
                }

                return GovernmentContributionTable::query()
                    ->agency($key)
                    ->whereDate('effective_date', $effective)
                    ->orderBy('bracket_min')
                    ->get();
            },
        );
    }
```

Replace the existing `bust()` method body so it also invalidates effective-date entries by bumping a version counter:

```php
    private function bust(ContributionAgency|string|null $agency): void
    {
        if (! $agency) return;
        $key = $agency instanceof ContributionAgency ? $agency->value : (string) $agency;
        Cache::forget("gov_table:{$key}:active");
        Cache::put("gov_table:{$key}:ver", ((int) Cache::get("gov_table:{$key}:ver", 1)) + 1, 86400);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -T api php artisan test --filter=EffectiveDatedBracketsTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add api/database/migrations/0221_index_government_tables_agency_effective_date.php \
        api/app/Modules/Payroll/Services/GovernmentContributionTableService.php \
        api/tests/Feature/Payroll/EffectiveDatedBracketsTest.php
git commit -m "feat(OGAMI-101): effective-dated government bracket selection"
```

---

### Task 2: Date-aware computation services

**Files:**
- Modify: `api/app/Modules/Payroll/Services/Government/SssComputationService.php`
- Modify: `api/app/Modules/Payroll/Services/Government/PhilhealthComputationService.php`
- Modify: `api/app/Modules/Payroll/Services/Government/PagibigComputationService.php`
- Modify: `api/app/Modules/Payroll/Services/Government/BirTaxComputationService.php`
- Test: `api/tests/Feature/Payroll/EffectiveDatedBracketsTest.php` (add a case)

**Interfaces:**
- Consumes: `GovernmentContributionTableService::bracketsEffectiveOn()` (Task 1).
- Produces: date-aware compute signatures, backward compatible (date defaults to `now()`):
  - `SssComputationService::compute(string|float|int $monthlySalary, ?Carbon $effectiveDate = null): array{ee:string,er:string}`
  - `PhilhealthComputationService::compute(string|float|int $monthlySalary, ?Carbon $effectiveDate = null): array{ee:string,er:string}`
  - `PagibigComputationService::compute(string|float|int $monthlySalary, ?Carbon $effectiveDate = null): array{ee:string,er:string}`
  - `BirTaxComputationService::compute(string|float|int $taxablePay, string $periodType = 'semi_monthly', ?Carbon $effectiveDate = null): string`

- [ ] **Step 1: Write the failing test (add to EffectiveDatedBracketsTest)**

Append this method to `api/tests/Feature/Payroll/EffectiveDatedBracketsTest.php`:

```php
    public function test_sss_service_uses_schedule_in_force_on_pay_date(): void
    {
        $this->sssRow('2024-01-01', 100.00, 200.00);
        $this->sssRow('2025-01-01', 150.00, 300.00);

        $svc = app(\App\Modules\Payroll\Services\Government\SssComputationService::class);

        $r2024 = $svc->compute('20000', \Illuminate\Support\Carbon::parse('2024-06-15'));
        $this->assertSame('100.00', $r2024['ee']);

        $r2025 = $svc->compute('20000', \Illuminate\Support\Carbon::parse('2025-06-15'));
        $this->assertSame('150.00', $r2025['ee']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test --filter=EffectiveDatedBracketsTest`
Expected: FAIL — `compute()` ignores the date and returns the active (last-written) row for both, so one assertion fails.

- [ ] **Step 3: Make `SssComputationService` date-aware**

In `api/app/Modules/Payroll/Services/Government/SssComputationService.php` add the import:

```php
use Illuminate\Support\Carbon;
```

Change the method signature and the bracket lookup line:

```php
    public function compute(string|float|int $monthlySalary, ?Carbon $effectiveDate = null): array
    {
        $salary = (string) $monthlySalary;
        if (bccomp($salary, '0', 2) <= 0) {
            return ['ee' => '0.00', 'er' => '0.00'];
        }

        $brackets = $this->tables->bracketsEffectiveOn(ContributionAgency::Sss, $effectiveDate ?? now());
```

(Leave the rest of the method unchanged.)

- [ ] **Step 4: Make `PhilhealthComputationService` date-aware**

In `PhilhealthComputationService.php` add `use Illuminate\Support\Carbon;`, then:

```php
    public function compute(string|float|int $monthlySalary, ?Carbon $effectiveDate = null): array
    {
        $salary = (string) $monthlySalary;
        if (bccomp($salary, '0', 2) <= 0) {
            return ['ee' => '0.00', 'er' => '0.00'];
        }

        $row = $this->tables->bracketsEffectiveOn(ContributionAgency::Philhealth, $effectiveDate ?? now())->first();
```

(Leave the rest unchanged.)

- [ ] **Step 5: Make `PagibigComputationService` date-aware**

In `PagibigComputationService.php` add the import:

```php
use Illuminate\Support\Carbon;
```

Change the signature and the bracket lookup line (leave the `self::CEILING` cap and the `foreach` rate logic unchanged):

```php
    public function compute(string|float|int $monthlySalary, ?Carbon $effectiveDate = null): array
    {
        $salary = (string) $monthlySalary;
        if (bccomp($salary, '0', 2) <= 0) {
            return ['ee' => '0.00', 'er' => '0.00'];
        }

        // Cap salary at the project ceiling.
        $basis = bccomp($salary, self::CEILING, 2) > 0 ? self::CEILING : $salary;

        $brackets = $this->tables->bracketsEffectiveOn(ContributionAgency::Pagibig, $effectiveDate ?? now());
```

- [ ] **Step 6: Make `BirTaxComputationService` date-aware**

In `BirTaxComputationService.php` add `use Illuminate\Support\Carbon;` and change the signature + lookup:

```php
    public function compute(string|float|int $taxablePay, string $periodType = 'semi_monthly', ?Carbon $effectiveDate = null): string
    {
        $taxable = (string) $taxablePay;
        if (bccomp($taxable, '0', 2) <= 0) {
            return '0.00';
        }

        $brackets = $this->tables->bracketsEffectiveOn(ContributionAgency::Bir, $effectiveDate ?? now());
```

(Leave the rest unchanged.)

- [ ] **Step 7: Run the date test and the existing computation regression suite**

Run: `docker compose exec -T api php artisan test --filter='EffectiveDatedBracketsTest|GovComputationServicesTest'`
Expected: PASS. `GovComputationServicesTest` still passes because it seeds only 2024 rows and the default date (`now()`) resolves to them.

- [ ] **Step 8: Commit**

```bash
git add api/app/Modules/Payroll/Services/Government/ api/tests/Feature/Payroll/EffectiveDatedBracketsTest.php
git commit -m "feat(OGAMI-101): government computation services select schedule by pay date"
```

---

### Task 3: Thread the pay date through the payroll engine

**Files:**
- Modify: `api/app/Modules/Payroll/Services/PayrollCalculatorService.php` (around lines 151-162)
- Test: `api/tests/Feature/Payroll/EffectiveDatedBracketsTest.php` (add an end-to-end case)

**Interfaces:**
- Consumes: date-aware `compute()` methods (Task 2); `PayrollCalculatorService::computeForEmployee(PayrollPeriod $period, Employee $employee): Payroll` (existing). `$period->payroll_date` is a `Carbon` (cast `date`).
- Produces: payroll computation that deducts using the schedule effective on `$period->payroll_date`.

- [ ] **Step 1: Write the failing test**

Append to `api/tests/Feature/Payroll/EffectiveDatedBracketsTest.php`:

```php
    public function test_payroll_engine_deducts_using_schedule_on_pay_date(): void
    {
        // SSS: 2024 flat EE 100 for everyone; 2025 flat EE 150.
        $this->sssRow('2024-01-01', 100.00, 200.00);
        $this->sssRow('2025-01-01', 150.00, 300.00);
        // PhilHealth + Pag-IBIG + BIR zeroed so only SSS moves the number.
        foreach ([['philhealth', 0.0], ['pagibig', 0.0]] as [$agency, $rate]) {
            GovernmentContributionTable::create([
                'agency' => $agency, 'bracket_min' => 0.00, 'bracket_max' => 999999.99,
                'ee_amount' => $rate, 'er_amount' => $rate,
                'effective_date' => '2025-01-01', 'is_active' => true,
            ]);
        }
        GovernmentContributionTable::create([
            'agency' => 'bir', 'bracket_min' => 0.00, 'bracket_max' => 999999.99,
            'ee_amount' => 0.0, 'er_amount' => 0.0, 'effective_date' => '2025-01-01', 'is_active' => true,
        ]);

        $employee = \App\Modules\HR\Models\Employee::factory()->create([
            'pay_type'             => 'monthly',
            'basic_monthly_salary' => 20000.00,
        ]);
        $period = \App\Modules\Payroll\Models\PayrollPeriod::factory()->create([
            'status'        => 'draft',
            'period_start'  => '2025-06-01',
            'period_end'    => '2025-06-15',
            'payroll_date'  => '2025-06-15',
            'is_first_half' => true,
            'is_thirteenth_month' => false,
        ]);

        $payroll = app(\App\Modules\Payroll\Services\PayrollCalculatorService::class)
            ->computeForEmployee($period, $employee);

        // 2025 SSS EE share is 150.00 (not the 2024 value of 100.00).
        $this->assertSame('150.00', (string) $payroll->sss_ee);
    }
```

> Note: if `Employee` factory field names differ (`basic_monthly_salary`/`pay_type`), align them with `Database\Factories\EmployeeFactory` — keep the assertion on `sss_ee`.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test --filter=test_payroll_engine_deducts_using_schedule_on_pay_date`
Expected: FAIL — engine still calls date-less `compute()`, so `now()` (not 2025-06-15) resolves the schedule. If the host clock is in 2025+ this may accidentally pass; the assertion is still valid once Step 3 makes it explicit.

- [ ] **Step 3: Pass the pay date at the call sites**

In `api/app/Modules/Payroll/Services/PayrollCalculatorService.php`, locate the block at lines 151-162 and edit it to compute the effective date once and pass it to all four services:

```php
            if ($period->is_first_half && ! $period->is_thirteenth_month) {
                $effectiveOn = $period->payroll_date;          // Carbon (date cast)
                $sssR = $this->sss->compute($govBasis, $effectiveOn);
                $phR  = $this->philhealth->compute($govBasis, $effectiveOn);
                $pgR  = $this->pagibig->compute($govBasis, $effectiveOn);
                // ... (keep the existing assignment of $sssEe/$phEe/$pgEe etc.)
```

And the BIR line (around 162):

```php
                $wht = $this->bir->compute($taxable, 'semi_monthly', $period->payroll_date);
```

(Do not change any other logic in the method.)

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T api php artisan test --filter=test_payroll_engine_deducts_using_schedule_on_pay_date`
Expected: PASS.

- [ ] **Step 5: Run the payroll regression suite**

Run: `docker compose exec -T api php artisan test --filter='PayrollCalculatorServiceTest|MidCycleSalaryProrationTest|GovComputationServicesTest'`
Expected: PASS (no regressions — default-date behaviour unchanged for 2024-only seeds).

- [ ] **Step 6: Commit**

```bash
git add api/app/Modules/Payroll/Services/PayrollCalculatorService.php api/tests/Feature/Payroll/EffectiveDatedBracketsTest.php
git commit -m "feat(OGAMI-101): payroll engine deducts using pay-date schedule"
```

---

### Task 4: Seed the 2025 government schedule

**Files:**
- Create: `api/database/seeders/GovernmentTable2025Seeder.php`
- Modify: `api/database/seeders/DatabaseSeeder.php`
- Test: `api/tests/Feature/Payroll/EffectiveDatedBracketsTest.php` (add a seeder case)

**Interfaces:**
- Consumes: `GovernmentContributionTable` model; `updateOrCreate` keyed on `['agency','bracket_min','effective_date']` (matches `GovernmentTableSeeder`).
- Produces: 2025 SSS (rate 15%: EE = MSC×5%, ER = MSC×10%, MSC ₱5,000–₱35,000 in ₱500 steps), PhilHealth (5%, floor ₱10,000, ceiling ₱100,000), and Pag-IBIG (1%/2% ≤₱1,500, else 2%/2%) rows effective `2025-01-01`. BIR is **not** re-seeded — the existing rows already carry current TRAIN rates (0/15/20/25/30/35%); see the audit note. The 2024 rows are left intact for historical-payroll accuracy.

- [ ] **Step 1: Write the failing test**

Append to `api/tests/Feature/Payroll/EffectiveDatedBracketsTest.php`:

```php
    public function test_2025_seeder_makes_sss_effective_2025(): void
    {
        $this->seed(\Database\Seeders\GovernmentTableSeeder::class);      // 2024
        $this->seed(\Database\Seeders\GovernmentTable2025Seeder::class);  // 2025
        Cache::flush();

        $svc = app(\App\Modules\Payroll\Services\Government\SssComputationService::class);

        // MSC 20,000 in 2025 → EE = 20000 * 5% = 1000.00
        $r = $svc->compute('20000', \Illuminate\Support\Carbon::parse('2025-07-15'));
        $this->assertSame('1000.00', $r['ee']);
        $this->assertSame('2000.00', $r['er']); // 20000 * 10%
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test --filter=test_2025_seeder_makes_sss_effective_2025`
Expected: FAIL — `Class "Database\Seeders\GovernmentTable2025Seeder" not found`.

- [ ] **Step 3: Create the 2025 seeder**

Create `api/database/seeders/GovernmentTable2025Seeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Payroll\Models\GovernmentContributionTable;
use Illuminate\Database\Seeder;

/**
 * 2025 PH government contribution schedule (effective 2025-01-01).
 *
 * SSS: contribution rate 15% (EE 5% / ER 10% of the Monthly Salary Credit),
 *      MSC ₱5,000–₱35,000 in ₱500 steps. (EC and WISP are employer-side /
 *      provident add-ons; refine here if the pilot requires their separate
 *      reporting — the regular SS share below is what drives the payslip.)
 * PhilHealth: premium 5%, floor ₱10,000, ceiling ₱100,000 (split 2.5%/2.5%).
 * Pag-IBIG: 1%/2% for ≤₱1,500 MSC, else 2%/2%.
 *
 * Source: SSS Circular 2024-006 (rate 15% from Jan 2025); PhilHealth Circular
 * 2024 (5% held); HDMF MC. Re-verify exact step values before live filing.
 */
class GovernmentTable2025Seeder extends Seeder
{
    private const EFFECTIVE = '2025-01-01';

    public function run(): void
    {
        // ─── SSS 2025 (computed from MSC × rate) ───────────────────────────
        for ($msc = 5000.00; $msc <= 35000.00; $msc += 500.00) {
            $min = $msc - 249.99 < 0 ? 0.00 : round($msc - 249.99, 2);
            $max = round($msc + 250.00, 2);
            GovernmentContributionTable::updateOrCreate(
                ['agency' => 'sss', 'bracket_min' => $min, 'effective_date' => self::EFFECTIVE],
                [
                    'bracket_max' => $msc >= 35000.00 ? 999999.99 : $max,
                    'ee_amount'   => round($msc * 0.05, 2),
                    'er_amount'   => round($msc * 0.10, 2),
                    'is_active'   => true,
                ],
            );
        }

        // ─── PhilHealth 2025 (rate-based single row) ───────────────────────
        GovernmentContributionTable::updateOrCreate(
            ['agency' => 'philhealth', 'bracket_min' => 10000.00, 'effective_date' => self::EFFECTIVE],
            ['bracket_max' => 100000.00, 'ee_amount' => 0.0250, 'er_amount' => 0.0250, 'is_active' => true],
        );

        // ─── Pag-IBIG 2025 ─────────────────────────────────────────────────
        foreach ([[0.00, 1500.00, 0.0100, 0.0200], [1500.01, 999999.99, 0.0200, 0.0200]] as [$min, $max, $ee, $er]) {
            GovernmentContributionTable::updateOrCreate(
                ['agency' => 'pagibig', 'bracket_min' => $min, 'effective_date' => self::EFFECTIVE],
                ['bracket_max' => $max, 'ee_amount' => $ee, 'er_amount' => $er, 'is_active' => true],
            );
        }

        $this->command?->info('Government contribution tables seeded for 2025 (SSS/PhilHealth/Pag-IBIG).');
    }
}
```

- [ ] **Step 4: Register the seeder**

In `api/database/seeders/DatabaseSeeder.php`, add `GovernmentTable2025Seeder::class` to the `$this->call([...])` array immediately after `GovernmentTableSeeder::class` (around line 28):

```php
            GovernmentTableSeeder::class,      // Task 23 (2024 schedule)
            GovernmentTable2025Seeder::class,  // OGAMI-101 (2025 schedule)
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -T api php artisan test --filter=test_2025_seeder_makes_sss_effective_2025`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add api/database/seeders/GovernmentTable2025Seeder.php api/database/seeders/DatabaseSeeder.php api/tests/Feature/Payroll/EffectiveDatedBracketsTest.php
git commit -m "feat(OGAMI-101): seed 2025 SSS/PhilHealth/Pag-IBIG schedule"
```

---

### Task 5: Fix the broken SSS R-3 export

**Files:**
- Modify: `api/app/Modules/Payroll/Exports/Government/SssR3Export.php`
- Test: `api/tests/Feature/Payroll/StatutoryExportsTest.php`

**Interfaces:**
- Consumes: `Payroll` (real columns `payroll_period_id`, `sss_ee`, `sss_er`, `basic_pay`), `PayrollPeriod` (`status`, `period_start`), `Employee` (`sss_no`, names).
- Produces: a working `SssR3Export` whose `collection()` and `map()` reference real columns. (EC share is not tracked separately in `payrolls`; the column is emitted as `0.00` with a note.)

- [ ] **Step 1: Write the failing test**

Create `api/tests/Feature/Payroll/StatutoryExportsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Exports\Government\SssR3Export;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatutoryExportsTest extends TestCase
{
    use RefreshDatabase;

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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test --filter=test_sss_r3_export_reads_real_columns`
Expected: FAIL — current `collection()` filters on the non-existent `period_id`/`status` columns (SQL error) and `map()` reads `sss_employee`/`sss_employer` (always null → `0.00`).

- [ ] **Step 3: Fix `collection()` and `map()`**

In `api/app/Modules/Payroll/Exports/Government/SssR3Export.php`, replace `collection()`:

```php
    public function collection(): Collection
    {
        return Payroll::query()
            ->with(['employee'])
            ->where('payroll_period_id', $this->period->id)
            ->whereNull('error_message')
            ->get();
    }
```

Replace the share-derivation lines in `map()`:

```php
        $eeShare = (float) ($row->sss_ee ?? 0);
        $erShare = (float) ($row->sss_er ?? 0);
        $ecShare = 0.0; // EC is not tracked separately on payrolls; report 0 until modelled.
        $monthly = (float) ($row->basic_pay ?? $row->gross_pay ?? 0);
```

(The `return [...]` block already formats `$eeShare`, `$erShare`, `$ecShare`, `$monthly` — leave it.)

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T api php artisan test --filter=test_sss_r3_export_reads_real_columns`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Payroll/Exports/Government/SssR3Export.php api/tests/Feature/Payroll/StatutoryExportsTest.php
git commit -m "fix(OGAMI-102): SSS R-3 export reads real payroll columns"
```

---

### Task 6: BIR 1601-C monthly remittance exporter

**Files:**
- Create: `api/app/Modules/Payroll/Services/Statutory/Bir1601CService.php`
- Create: `api/app/Modules/Payroll/Controllers/StatutoryExportController.php`
- Modify: `api/app/Modules/Payroll/routes.php`
- Test: `api/tests/Feature/Payroll/StatutoryExportsTest.php`

**Interfaces:**
- Consumes: `Payroll` (`gross_pay`, `withholding_tax`, `error_message`, `payroll_period_id`), `PayrollPeriod` (`status`, `period_start`, `is_thirteenth_month`).
- Produces:
  - `Bir1601CService::generate(int $year, int $month): array{period:string, headcount:int, total_compensation:float, total_withheld:float}`
  - `Bir1601CService::toCsv(array $data): string`
  - `StatutoryExportController::bir1601c(Request $request): Response` — `GET /api/v1/payroll/statutory/1601c?year=&month=`, permission `payroll.view`, returns `text/csv`.

- [ ] **Step 1: Write the failing test**

Append to `api/tests/Feature/Payroll/StatutoryExportsTest.php`:

```php
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
```

This test needs `RolePermissionSeeder`; add to `StatutoryExportsTest::setUp()`:

```php
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test --filter='test_bir_1601c_aggregates_month_totals|test_statutory_export_requires_auth'`
Expected: FAIL — route `payroll/statutory/1601c` returns 404.

- [ ] **Step 3: Create `Bir1601CService`**

Create `api/app/Modules/Payroll/Services/Statutory/Bir1601CService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services\Statutory;

use Illuminate\Support\Facades\DB;

/**
 * BIR Form 1601-C — Monthly Remittance Return of Income Taxes Withheld on
 * Compensation. Aggregates all finalized/disbursed regular payroll rows whose
 * period falls in the given calendar month.
 */
class Bir1601CService
{
    /**
     * @return array{period: string, headcount: int, total_compensation: float, total_withheld: float}
     */
    public function generate(int $year, int $month): array
    {
        $row = DB::table('payrolls as p')
            ->join('payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')
            ->whereIn('pp.status', ['finalized', 'disbursed'])
            ->where('pp.is_thirteenth_month', false)
            ->whereNull('p.error_message')
            ->whereYear('pp.period_start', $year)
            ->whereMonth('pp.period_start', $month)
            ->selectRaw('COUNT(DISTINCT p.employee_id) as headcount')
            ->selectRaw('COALESCE(SUM(p.gross_pay), 0) as total_compensation')
            ->selectRaw('COALESCE(SUM(p.withholding_tax), 0) as total_withheld')
            ->first();

        return [
            'period'             => sprintf('%04d-%02d', $year, $month),
            'headcount'          => (int) ($row->headcount ?? 0),
            'total_compensation' => round((float) ($row->total_compensation ?? 0), 2),
            'total_withheld'     => round((float) ($row->total_withheld ?? 0), 2),
        ];
    }

    /**
     * @param array{period: string, headcount: int, total_compensation: float, total_withheld: float} $data
     */
    public function toCsv(array $data): string
    {
        $lines = [
            'Form,Period,Headcount,Total Compensation,Total Tax Withheld',
            implode(',', [
                'BIR-1601-C',
                $data['period'],
                (string) $data['headcount'],
                number_format($data['total_compensation'], 2, '.', ''),
                number_format($data['total_withheld'], 2, '.', ''),
            ]),
        ];

        return implode("\r\n", $lines);
    }
}
```

- [ ] **Step 4: Create `StatutoryExportController` with the 1601-C method**

Create `api/app/Modules/Payroll/Controllers/StatutoryExportController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Services\Statutory\Bir1601CService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StatutoryExportController
{
    private function csv(string $body, string $filename): Response
    {
        return response($body, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function bir1601c(Request $request, Bir1601CService $service): Response
    {
        abort_unless($request->user()?->can('payroll.view'), 403);
        $year  = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);
        $data  = $service->generate($year, $month);

        return $this->csv($service->toCsv($data), sprintf('BIR-1601-C-%04d-%02d.csv', $year, $month));
    }
}
```

- [ ] **Step 5: Register the route**

In `api/app/Modules/Payroll/routes.php`, add the controller import near the other `use` lines:

```php
use App\Modules\Payroll\Controllers\StatutoryExportController;
```

Inside the existing `Route::middleware(['auth:sanctum', 'feature:payroll'])->group(function () {` block (next to the `bir-alphalist` route near line 98), add:

```php
        Route::prefix('payroll/statutory')->middleware('permission:payroll.view')->group(function () {
            Route::get('/1601c', [StatutoryExportController::class, 'bir1601c']);
        });
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec -T api php artisan test --filter='test_bir_1601c_aggregates_month_totals|test_statutory_export_requires_auth'`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add api/app/Modules/Payroll/Services/Statutory/Bir1601CService.php \
        api/app/Modules/Payroll/Controllers/StatutoryExportController.php \
        api/app/Modules/Payroll/routes.php api/tests/Feature/Payroll/StatutoryExportsTest.php
git commit -m "feat(OGAMI-102): BIR 1601-C monthly remittance export"
```

---

### Task 7: PhilHealth RF-1 exporter

**Files:**
- Create: `api/app/Modules/Payroll/Services/Statutory/PhilhealthRf1Service.php`
- Modify: `api/app/Modules/Payroll/Controllers/StatutoryExportController.php`
- Modify: `api/app/Modules/Payroll/routes.php`
- Test: `api/tests/Feature/Payroll/StatutoryExportsTest.php`

**Interfaces:**
- Consumes: `Payroll` (`philhealth_ee`, `philhealth_er`, `payroll_period_id`, `error_message`), `Employee` (`philhealth_no` encrypted, names).
- Produces:
  - `PhilhealthRf1Service::generate(int $year, int $month): array<int, array{philhealth_no:string, last_name:string, first_name:string, ee:float, er:float, total:float}>`
  - `PhilhealthRf1Service::toCsv(array $data): string`
  - `StatutoryExportController::philhealthRf1(...)` — `GET /api/v1/payroll/statutory/rf1?year=&month=`.

- [ ] **Step 1: Write the failing test**

Append to `api/tests/Feature/Payroll/StatutoryExportsTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test --filter=test_philhealth_rf1_lists_per_employee_shares`
Expected: FAIL — route `payroll/statutory/rf1` returns 404.

- [ ] **Step 3: Create `PhilhealthRf1Service`**

Create `api/app/Modules/Payroll/Services/Statutory/PhilhealthRf1Service.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services\Statutory;

use App\Modules\HR\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * PhilHealth RF-1 — Employer Remittance Report. One line per employee with the
 * EE/ER premium shares for the given month.
 */
class PhilhealthRf1Service
{
    /**
     * @return array<int, array{philhealth_no:string,last_name:string,first_name:string,ee:float,er:float,total:float}>
     */
    public function generate(int $year, int $month): array
    {
        $rows = DB::table('payrolls as p')
            ->join('payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')
            ->join('employees as e', 'e.id', '=', 'p.employee_id')
            ->whereIn('pp.status', ['finalized', 'disbursed'])
            ->where('pp.is_thirteenth_month', false)
            ->whereNull('p.error_message')
            ->whereYear('pp.period_start', $year)
            ->whereMonth('pp.period_start', $month)
            ->whereNull('e.deleted_at')
            ->groupBy('e.id', 'e.last_name', 'e.first_name')
            ->select([
                'e.id as employee_id',
                'e.last_name',
                'e.first_name',
                DB::raw('COALESCE(SUM(p.philhealth_ee), 0) as ee'),
                DB::raw('COALESCE(SUM(p.philhealth_er), 0) as er'),
            ])
            ->orderBy('e.last_name')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $numbers = Employee::withTrashed()
            ->whereIn('id', $rows->pluck('employee_id')->all())
            ->select(['id', 'philhealth_no'])
            ->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'philhealth_no' => (string) ($numbers[$r->employee_id]?->philhealth_no ?? ''),
            'last_name'     => strtoupper((string) $r->last_name),
            'first_name'    => strtoupper((string) $r->first_name),
            'ee'            => round((float) $r->ee, 2),
            'er'            => round((float) $r->er, 2),
            'total'         => round((float) $r->ee + (float) $r->er, 2),
        ])->toArray();
    }

    /**
     * @param array<int, array{philhealth_no:string,last_name:string,first_name:string,ee:float,er:float,total:float}> $data
     */
    public function toCsv(array $data): string
    {
        $lines = ['PhilHealth No,Last Name,First Name,EE Share,ER Share,Total'];
        foreach ($data as $row) {
            $lines[] = implode(',', [
                '"'.str_replace('"', '""', $row['philhealth_no']).'"',
                '"'.str_replace('"', '""', $row['last_name']).'"',
                '"'.str_replace('"', '""', $row['first_name']).'"',
                number_format($row['ee'], 2, '.', ''),
                number_format($row['er'], 2, '.', ''),
                number_format($row['total'], 2, '.', ''),
            ]);
        }

        return implode("\r\n", $lines);
    }
}
```

- [ ] **Step 4: Add the controller method**

In `api/app/Modules/Payroll/Controllers/StatutoryExportController.php`, add the import:

```php
use App\Modules\Payroll\Services\Statutory\PhilhealthRf1Service;
```

Add the method:

```php
    public function philhealthRf1(Request $request, PhilhealthRf1Service $service): Response
    {
        abort_unless($request->user()?->can('payroll.view'), 403);
        $year  = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        return $this->csv($service->toCsv($service->generate($year, $month)),
            sprintf('PhilHealth-RF1-%04d-%02d.csv', $year, $month));
    }
```

- [ ] **Step 5: Register the route**

In `api/app/Modules/Payroll/routes.php`, inside the `payroll/statutory` group added in Task 6:

```php
            Route::get('/rf1', [StatutoryExportController::class, 'philhealthRf1']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec -T api php artisan test --filter=test_philhealth_rf1_lists_per_employee_shares`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add api/app/Modules/Payroll/Services/Statutory/PhilhealthRf1Service.php \
        api/app/Modules/Payroll/Controllers/StatutoryExportController.php \
        api/app/Modules/Payroll/routes.php api/tests/Feature/Payroll/StatutoryExportsTest.php
git commit -m "feat(OGAMI-103): PhilHealth RF-1 remittance export"
```

---

### Task 8: Pag-IBIG MCRF exporter

**Files:**
- Create: `api/app/Modules/Payroll/Services/Statutory/PagibigMcrfService.php`
- Modify: `api/app/Modules/Payroll/Controllers/StatutoryExportController.php`
- Modify: `api/app/Modules/Payroll/routes.php`
- Test: `api/tests/Feature/Payroll/StatutoryExportsTest.php`

**Interfaces:**
- Consumes: `Payroll` (`pagibig_ee`, `pagibig_er`, `payroll_period_id`, `error_message`), `Employee` (`pagibig_no` encrypted, names).
- Produces:
  - `PagibigMcrfService::generate(int $year, int $month): array<int, array{pagibig_no:string,last_name:string,first_name:string,ee:float,er:float,total:float}>`
  - `PagibigMcrfService::toCsv(array $data): string`
  - `StatutoryExportController::pagibigMcrf(...)` — `GET /api/v1/payroll/statutory/mcrf?year=&month=`.

- [ ] **Step 1: Write the failing test**

Append to `api/tests/Feature/Payroll/StatutoryExportsTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test --filter=test_pagibig_mcrf_lists_per_employee_shares`
Expected: FAIL — route `payroll/statutory/mcrf` returns 404.

- [ ] **Step 3: Create `PagibigMcrfService`**

Create `api/app/Modules/Payroll/Services/Statutory/PagibigMcrfService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services\Statutory;

use App\Modules\HR\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Pag-IBIG MCRF — Membership Contribution Remittance Form. One line per
 * employee with the EE/ER contribution for the given month.
 */
class PagibigMcrfService
{
    /**
     * @return array<int, array{pagibig_no:string,last_name:string,first_name:string,ee:float,er:float,total:float}>
     */
    public function generate(int $year, int $month): array
    {
        $rows = DB::table('payrolls as p')
            ->join('payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')
            ->join('employees as e', 'e.id', '=', 'p.employee_id')
            ->whereIn('pp.status', ['finalized', 'disbursed'])
            ->where('pp.is_thirteenth_month', false)
            ->whereNull('p.error_message')
            ->whereYear('pp.period_start', $year)
            ->whereMonth('pp.period_start', $month)
            ->whereNull('e.deleted_at')
            ->groupBy('e.id', 'e.last_name', 'e.first_name')
            ->select([
                'e.id as employee_id',
                'e.last_name',
                'e.first_name',
                DB::raw('COALESCE(SUM(p.pagibig_ee), 0) as ee'),
                DB::raw('COALESCE(SUM(p.pagibig_er), 0) as er'),
            ])
            ->orderBy('e.last_name')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $numbers = Employee::withTrashed()
            ->whereIn('id', $rows->pluck('employee_id')->all())
            ->select(['id', 'pagibig_no'])
            ->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'pagibig_no' => (string) ($numbers[$r->employee_id]?->pagibig_no ?? ''),
            'last_name'  => strtoupper((string) $r->last_name),
            'first_name' => strtoupper((string) $r->first_name),
            'ee'         => round((float) $r->ee, 2),
            'er'         => round((float) $r->er, 2),
            'total'      => round((float) $r->ee + (float) $r->er, 2),
        ])->toArray();
    }

    /**
     * @param array<int, array{pagibig_no:string,last_name:string,first_name:string,ee:float,er:float,total:float}> $data
     */
    public function toCsv(array $data): string
    {
        $lines = ['Pag-IBIG MID No,Last Name,First Name,EE Share,ER Share,Total'];
        foreach ($data as $row) {
            $lines[] = implode(',', [
                '"'.str_replace('"', '""', $row['pagibig_no']).'"',
                '"'.str_replace('"', '""', $row['last_name']).'"',
                '"'.str_replace('"', '""', $row['first_name']).'"',
                number_format($row['ee'], 2, '.', ''),
                number_format($row['er'], 2, '.', ''),
                number_format($row['total'], 2, '.', ''),
            ]);
        }

        return implode("\r\n", $lines);
    }
}
```

- [ ] **Step 4: Add the controller method**

In `StatutoryExportController.php` add the import `use App\Modules\Payroll\Services\Statutory\PagibigMcrfService;` and the method:

```php
    public function pagibigMcrf(Request $request, PagibigMcrfService $service): Response
    {
        abort_unless($request->user()?->can('payroll.view'), 403);
        $year  = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        return $this->csv($service->toCsv($service->generate($year, $month)),
            sprintf('PagIBIG-MCRF-%04d-%02d.csv', $year, $month));
    }
```

- [ ] **Step 5: Register the route**

In `payroll/statutory` group:

```php
            Route::get('/mcrf', [StatutoryExportController::class, 'pagibigMcrf']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec -T api php artisan test --filter=test_pagibig_mcrf_lists_per_employee_shares`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add api/app/Modules/Payroll/Services/Statutory/PagibigMcrfService.php \
        api/app/Modules/Payroll/Controllers/StatutoryExportController.php \
        api/app/Modules/Payroll/routes.php api/tests/Feature/Payroll/StatutoryExportsTest.php
git commit -m "feat(OGAMI-103): Pag-IBIG MCRF remittance export"
```

---

### Task 9: BIR 1604-CF annual exporter

**Files:**
- Create: `api/app/Modules/Payroll/Services/Statutory/Bir1604CfService.php`
- Modify: `api/app/Modules/Payroll/Controllers/StatutoryExportController.php`
- Modify: `api/app/Modules/Payroll/routes.php`
- Test: `api/tests/Feature/Payroll/StatutoryExportsTest.php`

**Interfaces:**
- Consumes: `Payroll` (`gross_pay`, `withholding_tax`, `error_message`, `payroll_period_id`), `PayrollPeriod` (`status`, `period_start`, `is_thirteenth_month`).
- Produces:
  - `Bir1604CfService::generate(int $year): array{year:int, headcount:int, total_compensation:float, total_withheld:float}`
  - `Bir1604CfService::toCsv(array $data): string`
  - `StatutoryExportController::bir1604cf(...)` — `GET /api/v1/payroll/statutory/1604cf?year=`.

- [ ] **Step 1: Write the failing test**

Append to `api/tests/Feature/Payroll/StatutoryExportsTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test --filter=test_bir_1604cf_aggregates_year_totals`
Expected: FAIL — route returns 404.

- [ ] **Step 3: Create `Bir1604CfService`**

Create `api/app/Modules/Payroll/Services/Statutory/Bir1604CfService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services\Statutory;

use Illuminate\Support\Facades\DB;

/**
 * BIR Form 1604-CF — Annual Information Return of Income Taxes Withheld on
 * Compensation. Year-level totals; the per-employee detail is the Alphalist
 * (see BirAlphalistService).
 */
class Bir1604CfService
{
    /**
     * @return array{year: int, headcount: int, total_compensation: float, total_withheld: float}
     */
    public function generate(int $year): array
    {
        $row = DB::table('payrolls as p')
            ->join('payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')
            ->whereIn('pp.status', ['finalized', 'disbursed'])
            ->where('pp.is_thirteenth_month', false)
            ->whereNull('p.error_message')
            ->whereYear('pp.period_start', $year)
            ->selectRaw('COUNT(DISTINCT p.employee_id) as headcount')
            ->selectRaw('COALESCE(SUM(p.gross_pay), 0) as total_compensation')
            ->selectRaw('COALESCE(SUM(p.withholding_tax), 0) as total_withheld')
            ->first();

        return [
            'year'               => $year,
            'headcount'          => (int) ($row->headcount ?? 0),
            'total_compensation' => round((float) ($row->total_compensation ?? 0), 2),
            'total_withheld'     => round((float) ($row->total_withheld ?? 0), 2),
        ];
    }

    /**
     * @param array{year:int,headcount:int,total_compensation:float,total_withheld:float} $data
     */
    public function toCsv(array $data): string
    {
        return implode("\r\n", [
            'Form,Year,Headcount,Total Compensation,Total Tax Withheld',
            implode(',', [
                'BIR-1604-CF',
                (string) $data['year'],
                (string) $data['headcount'],
                number_format($data['total_compensation'], 2, '.', ''),
                number_format($data['total_withheld'], 2, '.', ''),
            ]),
        ]);
    }
}
```

- [ ] **Step 4: Add the controller method**

In `StatutoryExportController.php` add `use App\Modules\Payroll\Services\Statutory\Bir1604CfService;` and:

```php
    public function bir1604cf(Request $request, Bir1604CfService $service): Response
    {
        abort_unless($request->user()?->can('payroll.view'), 403);
        $year = (int) $request->query('year', now()->year);

        return $this->csv($service->toCsv($service->generate($year)), sprintf('BIR-1604-CF-%04d.csv', $year));
    }
```

- [ ] **Step 5: Register the route**

In `payroll/statutory` group:

```php
            Route::get('/1604cf', [StatutoryExportController::class, 'bir1604cf']);
```

- [ ] **Step 6: Run test to verify it passes (and the full statutory file)**

Run: `docker compose exec -T api php artisan test --filter=StatutoryExportsTest`
Expected: PASS (all statutory tests green).

- [ ] **Step 7: Commit**

```bash
git add api/app/Modules/Payroll/Services/Statutory/Bir1604CfService.php \
        api/app/Modules/Payroll/Controllers/StatutoryExportController.php \
        api/app/Modules/Payroll/routes.php api/tests/Feature/Payroll/StatutoryExportsTest.php
git commit -m "feat(OGAMI-103): BIR 1604-CF annual return export"
```

---

### Task 10: Statutory Exports SPA screen

**Files:**
- Create: `spa/src/api/payroll/statutory.ts`
- Create: `spa/src/pages/payroll/statutory/index.tsx`
- Modify: `spa/src/routes/payrollRoutes.tsx`
- Modify: `spa/src/components/layout/Sidebar.tsx` (around line 183, the payroll entry)

**Interfaces:**
- Consumes: backend `GET /api/v1/payroll/statutory/{1601c,rf1,mcrf,1604cf}` (Tasks 6–9).
- Produces: a `/payroll/statutory` page (lazy-loaded, `ModuleGuard module="payroll"` + `PermissionGuard permission="payroll.view"`) with year/month inputs and one download button per form; a `statutoryApi` client mirroring `exportsApi.download` (transient `<a download>` so the session cookie travels).

- [ ] **Step 1: Create the download client**

Create `spa/src/api/payroll/statutory.ts`:

```ts
/**
 * Statutory remittance export downloads (OGAMI-102/103).
 * Mirrors exportsApi.download: a transient <a download> hands the response
 * stream to the browser and carries the auth cookie automatically.
 */

function triggerDownload(url: string): void {
  const a = document.createElement('a');
  a.href = url;
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  a.remove();
}

export const statutoryApi = {
  bir1601c: (year: number, month: number): string => {
    const url = `/api/v1/payroll/statutory/1601c?year=${year}&month=${month}`;
    triggerDownload(url);
    return url;
  },
  philhealthRf1: (year: number, month: number): string => {
    const url = `/api/v1/payroll/statutory/rf1?year=${year}&month=${month}`;
    triggerDownload(url);
    return url;
  },
  pagibigMcrf: (year: number, month: number): string => {
    const url = `/api/v1/payroll/statutory/mcrf?year=${year}&month=${month}`;
    triggerDownload(url);
    return url;
  },
  bir1604cf: (year: number): string => {
    const url = `/api/v1/payroll/statutory/1604cf?year=${year}`;
    triggerDownload(url);
    return url;
  },
};
```

- [ ] **Step 2: Create the page**

Create `spa/src/pages/payroll/statutory/index.tsx`:

```tsx
import { useState } from 'react';
import { statutoryApi } from '@/api/payroll/statutory';

const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

export default function StatutoryExportsPage() {
  const now = new Date();
  const [year, setYear] = useState(now.getFullYear());
  const [month, setMonth] = useState(now.getMonth() + 1);

  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-xl font-semibold">Statutory Filing Exports</h1>
        <p className="text-sm text-muted-foreground">
          Generate BIR, PhilHealth, and Pag-IBIG remittance files for finalized payroll periods.
        </p>
      </div>

      <div className="flex items-end gap-4">
        <label className="flex flex-col text-sm">
          Year
          <input
            type="number"
            className="mt-1 rounded-md border px-2 py-1 font-mono tabular-nums"
            value={year}
            onChange={(e) => setYear(Number(e.target.value))}
          />
        </label>
        <label className="flex flex-col text-sm">
          Month
          <select
            className="mt-1 rounded-md border px-2 py-1"
            value={month}
            onChange={(e) => setMonth(Number(e.target.value))}
          >
            {MONTHS.map((m, i) => (
              <option key={m} value={i + 1}>{m}</option>
            ))}
          </select>
        </label>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 max-w-2xl">
        <button
          className="rounded-md border px-4 py-3 text-left hover:bg-accent"
          onClick={() => statutoryApi.bir1601c(year, month)}
        >
          <div className="font-medium">BIR 1601-C</div>
          <div className="text-sm text-muted-foreground">Monthly WHT on compensation</div>
        </button>
        <button
          className="rounded-md border px-4 py-3 text-left hover:bg-accent"
          onClick={() => statutoryApi.philhealthRf1(year, month)}
        >
          <div className="font-medium">PhilHealth RF-1</div>
          <div className="text-sm text-muted-foreground">Monthly employer remittance</div>
        </button>
        <button
          className="rounded-md border px-4 py-3 text-left hover:bg-accent"
          onClick={() => statutoryApi.pagibigMcrf(year, month)}
        >
          <div className="font-medium">Pag-IBIG MCRF</div>
          <div className="text-sm text-muted-foreground">Monthly contribution remittance</div>
        </button>
        <button
          className="rounded-md border px-4 py-3 text-left hover:bg-accent"
          onClick={() => statutoryApi.bir1604cf(year)}
        >
          <div className="font-medium">BIR 1604-CF</div>
          <div className="text-sm text-muted-foreground">Annual return ({year})</div>
        </button>
      </div>
    </div>
  );
}
```

- [ ] **Step 3: Register the route**

In `spa/src/routes/payrollRoutes.tsx`, add the lazy import after the existing page imports (after line 13):

```tsx
const StatutoryExportsPage        = lazy(() => import('@/pages/payroll/statutory'));
```

Add the route inside the `<Route element={<ModuleGuard module="payroll" />}>` block (after the adjustments routes):

```tsx
      <Route
        path="/payroll/statutory"
        element={<PermissionGuard permission="payroll.view"><StatutoryExportsPage /></PermissionGuard>}
      />
```

- [ ] **Step 4: Add the sidebar entry**

In `spa/src/components/layout/Sidebar.tsx`, locate the payroll nav item near line 183 and add a sibling entry directly after it:

```tsx
      { to: '/payroll/statutory', label: 'Statutory Exports', icon: FileText, feature: 'payroll', permission: 'payroll.view' },
```

If `FileText` is not already imported from `lucide-react` at the top of the file, add it to the existing `lucide-react` import (reuse an already-imported icon such as `Wallet` if you prefer to avoid touching imports).

- [ ] **Step 5: Verify the build and types**

Run: `cd spa && npm run build`
Expected: build succeeds with no TypeScript errors; the new page and route compile.

- [ ] **Step 6: Manual smoke check**

Run the SPA (`cd spa && npm run dev`), log in as a user with `payroll.view`, navigate to `/payroll/statutory`, pick a year/month that has a finalized period, and click each button. Expected: a `.csv` file downloads for each form.

- [ ] **Step 7: Commit**

```bash
git add spa/src/api/payroll/statutory.ts spa/src/pages/payroll/statutory/index.tsx \
        spa/src/routes/payrollRoutes.tsx spa/src/components/layout/Sidebar.tsx
git commit -m "feat(OGAMI-102/103): Statutory Exports screen + download client"
```

---

## Final verification

After all tasks, run the payroll suites together to confirm no regressions:

Run: `docker compose exec -T api php artisan test --filter='EffectiveDatedBracketsTest|StatutoryExportsTest|GovComputationServicesTest|PayrollCalculatorServiceTest|BirAlphalistTest|MidCycleSalaryProrationTest'`
Expected: all PASS.

## Out of scope (separate plans)

- SSS R-5 / SSS contribution payment return cover sheet.
- Supplier-side EWT (BIR 2306/2307, 1604-E) — depends on AP/bill EWT capture in the Accounting module.
- eBIRForms XML packaging and byte-exact official form layouts (current outputs are agency-format CSV — sufficient for pilot, verify against official templates before live filing).
- SSS EC/WISP as separate reported columns (the regular SS share is computed; EC/WISP are not modelled — add if the pilot requires their separate reporting). Pag-IBIG already caps basis at ₱10,000 in `PagibigComputationService` — no change needed.
