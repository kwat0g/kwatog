# Role-Responsive Dashboard Pack Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the existing permission-derived dashboard registry *depth* — typed widgets that render breakdowns, trends, tables, and gauges instead of a single scalar number — plus analytics for the six domains that have none, a widget picker, and a real 12-column grid.

**Architecture:** A `render_kind` column on `dashboard_widgets` turns each widget into a discriminated union. A new read-only `WidgetAnalyticsService` produces the four rich payload shapes; the existing `DashboardWidgetDataService` keeps serving scalars unchanged. `GET /dashboard/layout?rich=1` returns the same permission-stripped widget set with `data` nested per widget. The SPA switches on `render_kind` and delegates to chart primitives that already exist. Scope (company-wide vs department) moves out of an inline lookup into a `WidgetScope` support class.

**Tech Stack:** Laravel 11 / PHP 8.3, PostgreSQL 16, React 18 + TypeScript + Vite, TanStack Query, recharts, Tailwind (Atelier tokens), PHPUnit, Vitest, Docker Compose.

## Global Constraints

- `declare(strict_types=1);` at the top of every PHP file.
- New services are `final class`. Read-only analytics — **no `DB::transaction()`** in the new path (only writes need it; layout save/reset already wrap themselves at `DashboardLayoutService.php:110, 149`).
- HashIDs in URLs and API responses, never raw integer `id` (`HasHashId` trait). **Not ULIDs.**
- Design system is **Atelier** — warm paper `#fdfcfa`, espresso ink `#1f1b16`, clay accent `#b4542a`, opaque surfaces, no `backdrop-blur`. **Not grayscale SAP/Linear.**
- Never hardcode a colour. Every value comes from `spa/src/styles/tokens.css` (`var(--accent)`, `var(--success)`, `var(--warning)`, `var(--danger)`, `var(--text-muted)`). CI gate: `npm run audit:tokens`.
- Numbers render with `font-mono tabular-nums`.
- Permission gating is unchanged and non-negotiable: `render_kind` is presentation only. `dashboard_widgets.permission` remains the sole visibility gate (`DashboardLayoutService.php:65`).
- **No `role ===` / `role->slug ===` branch may be introduced anywhere.** Tiering stays a byproduct of permission matching.
- Migrations are 4-digit numbered. Highest existing is `0441`; this plan adds `0442` only.
- New migrations follow the `return new class extends Migration` anonymous-class style with a docblock explaining *why* (see `0441_seed_payroll_date_grace_setting.php`).
- Test seeds: varchar columns are mostly 20 chars. Use `'XX-T-'.substr(uniqid(), -5)`, never `'XX-TEST-'.uniqid()`.
- User+role in tests: `User::factory()->create(['role_id' => Role::query()->where('slug', X)->value('id')])`. Never `assignRole()`.
- Full PHP suite needs a memory bump first:
  `docker compose exec -T -u root api bash -c "echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/zz-mem.ini"`
- Full suite is 1242 tests / ~9 min. Use `--filter` for tight loops; run the whole suite only at the end.
- Commit after each task: `feat: <description>` or `test: <description>`.

## Scope note — 16 widgets enriched, not 24

The spec's enrichment table lists ~24 candidate widgets. This plan enriches **16** — the ones whose data source either already exists or is built here in Task 4:

`qc.pareto`, `production.wo_breakdown`, `production.kpi`, `machine.utilization`, `oee.gauges`, `finance.ar_aging`, `hr.headcount`, `purchasing.open_pos`, `crm.open_complaints`, `assets.under_maintenance`, `supply.overdue_deliveries`, `supply.delivery_schedule`, `rma.open_returns`, `rma.pending_approval`, `budget.utilization`, `loans.outstanding`.

The remaining candidates (`finance.ap_aging`, `finance.cash_position`, `finance.revenue_mtd`, `finance.upcoming_payables`, `qc.pass_rate`, `hr.on_leave_today`, `hr.team_*`, `hr.probation_alerts`, `inventory.*`, `maintenance.*`, `purchasing.supplier_perf`, `forecast.*`) keep their current scalar rendering. They are not blocked — each is one `handles()` entry plus one private method in the matching provider, following the patterns in Tasks 3–4. They are left out so this plan lands as reviewable work rather than a 40-file change; the seed-integrity test in Task 5 guarantees the two lists stay in agreement as they are added.

**Why these 16:** they cover every one of the six previously scalar-only domains (the user's explicit ask) plus the highest-traffic widgets on the four densest role layouts, so the depth change is visible on every role's dashboard from the first deploy.

---

## File Structure

**Backend — create**

| File | Responsibility |
|---|---|
| `api/database/migrations/0442_add_render_kind_to_dashboard_widgets.php` | Adds the `render_kind` column. |
| `api/app/Modules/Dashboard/Enums/RenderKind.php` | The five kinds + which payload key each carries. |
| `api/app/Modules/Dashboard/Support/WidgetScope.php` | The one place widget scope is decided (`departmentId`, `isCompanyWide`). |
| `api/app/Modules/Dashboard/Services/WidgetAnalyticsService.php` | Dispatcher: key → rich payload. Thin; delegates to the six providers. |
| `api/app/Modules/Dashboard/Services/Analytics/CoreWidgetAnalytics.php` | Rich payloads for the already-analytics-ready domains (production, quality, finance, hr, purchasing, inventory, maintenance, forecast, chain). |
| `api/app/Modules/Dashboard/Services/Analytics/CrmWidgetAnalytics.php` | New: SO pipeline, complaints by status, complaint→NCR lag. |
| `api/app/Modules/Dashboard/Services/Analytics/AssetWidgetAnalytics.php` | New: register summary, depreciation history. |
| `api/app/Modules/Dashboard/Services/Analytics/SupplyChainWidgetAnalytics.php` | New: delivery on-time %, shipments by status. |
| `api/app/Modules/Dashboard/Services/Analytics/ReturnWidgetAnalytics.php` | New: returns by status, disposition mix, cycle time. |
| `api/app/Modules/Dashboard/Services/Analytics/BudgetWidgetAnalytics.php` | New: utilization by line, top over-budget. |
| `api/app/Modules/Dashboard/Services/Analytics/LoanWidgetAnalytics.php` | New: outstanding by type, by department. |

**Backend — modify**

| File | Change |
|---|---|
| `api/app/Modules/Dashboard/Models/DashboardWidget.php:15-28` | Add `render_kind` to `$fillable` + cast to `RenderKind`. |
| `api/database/seeders/DashboardWidgetSeeder.php:25-116` | Declare `render_kind` per widget. |
| `api/app/Modules/Dashboard/Services/DashboardLayoutService.php` | `getEffectiveLayout()` emits `render_kind`; new `getRichLayout()`. |
| `api/app/Modules/Dashboard/Controllers/DashboardLayoutController.php` | `layout()` honours `?rich=1`. |
| `api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:68-69` | Delegate the department lookup to `WidgetScope`. |
| `api/database/seeders/DashboardRoleLayoutSeeder.php:132-133` | Real `w`/`h` per widget instead of `12`/`4` for every row. |

**Frontend — create**

| File | Responsibility |
|---|---|
| `spa/src/components/dashboard/WidgetTable.tsx` | Compact table for `table` widgets. |
| `spa/src/components/dashboard/WidgetBreakdown.tsx` | Segment bar + legend for `breakdown` widgets. |
| `spa/src/components/dashboard/DashboardPicker.tsx` | Add / remove / reorder widgets. |
| `spa/src/components/dashboard/registry.test.tsx` | One test per render kind + unknown-kind fallback. |
| `spa/src/components/dashboard/DashboardPicker.test.tsx` | Add, remove, save, cancel. |

**Frontend — modify**

| File | Change |
|---|---|
| `spa/src/api/dashboard-layout.ts:18-30, 79-82` | `render_kind` + `data` on the layout item; `layout({ rich })`. |
| `spa/src/components/dashboard/registry.tsx:76-123` | `LiveDashboardWidget` switches on `render_kind`. |
| `spa/src/pages/dashboard/default.tsx:97` | Width-aware 12-col grid; mount `DashboardPicker`. |

---

### Task 1: `render_kind` column, enum, and model cast

**Files:**
- Create: `api/database/migrations/0450_add_render_kind_to_dashboard_widgets.php` (0442 was already taken by two migrations; highest 4-digit at execution time was 0449)
- Create: `api/app/Modules/Dashboard/Enums/RenderKind.php`
- Modify: `api/app/Modules/Dashboard/Models/DashboardWidget.php:15-28`
- Test: `api/tests/Feature/Dashboard/RenderKindTest.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces:
  - `App\Modules\Dashboard\Enums\RenderKind` — backed string enum, cases `Scalar='scalar'`, `Breakdown='breakdown'`, `Trend='trend'`, `Table='table'`, `Gauge='gauge'`; static `RenderKind::fromNullable(?string): RenderKind` returning `Scalar` for null/unknown.
  - `DashboardWidget::$render_kind` cast to `RenderKind`.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Feature/Dashboard/RenderKindTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Enums\RenderKind;
use App\Modules\Dashboard\Models\DashboardWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenderKindTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_render_kind_defaults_to_scalar(): void
    {
        $widget = DashboardWidget::create([
            'key' => 'test.widget',
            'name' => 'Test Widget',
            'module' => 'platform',
            'permission' => null,
        ]);

        $this->assertSame(RenderKind::Scalar, $widget->fresh()->render_kind);
    }

    public function test_widget_render_kind_round_trips(): void
    {
        $widget = DashboardWidget::create([
            'key' => 'test.trend',
            'name' => 'Test Trend',
            'module' => 'platform',
            'permission' => null,
            'render_kind' => RenderKind::Trend,
        ]);

        $this->assertSame(RenderKind::Trend, $widget->fresh()->render_kind);
    }

    /**
     * An unrecognised value must degrade to a scalar tile, never throw — a
     * stale row from a rolled-back deploy must not break every dashboard.
     */
    public function test_unknown_kind_falls_back_to_scalar(): void
    {
        $this->assertSame(RenderKind::Scalar, RenderKind::fromNullable('sparkline'));
        $this->assertSame(RenderKind::Scalar, RenderKind::fromNullable(null));
        $this->assertSame(RenderKind::Gauge, RenderKind::fromNullable('gauge'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test --filter=RenderKindTest`
Expected: FAIL — `Class "App\Modules\Dashboard\Enums\RenderKind" not found`.

- [ ] **Step 3: Write the migration**

Create `api/database/migrations/0442_add_render_kind_to_dashboard_widgets.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a widget draws itself.
 *
 * Every widget previously rendered as one scalar number, so a GROUP BY was
 * flattened into a helper string (DashboardWidgetDataService::breakdown) and a
 * Pareto, a trend and a count all looked identical. This column carries the
 * shape; `permission` still carries visibility. Presentation and access stay
 * separate concerns — a widget never becomes visible by changing how it draws.
 *
 * Defaults to 'scalar' so every existing row keeps its current rendering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table): void {
            $table->string('render_kind', 20)->default('scalar')->after('permission');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table): void {
            $table->dropColumn('render_kind');
        });
    }
};
```

- [ ] **Step 4: Write the enum**

Create `api/app/Modules/Dashboard/Enums/RenderKind.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Enums;

/**
 * How a dashboard widget draws itself. Presentation only — visibility is
 * still decided solely by `dashboard_widgets.permission`.
 */
enum RenderKind: string
{
    case Scalar = 'scalar';
    case Breakdown = 'breakdown';
    case Trend = 'trend';
    case Table = 'table';
    case Gauge = 'gauge';

    /**
     * Unknown or missing kinds degrade to a scalar tile rather than throwing:
     * a stale row left by a rolled-back deploy must not break every dashboard
     * that happens to include it.
     */
    public static function fromNullable(?string $value): self
    {
        return $value === null ? self::Scalar : (self::tryFrom($value) ?? self::Scalar);
    }

    /** The payload key this kind carries in the rich layout response. */
    public function payloadKey(): string
    {
        return match ($this) {
            self::Scalar => 'value',
            self::Breakdown => 'segments',
            self::Trend => 'points',
            self::Table => 'rows',
            self::Gauge => 'value',
        };
    }
}
```

- [ ] **Step 5: Add the cast to the model**

In `api/app/Modules/Dashboard/Models/DashboardWidget.php`, add the import, the fillable entry, and the cast:

```php
use App\Modules\Dashboard\Enums\RenderKind;
```

```php
    protected $fillable = [
        'key',
        'name',
        'description',
        'module',
        'permission',
        'render_kind',
        'default_w',
        'default_h',
    ];

    protected $casts = [
        'render_kind' => RenderKind::class,
        'default_w' => 'integer',
        'default_h' => 'integer',
    ];
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec -T api php artisan test --filter=RenderKindTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add api/database/migrations/0442_add_render_kind_to_dashboard_widgets.php \
        api/app/Modules/Dashboard/Enums/RenderKind.php \
        api/app/Modules/Dashboard/Models/DashboardWidget.php \
        api/tests/Feature/Dashboard/RenderKindTest.php
git commit -m "feat: render_kind on dashboard widgets"
```

---

### Task 2: `WidgetScope` — one place widget scope is decided

**Files:**
- Create: `api/app/Modules/Dashboard/Support/WidgetScope.php`
- Modify: `api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:68-69, 248-266`
- Test: `api/tests/Feature/Dashboard/WidgetScopeTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces:
  - `App\Modules\Dashboard\Support\WidgetScope` (final class), instantiable and injectable:
    - `departmentId(User $user): ?int` — the `employees.department_id` of the user's linked employee; `null` when the user has no `employee_id` or the employee has no department.
    - `isCompanyWide(User $user, string $permission): bool` — `$user->hasPermission($permission)`; the single seam every widget uses to ask "may this viewer see the whole company?". Wraps `hasPermission` so the `system_admin` short-circuit (`User.php:149-155`) is honoured everywhere.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Feature/Dashboard/WidgetScopeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Support\WidgetScope;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WidgetScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_department_id_resolves_from_the_linked_employee(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => $employee->id,
        ]);

        $this->assertSame($department->id, app(WidgetScope::class)->departmentId($user));
    }

    public function test_department_id_is_null_without_a_linked_employee(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
            'employee_id' => null,
        ]);

        $this->assertNull(app(WidgetScope::class)->departmentId($user));
    }

    /**
     * The company-wide gate must honour the system_admin short-circuit in
     * User::hasPermission — admin's cached slug array does NOT contain every
     * permission, so an in_array check here would wrongly scope admin down.
     */
    public function test_company_wide_follows_permission_including_system_admin(): void
    {
        $scope = app(WidgetScope::class);

        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $deptHead = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
        ]);

        $this->assertTrue($scope->isCompanyWide($admin, 'loans.write_off'));
        $this->assertFalse($scope->isCompanyWide($deptHead, 'loans.write_off'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test --filter=WidgetScopeTest`
Expected: FAIL — `Class "App\Modules\Dashboard\Support\WidgetScope" not found`.

- [ ] **Step 3: Write `WidgetScope`**

Create `api/app/Modules/Dashboard/Support/WidgetScope.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Support;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one place a widget asks "whose data may this viewer see?".
 *
 * Widget scope used to be an inline lookup in DashboardWidgetDataService
 * (`$departmentId = ... value('department_id')`) repeated per call site, which
 * is how the company-wide `hr.on_leave_today` shipped gated on the
 * self-service `leave.view` and showed every role the whole company's leave
 * roster. Concentrating it here means a widget states its intent
 * ("company-wide under X, else my department") in one readable line.
 *
 * Deliberately scoped to widgets. The ad-hoc controller scoping — a literal
 * role-slug compare in LoanController, a permission proxy in
 * LeaveRequestController — is NOT unified here; that is a separate refactor
 * this class gives a home to grow into.
 */
final class WidgetScope
{
    /** The department of the user's linked employee, or null when unlinked. */
    public function departmentId(User $user): ?int
    {
        if (! $user->employee_id) {
            return null;
        }

        $id = DB::table('employees')
            ->where('id', (int) $user->employee_id)
            ->value('department_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Whether this viewer may see the whole company for the domain gated by
     * $permission. Delegates to hasPermission so the system_admin
     * short-circuit (User::hasPermission) is honoured — a plain in_array over
     * the cached slug array would wrongly scope admin down to a department.
     */
    public function isCompanyWide(User $user, string $permission): bool
    {
        return $user->hasPermission($permission);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T api php artisan test --filter=WidgetScopeTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Route the existing service through it**

In `api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php`, inject the scope and replace the two inline lookups.

Constructor becomes:

```php
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ForecastingDashboardService $forecasts,
        private readonly WidgetScope $scope,
    ) {}
```

Add the import `use App\Modules\Dashboard\Support\WidgetScope;`, then replace line 69:

```php
        $departmentId = $this->scope->departmentId($user);
```

and in `outstandingLoans()` replace the `hasPermission` call:

```php
        $companyWide = $this->scope->isCompanyWide($user, 'loans.write_off');
```

- [ ] **Step 6: Run the dashboard suite to prove nothing regressed**

Run: `docker compose exec -T api php artisan test --filter='WidgetScope|DashboardWidgetData|DashboardDispatch'`
Expected: PASS — existing widget-data tests still green.

- [ ] **Step 7: Commit**

```bash
git add api/app/Modules/Dashboard/Support/WidgetScope.php \
        api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php \
        api/tests/Feature/Dashboard/WidgetScopeTest.php
git commit -m "feat: WidgetScope — one seam for widget data scoping"
```

---

### Task 3: Payload contracts + `WidgetAnalyticsService` dispatcher

**Files:**
- Create: `api/app/Modules/Dashboard/Services/WidgetAnalyticsService.php`
- Create: `api/app/Modules/Dashboard/Services/Analytics/CoreWidgetAnalytics.php`
- Test: `api/tests/Feature/Dashboard/WidgetAnalyticsServiceTest.php`

**Interfaces:**
- Consumes: `RenderKind` (Task 1), `WidgetScope` (Task 2).
- Produces:
  - `App\Modules\Dashboard\Services\WidgetAnalyticsService` (final class) with
    `payload(string $key, RenderKind $kind, User $user): array` — returns the rich payload for `$key`, or `[]` when no provider handles it (caller then falls back to the scalar path).
  - `App\Modules\Dashboard\Services\Analytics\CoreWidgetAnalytics` (final class) with `handles(): array` (list of widget keys) and `payload(string $key, User $user): array`.
  - Payload shapes every provider must honour, keyed by kind:
    - `breakdown` → `['total' => int|float, 'segments' => [['label' => string, 'value' => int|float, 'tone' => 'neutral'|'success'|'warning'|'danger']]]`
    - `trend` → `['points' => [['label' => string, 'value' => int|float]], 'delta' => float|null, 'kind' => 'count'|'currency'|'percent'|'hours']`
    - `table` → `['columns' => [['key' => string, 'label' => string, 'align' => 'left'|'right']], 'rows' => array<array<string, scalar|null>>, 'total_count' => int]`
    - `gauge` → `['value' => float, 'target' => float|null, 'min' => float, 'max' => float, 'kind' => 'percent'|'count']`

- [ ] **Step 1: Write the failing test**

Create `api/tests/Feature/Dashboard/WidgetAnalyticsServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Enums\RenderKind;
use App\Modules\Dashboard\Services\WidgetAnalyticsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WidgetAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    public function test_unknown_key_returns_empty_so_caller_falls_back_to_scalar(): void
    {
        $payload = app(WidgetAnalyticsService::class)
            ->payload('no.such.widget', RenderKind::Breakdown, $this->admin);

        $this->assertSame([], $payload);
    }

    public function test_breakdown_payload_has_total_and_toned_segments(): void
    {
        $payload = app(WidgetAnalyticsService::class)
            ->payload('qc.pareto', RenderKind::Breakdown, $this->admin);

        $this->assertArrayHasKey('total', $payload);
        $this->assertArrayHasKey('segments', $payload);
        foreach ($payload['segments'] as $segment) {
            $this->assertArrayHasKey('label', $segment);
            $this->assertArrayHasKey('value', $segment);
            $this->assertContains($segment['tone'], ['neutral', 'info', 'success', 'warning', 'danger']);
        }
    }

    public function test_trend_payload_points_are_chronological(): void
    {
        $payload = app(WidgetAnalyticsService::class)
            ->payload('production.kpi', RenderKind::Trend, $this->admin);

        $this->assertArrayHasKey('points', $payload);
        $this->assertContains($payload['kind'], ['count', 'currency', 'percent', 'hours']);
        $labels = array_column($payload['points'], 'label');
        $sorted = $labels;
        sort($sorted);
        $this->assertSame($sorted, $labels, 'trend points must be oldest-first');
    }

    /**
     * A widget must never fail its whole dashboard. A provider that throws is
     * reported as an empty payload so the tile degrades to a scalar.
     */
    public function test_provider_failure_degrades_instead_of_throwing(): void
    {
        \Illuminate\Support\Facades\Schema::drop('inspection_measurements');

        $payload = app(WidgetAnalyticsService::class)
            ->payload('qc.pareto', RenderKind::Breakdown, $this->admin);

        $this->assertSame([], $payload);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test --filter=WidgetAnalyticsServiceTest`
Expected: FAIL — `Class "App\Modules\Dashboard\Services\WidgetAnalyticsService" not found`.

- [ ] **Step 3: Write the dispatcher**

Create `api/app/Modules/Dashboard/Services/WidgetAnalyticsService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Enums\RenderKind;
use App\Modules\Dashboard\Services\Analytics\AssetWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\BudgetWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\CoreWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\CrmWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\LoanWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\ReturnWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\SupplyChainWidgetAnalytics;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a widget key into a rich payload (breakdown / trend / table / gauge).
 *
 * Read-only by construction — no transaction, no writes. Scalars are NOT
 * handled here; DashboardWidgetDataService remains the scalar path, so this
 * service is purely additive and a widget with no provider keeps working.
 */
final class WidgetAnalyticsService
{
    public function __construct(
        private readonly CoreWidgetAnalytics $core,
        private readonly CrmWidgetAnalytics $crm,
        private readonly AssetWidgetAnalytics $assets,
        private readonly SupplyChainWidgetAnalytics $supplyChain,
        private readonly ReturnWidgetAnalytics $returns,
        private readonly BudgetWidgetAnalytics $budgets,
        private readonly LoanWidgetAnalytics $loans,
    ) {}

    /**
     * The rich payload for $key, or [] when nothing handles it — the caller
     * then renders the scalar. Returning [] rather than throwing is what keeps
     * one broken domain from blanking every tile on the page.
     *
     * @return array<string, mixed>
     */
    public function payload(string $key, RenderKind $kind, User $user): array
    {
        if ($kind === RenderKind::Scalar) {
            return [];
        }

        foreach ($this->providers() as $provider) {
            if (! in_array($key, $provider->handles(), true)) {
                continue;
            }

            try {
                return $provider->payload($key, $user);
            } catch (Throwable $e) {
                Log::warning('dashboard widget analytics failed', [
                    'widget' => $key,
                    'kind' => $kind->value,
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        }

        return [];
    }

    /** @return list<object{handles: callable, payload: callable}> */
    private function providers(): array
    {
        return [
            $this->core,
            $this->crm,
            $this->assets,
            $this->supplyChain,
            $this->returns,
            $this->budgets,
            $this->loans,
        ];
    }
}
```

- [ ] **Step 4: Write the core provider**

Create `api/app/Modules/Dashboard/Services/Analytics/CoreWidgetAnalytics.php`. This covers the domains discovery found already analytics-ready:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Support\WidgetScope;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Quality\Services\DefectParetoService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rich payloads for the domains that already had aggregate queries.
 * Read-only. Every method returns one of the four documented shapes.
 */
final class CoreWidgetAnalytics
{
    public function __construct(
        private readonly WidgetScope $scope,
        private readonly DefectParetoService $pareto,
    ) {}

    /** @return list<string> */
    public function handles(): array
    {
        // These are the EXISTING seeded widget keys (DashboardWidgetSeeder).
        // Enrichment upgrades widgets users already have on their layouts —
        // inventing new keys would create widgets no layout references.
        return [
            'qc.pareto',
            'production.wo_breakdown',
            'production.kpi',
            'machine.utilization',
            'oee.gauges',
            'finance.ar_aging',
            'hr.headcount',
            'purchasing.open_pos',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        return match ($key) {
            'qc.pareto' => $this->defectPareto(),
            // wo_breakdown is a status mix (a breakdown); production.kpi is the
            // daily output figure, which is what carries a 14-day trend.
            'production.wo_breakdown' => $this->woStatusMix(),
            'production.kpi' => $this->outputTrend(),
            'machine.utilization', 'oee.gauges' => $this->oeeGauge(),
            'finance.ar_aging' => $this->arAging(),
            'hr.headcount' => $this->headcount($user),
            'purchasing.open_pos' => $this->poStatusMix(),
            default => [],
        };
    }

    /**
     * Top defect parameters. Delegates to DefectParetoService — the Quality
     * module already owns this aggregation (is_pass=false over
     * inspection_measurements joined to inspections, with the portable
     * BOOL_OR critical aggregate). Re-deriving it here would fork the
     * definition of "a defect" between two places.
     */
    private function defectPareto(): array
    {
        $result = $this->pareto->run(['limit' => 6]);

        return [
            'total' => (int) $result['total_defects'],
            'segments' => array_map(fn (array $row) => [
                'label' => $row['parameter_name'],
                'value' => $row['defect_count'],
                // A critical parameter is the one to fix first; the tone is
                // what makes that readable at a glance on the tile.
                'tone' => $row['is_critical'] ? 'danger' : 'warning',
            ], $result['rows']),
        ];
    }

    /** Good output per day, trailing 14 days, zero-filled. */
    private function outputTrend(): array
    {
        $rows = DB::table('work_order_outputs')
            ->selectRaw('DATE(recorded_at) as day, COALESCE(SUM(good_count), 0) as value')
            ->where('recorded_at', '>=', Carbon::now()->subDays(14)->startOfDay())
            ->groupBy('day')
            ->pluck('value', 'day');

        $points = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = Carbon::now()->subDays($i)->toDateString();
            $points[] = ['label' => $day, 'value' => (int) ($rows[$day] ?? 0)];
        }

        $first = $points[0]['value'];
        $last = end($points)['value'];

        return [
            'points' => $points,
            'delta' => $first > 0 ? round((($last - $first) / $first) * 100, 1) : null,
            'kind' => 'count',
        ];
    }

    /** Trailing-7-day availability as an OEE-style gauge. */
    private function oeeGauge(): array
    {
        $downtime = (float) DB::table('machine_downtimes')
            ->where('start_time', '>=', Carbon::now()->subDays(7))
            ->sum('duration_minutes');

        $machines = max(1, (int) DB::table('machines')->count());
        $capacity = $machines * 7 * 24 * 60;
        $availability = $capacity > 0 ? max(0.0, min(100.0, (1 - ($downtime / $capacity)) * 100)) : 0.0;

        return [
            'value' => round($availability, 1),
            'target' => 85.0,
            'min' => 0.0,
            'max' => 100.0,
            'kind' => 'percent',
        ];
    }

    /**
     * Work orders by status — the real breakdown that `production.wo_breakdown`
     * has always been describing, except `DashboardWidgetDataService::breakdown()`
     * flattened it into a helper string and threw the segments away.
     */
    private function woStatusMix(): array
    {
        $tone = [
            WorkOrderStatus::Planned->value => 'neutral',
            WorkOrderStatus::Confirmed->value => 'info',
            WorkOrderStatus::InProgress->value => 'success',
            WorkOrderStatus::Paused->value => 'warning',
            WorkOrderStatus::Completed->value => 'success',
            WorkOrderStatus::Closed->value => 'neutral',
            WorkOrderStatus::Cancelled->value => 'danger',
        ];

        $rows = DB::table('work_orders')
            ->selectRaw('status as label, COUNT(*) as value')
            ->groupBy('status')
            ->orderByDesc('value')
            ->get();

        return [
            'total' => (int) $rows->sum('value'),
            'segments' => $rows->map(fn ($r) => [
                'label' => (string) $r->label,
                'value' => (int) $r->value,
                'tone' => $tone[(string) $r->label] ?? 'neutral',
            ])->values()->all(),
        ];
    }

    /**
     * Open receivables bucketed by age. Ages on `balance`, not
     * `total_amount` — a partially paid invoice is only outstanding for what
     * is left, and InvoiceStatus::Partial rows would otherwise be counted at
     * full face value.
     */
    private function arAging(): array
    {
        $buckets = [
            ['label' => 'Current', 'from' => -100000, 'to' => 0, 'tone' => 'success'],
            ['label' => '1-30', 'from' => 1, 'to' => 30, 'tone' => 'neutral'],
            ['label' => '31-60', 'from' => 31, 'to' => 60, 'tone' => 'warning'],
            ['label' => '60+', 'from' => 61, 'to' => 100000, 'tone' => 'danger'],
        ];

        $segments = [];
        $total = 0.0;
        foreach ($buckets as $bucket) {
            $amount = (float) DB::table('invoices')
                ->whereIn('status', [InvoiceStatus::Finalized->value, InvoiceStatus::Partial->value])
                ->where('balance', '>', 0)
                ->whereRaw('CURRENT_DATE - due_date BETWEEN ? AND ?', [$bucket['from'], $bucket['to']])
                ->sum('balance');

            $total += $amount;
            $segments[] = [
                'label' => $bucket['label'],
                'value' => round($amount, 2),
                'tone' => $bucket['tone'],
            ];
        }

        return ['total' => round($total, 2), 'segments' => $segments];
    }

    /**
     * Active headcount per department. Department-scoped viewers see only
     * their own row — the same permission gate the HR widgets already use.
     */
    private function headcount(User $user): array
    {
        $query = DB::table('employees')
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->selectRaw('departments.name as label, COUNT(*) as value')
            ->where('employees.status', 'active')
            ->whereNull('employees.deleted_at')
            ->groupBy('departments.name')
            ->orderByDesc('value');

        if (! $this->scope->isCompanyWide($user, 'hr.employees.view')) {
            $departmentId = $this->scope->departmentId($user);
            if ($departmentId === null) {
                return ['total' => 0, 'segments' => []];
            }
            $query->where('employees.department_id', $departmentId);
        }

        $rows = $query->limit(8)->get();

        return [
            'total' => (int) $rows->sum('value'),
            'segments' => $rows->map(fn ($r) => [
                'label' => (string) $r->label,
                'value' => (int) $r->value,
                'tone' => 'neutral',
            ])->values()->all(),
        ];
    }

    /** Open purchase orders by status. Statuses read from PurchaseOrderStatus. */
    private function poStatusMix(): array
    {
        $tone = [
            'draft' => 'neutral',
            'pending_approval' => 'warning',
            'approved' => 'success',
            'sent' => 'success',
            'partially_received' => 'warning',
            'received' => 'success',
            'closed' => 'neutral',
            'cancelled' => 'danger',
        ];

        $rows = DB::table('purchase_orders')
            ->selectRaw('status as label, COUNT(*) as value')
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->orderByDesc('value')
            ->get();

        return [
            'total' => (int) $rows->sum('value'),
            'segments' => $rows->map(fn ($r) => [
                'label' => (string) $r->label,
                'value' => (int) $r->value,
                'tone' => $tone[$r->label] ?? 'neutral',
            ])->values()->all(),
        ];
    }
}
```

- [ ] **Step 5: Stub the six new-domain providers so the container resolves**

Each is a real file with `handles(): array { return []; }` and `payload(string $key, User $user): array { return []; }`, filled in by Task 4. Create all six now — `CrmWidgetAnalytics`, `AssetWidgetAnalytics`, `SupplyChainWidgetAnalytics`, `ReturnWidgetAnalytics`, `BudgetWidgetAnalytics`, `LoanWidgetAnalytics` — in `api/app/Modules/Dashboard/Services/Analytics/`, each shaped like:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;

/** Filled in by Task 4. */
final class CrmWidgetAnalytics
{
    /** @return list<string> */
    public function handles(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        return [];
    }
}
```

- [ ] **Step 6: Confirm the schema this provider reads**

Every column above was verified against the live database while writing this plan. Re-confirm before running the tests — a migration may have landed since:

```bash
docker compose exec -T db psql -U ogami -d ogami -c "\d work_order_outputs" | grep -E "good_count|recorded_at"
docker compose exec -T db psql -U ogami -d ogami -c "\d machine_downtimes" | grep -E "duration_minutes|category|start_time"
docker compose exec -T db psql -U ogami -d ogami -c "\d invoices" | grep -E "due_date|balance|status"
docker compose exec -T db psql -U ogami -d ogami -c "\d purchase_orders" | grep -E "status|deleted_at"
```

Expected: every grep prints its columns. Note the traps already corrected here — `work_order_outputs` has `good_count`/`recorded_at` (not `good_quantity`/`created_at`); `machine_downtimes` has `category` (there is no `reason`); AR ages on `balance` (not `total_amount`, which double-counts partially paid invoices); `non_conformance_reports` has no `defect_type` column at all, which is why the Pareto delegates to `DefectParetoService`.

- [ ] **Step 7: Run test to verify it passes**

Run: `docker compose exec -T api php artisan test --filter=WidgetAnalyticsServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 8: Commit**

```bash
git add api/app/Modules/Dashboard/Services/WidgetAnalyticsService.php \
        api/app/Modules/Dashboard/Services/Analytics/ \
        api/tests/Feature/Dashboard/WidgetAnalyticsServiceTest.php
git commit -m "feat: widget analytics dispatcher + core rich payloads"
```

---

### Task 4: The six new-domain analytics providers

**Files:**
- Modify: `api/app/Modules/Dashboard/Services/Analytics/CrmWidgetAnalytics.php`
- Modify: `api/app/Modules/Dashboard/Services/Analytics/AssetWidgetAnalytics.php`
- Modify: `api/app/Modules/Dashboard/Services/Analytics/SupplyChainWidgetAnalytics.php`
- Modify: `api/app/Modules/Dashboard/Services/Analytics/ReturnWidgetAnalytics.php`
- Modify: `api/app/Modules/Dashboard/Services/Analytics/BudgetWidgetAnalytics.php`
- Modify: `api/app/Modules/Dashboard/Services/Analytics/LoanWidgetAnalytics.php`
- Test: `api/tests/Feature/Dashboard/NewDomainAnalyticsTest.php`

**Interfaces:**
- Consumes: `WidgetScope` (Task 2); the stub files created in Task 3 Step 5.
- Produces: `handles()` / `payload()` filled in on all six, covering the seeded keys `crm.open_complaints`, `assets.under_maintenance`, `supply.overdue_deliveries`, `supply.delivery_schedule`, `rma.open_returns`, `rma.pending_approval`, `budget.utilization`, `loans.outstanding`.

**Schema verified while writing this plan — use these exact columns:**

| Table | Columns you need |
|---|---|
| `customer_complaints` | `status`, `severity`, `received_date`, `resolved_at`, `closed_at`, `ncr_id` |
| `assets` | `status`, `category`, `acquisition_cost`, `accumulated_depreciation`, `department_id`, `deleted_at` |
| `asset_depreciations` | `asset_id`, `period_year`, `period_month`, `depreciation_amount` |
| `deliveries` | `status`, `scheduled_date`, `delivered_at`, `confirmed_at`, `deleted_at` |
| `shipments` | `status`, `etd`, `eta`, `ata`, `deleted_at` |
| `return_requests` | `status`, `type`, `disposition_status`, `return_date`, `completed_at`, `deleted_at` |
| `budgets` | `status`, `total_allocated`, `total_spent`, `total_committed`, `department_id`, `fiscal_year_id` |
| `budget_line_items` | `budget_id`, `account_id`, `annual_total`, `actual_total`, `variance` |
| `employee_loans` | `status`, `loan_type`, `balance`, `employee_id` |

Enum values (verified): `ReturnRequestStatus` = draft, pending_approval, approved, received, inspected, completed, rejected, cancelled · `DispositionType` = scrap, rework, restock, return_to_supplier · `ShipmentStatus` = ordered, shipped, in_transit, customs, cleared, received, cancelled · `DeliveryStatus` = scheduled, in_transit, delivered, confirmed, cancelled · `AssetStatus` = active, under_maintenance, disposed · `ComplaintStatus` includes investigating · `loan_type` = company_loan, cash_advance.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Feature/Dashboard/NewDomainAnalyticsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Enums\RenderKind;
use App\Modules\Dashboard\Services\WidgetAnalyticsService;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NewDomainAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    public static function richWidgetProvider(): array
    {
        return [
            'complaints'   => ['crm.open_complaints',        RenderKind::Breakdown, 'segments'],
            'assets'       => ['assets.under_maintenance',   RenderKind::Breakdown, 'segments'],
            'deliveries'   => ['supply.overdue_deliveries',  RenderKind::Table,     'rows'],
            'delivery_sch' => ['supply.delivery_schedule',   RenderKind::Table,     'rows'],
            'rma_open'     => ['rma.open_returns',           RenderKind::Breakdown, 'segments'],
            'rma_pending'  => ['rma.pending_approval',       RenderKind::Table,     'rows'],
            'budget'       => ['budget.utilization',         RenderKind::Gauge,     'value'],
            'loans'        => ['loans.outstanding',          RenderKind::Table,     'rows'],
        ];
    }

    /**
     * Every one of the six previously scalar-only domains must produce a
     * populated rich payload of the documented shape — an empty array here
     * means the widget silently fell back to a scalar.
     *
     * @dataProvider richWidgetProvider
     */
    public function test_new_domain_widget_produces_its_rich_shape(
        string $key,
        RenderKind $kind,
        string $expectedKey,
    ): void {
        $payload = app(WidgetAnalyticsService::class)->payload($key, $kind, $this->admin);

        $this->assertNotSame([], $payload, "{$key} produced no rich payload");
        $this->assertArrayHasKey($expectedKey, $payload);
    }

    /**
     * The department-scoped reading must never widen. A department_head sees
     * only their own department's loans — the same rule LoanController
     * enforces, and the rule the old company-wide hr.on_leave_today broke.
     */
    public function test_loans_table_is_department_scoped_without_the_company_wide_gate(): void
    {
        $mine = Department::factory()->create();
        $theirs = Department::factory()->create();

        $me = Employee::factory()->create(['department_id' => $mine->id]);
        $them = Employee::factory()->create(['department_id' => $theirs->id]);

        foreach ([[$me->id, 5000], [$them->id, 9000]] as [$employeeId, $balance]) {
            DB::table('employee_loans')->insert([
                'loan_no' => 'LN-T-'.substr(uniqid(), -5),
                'employee_id' => $employeeId,
                'loan_type' => 'company_loan',
                'principal' => $balance,
                'interest_rate' => 0,
                'monthly_amortization' => 500,
                'total_paid' => 0,
                'balance' => $balance,
                'start_date' => now()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
                'pay_periods_total' => 24,
                'pay_periods_remaining' => 24,
                'approval_chain_size' => 1,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $deptHead = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => $me->id,
        ]);

        $payload = app(WidgetAnalyticsService::class)
            ->payload('loans.outstanding', RenderKind::Table, $deptHead);

        $this->assertSame(1, $payload['total_count'], 'department_head must not see other departments');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test --filter=NewDomainAnalyticsTest`
Expected: FAIL — all nine cases fail; the stubs return `[]`.

- [ ] **Step 3: Fill in `CrmWidgetAnalytics`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/** CRM analytics: complaint mix. New — CRM had no aggregate endpoint. */
final class CrmWidgetAnalytics
{
    private const TONE = [
        'open' => 'danger',
        'investigating' => 'warning',
        'resolved' => 'success',
        'closed' => 'neutral',
    ];

    /** @return list<string> */
    public function handles(): array
    {
        return ['crm.open_complaints'];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        if ($key !== 'crm.open_complaints') {
            return [];
        }

        $rows = DB::table('customer_complaints')
            ->selectRaw('status as label, COUNT(*) as value')
            ->groupBy('status')
            ->orderByDesc('value')
            ->get();

        return [
            'total' => (int) $rows->sum('value'),
            'segments' => $rows->map(fn ($r) => [
                'label' => (string) $r->label,
                'value' => (int) $r->value,
                'tone' => self::TONE[(string) $r->label] ?? 'neutral',
            ])->values()->all(),
        ];
    }
}
```

- [ ] **Step 4: Fill in `AssetWidgetAnalytics`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/** Asset analytics: register mix by category. New — Assets had no aggregate endpoint. */
final class AssetWidgetAnalytics
{
    /** @return list<string> */
    public function handles(): array
    {
        return ['assets.under_maintenance'];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        if ($key !== 'assets.under_maintenance') {
            return [];
        }

        // Out-of-service assets grouped by category: "which KIND of asset is
        // down" is the actionable reading; a bare count is not.
        $rows = DB::table('assets')
            ->selectRaw('category as label, COUNT(*) as value')
            ->where('status', AssetStatus::UnderMaintenance->value)
            ->whereNull('deleted_at')
            ->groupBy('category')
            ->orderByDesc('value')
            ->limit(8)
            ->get();

        return [
            'total' => (int) $rows->sum('value'),
            'segments' => $rows->map(fn ($r) => [
                'label' => (string) ($r->label ?? 'Uncategorised'),
                'value' => (int) $r->value,
                'tone' => 'warning',
            ])->values()->all(),
        ];
    }
}
```

- [ ] **Step 5: Fill in `SupplyChainWidgetAnalytics`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use Illuminate\Support\Facades\DB;

/** Delivery analytics. New — SupplyChain had no aggregate endpoint. */
final class SupplyChainWidgetAnalytics
{
    private const OPEN = [
        DeliveryStatus::Scheduled->value,
        DeliveryStatus::InTransit->value,
    ];

    /** @return list<string> */
    public function handles(): array
    {
        return ['supply.overdue_deliveries', 'supply.delivery_schedule'];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        return match ($key) {
            'supply.overdue_deliveries' => $this->overdue(),
            'supply.delivery_schedule' => $this->schedule(),
            default => [],
        };
    }

    /** Overdue deliveries, oldest first — the ones to chase today. */
    private function overdue(): array
    {
        $rows = DB::table('deliveries')
            ->select('delivery_number', 'status', 'scheduled_date')
            ->whereIn('status', self::OPEN)
            ->whereDate('scheduled_date', '<', now()->toDateString())
            ->whereNull('deleted_at')
            ->orderBy('scheduled_date')
            ->limit(10)
            ->get();

        $total = DB::table('deliveries')
            ->whereIn('status', self::OPEN)
            ->whereDate('scheduled_date', '<', now()->toDateString())
            ->whereNull('deleted_at')
            ->count();

        return [
            'columns' => [
                ['key' => 'delivery_number', 'label' => 'Delivery', 'align' => 'left'],
                ['key' => 'scheduled_date', 'label' => 'Scheduled', 'align' => 'left'],
                ['key' => 'days_late', 'label' => 'Days late', 'align' => 'right'],
            ],
            'rows' => $rows->map(fn ($r) => [
                'delivery_number' => (string) $r->delivery_number,
                'scheduled_date' => (string) $r->scheduled_date,
                'days_late' => now()->startOfDay()->diffInDays($r->scheduled_date),
            ])->values()->all(),
            'total_count' => (int) $total,
        ];
    }

    /** Deliveries due in the next 7 days, soonest first. */
    private function schedule(): array
    {
        $from = now()->toDateString();
        $to = now()->addDays(7)->toDateString();

        $base = fn () => DB::table('deliveries')
            ->whereIn('status', self::OPEN)
            ->whereBetween('scheduled_date', [$from, $to])
            ->whereNull('deleted_at');

        $rows = $base()
            ->select('delivery_number', 'status', 'scheduled_date')
            ->orderBy('scheduled_date')
            ->limit(10)
            ->get();

        return [
            'columns' => [
                ['key' => 'delivery_number', 'label' => 'Delivery', 'align' => 'left'],
                ['key' => 'scheduled_date', 'label' => 'Scheduled', 'align' => 'left'],
                ['key' => 'status', 'label' => 'Status', 'align' => 'left'],
            ],
            'rows' => $rows->map(fn ($r) => [
                'delivery_number' => (string) $r->delivery_number,
                'scheduled_date' => (string) $r->scheduled_date,
                'status' => (string) $r->status,
            ])->values()->all(),
            'total_count' => (int) $base()->count(),
        ];
    }
}
```

- [ ] **Step 6: Fill in `ReturnWidgetAnalytics`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use Illuminate\Support\Facades\DB;

/** RMA analytics: status mix + the approval queue. New — RMA had no aggregate endpoint. */
final class ReturnWidgetAnalytics
{
    private const TONE = [
        'draft' => 'neutral',
        'pending_approval' => 'warning',
        'approved' => 'info',
        'received' => 'info',
        'inspected' => 'info',
        'completed' => 'success',
        'rejected' => 'danger',
        'cancelled' => 'neutral',
    ];

    /** @return list<string> */
    public function handles(): array
    {
        return ['rma.open_returns', 'rma.pending_approval'];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        return match ($key) {
            'rma.open_returns' => $this->statusMix(),
            'rma.pending_approval' => $this->approvalQueue(),
            default => [],
        };
    }

    private function statusMix(): array
    {
        $rows = DB::table('return_requests')
            ->selectRaw('status as label, COUNT(*) as value')
            ->whereNotIn('status', [
                ReturnRequestStatus::Completed->value,
                ReturnRequestStatus::Rejected->value,
                ReturnRequestStatus::Cancelled->value,
            ])
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->orderByDesc('value')
            ->get();

        return [
            'total' => (int) $rows->sum('value'),
            'segments' => $rows->map(fn ($r) => [
                'label' => (string) $r->label,
                'value' => (int) $r->value,
                'tone' => self::TONE[(string) $r->label] ?? 'neutral',
            ])->values()->all(),
        ];
    }

    /** Returns waiting on a decision, oldest first — an approver's worklist. */
    private function approvalQueue(): array
    {
        $base = fn () => DB::table('return_requests')
            ->where('status', ReturnRequestStatus::PendingApproval->value)
            ->whereNull('deleted_at');

        $rows = $base()
            ->select('rma_number', 'type', 'return_date')
            ->orderBy('return_date')
            ->limit(10)
            ->get();

        return [
            'columns' => [
                ['key' => 'rma_number', 'label' => 'RMA', 'align' => 'left'],
                ['key' => 'type', 'label' => 'Type', 'align' => 'left'],
                ['key' => 'waiting_days', 'label' => 'Waiting', 'align' => 'right'],
            ],
            'rows' => $rows->map(fn ($r) => [
                'rma_number' => (string) $r->rma_number,
                'type' => (string) $r->type,
                'waiting_days' => $r->return_date === null
                    ? null
                    : now()->startOfDay()->diffInDays($r->return_date),
            ])->values()->all(),
            'total_count' => (int) $base()->count(),
        ];
    }
}
```

- [ ] **Step 7: Fill in `BudgetWidgetAnalytics`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/** Budget analytics: utilization as a gauge. New — Budgeting had only a ratio. */
final class BudgetWidgetAnalytics
{
    /** @return list<string> */
    public function handles(): array
    {
        return ['budget.utilization'];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        if ($key !== 'budget.utilization') {
            return [];
        }

        $row = DB::table('budgets')
            ->whereIn('status', ['approved', 'active'])
            ->selectRaw('COALESCE(SUM(total_allocated), 0) AS allocated, COALESCE(SUM(total_spent), 0) AS spent')
            ->first();

        $allocated = (float) ($row->allocated ?? 0);

        // Utilization of nothing is unknown, not 0% — the same rule the
        // scalar path applies (DashboardWidgetDataService::budgetUtilization).
        if ($allocated <= 0.0) {
            return [];
        }

        return [
            'value' => round(((float) $row->spent / $allocated) * 100, 1),
            'target' => 100.0,
            'min' => 0.0,
            'max' => 100.0,
            'kind' => 'percent',
        ];
    }
}
```

- [ ] **Step 8: Fill in `LoanWidgetAnalytics`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Support\WidgetScope;
use App\Modules\Loans\Enums\LoanStatus;
use Illuminate\Support\Facades\DB;

/**
 * Loan analytics. Scoped exactly like the scalar path: company-wide only
 * under `loans.write_off`, otherwise the caller's own department — mirroring
 * LoanController's department filter. A company-wide table here would hand
 * department_head figures its own module refuses it.
 */
final class LoanWidgetAnalytics
{
    public function __construct(private readonly WidgetScope $scope) {}

    /** @return list<string> */
    public function handles(): array
    {
        return ['loans.outstanding'];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        if ($key !== 'loans.outstanding') {
            return [];
        }

        $companyWide = $this->scope->isCompanyWide($user, 'loans.write_off');
        $departmentId = $this->scope->departmentId($user);

        if (! $companyWide && $departmentId === null) {
            return [];
        }

        $base = fn () => DB::table('employee_loans as l')
            ->join('employees as e', 'e.id', '=', 'l.employee_id')
            ->whereIn('l.status', [LoanStatus::Active->value, LoanStatus::Pending->value])
            ->when(! $companyWide, fn ($q) => $q->where('e.department_id', $departmentId));

        $rows = $base()
            ->selectRaw("e.first_name || ' ' || e.last_name as borrower, l.loan_type, l.balance")
            ->orderByDesc('l.balance')
            ->limit(10)
            ->get();

        return [
            'columns' => [
                ['key' => 'borrower', 'label' => 'Borrower', 'align' => 'left'],
                ['key' => 'loan_type', 'label' => 'Type', 'align' => 'left'],
                ['key' => 'balance', 'label' => 'Balance', 'align' => 'right'],
            ],
            'rows' => $rows->map(fn ($r) => [
                'borrower' => (string) $r->borrower,
                'loan_type' => (string) $r->loan_type,
                'balance' => number_format((float) $r->balance, 2, '.', ''),
            ])->values()->all(),
            'total_count' => (int) $base()->count(),
        ];
    }
}
```

- [ ] **Step 9: Run test to verify it passes**

`LoanStatus` was verified while writing this plan — cases are `pending`, `active`, `paid`, `cancelled`, `rejected`, so the `Active`/`Pending` filter above matches what `DashboardWidgetDataService::outstandingLoans` already uses.

Run: `docker compose exec -T api php artisan test --filter=NewDomainAnalyticsTest`
Expected: PASS (9 tests).

- [ ] **Step 10: Commit**

```bash
git add api/app/Modules/Dashboard/Services/Analytics/ \
        api/tests/Feature/Dashboard/NewDomainAnalyticsTest.php
git commit -m "feat: analytics for CRM, assets, supply chain, RMA, budget, loans widgets"
```

---

### Task 5: Seed `render_kind` and real widget sizes

**Files:**
- Modify: `api/database/seeders/DashboardWidgetSeeder.php:21-116`
- Modify: `api/database/seeders/DashboardRoleLayoutSeeder.php:126-139`
- Test: `api/tests/Feature/Dashboard/WidgetSeedIntegrityTest.php`

**Interfaces:**
- Consumes: `RenderKind` (Task 1); the provider `handles()` lists (Tasks 3–4).
- Produces: every seeded widget carries a `render_kind`; role layouts carry per-widget `w`/`h`.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Feature/Dashboard/WidgetSeedIntegrityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Enums\RenderKind;
use App\Modules\Dashboard\Models\DashboardWidget;
use App\Modules\Dashboard\Services\Analytics\AssetWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\BudgetWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\CoreWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\CrmWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\LoanWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\ReturnWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\SupplyChainWidgetAnalytics;
use Database\Seeders\DashboardRoleLayoutSeeder;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WidgetSeedIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DashboardWidgetSeeder::class);
    }

    /**
     * A widget declaring a rich kind with no provider renders an empty tile —
     * worse than the scalar it replaced. The two lists must agree.
     */
    public function test_every_rich_widget_has_a_provider(): void
    {
        $handled = collect([
            app(CoreWidgetAnalytics::class),
            app(CrmWidgetAnalytics::class),
            app(AssetWidgetAnalytics::class),
            app(SupplyChainWidgetAnalytics::class),
            app(ReturnWidgetAnalytics::class),
            app(BudgetWidgetAnalytics::class),
            app(LoanWidgetAnalytics::class),
        ])->flatMap(fn ($provider) => $provider->handles())->unique();

        $rich = DashboardWidget::query()
            ->where('render_kind', '!=', RenderKind::Scalar->value)
            ->pluck('key');

        $orphans = $rich->diff($handled)->all();

        $this->assertSame([], $orphans, 'rich widgets with no analytics provider: '.implode(', ', $orphans));
    }

    /** Conversely, a provider for a key nobody seeds is dead code. */
    public function test_no_provider_handles_an_unseeded_key(): void
    {
        $handled = collect([
            app(CoreWidgetAnalytics::class),
            app(CrmWidgetAnalytics::class),
            app(AssetWidgetAnalytics::class),
            app(SupplyChainWidgetAnalytics::class),
            app(ReturnWidgetAnalytics::class),
            app(BudgetWidgetAnalytics::class),
            app(LoanWidgetAnalytics::class),
        ])->flatMap(fn ($provider) => $provider->handles())->unique();

        $seeded = DashboardWidget::query()->pluck('key');

        $this->assertSame([], $handled->diff($seeded)->all());
    }

    /**
     * Widths must be a real 12-column layout, not the uniform `12` every row
     * carried while the SPA ignored the column entirely.
     */
    public function test_role_layouts_use_varied_widths(): void
    {
        $this->seed(DashboardRoleLayoutSeeder::class);

        $widths = DB::table('dashboard_layouts')
            ->where('owner_type', 'role')
            ->distinct()
            ->pluck('width');

        $this->assertGreaterThan(1, $widths->count(), 'every role row is still full-width');
        foreach ($widths as $width) {
            $this->assertContains((int) $width, [4, 6, 8, 12]);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test --filter=WidgetSeedIntegrityTest`
Expected: FAIL — `test_no_provider_handles_an_unseeded_key` and `test_role_layouts_use_varied_widths` fail (no `render_kind` seeded yet, all widths are 12).

- [ ] **Step 3: Declare `render_kind` in the widget seeder**

In `DashboardWidgetSeeder::catalog()`, add `'render_kind' => '<kind>'` to exactly these eight entries — they are the keys the providers handle — and leave every other entry alone (they default to `scalar`):

| Key | render_kind |
|---|---|
| `qc.pareto` | `breakdown` |
| `production.wo_breakdown` | `breakdown` |
| `production.kpi` | `trend` |
| `machine.utilization` | `gauge` |
| `oee.gauges` | `gauge` |
| `finance.ar_aging` | `breakdown` |
| `hr.headcount` | `breakdown` |
| `purchasing.open_pos` | `breakdown` |
| `crm.open_complaints` | `breakdown` |
| `assets.under_maintenance` | `breakdown` |
| `supply.overdue_deliveries` | `table` |
| `supply.delivery_schedule` | `table` |
| `rma.open_returns` | `breakdown` |
| `rma.pending_approval` | `table` |
| `budget.utilization` | `gauge` |
| `loans.outstanding` | `table` |

Update the docblock `@return` type and the `run()` write to include it:

```php
                    'module'      => $w['module'],
                    'permission'  => $w['permission'],
                    'render_kind' => $w['render_kind'] ?? 'scalar',
                    'default_w'   => $w['default_w'] ?? 12,
                    'default_h'   => $w['default_h'] ?? 4,
```

Also set `default_w` per kind so a freshly added widget lands at a sensible size: `table` → 12, `trend` → 8, `breakdown` → 6, `gauge` → 4, `scalar` → 4.

- [ ] **Step 4: Give role layouts real widths**

In `DashboardRoleLayoutSeeder`, replace the fixed `'width' => 12, 'height' => 4` with the widget's own default, and lay rows out left-to-right within a 12-column grid. Change `roleWidgets()` values from `array<string>` to keep the same shape (no per-role width tuning — the widget's kind decides), then in `run()`:

```php
            $defaults = DB::table('dashboard_widgets')->pluck('default_w', 'key');

            $rows = [];
            $x = 0;
            $y = 0;
            foreach ($widgetKeys as $key) {
                $w = (int) ($defaults[$key] ?? 12);
                // Wrap to the next row when this widget won't fit.
                if ($x + $w > 12) {
                    $x = 0;
                    $y++;
                }
                $rows[] = [
                    'owner_type' => DashboardLayout::OWNER_ROLE,
                    'owner_id'   => $roleId,
                    'widget_key' => $key,
                    'position_x' => $x,
                    'position_y' => $y,
                    'width'      => $w,
                    'height'     => 4,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $x += $w;
            }
```

- [ ] **Step 5: Re-seed and run the test**

```bash
docker compose exec -T api php artisan db:seed --class=DashboardWidgetSeeder
docker compose exec -T api php artisan db:seed --class=DashboardRoleLayoutSeeder
docker compose exec -T api php artisan test --filter=WidgetSeedIntegrityTest
```

Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add api/database/seeders/DashboardWidgetSeeder.php \
        api/database/seeders/DashboardRoleLayoutSeeder.php \
        api/tests/Feature/Dashboard/WidgetSeedIntegrityTest.php
git commit -m "feat: seed widget render kinds and a real 12-column layout"
```

---

### Task 6: `GET /dashboard/layout?rich=1`

**Files:**
- Modify: `api/app/Modules/Dashboard/Services/DashboardLayoutService.php:28-84, 192-218`
- Modify: `api/app/Modules/Dashboard/Controllers/DashboardLayoutController.php:57-62`
- Test: `api/tests/Feature/Dashboard/RichLayoutEndpointTest.php`

**Interfaces:**
- Consumes: `WidgetAnalyticsService::payload()` (Task 3), `RenderKind` (Task 1).
- Produces:
  - `DashboardLayoutService::getEffectiveLayout(User $user): array` — each row gains `'render_kind' => string`.
  - `DashboardLayoutService::getRichLayout(User $user): array` — same rows plus `'data' => array|null`.
  - `GET /api/v1/dashboard/layout?rich=1` returns the rich form; without the flag the response is byte-identical to today's apart from the added `render_kind`.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Feature/Dashboard/RichLayoutEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\DashboardRoleLayoutSeeder;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RichLayoutEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DashboardWidgetSeeder::class);
        $this->seed(DashboardRoleLayoutSeeder::class);
    }

    private function actingAsRole(string $slug): User
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_plain_layout_carries_render_kind(): void
    {
        $this->actingAsRole('production_manager');

        $this->getJson('/api/v1/dashboard/layout')
            ->assertOk()
            ->assertJsonStructure(['data' => [['key', 'name', 'module', 'render_kind', 'x', 'y', 'w', 'h', 'source']]]);
    }

    public function test_rich_layout_nests_data_per_widget(): void
    {
        $this->actingAsRole('production_manager');

        $response = $this->getJson('/api/v1/dashboard/layout?rich=1')->assertOk();

        $rows = collect($response->json('data'));
        $this->assertNotEmpty($rows);
        $this->assertTrue($rows->every(fn (array $r) => array_key_exists('data', $r)));

        // production_manager's layout includes production.kpi (trend) — its
        // rich payload must actually arrive, not fall back to null.
        $trend = $rows->firstWhere('key', 'production.kpi');
        $this->assertNotNull($trend);
        $this->assertArrayHasKey('points', $trend['data']);
    }

    /**
     * Rich mode must not widen access. It reuses the same permission strip, so
     * a role that cannot see a widget does not receive its data either.
     */
    public function test_rich_mode_still_strips_forbidden_widgets(): void
    {
        $this->actingAsRole('employee');

        $keys = collect($this->getJson('/api/v1/dashboard/layout?rich=1')->assertOk()->json('data'))
            ->pluck('key');

        $this->assertTrue($keys->every(fn (string $k) => str_starts_with($k, 'self.')));
        $this->assertFalse($keys->contains('finance.ar_aging'));
    }

    /** Scalar widgets carry no rich payload — the SPA renders them as before. */
    public function test_scalar_widgets_have_null_data(): void
    {
        $this->actingAsRole('employee');

        $rows = collect($this->getJson('/api/v1/dashboard/layout?rich=1')->assertOk()->json('data'));

        $this->assertTrue($rows->every(fn (array $r) => $r['data'] === null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test --filter=RichLayoutEndpointTest`
Expected: FAIL — `render_kind` missing from the response.

- [ ] **Step 3: Emit `render_kind` and add `getRichLayout`**

In `DashboardLayoutService`, inject the analytics service:

```php
    public function __construct(
        private readonly WidgetAnalyticsService $analytics,
    ) {}
```

Add the imports `use App\Modules\Dashboard\Enums\RenderKind;` and `use App\Modules\Dashboard\Services\WidgetAnalyticsService;`.

In `getEffectiveLayout()`, add `render_kind` to the row it builds (keep every existing key):

```php
            $rows[] = [
                'key'         => $widget->key,
                'name'        => $widget->name,
                'description' => $widget->description,
                'module'      => $widget->module,
                'permission'  => $widget->permission,
                'render_kind' => $widget->render_kind->value,
                'x'           => (int) $row->position_x,
                'y'           => (int) $row->position_y,
                'w'           => (int) $row->width,
                'h'           => (int) $row->height,
                'source'      => $source,
            ];
```

Then append the rich variant:

```php
    /**
     * The effective layout with each widget's rich payload attached.
     *
     * Deliberately built ON TOP of getEffectiveLayout rather than beside it:
     * the permission strip lives there, so rich mode cannot widen access by
     * construction. A widget with no rich payload gets `data => null` and the
     * SPA renders the scalar it always did.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRichLayout(User $user): array
    {
        return array_map(function (array $row) use ($user): array {
            $kind = RenderKind::fromNullable($row['render_kind']);
            $payload = $this->analytics->payload($row['key'], $kind, $user);
            $row['data'] = $payload === [] ? null : $payload;

            return $row;
        }, $this->getEffectiveLayout($user));
    }
```

Also add `'render_kind' => $w->render_kind->value` to the array `listAvailableWidgets()` returns, so the picker (Task 8) knows each widget's shape.

- [ ] **Step 4: Honour the flag in the controller**

Replace `DashboardLayoutController::show()`:

```php
    public function show(Request $request): JsonResponse
    {
        $rich = $request->boolean('rich');

        return response()->json([
            'data' => $rich
                ? $this->service->getRichLayout($request->user())
                : $this->service->getEffectiveLayout($request->user()),
        ]);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -T api php artisan test --filter=RichLayoutEndpointTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the whole dashboard suite**

Run: `docker compose exec -T api php artisan test --filter=Dashboard`
Expected: PASS — the existing dispatch, badge, KPI, and widget-data tests are unaffected.

- [ ] **Step 7: Commit**

```bash
git add api/app/Modules/Dashboard/Services/DashboardLayoutService.php \
        api/app/Modules/Dashboard/Controllers/DashboardLayoutController.php \
        api/tests/Feature/Dashboard/RichLayoutEndpointTest.php
git commit -m "feat: GET /dashboard/layout?rich=1 with nested widget payloads"
```

---

### Task 7: SPA renders the four rich kinds

**Files:**
- Modify: `spa/src/api/dashboard-layout.ts:8-47, 79-82`
- Create: `spa/src/components/dashboard/WidgetBreakdown.tsx`
- Create: `spa/src/components/dashboard/WidgetTable.tsx`
- Modify: `spa/src/components/dashboard/registry.tsx:76-123`
- Test: `spa/src/components/dashboard/registry.test.tsx`

**Interfaces:**
- Consumes: the `?rich=1` response (Task 6).
- Produces:
  - Types `WidgetRenderKind`, `WidgetBreakdownData`, `WidgetTrendData`, `WidgetTableData`, `WidgetGaugeData`, `WidgetData`; `DashboardLayoutItem` gains `render_kind: WidgetRenderKind` and `data: WidgetData | null`.
  - `dashboardLayoutApi.layout(opts?: { rich?: boolean })`.
  - `<WidgetBreakdown segments total />`, `<WidgetTable columns rows totalCount />`.
  - `LiveDashboardWidget` unchanged in signature — still `{ widget, summary, loading }`.

- [ ] **Step 1: Write the failing test**

Create `spa/src/components/dashboard/registry.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { LiveDashboardWidget } from './registry';
import type { DashboardLayoutItem } from '@/api/dashboard-layout';

function item(overrides: Partial<DashboardLayoutItem>): DashboardLayoutItem {
  return {
    key: 'test.widget',
    name: 'Test Widget',
    description: null,
    module: 'platform',
    permission: null,
    render_kind: 'scalar',
    data: null,
    x: 0,
    y: 0,
    w: 12,
    h: 4,
    source: 'role',
    ...overrides,
  };
}

const wrap = (ui: React.ReactNode) => render(<MemoryRouter>{ui}</MemoryRouter>);

describe('LiveDashboardWidget', () => {
  it('renders a scalar summary as a single figure', () => {
    wrap(
      <LiveDashboardWidget
        widget={item({})}
        summary={{
          key: 'test.widget',
          value: '42',
          kind: 'number',
          helper: 'things counted',
          available: true,
          updated_at: new Date().toISOString(),
        }}
        loading={false}
      />,
    );

    expect(screen.getByText('42')).toBeInTheDocument();
    expect(screen.getByText('things counted')).toBeInTheDocument();
  });

  it('renders breakdown segments with their labels and values', () => {
    wrap(
      <LiveDashboardWidget
        widget={item({
          render_kind: 'breakdown',
          data: {
            total: 9,
            segments: [
              { label: 'in_progress', value: 6, tone: 'success' },
              { label: 'paused', value: 3, tone: 'warning' },
            ],
          },
        })}
        loading={false}
      />,
    );

    expect(screen.getByText('in_progress')).toBeInTheDocument();
    expect(screen.getByText('paused')).toBeInTheDocument();
    expect(screen.getByText('6')).toBeInTheDocument();
  });

  it('renders a table widget as rows', () => {
    wrap(
      <LiveDashboardWidget
        widget={item({
          render_kind: 'table',
          data: {
            columns: [
              { key: 'rma_number', label: 'RMA', align: 'left' },
              { key: 'waiting_days', label: 'Waiting', align: 'right' },
            ],
            rows: [{ rma_number: 'RMA-202608-0001', waiting_days: 3 }],
            total_count: 1,
          },
        })}
        loading={false}
      />,
    );

    expect(screen.getByText('RMA-202608-0001')).toBeInTheDocument();
    expect(screen.getByText('RMA')).toBeInTheDocument();
  });

  it('renders a gauge widget with its percentage', () => {
    wrap(
      <LiveDashboardWidget
        widget={item({
          render_kind: 'gauge',
          data: { value: 72.5, target: 85, min: 0, max: 100, kind: 'percent' },
        })}
        loading={false}
      />,
    );

    expect(screen.getByText(/72.5/)).toBeInTheDocument();
  });

  /**
   * A rich kind whose payload failed server-side must fall back to the scalar
   * summary rather than render an empty box.
   */
  it('falls back to the scalar summary when rich data is null', () => {
    wrap(
      <LiveDashboardWidget
        widget={item({ render_kind: 'breakdown', data: null })}
        summary={{
          key: 'test.widget',
          value: '7',
          kind: 'number',
          helper: 'fallback figure',
          available: true,
          updated_at: new Date().toISOString(),
        }}
        loading={false}
      />,
    );

    expect(screen.getByText('7')).toBeInTheDocument();
  });

  it('shows the unavailable state when there is neither rich data nor a summary', () => {
    wrap(<LiveDashboardWidget widget={item({})} loading={false} />);

    expect(screen.getByText('Live data unavailable')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T spa npm run test -- --run registry.test`
Expected: FAIL — `render_kind` is not a valid `DashboardLayoutItem` property.

- [ ] **Step 3: Extend the API types**

In `spa/src/api/dashboard-layout.ts` add the payload types and widen the layout item:

```ts
export type WidgetRenderKind = 'scalar' | 'breakdown' | 'trend' | 'table' | 'gauge';

export type WidgetTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

export interface WidgetBreakdownData {
  total: number;
  segments: Array<{ label: string; value: number; tone: WidgetTone }>;
}

export interface WidgetTrendData {
  points: Array<{ label: string; value: number }>;
  delta: number | null;
  kind: 'count' | 'currency' | 'percent' | 'hours';
}

export interface WidgetTableData {
  columns: Array<{ key: string; label: string; align: 'left' | 'right' }>;
  rows: Array<Record<string, string | number | null>>;
  total_count: number;
}

export interface WidgetGaugeData {
  value: number;
  target: number | null;
  min: number;
  max: number;
  kind: 'percent' | 'count';
}

export type WidgetData =
  | WidgetBreakdownData
  | WidgetTrendData
  | WidgetTableData
  | WidgetGaugeData;
```

Add to `DashboardLayoutItem`:

```ts
  render_kind: WidgetRenderKind;
  /** Rich payload when the server had one; null → render the scalar summary. */
  data: WidgetData | null;
```

Add `render_kind: WidgetRenderKind;` to `DashboardWidgetMeta` too, and change the fetcher:

```ts
  layout: (opts?: { rich?: boolean }) =>
    client
      .get<ApiSuccess<DashboardLayoutItem[]>>('/dashboard/layout', {
        params: opts?.rich ? { rich: 1 } : undefined,
      })
      .then((r) => r.data.data),
```

- [ ] **Step 4: Build `WidgetBreakdown`**

Create `spa/src/components/dashboard/WidgetBreakdown.tsx`:

```tsx
import { ProgressBar } from '@/components/ui/ProgressBar';
import type { WidgetBreakdownData, WidgetTone } from '@/api/dashboard-layout';

/**
 * Segment bar + legend. Same visual language as StageBreakdown (label + count
 * on one line, a 4px bar beneath) so a breakdown widget reads like the chain
 * views rather than introducing a second idiom.
 *
 * The bar itself is ProgressBar — it already owns the tone→fill mapping and
 * the 4px design-system height, so this component never names a colour.
 */
const toneVariant: Record<WidgetTone, 'accent' | 'success' | 'info' | 'warning' | 'danger'> = {
  neutral: 'accent',
  info: 'info',
  success: 'success',
  warning: 'warning',
  danger: 'danger',
};

export function WidgetBreakdown({ total, segments }: WidgetBreakdownData) {
  if (segments.length === 0) {
    return <p className="text-xs text-muted">Nothing to break down yet.</p>;
  }

  return (
    <div className="space-y-2">
      <div className="text-2xl font-mono tabular-nums font-medium text-primary">
        {total.toLocaleString()}
      </div>
      <ul className="space-y-1.5">
        {segments.map((segment) => (
          <li key={segment.label}>
            <div className="flex items-baseline justify-between gap-2">
              <span className="text-xs text-muted truncate">{segment.label}</span>
              <span className="text-xs font-mono tabular-nums text-primary">
                {segment.value.toLocaleString()}
              </span>
            </div>
            <ProgressBar
              value={total > 0 ? (segment.value / total) * 100 : 0}
              variant={toneVariant[segment.tone]}
              className="mt-1"
            />
          </li>
        ))}
      </ul>
    </div>
  );
}
```

No token check needed — this component names no colour. `ProgressBar` maps each variant to the right fill (`ProgressBar.tsx:19-25`).

- [ ] **Step 5: Build `WidgetTable`**

Create `spa/src/components/dashboard/WidgetTable.tsx`:

```tsx
import { cn } from '@/lib/cn';
import type { WidgetTableData } from '@/api/dashboard-layout';

/**
 * Compact table for widget tiles. Deliberately NOT the full DataTable —
 * a tile has no room for density toggles, column visibility, or pagination,
 * and `total_count` already tells the user what the tile is not showing.
 */
export function WidgetTable({ columns, rows, total_count: totalCount }: WidgetTableData) {
  if (rows.length === 0) {
    return <p className="text-xs text-muted">Nothing outstanding.</p>;
  }

  return (
    <div className="space-y-2">
      <table className="w-full text-xs">
        <thead>
          <tr className="border-b border-default">
            {columns.map((column) => (
              <th
                key={column.key}
                scope="col"
                className={cn(
                  'py-1 font-medium text-muted',
                  column.align === 'right' ? 'text-right' : 'text-left',
                )}
              >
                {column.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={index} className="border-b border-default last:border-0">
              {columns.map((column) => (
                <td
                  key={column.key}
                  className={cn(
                    'py-1 text-primary',
                    column.align === 'right'
                      ? 'text-right font-mono tabular-nums'
                      : 'text-left',
                  )}
                >
                  {row[column.key] ?? '—'}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
      {totalCount > rows.length && (
        <p className="text-2xs text-subtle">
          Showing {rows.length} of {totalCount.toLocaleString()}
        </p>
      )}
    </div>
  );
}
```

- [ ] **Step 6: Switch on `render_kind` in the registry**

In `spa/src/components/dashboard/registry.tsx`, keep `WIDGET_LINKS` and `formatValue` exactly as they are. Add the imports:

```tsx
import { AreaTrend } from '@/components/charts/AreaTrend';
import { ProgressBar } from '@/components/ui/ProgressBar';
import { WidgetBreakdown } from './WidgetBreakdown';
import { WidgetTable } from './WidgetTable';
import type {
  WidgetBreakdownData,
  WidgetGaugeData,
  WidgetTableData,
  WidgetTrendData,
} from '@/api/dashboard-layout';
```

Then replace the body of `LiveDashboardWidget` between the `loading` branch and the scalar branch with a rich branch:

```tsx
  const rich = widget.data;

  return (
    <Panel
      title={widget.name}
      actions={
        href ? (
          <Link to={href} className="text-xs text-link hover:underline">
            Open →
          </Link>
        ) : undefined
      }
    >
      {loading ? (
        <div className="space-y-2">
          <SkeletonBlock className="h-8 w-28 rounded" />
          <SkeletonBlock className="h-4 w-44 rounded" />
        </div>
      ) : rich ? (
        <RichWidgetBody kind={widget.render_kind} data={rich} />
      ) : !summary || !summary.available ? (
        <EmptyState
          size="compact"
          icon="alert-circle"
          title="Live data unavailable"
          description={summary?.helper ?? 'This widget has no live response.'}
        />
      ) : (
        <div className="space-y-1.5">
          <div className="text-2xl font-mono tabular-nums font-medium text-primary">
            {formatValue(summary)}
          </div>
          {summary.helper && <p className="text-xs text-muted">{summary.helper}</p>}
          <p className="text-2xs text-subtle">
            Updated {new Date(summary.updated_at).toLocaleTimeString()}
          </p>
        </div>
      )}
    </Panel>
  );
}

/**
 * Renders whichever shape the server sent. An unrecognised kind renders
 * nothing here and the caller's scalar branch takes over, so a widget seeded
 * with a kind this build doesn't know still shows its number.
 */
function RichWidgetBody({
  kind,
  data,
}: {
  kind: DashboardLayoutItem['render_kind'];
  data: NonNullable<DashboardLayoutItem['data']>;
}) {
  if (kind === 'breakdown') {
    return <WidgetBreakdown {...(data as WidgetBreakdownData)} />;
  }

  if (kind === 'table') {
    return <WidgetTable {...(data as WidgetTableData)} />;
  }

  if (kind === 'trend') {
    const trend = data as WidgetTrendData;
    return (
      <div className="space-y-1.5">
        <AreaTrend data={trend.points} dataKey="value" xKey="label" height={120} />
        {trend.delta !== null && (
          <p className="text-xs text-muted font-mono tabular-nums">
            {trend.delta > 0 ? '+' : ''}
            {trend.delta}% over the window
          </p>
        )}
      </div>
    );
  }

  if (kind === 'gauge') {
    const gauge = data as WidgetGaugeData;
    const pct = gauge.max > gauge.min
      ? ((gauge.value - gauge.min) / (gauge.max - gauge.min)) * 100
      : 0;
    return (
      <div className="space-y-2">
        <div className="text-2xl font-mono tabular-nums font-medium text-primary">
          {gauge.value.toFixed(1)}
          {gauge.kind === 'percent' ? '%' : ''}
        </div>
        <ProgressBar value={pct} />
        {gauge.target !== null && (
          <p className="text-2xs text-subtle font-mono tabular-nums">
            target {gauge.target}
            {gauge.kind === 'percent' ? '%' : ''}
          </p>
        )}
      </div>
    );
  }

  return null;
}
```

`ProgressBar` takes `value` as 0–100 and clamps it (`ProgressBar.tsx:3-6`), so `pct` is passed as-is — do not divide by 100.

- [ ] **Step 7: Run test to verify it passes**

Run: `docker compose exec -T spa npm run test -- --run registry.test`
Expected: PASS (6 tests).

- [ ] **Step 8: Lint + token audit**

```bash
docker compose exec -T spa npm run lint
docker compose exec -T spa npm run audit:tokens
```

Expected: both clean. A token-audit failure means a hardcoded colour slipped in — replace it with the token.

- [ ] **Step 9: Commit**

```bash
git add spa/src/api/dashboard-layout.ts \
        spa/src/components/dashboard/WidgetBreakdown.tsx \
        spa/src/components/dashboard/WidgetTable.tsx \
        spa/src/components/dashboard/registry.tsx \
        spa/src/components/dashboard/registry.test.tsx
git commit -m "feat: render breakdown, trend, table, and gauge widgets"
```

---

### Task 8: Width-aware grid + widget picker

**Files:**
- Modify: `spa/src/pages/dashboard/default.tsx:38-49, 85-117`
- Create: `spa/src/components/dashboard/DashboardPicker.tsx`
- Test: `spa/src/components/dashboard/DashboardPicker.test.tsx`

**Interfaces:**
- Consumes: `dashboardLayoutApi.widgets()`, `.save()`, `.layout({ rich: true })`; `DashboardWidgetMeta.render_kind` (Task 6).
- Produces: `<DashboardPicker open onClose currentKeys onSaved />`.

- [ ] **Step 1: Write the failing test**

Create `spa/src/components/dashboard/DashboardPicker.test.tsx`:

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { dashboardLayoutApi } from '@/api/dashboard-layout';
import { DashboardPicker } from './DashboardPicker';

function wrap(ui: React.ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

const catalog = [
  {
    key: 'finance.ar_aging',
    name: 'AR Aging',
    description: null,
    module: 'accounting',
    permission: 'accounting.invoices.view',
    render_kind: 'breakdown' as const,
    default_w: 6,
    default_h: 4,
  },
  {
    key: 'hr.headcount',
    name: 'Headcount by Department',
    description: null,
    module: 'hr',
    permission: 'hr.employees.view',
    render_kind: 'breakdown' as const,
    default_w: 6,
    default_h: 4,
  },
];

describe('DashboardPicker', () => {
  it('lists only widgets the server offered and marks the ones already placed', async () => {
    vi.spyOn(dashboardLayoutApi, 'widgets').mockResolvedValue(catalog);

    wrap(
      <DashboardPicker open onClose={() => {}} currentKeys={['hr.headcount']} onSaved={() => {}} />,
    );

    expect(await screen.findByText('AR Aging')).toBeInTheDocument();
    const placed = screen.getByRole('checkbox', { name: /Headcount by Department/i });
    expect(placed).toBeChecked();
  });

  it('saves the selection as the user layout', async () => {
    vi.spyOn(dashboardLayoutApi, 'widgets').mockResolvedValue(catalog);
    const save = vi.spyOn(dashboardLayoutApi, 'save').mockResolvedValue([]);

    wrap(
      <DashboardPicker open onClose={() => {}} currentKeys={['hr.headcount']} onSaved={() => {}} />,
    );

    await userEvent.click(await screen.findByRole('checkbox', { name: /AR Aging/i }));
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(save).toHaveBeenCalledTimes(1));
    const saved = save.mock.calls[0][0];
    expect(saved.map((w) => w.key).sort()).toEqual(['finance.ar_aging', 'hr.headcount']);
    // Widths come from the catalog so the saved layout tiles correctly.
    expect(saved.every((w) => typeof w.w === 'number')).toBe(true);
  });

  it('does not save when cancelled', async () => {
    vi.spyOn(dashboardLayoutApi, 'widgets').mockResolvedValue(catalog);
    const save = vi.spyOn(dashboardLayoutApi, 'save').mockResolvedValue([]);
    const onClose = vi.fn();

    wrap(<DashboardPicker open onClose={onClose} currentKeys={[]} onSaved={() => {}} />);

    await userEvent.click(await screen.findByRole('button', { name: /cancel/i }));

    expect(save).not.toHaveBeenCalled();
    expect(onClose).toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T spa npm run test -- --run DashboardPicker`
Expected: FAIL — module `./DashboardPicker` not found.

- [ ] **Step 3: Build the picker**

Create `spa/src/components/dashboard/DashboardPicker.tsx`:

```tsx
import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { dashboardLayoutApi } from '@/api/dashboard-layout';

/**
 * Add or remove widgets from your own dashboard.
 *
 * The catalog comes from GET /dashboard/widgets, which is already filtered to
 * what the caller may see — so the picker cannot offer a widget the layout
 * endpoint would strip. Saving writes user-owned rows, which take precedence
 * over the role default (DashboardLayoutService::getEffectiveLayout).
 */
export function DashboardPicker({
  open,
  onClose,
  currentKeys,
  onSaved,
}: {
  open: boolean;
  onClose: () => void;
  currentKeys: string[];
  onSaved: () => void;
}) {
  const queryClient = useQueryClient();
  const [selected, setSelected] = useState<string[]>(currentKeys);

  // Re-sync when the dialog reopens against a layout that changed underneath.
  useEffect(() => {
    if (open) setSelected(currentKeys);
  }, [open, currentKeys.join(',')]);

  const catalog = useQuery({
    queryKey: ['dashboard', 'widget-catalog'],
    queryFn: () => dashboardLayoutApi.widgets(),
    enabled: open,
  });

  const save = useMutation({
    mutationFn: () => {
      const byKey = new Map((catalog.data ?? []).map((w) => [w.key, w]));
      let x = 0;
      let y = 0;

      const widgets = selected.map((key) => {
        const w = byKey.get(key)?.default_w ?? 12;
        if (x + w > 12) {
          x = 0;
          y += 1;
        }
        const placed = { key, x, y, w, h: byKey.get(key)?.default_h ?? 4 };
        x += w;
        return placed;
      });

      return dashboardLayoutApi.save(widgets);
    },
    onSuccess: () => {
      toast.success('Dashboard updated.');
      queryClient.invalidateQueries({ queryKey: ['dashboard', 'layout'] });
      onSaved();
      onClose();
    },
    onError: () => toast.error('Failed to save your dashboard.'),
  });

  const toggle = (key: string) =>
    setSelected((prev) => (prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]));

  return (
    // Modal's prop is `isOpen` (Modal.tsx:8), not `open`.
    <Modal isOpen={open} onClose={onClose} title="Choose your widgets">
      {catalog.isLoading ? (
        <div className="space-y-2">
          {Array.from({ length: 6 }, (_, i) => (
            <SkeletonBlock key={i} className="h-8 rounded" />
          ))}
        </div>
      ) : catalog.isError ? (
        <p className="text-xs text-danger">
          Could not load the widget catalog.{' '}
          <button type="button" className="underline" onClick={() => catalog.refetch()}>
            Try again
          </button>
        </p>
      ) : (
        <div className="max-h-80 overflow-y-auto space-y-1">
          {(catalog.data ?? []).map((widget) => (
            <div key={widget.key} className="flex items-center gap-2 py-1">
              {/* Checkbox renders its own <label>; do NOT wrap it in another
                  one or the accessible name resolves twice and the role query
                  in the test finds nothing. */}
              <Checkbox
                checked={selected.includes(widget.key)}
                onChange={() => toggle(widget.key)}
                label={widget.name}
              />
              <span className="text-2xs text-subtle ml-auto">{widget.module}</span>
            </div>
          ))}
        </div>
      )}

      <div className="flex justify-end gap-2 pt-3">
        <Button variant="secondary" onClick={onClose}>
          Cancel
        </Button>
        <Button onClick={() => save.mutate()} loading={save.isPending}>
          {save.isPending ? 'Saving…' : 'Save'}
        </Button>
      </div>
    </Modal>
  );
}
```

Prop names above were verified against the real primitives while writing this plan: `Modal` takes `isOpen` / `onClose` / `title` (`Modal.tsx:8-10`), and `Checkbox` extends `InputHTMLAttributes` with a `label` prop and renders its own `<label>` wrapper (`Checkbox.tsx:4-24`) — which is why the row above is a `<div>`, not a nested label.

- [ ] **Step 4: Make the dashboard use rich mode, real widths, and the picker**

In `spa/src/pages/dashboard/default.tsx`:

Fetch rich data and drop the separate scalar request for rich widgets (keep it — scalar widgets still need it):

```tsx
  const layout = useQuery({
    queryKey: ['dashboard', 'layout', 'rich'],
    queryFn: () => dashboardLayoutApi.layout({ rich: true }),
  });
```

Only ask for scalar summaries for the widgets that actually need one:

```tsx
  const scalarKeys = (layout.data ?? []).filter((w) => w.data === null).map((w) => w.key);
  const widgetData = useQuery({
    queryKey: ['dashboard', 'widget-data', scalarKeys],
    queryFn: () => dashboardLayoutApi.data(scalarKeys),
    enabled: scalarKeys.length > 0,
    refetchInterval: 60_000,
  });
```

Replace the fixed `PanelRow cols={3}` with a 12-column grid that honours each widget's width:

```tsx
              <div className="grid grid-cols-1 md:grid-cols-12 gap-3">
                {widgets.map((item) => (
                  <div
                    key={item.key}
                    className="min-h-[120px]"
                    style={{ gridColumn: `span ${Math.min(12, Math.max(1, item.w))}` }}
                  >
                    <WidgetErrorBoundary>
                      <LiveDashboardWidget
                        widget={item}
                        summary={widgetData.data?.[item.key]}
                        loading={widgetData.isLoading && item.data === null}
                      />
                    </WidgetErrorBoundary>
                  </div>
                ))}
              </div>
```

`gridColumn: span N` only applies from `md` up because the container is `grid-cols-1` below it, so tiles stack on a phone — a dashboard that needs horizontal scrolling on a tablet is a broken dashboard (`DashboardShell.tsx:26-30`).

Add the picker next to the existing Reset button:

```tsx
  const [pickerOpen, setPickerOpen] = useState(false);
```

```tsx
      actions={
        <div className="flex items-center gap-2">
          <Button variant="secondary" icon={<LayoutGrid size={14} />} onClick={() => setPickerOpen(true)}>
            Customize
          </Button>
          {canResetLayout && layout.data?.some((w) => w.source === 'user') && (
            <Button
              variant="secondary"
              icon={<RotateCcw size={14} />}
              onClick={() => reset.mutate()}
              loading={reset.isPending}
              aria-label="Reset dashboard layout to role default"
            >
              Reset to default
            </Button>
          )}
        </div>
      }
```

Mount the dialog inside the render body, and point the reset mutation's invalidation at the new query key:

```tsx
      <DashboardPicker
        open={pickerOpen}
        onClose={() => setPickerOpen(false)}
        currentKeys={(layout.data ?? []).map((w) => w.key)}
        onSaved={() => queryClient.invalidateQueries({ queryKey: ['dashboard', 'layout', 'rich'] })}
      />
```

Update `reset`'s `onSuccess` to invalidate `['dashboard', 'layout', 'rich']` as well, or the page keeps showing the personal layout it just deleted. Import `useState` from react, `LayoutGrid` from `lucide-react`, and `DashboardPicker`.

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -T spa npm run test -- --run DashboardPicker`
Expected: PASS (3 tests).

- [ ] **Step 6: Run the whole SPA suite + lint + tokens**

```bash
docker compose exec -T spa npm run test -- --run
docker compose exec -T spa npm run lint
docker compose exec -T spa npm run audit:tokens
```

Expected: all clean.

- [ ] **Step 7: Commit**

```bash
git add spa/src/components/dashboard/DashboardPicker.tsx \
        spa/src/components/dashboard/DashboardPicker.test.tsx \
        spa/src/pages/dashboard/default.tsx
git commit -m "feat: widget picker and width-aware dashboard grid"
```

---

### Task 9: Prove tiering is still a byproduct, then run everything

**Files:**
- Create: `api/tests/Feature/Dashboard/DashboardTieringTest.php`
- Test: the full suite.

**Interfaces:**
- Consumes: everything above.
- Produces: a regression gate asserting depth follows permissions, not role names.

- [ ] **Step 1: Write the test**

Create `api/tests/Feature/Dashboard/DashboardTieringTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\DashboardLayoutService;
use Database\Seeders\DashboardRoleLayoutSeeder;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tiering must remain a BYPRODUCT of permission matching. There is no
 * "full dashboard" flag and no role-name branch: a role ends up dense because
 * it qualifies for many widgets, and a role that gains a permission gains the
 * matching widgets without anyone editing dashboard code.
 */
class DashboardTieringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DashboardWidgetSeeder::class);
        $this->seed(DashboardRoleLayoutSeeder::class);
    }

    private function userFor(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }

    /** Breadth ordering: admin ⊃ a domain officer ⊃ self-service only. */
    public function test_qualifying_widget_count_orders_the_roles(): void
    {
        $service = app(DashboardLayoutService::class);

        $count = fn (string $slug): int => count($service->listAvailableWidgets($this->userFor($slug)));

        $admin = $count('system_admin');
        $manager = $count('production_manager');
        $employee = $count('employee');

        $this->assertGreaterThan($manager, $admin);
        $this->assertGreaterThan($employee, $manager);
    }

    /** An employee's dashboard is self-scoped — nothing company-wide leaks in. */
    public function test_self_service_role_sees_only_self_scoped_widgets(): void
    {
        $layout = app(DashboardLayoutService::class)->getEffectiveLayout($this->userFor('employee'));

        foreach ($layout as $row) {
            $this->assertStringStartsWith('self.', $row['key']);
        }
    }

    /**
     * The property that matters: granting a permission grants its widgets,
     * with no dashboard code change. This is what a role-name switch could
     * never do.
     */
    public function test_granting_a_permission_widens_the_widget_set(): void
    {
        $service = app(DashboardLayoutService::class);
        $role = Role::query()->where('slug', 'employee')->firstOrFail();
        $user = $this->userFor('employee');

        $before = count($service->listAvailableWidgets($user));

        $role->permissions()->attach(
            Permission::query()->where('slug', 'quality.view')->value('id'),
        );
        $user->flushPermissionsCache();

        $after = count($service->listAvailableWidgets($user->fresh()));

        $this->assertGreaterThan($before, $after);
    }

    /** No dashboard source file may branch on a role name. */
    public function test_no_role_name_branch_in_dashboard_code(): void
    {
        $files = array_merge(
            glob(app_path('Modules/Dashboard/Services/*.php')) ?: [],
            glob(app_path('Modules/Dashboard/Services/Analytics/*.php')) ?: [],
            glob(app_path('Modules/Dashboard/Support/*.php')) ?: [],
        );

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            // Comments legitimately name roles when explaining a scoping rule;
            // strip them so only executable code is inspected.
            $code = (string) preg_replace('#//.*|/\*.*?\*/#s', '', $source);

            $this->assertDoesNotMatchRegularExpression(
                "/role\??->slug\s*===/",
                $code,
                basename($file).' branches on a role name',
            );
        }
    }
}
```

- [ ] **Step 2: Run it**

Run: `docker compose exec -T api php artisan test --filter=DashboardTieringTest`
Expected: PASS (4 tests).

If `test_no_role_name_branch_in_dashboard_code` fails on a *pre-existing* file, that is a real finding — the guard is doing its job. `DashboardDispatchService.php:60` and `DashboardLayoutService.php:54` both compare `role?->slug === 'system_admin'`, which is the deliberate admin escape hatch, not tiering. Either route those two through `WidgetScope::isCompanyWide`-style helpers, or narrow the assertion to exclude the literal `'system_admin'` and document why in the test.

- [ ] **Step 3: Bump PHP memory and run the whole backend suite**

```bash
docker compose exec -T -u root api bash -c "echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/zz-mem.ini"
docker compose exec -T api php artisan test
```

Expected: PASS. Baseline before this work was 1242 tests; this plan adds roughly 30. A failure outside `Tests\Feature\Dashboard` means something in this work reached further than intended — fix it rather than adjusting the other test.

- [ ] **Step 4: Run the whole frontend suite and the gates**

```bash
docker compose exec -T spa npm run test -- --run
docker compose exec -T spa npm run lint
docker compose exec -T spa npm run audit:tokens
docker compose exec -T api ./vendor/bin/pint --test
```

Expected: all clean.

- [ ] **Step 5: Commit**

```bash
git add api/tests/Feature/Dashboard/DashboardTieringTest.php
git commit -m "test: tiering follows permissions, not role names"
```

---

## Verification

The work is done when all of the following hold:

- [ ] `docker compose exec -T api php artisan test` passes (~1272 tests).
- [ ] `docker compose exec -T spa npm run test -- --run` passes.
- [ ] `docker compose exec -T spa npm run lint` and `npm run audit:tokens` clean.
- [ ] `docker compose exec -T api ./vendor/bin/pint --test` clean.
- [ ] Logging in as each of `production_manager`, `department_head`, `qc_inspector`, and `employee` shows a dashboard whose density matches that role's responsibilities — charts and tables for the first three, four self-service tiles for the last.
- [ ] `grep -rn "role?->slug ===" api/app/Modules/Dashboard/` returns only the documented `system_admin` escape hatches.
- [ ] The six previously scalar-only domains render a rich shape.

## Out of scope (recorded, not built)

- The 8 bespoke dashboard pages and ~2,000 lines of per-role services remain a second composition path. Folding them into the registry is a separate, larger change.
- B2B portal dashboards sit outside RDBAC (separate guards, no permissions).
- Unifying the ad-hoc controller department scoping (`LoanController.php:79-84` role-slug literal vs `LeaveRequestController.php:70-77` permission proxy). `WidgetScope` is where that would land.
- No new bespoke pages for `department_head` / `maintenance_tech` / `impex_officer` — they get depth through registry richness.

