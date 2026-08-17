# Tranche B — Money Correctness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the budget-consumption and approval-threshold decisions exact at the cent by comparing money to money through `App\Common\Support\Money`, so a department at 99.95% consumed stops being classified `exhausted`.

**Architecture:** One new pure classifier (`BudgetConsumptionLevel`) replaces the `round(pct,1)`-then-compare logic duplicated across two services, by comparing `consumedAfter` against `allocated × ratio` — no division, no rounding. `Budget::getAvailableAttribute()` returns the exact decimal string PostgreSQL already supplies instead of casting to `float`, and the float money signatures through `BudgetEnforcementService`, `ApprovalService` and their six callers become strings.

**Tech Stack:** Laravel 12 / PHP 8.3, PHPUnit 11, PostgreSQL 16, bcmath via `App\Common\Support\Money`, Docker Compose.

**Spec:** `docs/superpowers/specs/2026-08-16-tranche-b-money-correctness-design.md`

## Global Constraints

- Repository root `/home/kwat0g/Desktop/kwatog`. PHP runs inside Docker: `docker compose exec -T api …`. Node validator scripts run on the host, unprefixed.
- **Never run two PHPUnit processes at once.** They share the `ogami_test` database; concurrent runs produce phantom failures.
- Containers stop on their own during long sessions. Before any PHP command: `docker compose up -d db api` and wait ~15s. A command that reports `service "api" is not running` produced **no result** — do not read its output as a finding.
- `declare(strict_types=1);` on every PHP file, per `CLAUDE.md`.
- **Money is never a float.** All monetary values move as decimal strings and are compared only via `Money::gt/gte/lt/lte/cmp/isZero`. `Money` accepts `string|float|int` and returns `string`.
- **No migration, no `$casts` changes, no SPA changes.** The columns are already `decimal(15,2)` and arrive as exact strings; `BudgetResource` already casts `(float)` at the API boundary and stays the boundary.
- Commit rhythm: `test:` → `fix:` / `feat:` → `docs:`. Stage explicit file lists — never `git add -A` or a directory. Another contributor commits to this branch concurrently; run `git status --porcelain` before staging and verify your commit is non-empty afterwards.
- Ratio settings are floats read via `SettingsService::requiredFloat` and are already validated in order. Live values: `budget.warning_ratio 0.8`, `critical_ratio 0.95`, `exhausted_ratio 1`, `overdrawn_ratio 1.2`.
- Suite baseline to match or beat: `Tests: 1815, Assertions: 9106`.

## The behaviour this plan changes

At `allocated = 1,000,000.00`, `spent + committed = 999,000.00`, `amount = 500.00`:

| | Before | After |
|---|---|---|
| consumption after request | 99.95% | 99.95% |
| classified level | `exhausted` | **`critical`** |
| `budget_warning_level` stamped | `exhausted` | `critical` |
| `assertAcknowledged()` on PO/PR approval | **throws** — blocked until Finance acknowledges | passes — `critical` is advisory |

`critical` is deliberately still a warning level: `assess()`'s `requiresAcknowledgment` is `in_array($level, ['exhausted','overdrawn'])`, and `assertAcknowledged()` throws only for those two. So the band moves from blocking to advisory, which is the fix. **The test must assert `critical` specifically, not merely "not exhausted"** — asserting the weaker thing would pass even if the classifier collapsed to `ok`.

## File Structure

| File | Responsibility | Task |
|---|---|---|
| `api/app/Modules/Accounting/Support/BudgetConsumptionLevel.php` | **Create.** Pure classifier: `(consumedAfter, allocated, ratios) → level`. No division, no rounding, no dependencies beyond `Money`. | 1 |
| `api/tests/Unit/BudgetConsumptionLevelTest.php` | **Create.** Unit tests, no database. | 1 |
| `api/app/Modules/Accounting/Models/Budget.php:71` | Modify. `getAvailableAttribute()` returns `string`. `:76` gains a display-only docblock. | 2 |
| `api/app/Modules/Accounting/Services/BudgetEnforcementService.php` | Modify. `checkAvailability/assess/enforce` take `string $amount`; classification delegates to the classifier; comparisons use `Money`. | 3 |
| `api/tests/Feature/Accounting/BudgetEnforcementBoundaryTest.php` | **Create.** Boundary tests through the real service and database. | 3 |
| `api/app/Modules/Accounting/Services/BudgetService.php:82` | Modify. `checkConsumption()` delegates to the classifier. | 4 |
| `api/app/Common/Services/ApprovalService.php:23,39` | Modify. `?string $amount`; threshold read as string; `Money::lt`. | 5 |
| `api/app/Common/Traits/HasApprovalWorkflow.php:21` | Modify. `?string $amount`. | 5 |
| `api/tests/Unit/ApprovalServiceTest.php:54` | Modify. Existing float named-arg becomes a string. | 5 |
| `api/tests/Feature/Approvals/ApprovalThresholdBoundaryTest.php` | **Create.** Seeds its own workflow with a threshold, since none ships. | 5 |
| 6 caller sites | Modify. Drop `(float)` casts. | 6 |
| `docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md`, `SYSTEM-AUDIT-FINDING-LIFECYCLE.json`, `AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json`, `scripts/verify-audit-acceptance-manifest.mjs` | Modify. Register F-044 and F-045; gate count 43 → 45. | 7 |

---

### Task 1: The shared consumption classifier

**Files:**
- Create: `api/app/Modules/Accounting/Support/BudgetConsumptionLevel.php`
- Test: `api/tests/Unit/BudgetConsumptionLevelTest.php`

**Interfaces:**
- Consumes: `App\Common\Support\Money` (existing: `add`, `sub`, `mul`, `gte`, `lte`, `isZero`, all `string|float|int → string`).
- Produces: `BudgetConsumptionLevel::classify(string $consumedAfter, string $allocated, array $ratios): string` returning one of the class constants `OK`, `WARNING`, `CRITICAL`, `EXHAUSTED`, `OVERDRAWN`. Tasks 3 and 4 both call exactly this.

`api/app/Modules/Accounting/Support/` does not exist yet — create the directory. Sibling modules (`CRM`, `Admin`, `Dashboard`, `Purchasing`, `Inventory`) already use a `Support/` directory, so this follows the established convention.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Unit/BudgetConsumptionLevelTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Accounting\Support\BudgetConsumptionLevel;
use PHPUnit\Framework\TestCase;

/**
 * Tranche B / D1 — consumption classification must compare money to money.
 *
 * The defect this replaces rounded consumption to one decimal and compared the
 * percentage against a ratio, so everything from 99.95% up became 100.0 and
 * classified `exhausted`. Every fixture below uses adversarial cent values:
 * round thousands are exactly where float and decimal agree and so prove nothing.
 */
class BudgetConsumptionLevelTest extends TestCase
{
    /** @var array{warning: float, critical: float, exhausted: float, overdrawn: float} */
    private const RATIOS = [
        'warning'   => 0.8,
        'critical'  => 0.95,
        'exhausted' => 1.0,
        'overdrawn' => 1.2,
    ];

    private function classify(string $consumedAfter, string $allocated): string
    {
        return BudgetConsumptionLevel::classify($consumedAfter, $allocated, self::RATIOS);
    }

    public function test_the_99_95_percent_band_is_critical_not_exhausted(): void
    {
        // The regression case. 999,500.00 of 1,000,000.00 is 99.95% consumed:
        // over the 95% critical threshold, under the 100% exhausted threshold.
        $this->assertSame('critical', $this->classify('999500.00', '1000000.00'));
    }

    public function test_exactly_one_hundred_percent_is_exhausted(): void
    {
        // Guards against over-correcting: spending the budget to the last
        // centavo must still require Finance acknowledgment.
        $this->assertSame('exhausted', $this->classify('1000000.00', '1000000.00'));
    }

    public function test_one_centavo_below_the_ceiling_is_critical(): void
    {
        $this->assertSame('critical', $this->classify('999999.99', '1000000.00'));
    }

    public function test_one_centavo_above_the_ceiling_is_exhausted(): void
    {
        $this->assertSame('exhausted', $this->classify('1000000.01', '1000000.00'));
    }

    public function test_unchanged_bands_still_classify_as_before(): void
    {
        $this->assertSame('ok', $this->classify('799999.99', '1000000.00'));
        $this->assertSame('warning', $this->classify('800000.00', '1000000.00'));
        $this->assertSame('critical', $this->classify('950000.00', '1000000.00'));
        $this->assertSame('overdrawn', $this->classify('1200000.00', '1000000.00'));
    }

    public function test_zero_allocation_is_ok_and_never_divides(): void
    {
        $this->assertSame('ok', $this->classify('0.00', '0.00'));
        $this->assertSame('ok', $this->classify('500.00', '0.00'));
    }

    public function test_cent_values_that_a_float_ratio_would_misplace(): void
    {
        // 0.1 + 0.2 !== 0.3 in binary floating point. These allocations and
        // amounts are chosen so a float pathway lands on the wrong side.
        $this->assertSame('critical', $this->classify('0.29', '0.30'));
        $this->assertSame('exhausted', $this->classify('0.30', '0.30'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker compose up -d db api && sleep 15
docker compose exec -T api php artisan test --filter=BudgetConsumptionLevelTest
```

Expected: FAIL — `Class "App\Modules\Accounting\Support\BudgetConsumptionLevel" not found`.

- [ ] **Step 3: Write the classifier**

Create `api/app/Modules/Accounting/Support/BudgetConsumptionLevel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Common\Support\Money;

/**
 * Classifies how much of a budget is consumed into a severity level.
 *
 * WHY THIS COMPARES AMOUNTS AND NEVER DIVIDES:
 *   The implementation this replaces computed `round(consumed / allocated * 100, 1)`
 *   and compared that percentage against a ratio. Rounding to one decimal
 *   discards 0.05% of resolution, and `budget.exhausted_ratio` is exactly 1.00,
 *   so every consumption level from 99.95% upward became 100.0 and classified
 *   `exhausted` — stamping a false label that blocked PO/PR approval through
 *   BudgetEnforcementService::assertAcknowledged().
 *
 *   Restating the comparison as money-against-money removes both the division
 *   and the rounding from the decision. Division is also the one bcmath
 *   operation that forces an explicit scale choice, so avoiding it avoids a
 *   precision question entirely.
 *
 * Percentages are still fine for display. They are not fine as a decision input.
 */
final class BudgetConsumptionLevel
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const CRITICAL = 'critical';
    public const EXHAUSTED = 'exhausted';
    public const OVERDRAWN = 'overdrawn';

    /**
     * @param  string  $consumedAfter  spent + committed + the amount under consideration
     * @param  string  $allocated      the budget ceiling
     * @param  array{warning: float, critical: float, exhausted: float, overdrawn: float}  $ratios
     */
    public static function classify(string $consumedAfter, string $allocated, array $ratios): string
    {
        // A zero or negative ceiling has no meaningful consumption ratio. Return
        // early rather than multiplying by it, so no caller can divide by it.
        if (Money::lte($allocated, '0')) {
            return self::OK;
        }

        // Descending severity: the first threshold reached wins.
        $levels = [
            self::OVERDRAWN => $ratios['overdrawn'],
            self::EXHAUSTED => $ratios['exhausted'],
            self::CRITICAL  => $ratios['critical'],
            self::WARNING   => $ratios['warning'],
        ];

        foreach ($levels as $level => $ratio) {
            if (Money::gte($consumedAfter, Money::mul($allocated, $ratio))) {
                return $level;
            }
        }

        return self::OK;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
docker compose exec -T api php artisan test --filter=BudgetConsumptionLevelTest
```

Expected: PASS, 7 tests. Record the assertion count — Task 7 registers it as evidence.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Accounting/Support/BudgetConsumptionLevel.php api/tests/Unit/BudgetConsumptionLevelTest.php
git commit -m "feat: classify budget consumption by comparing money to money

The logic this replaces computed round(consumed / allocated * 100, 1) and
compared the percentage against a ratio. Rounding to one decimal discards
0.05% of resolution and budget.exhausted_ratio is exactly 1.00, so every
consumption level from 99.95% up became 100.0 and classified exhausted.

Restating the comparison as consumedAfter >= allocated x ratio removes both
the division and the rounding from the decision. Extracted rather than inlined
because BudgetEnforcementService and BudgetService both need it and the
duplicated percentage logic is how they drifted apart.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Budget::getAvailableAttribute returns an exact string

**Files:**
- Modify: `api/app/Modules/Accounting/Models/Budget.php:71-83`

**Interfaces:**
- Consumes: `App\Common\Support\Money` — add the import; `Budget.php` does not have it.
- Produces: `Budget::$available` is now a `string` decimal (e.g. `'8325000.00'`) rather than a `float`. Task 3 relies on this. `Budget::$utilization_percent` remains a `float` and is display-only.

**Background.** `total_allocated`, `total_spent` and `total_committed` arrive from PostgreSQL as exact decimal strings — verified at runtime, `total_allocated` is `string '18500000.00'` while `available` is `double 8325000.0`. `Budget` declares no decimal casts (`$casts` covers only `submitted_at`/`approved_at`), so this accessor is the first and only place precision is lost.

`BudgetResource` already casts `(float)` for the three sibling money fields, so it remains the API boundary and `spa/src/types/budgeting.ts` stays accurate. Do not change either.

- [ ] **Step 1: Add the Money import**

In `api/app/Modules/Accounting/Models/Budget.php`, add to the `use` block in alphabetical position:

```php
use App\Common\Support\Money;
```

- [ ] **Step 2: Replace both accessors**

The current code at `:71-83` reads:

```php
    public function getAvailableAttribute(): float
    {
        return (float) ($this->total_allocated - $this->total_spent - $this->total_committed);
    }

    public function getUtilizationPercentAttribute(): float
    {
        if ($this->total_allocated <= 0) {
            return 0;
        }
        return round(($this->total_spent + $this->total_committed) / $this->total_allocated * 100, 1);
    }
```

Replace with:

```php
    /**
     * Remaining budget as an exact decimal string.
     *
     * The columns arrive from PostgreSQL as exact decimal strings, so this is
     * the one place precision could be lost. It previously returned a float and
     * fed BudgetEnforcementService's block/allow comparisons directly.
     * BudgetResource casts to float at the API boundary, as it already does for
     * total_allocated / total_spent / total_committed.
     */
    public function getAvailableAttribute(): string
    {
        return Money::sub(
            Money::sub((string) $this->total_allocated, (string) $this->total_spent),
            (string) $this->total_committed,
        );
    }

    /**
     * DISPLAY ONLY — never a decision input.
     *
     * This is rounded to one decimal, which is fine for a dashboard and wrong
     * for a threshold comparison: budget.exhausted_ratio is 1.00, so a rounded
     * 99.95% becomes 100.0 and misclassifies as exhausted. Anything deciding a
     * level must use App\Modules\Accounting\Support\BudgetConsumptionLevel,
     * which compares amounts instead. See Tranche B / F-044.
     */
    public function getUtilizationPercentAttribute(): float
    {
        if (Money::lte((string) $this->total_allocated, '0')) {
            return 0;
        }

        return round(
            ((float) $this->total_spent + (float) $this->total_committed) / (float) $this->total_allocated * 100,
            1,
        );
    }
```

- [ ] **Step 3: Verify the accessor returns an exact string**

```bash
docker compose exec -T api php -r '
require "vendor/autoload.php"; $a = require "bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$b = App\Modules\Accounting\Models\Budget::query()->first();
printf("available: type=%s value=%s\n", gettype($b->available), var_export($b->available, true));
printf("utilization_percent: type=%s value=%s\n", gettype($b->utilization_percent), var_export($b->utilization_percent, true));
' 2>&1 | grep -v level=warning
```

Expected: `available: type=string value='8325000.00'` and `utilization_percent: type=double`. Before this change `available` was `type=double value=8325000.0`.

- [ ] **Step 4: Confirm nothing that reads these attributes broke**

```bash
docker compose exec -T api php artisan test --filter='Budget'
```

Expected: PASS. `BudgetService:115` does `(float) $deptBudgets->sum(fn ($b) => $b->available)` — summing numeric strings then casting is still correct for that dashboard aggregate, so it needs no change in this task.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Accounting/Models/Budget.php
git commit -m "fix: return exact remaining budget instead of a float

total_allocated, total_spent and total_committed arrive from PostgreSQL as
exact decimal strings; Budget declares no decimal casts, so getAvailable was
the only place that precision was discarded — and it fed the block/allow
comparisons in BudgetEnforcementService directly.

getUtilizationPercent keeps returning a rounded float and is now documented as
display-only, because rounding to one decimal is exactly what makes it unfit as
a threshold input.

BudgetResource is untouched: it already casts to float at the API boundary for
the three sibling money fields, so the SPA contract is unchanged.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: BudgetEnforcementService decides on exact money

**Files:**
- Modify: `api/app/Modules/Accounting/Services/BudgetEnforcementService.php`
- Test: `api/tests/Feature/Accounting/BudgetEnforcementBoundaryTest.php` (create)

**Interfaces:**
- Consumes: `BudgetConsumptionLevel::classify(string, string, array): string` from Task 1; `Budget::$available` as `string` from Task 2.
- Produces: `checkAvailability(int $departmentId, string $amount, ?int $fiscalYearId = null): array` returning `[bool $canProceed, string $level, string $message]`; `assess(Model $document, int $departmentId, string $amount, ?int $fiscalYearId = null): array`; `enforce(int $departmentId, string $amount, ?int $fiscalYearId = null): void`. Task 6 updates the callers to these signatures.

**Background.** `assess()` stamps `budget_warning_level` unconditionally — the `forceFill` has no enforcement-mode guard — and `assertAcknowledged()` (called at `PurchaseOrderService.php:347` and `PurchaseRequestService.php:356`) throws whenever the level is `exhausted`/`overdrawn` without an acknowledgment, also with no mode guard. So a false `exhausted` blocks approval in every mode, including the live `warn`.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Feature/Accounting/BudgetEnforcementBoundaryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\BudgetEnforcementService;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tranche B / F-044 — the budget gate must decide on exact money.
 *
 * The regression: consumption was rounded to one decimal before being compared
 * against budget.exhausted_ratio = 1.00, so 99.95% became 100.0 and classified
 * `exhausted`. That stamped a false label which assertAcknowledged() then turned
 * into a hard block on PO/PR approval, in every enforcement mode.
 */
class BudgetEnforcementBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function budgetFor(string $allocated, string $spent, string $committed): Department
    {
        $department = Department::factory()->create();
        $fiscalYear = FiscalYear::query()->create([
            'year'       => 2026,
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
            'is_active'  => true,
        ]);

        Budget::query()->create([
            'fiscal_year_id'   => $fiscalYear->id,
            'department_id'    => $department->id,
            'budget_type'      => 'operating',
            'name'             => 'Boundary probe',
            'total_allocated'  => $allocated,
            'total_spent'      => $spent,
            'total_committed'  => $committed,
            'status'           => 'approved',
        ]);

        return $department;
    }

    private function level(Department $department, string $amount): string
    {
        [, $level, ] = app(BudgetEnforcementService::class)
            ->checkAvailability((int) $department->id, $amount);

        return $level;
    }

    public function test_the_99_95_percent_band_is_critical_not_exhausted(): void
    {
        // 999,000.00 already consumed of 1,000,000.00; a 500.00 request takes it
        // to 99.95%. The department genuinely has 1,000.00 available.
        $department = $this->budgetFor('1000000.00', '900000.00', '99000.00');

        $this->assertSame(
            'critical',
            $this->level($department, '500.00'),
            'A department at 99.95% must be critical (advisory), not exhausted (which blocks approval).',
        );
    }

    public function test_exactly_one_hundred_percent_is_exhausted(): void
    {
        $department = $this->budgetFor('1000000.00', '900000.00', '99500.00');

        $this->assertSame('exhausted', $this->level($department, '500.00'));
    }

    public function test_a_request_equal_to_available_is_not_overdrawn(): void
    {
        // available = 1,000.00 exactly; requesting exactly that is not overdrawn.
        $department = $this->budgetFor('1000000.00', '900000.00', '99000.00');

        $this->assertNotSame('overdrawn', $this->level($department, '1000.00'));
    }

    public function test_one_centavo_over_available_is_overdrawn(): void
    {
        $department = $this->budgetFor('1000000.00', '900000.00', '99000.00');

        [$canProceed, $level, ] = app(BudgetEnforcementService::class)
            ->checkAvailability((int) $department->id, '1000.01');

        $this->assertFalse($canProceed);
        $this->assertSame('overdrawn', $level);
    }

    public function test_a_zero_allocation_budget_is_exhausted_and_never_divides(): void
    {
        // NOTE the difference from the classifier's own unit test, which returns
        // 'ok' for a zero ceiling. checkAvailability guards on available <= 0
        // FIRST and returns 'exhausted' — existing semantics, deliberately
        // preserved: a budget with nothing in it is exhausted, not fine. The
        // classifier's zero-ceiling branch is therefore unreachable through this
        // path and exists so the classifier cannot divide by zero on its own.
        $department = $this->budgetFor('0.00', '0.00', '0.00');

        $this->assertSame('exhausted', $this->level($department, '500.00'));
    }

    public function test_two_budgets_for_one_department_sum_exactly(): void
    {
        // Cent values that drift when summed as floats.
        $department = $this->budgetFor('0.10', '0.00', '0.00');
        $fiscalYear = FiscalYear::query()->where('is_active', true)->firstOrFail();

        Budget::query()->create([
            'fiscal_year_id'  => $fiscalYear->id,
            'department_id'   => $department->id,
            'budget_type'     => 'operating',
            'name'            => 'Second budget',
            'total_allocated' => '0.20',
            'total_spent'     => '0.00',
            'total_committed' => '0.00',
            'status'          => 'approved',
        ]);

        // allocated totals 0.30; a 0.30 request is exactly 100% consumed.
        $this->assertSame('exhausted', $this->level($department, '0.30'));
        // 0.29 is 96.67% — critical, not exhausted.
        $this->assertSame('critical', $this->level($department, '0.29'));
    }
}
```

- [ ] **Step 2: Run the test to verify the regression cases fail**

```bash
docker compose exec -T api php artisan test --filter=BudgetEnforcementBoundaryTest
```

Expected: FAIL. `test_the_99_95_percent_band_is_critical_not_exhausted` fails with
`Failed asserting that two strings are identical. -'critical' +'exhausted'`, and
`test_two_budgets_for_one_department_sum_exactly` fails on its `'critical'` assertion.

If every test passes at this step, stop and report — the premise is wrong and the fix is not needed as described.

- [ ] **Step 3: Replace the classification body**

In `api/app/Modules/Accounting/Services/BudgetEnforcementService.php`, add two imports:

```php
use App\Common\Support\Money;
use App\Modules\Accounting\Support\BudgetConsumptionLevel;
```

Change the `checkAvailability` signature at `:24` from `float $amount` to `string $amount`, and replace the body from the `$available` assignment through the final `return` with:

```php
        // Exact sums. Collection::sum() on these columns coerces to float.
        $available = Money::zero();
        $spent = Money::zero();
        $committed = Money::zero();
        $allocated = Money::zero();
        foreach ($budgets as $budget) {
            $available = Money::add($available, $budget->available);
            $spent     = Money::add($spent, (string) $budget->total_spent);
            $committed = Money::add($committed, (string) $budget->total_committed);
            $allocated = Money::add($allocated, (string) $budget->total_allocated);
        }

        $currency = app(\App\Common\Services\CurrencyDisplayService::class);

        if (Money::lte($available, '0')) {
            return [false, BudgetConsumptionLevel::EXHAUSTED, 'Budget exhausted. No remaining available funds ('.$currency->format(0).' available).'];
        }

        if (Money::gt($amount, $available)) {
            return [false, BudgetConsumptionLevel::OVERDRAWN, 'Insufficient budget. Requested: '.$currency->format($amount)
                . ', Available: '.$currency->format($available).'.'];
        }

        $level = BudgetConsumptionLevel::classify(
            Money::add($spent, $committed, $amount),
            $allocated,
            [
                'warning'   => $this->settings->requiredFloat('budget.warning_ratio', 0, 1),
                'critical'  => $this->settings->requiredFloat('budget.critical_ratio', $this->settings->requiredFloat('budget.warning_ratio', 0, 1), 1),
                'exhausted' => $this->settings->requiredFloat('budget.exhausted_ratio', $this->settings->requiredFloat('budget.critical_ratio', 0, 1)),
                'overdrawn' => $this->settings->requiredFloat('budget.overdrawn_ratio', $this->settings->requiredFloat('budget.exhausted_ratio', 0)),
            ],
        );

        // DISPLAY ONLY, and deliberately plain float math: rounding it is
        // precisely what misclassified the 99.95%-99.99% band as exhausted, so
        // routing it through Money would imply a precision that must never
        // matter here. Unreachable when $allocated is zero — the available <= 0
        // guard above returns first — so this cannot divide by zero.
        $pct = round(((float) Money::add($spent, $committed, $amount) / (float) $allocated) * 100, 1);

        return match ($level) {
            BudgetConsumptionLevel::OVERDRAWN => [false, $level, "Budget {$pct}% consumed. VP approval required."],
            BudgetConsumptionLevel::EXHAUSTED => [false, $level, "Budget {$pct}% consumed. Finance acknowledgment required."],
            BudgetConsumptionLevel::CRITICAL  => [false, $level, "Budget {$pct}% consumed. Finance acknowledgment required."],
            BudgetConsumptionLevel::WARNING   => [true, $level, "Budget {$pct}% consumed. Warning sent to department head."],
            default                           => [true, $level, "Budget within limits ({$pct}% consumed). ".$currency->format($available)." available."],
        };
```

Then change `assess()` at `:78` and `enforce()` at `:143` from `float $amount` to `string $amount`. Their bodies need no other change: `assess()` passes `$amount` straight to `checkAvailability()` and into the `Log::warning` context, and `enforce()` only forwards it.

- [ ] **Step 4: Run the test to verify it passes**

```bash
docker compose exec -T api php artisan test --filter=BudgetEnforcementBoundaryTest
```

Expected: PASS, 6 tests. Record the assertion count for Task 7.

- [ ] **Step 5: Confirm the wider Accounting and Purchasing suites still pass**

```bash
docker compose exec -T api php artisan test --filter='Accounting|Purchasing|Budget'
```

Expected: PASS. Callers still pass floats at this point; PHP coerces a float argument to a `string` parameter without `strict_types` at the *call site*, but these files all declare `strict_types=1`, so a float argument to a `string` parameter is a `TypeError`. If any test fails with `TypeError: … must be of type string, float given`, that is Task 6's work — note the failing callers and continue; do **not** fix them here, because Task 6 changes them as one reviewable batch.

- [ ] **Step 6: Commit**

```bash
git add api/app/Modules/Accounting/Services/BudgetEnforcementService.php api/tests/Feature/Accounting/BudgetEnforcementBoundaryTest.php
git commit -m "fix: decide budget availability on exact money

checkAvailability rounded consumption to one decimal and compared the
percentage against budget.exhausted_ratio = 1.00, so 99.95% became 100.0 and
classified exhausted. assess() stamps that level unconditionally and
assertAcknowledged() throws on exhausted without an acknowledgment, in every
enforcement mode — so an affordable PO/PR could not be approved until Finance
acknowledged an overrun that did not exist.

Classification now delegates to BudgetConsumptionLevel, which compares
consumedAfter against allocated x ratio. The percentage survives only to build
the message text and is commented as display-only. Sums use Money::add rather
than Collection::sum, which coerces these columns to float.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: BudgetService::checkConsumption shares the classifier

**Files:**
- Modify: `api/app/Modules/Accounting/Services/BudgetService.php:82-94`

**Interfaces:**
- Consumes: `BudgetConsumptionLevel::classify(string, string, array): string` from Task 1.
- Produces: `checkConsumption(Budget $budget): string` — unchanged signature, unchanged return values.

**Background.** This is the second copy of the same defect. It reads `$budget->utilization_percent`, which is `round(…, 1)`, and compares `$pct / 100 >= $exhausted`. Fixing only Task 3 would leave an identical misclassification driving alert and label state.

- [ ] **Step 1: Write the failing parity test**

Append to `api/tests/Feature/Accounting/BudgetEnforcementBoundaryTest.php`:

```php
    public function test_check_consumption_agrees_with_the_enforcement_gate(): void
    {
        // Same figures through both decision paths must yield the same level.
        // checkConsumption looks at the budget as it stands, so the comparable
        // enforcement call is one with a zero amount.
        $department = $this->budgetFor('1000000.00', '900000.00', '99950.00');
        $budget = \App\Modules\Accounting\Models\Budget::query()
            ->where('department_id', $department->id)
            ->firstOrFail();

        $this->assertSame(
            $this->level($department, '0.00'),
            app(\App\Modules\Accounting\Services\BudgetService::class)->checkConsumption($budget),
            'checkConsumption and checkAvailability must classify identical figures identically.',
        );
        $this->assertSame('critical', app(\App\Modules\Accounting\Services\BudgetService::class)->checkConsumption($budget));
    }
```

- [ ] **Step 2: Run it to verify it fails**

```bash
docker compose exec -T api php artisan test --filter=test_check_consumption_agrees_with_the_enforcement_gate
```

Expected: FAIL. 999,950.00 of 1,000,000.00 is 99.995%, which `round(…, 1)` turns into `100.0`, so `checkConsumption` returns `exhausted` while the fixed gate returns `critical`.

- [ ] **Step 3: Delegate to the classifier**

In `api/app/Modules/Accounting/Services/BudgetService.php`, add two imports:

```php
use App\Common\Support\Money;
use App\Modules\Accounting\Support\BudgetConsumptionLevel;
```

Replace the body of `checkConsumption` at `:82-94`:

```php
    /**
     * Check budget consumption level and return warning severity.
     * Returns 'ok' | 'warning' | 'critical' | 'exhausted' | 'overdrawn'
     *
     * Delegates to BudgetConsumptionLevel so this and
     * BudgetEnforcementService::checkAvailability cannot drift apart. It
     * previously read $budget->utilization_percent, which is rounded to one
     * decimal and therefore misclassified the 99.95%-99.99% band as exhausted.
     */
    public function checkConsumption(Budget $budget): string
    {
        $warning = $this->settings->requiredFloat('budget.warning_ratio', 0, 1);
        $critical = $this->settings->requiredFloat('budget.critical_ratio', $warning, 1);
        $exhausted = $this->settings->requiredFloat('budget.exhausted_ratio', $critical);
        $overdrawn = $this->settings->requiredFloat('budget.overdrawn_ratio', $exhausted);

        return BudgetConsumptionLevel::classify(
            Money::add((string) $budget->total_spent, (string) $budget->total_committed),
            (string) $budget->total_allocated,
            compact('warning', 'critical', 'exhausted', 'overdrawn'),
        );
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
docker compose exec -T api php artisan test --filter=BudgetEnforcementBoundaryTest
```

Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Accounting/Services/BudgetService.php api/tests/Feature/Accounting/BudgetEnforcementBoundaryTest.php
git commit -m "fix: classify budget consumption from exact columns, not a rounded percent

checkConsumption read utilization_percent, which is round(..., 1), and compared
it against budget.exhausted_ratio = 1.00 — the same defect the enforcement gate
had, in a second decision path driving alert and label state. Both now delegate
to BudgetConsumptionLevel, so they cannot drift apart again.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: The approval threshold compares exact money

**Files:**
- Modify: `api/app/Common/Services/ApprovalService.php:23,39-41`
- Modify: `api/app/Common/Traits/HasApprovalWorkflow.php:21`
- Modify: `api/tests/Unit/ApprovalServiceTest.php:54`
- Test: `api/tests/Feature/Approvals/ApprovalThresholdBoundaryTest.php` (create)

**Interfaces:**
- Consumes: `App\Common\Support\Money`; neither file imports it yet.
- Produces: `ApprovalService::submit(Model $approvable, string $workflowType, ?string $amount = null): void` and `HasApprovalWorkflow::submitForApproval(string $workflowType, ?string $amount = null): void`. Task 6 updates the five callers.

**Background — this is latent, not live.** Verified against the database: every `workflow_definitions.amount_threshold` is `NULL` and no `steps` JSON entry carries a `threshold` key, so `isset($step['threshold'])` is always false and no step is ever skipped by amount today. It is fixed because the call sites are open and it becomes lossy the moment an operator configures a threshold.

The threshold is read from the `steps` **JSON** column, not the `amount_threshold` column the docblock cites. A JSON number loses precision at `json_decode` before this code sees it, so the docblock must tell operators to store a step threshold as a JSON string.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Feature/Approvals/ApprovalThresholdBoundaryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Common\Models\ApprovalRecord;
use App\Common\Models\WorkflowDefinition;
use App\Common\Services\ApprovalService;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tranche B / F-045 — approval-step skipping must compare exact money.
 *
 * This must seed its own workflow: no shipped workflow_definitions row carries a
 * threshold, so the skip path is unreachable with the default data. The
 * semantic under test is strict: a step is skipped when amount < threshold, so
 * an amount exactly equal to the threshold is RETAINED.
 */
class ApprovalThresholdBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function workflowWithThreshold(): void
    {
        WorkflowDefinition::query()->create([
            'workflow_type' => 'tranche_b_threshold',
            'name'          => 'Threshold boundary probe',
            // Stored as a JSON string, not a JSON number: a number would lose
            // precision at json_decode before the comparison ever runs.
            'steps'         => [
                ['order' => 1, 'role' => 'department_head', 'label' => 'Dept Head', 'threshold' => '50000.00'],
                ['order' => 2, 'role' => 'finance_officer', 'label' => 'Finance'],
            ],
        ]);
    }

    private function actionForStepOne(string $amount): string
    {
        $pr = PurchaseRequest::factory()->create();
        app(ApprovalService::class)->submit($pr, 'tranche_b_threshold', $amount);

        return (string) ApprovalRecord::query()
            ->where('approvable_type', $pr->getMorphClass())
            ->where('approvable_id', $pr->getKey())
            ->where('step_order', 1)
            ->value('action');
    }

    public function test_one_centavo_below_the_threshold_skips_the_step(): void
    {
        $this->workflowWithThreshold();

        $this->assertSame('skipped', $this->actionForStepOne('49999.99'));
    }

    public function test_exactly_the_threshold_retains_the_step(): void
    {
        $this->workflowWithThreshold();

        $this->assertSame('pending', $this->actionForStepOne('50000.00'));
    }

    public function test_one_centavo_above_the_threshold_retains_the_step(): void
    {
        $this->workflowWithThreshold();

        $this->assertSame('pending', $this->actionForStepOne('50000.01'));
    }

    public function test_a_step_without_a_threshold_is_always_retained(): void
    {
        $this->workflowWithThreshold();
        $pr = PurchaseRequest::factory()->create();
        app(ApprovalService::class)->submit($pr, 'tranche_b_threshold', '1.00');

        $this->assertSame('pending', (string) ApprovalRecord::query()
            ->where('approvable_type', $pr->getMorphClass())
            ->where('approvable_id', $pr->getKey())
            ->where('step_order', 2)
            ->value('action'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
docker compose exec -T api php artisan test --filter=ApprovalThresholdBoundaryTest
```

Expected: FAIL with `TypeError: … submit(): Argument #3 ($amount) must be of type ?float, string given` — the signature is still `?float`.

- [ ] **Step 3: Change both signatures and the comparison**

In `api/app/Common/Services/ApprovalService.php`, add the import:

```php
use App\Common\Support\Money;
```

Change `:23` to:

```php
    public function submit(Model $approvable, string $workflowType, ?string $amount = null): void
```

Extend the method docblock above it with:

```php
     * $amount is a decimal string, never a float — it is compared against a
     * step threshold to decide whether that step is skipped, and a float
     * comparison at the boundary depends on binary representation.
     *
     * A step threshold lives in the `steps` JSON column as `threshold`. Store it
     * as a JSON **string** (`"50000.00"`): a JSON number is decoded to a PHP
     * float before this method ever sees it, so precision is lost upstream of
     * any comparison we can make here.
```

Replace `:39-41`:

```php
                $threshold = isset($step['threshold']) ? (float) $step['threshold'] : null;
                $action = ($threshold !== null && $amount !== null && $amount < $threshold)
                    ? 'skipped'
                    : 'pending';
```

with:

```php
                $threshold = isset($step['threshold']) ? (string) $step['threshold'] : null;
                $action = ($threshold !== null && $amount !== null && Money::lt($amount, $threshold))
                    ? 'skipped'
                    : 'pending';
```

In `api/app/Common/Traits/HasApprovalWorkflow.php`, change `:21` to:

```php
    public function submitForApproval(string $workflowType, ?string $amount = null): void
```

- [ ] **Step 4: Update the existing unit test**

`api/tests/Unit/ApprovalServiceTest.php:54` currently passes a float named argument:

```php
        app(ApprovalService::class)->submit($approvable, 'purchase_order', amount: 10000.00);
```

Change it to a decimal string:

```php
        app(ApprovalService::class)->submit($approvable, 'purchase_order', amount: '10000.00');
```

- [ ] **Step 5: Run both suites to verify they pass**

```bash
docker compose exec -T api php artisan test --filter=ApprovalThresholdBoundaryTest
docker compose exec -T api php artisan test --filter=ApprovalServiceTest
```

Expected: both PASS — 4 tests and the existing `ApprovalServiceTest` set respectively. Record the boundary test's assertion count for Task 7.

- [ ] **Step 6: Commit**

```bash
git add api/app/Common/Services/ApprovalService.php api/app/Common/Traits/HasApprovalWorkflow.php api/tests/Unit/ApprovalServiceTest.php api/tests/Feature/Approvals/ApprovalThresholdBoundaryTest.php
git commit -m "fix: compare approval step thresholds as exact money

submit() took a float amount and compared it against a step threshold that was
itself float-cast, to decide whether an approval step is skipped. Latent today —
no shipped workflow carries a threshold and no step is ever skipped by amount —
but lossy at the boundary the moment one is configured.

The threshold lives in the steps JSON column, not the amount_threshold column
the docblock cited, so the docblock now tells operators to store it as a JSON
string: a JSON number is decoded to a float before any comparison we make.

The boundary test seeds its own workflow because none ships with a threshold.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Callers pass decimal strings

**Files:**
- Modify: `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:329`
- Modify: `api/app/Modules/Purchasing/Services/PurchaseRequestService.php:215,287,294`
- Modify: `api/app/Modules/Loans/Services/LoanService.php:200`
- Modify: `api/app/Modules/HR/Services/SalaryAdjustmentService.php:55`
- Modify: `api/app/Modules/Accounting/Services/BillService.php:145`
- Modify: `api/app/Modules/Accounting/Controllers/BudgetController.php:286`

**Interfaces:**
- Consumes: the `string`/`?string` signatures from Tasks 3 and 5.
- Produces: nothing later tasks rely on.

These are seven small edits of the same shape — replace a `(float)` cast with `(string)`, plus one float comparison. They are batched into one task because a reviewer would accept or reject them as a set.

`PurchaseOrderService`, `LoanService` and `BillService` already import `Money`. `PurchaseRequestService` and `SalaryAdjustmentService` do not and only need it if they use it — only `PurchaseRequestService` does.

- [ ] **Step 1: Apply all seven edits**

`PurchaseOrderService.php:329`:

```php
            $this->approvals->submit($po, 'purchase_order', (string) $po->total_amount);
```

`LoanService.php:200`:

```php
            $this->approvals->submit($loan, $type->workflowType(), (string) $data['principal']);
```

`SalaryAdjustmentService.php:55`:

```php
            $this->approvals->submit($adjustment, self::WORKFLOW_TYPE, $amount !== null ? (string) $amount : null);
```

`BillService.php:145`:

```php
                    [$canProceed, , $message] = $this->budget->checkAvailability($deptId, (string) $total);
```

`BudgetController.php:286`:

```php
            (string) $validated['amount'],
```

`PurchaseRequestService.php:215`:

```php
            $total = (string) $pr->totalEstimatedAmount();
```

`PurchaseRequestService.php:287` and `:294` — change the parameter type and the comparison:

```php
    private function submitUrgent(PurchaseRequest $pr, string $total): void
    {
        $this->approvals->submit($pr, 'purchase_request', $total);

        // Resolve the cap. '0' disables skipping; any positive value is the
        // inclusive ceiling under which the Dept Head step may be skipped.
        $limit = $this->settings->requiredFloat('purchasing.urgent_skip_limit', 0);
        $maySkip = $limit > 0 && Money::lte($total, $limit);
```

and add the import to `PurchaseRequestService.php`:

```php
use App\Common\Support\Money;
```

`Money::lte` accepts `string|float|int`, so `$limit` needs no cast. `$limit > 0` stays a float comparison against literal zero, which is exact for that purpose — it is a feature switch, not a money comparison.

- [ ] **Step 2: Verify no float money signatures remain**

```bash
docker compose exec -T api sh -c "grep -rnE '(\?float|float) \\\$(amount|total)' app/Common/Services/ApprovalService.php app/Common/Traits/HasApprovalWorkflow.php app/Modules/Accounting/Services/BudgetEnforcementService.php app/Modules/Purchasing/Services/PurchaseRequestService.php" || echo 'CLEAN — no float money signatures remain'
```

Expected: `CLEAN — no float money signatures remain`.

- [ ] **Step 3: Run every affected suite**

```bash
docker compose exec -T api php artisan test --filter='Accounting|Purchasing|Approval|Loan|Salary|Budget|Bill'
```

Expected: PASS, no `TypeError`. A `TypeError: … must be of type string, float given` means a caller was missed — find it in the stack trace and fix it here.

- [ ] **Step 4: Run PHPStan**

```bash
docker compose exec -T api vendor/bin/phpstan analyse app --memory-limit=1G --no-progress
```

Expected: `[OK] No errors`. larastan is active at level 0 from Tranche A; a type mismatch introduced by these edits would surface here.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Purchasing/Services/PurchaseOrderService.php \
        api/app/Modules/Purchasing/Services/PurchaseRequestService.php \
        api/app/Modules/Loans/Services/LoanService.php \
        api/app/Modules/HR/Services/SalaryAdjustmentService.php \
        api/app/Modules/Accounting/Services/BillService.php \
        api/app/Modules/Accounting/Controllers/BudgetController.php
git commit -m "fix: pass decimal strings into the budget and approval gates

Seven call sites cast money to float on the way into checkAvailability and
submit, discarding the exactness the columns already carry. The urgent-skip
comparison in submitUrgent also decided on a float; it now uses Money::lte.
The \$limit > 0 feature switch stays a float comparison against literal zero,
which is exact for that purpose.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Register F-044 and F-045 in the governance contract

**Files:**
- Modify: `docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md`
- Modify: `docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json`
- Modify: `docs/AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json`
- Modify: `scripts/verify-audit-acceptance-manifest.mjs`

**Interfaces:**
- Consumes: assertion counts recorded in Tasks 1, 3 and 5.
- Produces: nothing later tasks rely on.

The repository enforces a three-way 1:1 invariant in `.github/workflows/audit-governance.yml` across the dated findings registers, the lifecycle JSON and the acceptance manifest. Tranche A generalised register discovery, so a new finding needs a register section, a lifecycle row and a gate — and the manifest verifier's explicit gate count must be incremented, deliberately.

- [ ] **Step 1: Append two register sections**

Append to `docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md`, matching the established bullet labels used by F-039–F-043 in the same file (Module / feature, Related modules, Category, Affected roles, Current Behavior, Problem, Real-world scenario, Root Cause, Recommended Improvement, Ideal Process, New Feature/Module Required, Cross-Module Impact, Evidence, Priority, Impact, Complexity):

- **F-044** — the live defect. Round-then-threshold classification in
  `BudgetEnforcementService::checkAvailability` and `BudgetService::checkConsumption`.
  State the worked example (1,000,000.00 allocated, 999,000.00 consumed, 500.00
  requested → 99.95% → `round` → `100.0` → `exhausted`), and state the blocking
  chain: `assess()` stamps the level with no mode guard, and
  `assertAcknowledged()` — called at `PurchaseOrderService.php:347` and
  `PurchaseRequestService.php:356`, also with no mode guard — throws on
  `exhausted` without an acknowledgment, so the false label blocks approval in
  every enforcement mode including the live `warn`. Priority P1.
- **F-045** — the latent defect. Float money through
  `Budget::getAvailableAttribute`, `BudgetEnforcementService`'s comparisons,
  `ApprovalService::submit`, `HasApprovalWorkflow::submitForApproval` and
  `PurchaseRequestService`'s urgent-skip comparison. Record explicitly that it is
  latent: every `amount_threshold` is `NULL`, no `steps` JSON entry carries a
  `threshold`, and `purchasing.urgent_skip_limit` is `0`, so both mechanisms are
  disabled by configuration and decide nothing today. Priority P2.

- [ ] **Step 2: Append two lifecycle rows**

Add a comma after the `F-043` row in `docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json` and append, substituting the real assertion counts recorded in Tasks 1, 3 and 5 — do not invent them, a fabricated count is a false evidence claim in a CI-validated register:

```json
  {"id": "F-044", "status": "verified", "owner": "Finance Platform", "evidence_date": "2026-08-16", "verification_scope": "budget consumption classification at the 99.95%, 100.00% and one-centavo boundaries, through the real service and database", "policy_decision": null, "regression_proof": "Budget consumption boundaries: <Task 1 + Task 3 test counts> focused tests / <assertions> assertions; the 99.95% band now classifies critical instead of exhausted"},
  {"id": "F-045", "status": "verified", "owner": "Finance Platform", "evidence_date": "2026-08-16", "verification_scope": "decimal-string money through the budget and approval gates; latent by configuration, so verified by boundary tests against a seeded threshold rather than by observed misbehaviour", "policy_decision": null, "regression_proof": "Approval threshold boundaries: <Task 5 test count> focused tests / <assertions> assertions; no float money signatures remain in the four gate files"}
```

- [ ] **Step 3: Append two gates and bump the count**

In `docs/AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json`, add a comma after the `F-043` gate and append, matching the existing compact one-object-per-line style:

```json
    {"id":"F-044","type":"focused_test","command":"cd api && php artisan test --filter=BudgetEnforcementBoundaryTest"},
    {"id":"F-045","type":"focused_test","command":"cd api && php artisan test --filter=ApprovalThresholdBoundaryTest"}
```

In `scripts/verify-audit-acceptance-manifest.mjs`, change the two `43` occurrences to `45`:

```js
if (manifest.gates?.length !== 45) errors.push(`expected 45 gates, got ${manifest.gates?.length ?? 0}`);
```

```js
console.log('Audit acceptance manifest clean: 45 findings mapped; F-030 remains external-evidence-only.');
```

- [ ] **Step 4: Run both governance validators**

```bash
node scripts/verify-audit-finding-lifecycle.mjs; echo "lifecycle exit=$?"
node scripts/verify-audit-acceptance-manifest.mjs; echo "manifest exit=$?"
```

Expected:

```
Audit lifecycle clean: 45 findings across 2 register(s) (open=2, mitigated=1, verified=42, decision_required=0).
lifecycle exit=0
Audit acceptance manifest clean: 45 findings mapped; F-030 remains external-evidence-only.
manifest exit=0
```

- [ ] **Step 5: Run both new gate commands exactly as stored**

```bash
docker compose exec -T api php artisan test --filter=BudgetEnforcementBoundaryTest; echo "F-044 exit=$?"
docker compose exec -T api php artisan test --filter=ApprovalThresholdBoundaryTest; echo "F-045 exit=$?"
```

Expected: both exit 0. A gate that passes because it selected no tests is a failure — `failOnEmptyTestSuite="true"` (added in Tranche A) makes an empty selection exit non-zero, so confirm each reports its real test count.

- [ ] **Step 6: Commit**

```bash
git add docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md \
        docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json \
        docs/AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json \
        scripts/verify-audit-acceptance-manifest.mjs
git commit -m "docs: register F-044 and F-045 in the audit governance contract

F-044 is the live defect: round-then-threshold classification stamped a false
exhausted label on a department at 99.95%, and assertAcknowledged turned that
label into a hard block on PO/PR approval in every enforcement mode.

F-045 is latent and recorded as such: every amount_threshold is NULL, no steps
JSON carries a threshold, and purchasing.urgent_skip_limit is 0, so the float
comparisons in the approval chain decide nothing today.

Gate count raised to 45, kept explicit so registry growth stays reviewable.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Full-suite and CI verification

**Files:** none modified. This task produces evidence.

**Interfaces:**
- Consumes: Tasks 1–7 complete.
- Produces: the measured result satisfying the spec's exit criteria.

- [ ] **Step 1: Confirm no stray PHPUnit process**

```bash
docker compose up -d db api && sleep 15
docker compose exec -T api ps aux | grep -c '[p]hpunit'
```

Expected: `0`. If not, wait — do not start a second run.

- [ ] **Step 2: Raise the PHP memory limit**

```bash
docker compose exec -T -u root api bash -c "echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/zz-mem.ini"
```

- [ ] **Step 3: Run the full suite serially to completion**

```bash
git rev-parse HEAD
docker compose exec -T api php artisan test --without-tty 2>&1 | tail -25
git rev-parse HEAD
```

Expected: green. The baseline before this tranche was `Tests: 1815, Assertions: 9106`; this tranche adds roughly 17 tests, so expect about 1832 and a higher assertion count. Runtime ~15 minutes — run it detached with a timeout of at least 20 minutes so your own tooling cannot kill it partway. Report both HEAD values; if HEAD moved during the run, say so, because it is then no longer a clean measurement of a fixed tree.

If any pre-existing test fails, record it with file, test name and error, then stop and report — do not expand scope.

- [ ] **Step 4: Confirm every exit criterion**

```bash
docker compose exec -T api vendor/bin/phpstan analyse app --memory-limit=1G; echo "1. phpstan=$?"
node scripts/verify-audit-finding-lifecycle.mjs;                            echo "2. lifecycle=$?"
node scripts/verify-audit-acceptance-manifest.mjs;                          echo "3. manifest=$?"
```

Expected: all exit 0, both validators reporting 45.

- [ ] **Step 5: Push and observe the real CI run**

```bash
git push origin main
sleep 120
gh run list --limit 3
```

Then wait for `API tests` to complete and confirm success:

```bash
gh run list --limit 1 --workflow "API tests"
```

**This step is required, not optional.** Tranche A established the rule the hard way: `api/.env.testing` exists on a developer machine and not in CI, so local verification passed while CI had been broken the whole time. Do not report this tranche complete on local evidence alone.

If `API tests` fails, read the failing step's log (`gh run view <id> --log-failed`) and report the actual error rather than inferring where it failed.

- [ ] **Step 6: Record the measured evidence**

If Task 7's lifecycle rows used estimated counts, replace them with the measured values now and commit:

```bash
git add docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json
git commit -m "docs: record measured Tranche B regression evidence"
git push origin main
```

If the counts were already exact, skip this step.

---

## Out of scope

- Making the budgeting API and `spa/src/types/budgeting.ts` string-typed
- Adding `decimal:2` casts to `Budget` — no correctness effect, the columns already return exact strings
- `CustomerResource`'s `credit_available`, which already follows the string convention
- Tranches C–E: the 75 hot unindexed FK join keys, the DTR import N+1, payslip DOLE fields, error tracking, dead-scheduler alerting, the hygiene sweep
- F-042 (`phpunit.xml` `APP_ENV` without `force="true"`), still deliberately open
- The three pre-existing vacuous gates F-009, F-015 and F-037, whose `--filter` strings match no tests
