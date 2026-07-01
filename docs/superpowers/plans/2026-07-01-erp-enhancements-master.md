# ERP Enhancements — Master Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement 8 ERP enhancements that strengthen IATF 16949 compliance, deepen manufacturing intelligence, and fill coverage gaps.

**Architecture:** Extends existing Quality, Production, and Dashboard modules. Each feature section is independently executable. Features are ordered by dependency: SPC → COPQ → Production → KPI → Document Control → Shop Floor → API Docs. Traceability is nearly complete and can run anytime.

**Tech Stack:** Laravel 11 (PHP 8.3), React 18 (TypeScript), PostgreSQL 16, TanStack Query, Recharts, Zustand. No new packages except `darkaonline/l5-swagger` for Feature 8.

**Spec source:** `docs/superpowers/specs/2026-07-01-erp-enhancements-design.md`

## Global Constraints

- Follow CLAUDE.md conventions: Migration → Enum → Model → Service → Request → Resource → Controller → Routes → Types → API → Pages
- Every model uses `HasHashId` trait. Every API Resource returns `hash_id`, never raw `id`
- Every list page handles 5 states: loading, error, empty, data, stale
- Numbers use `font-mono tabular-nums`. Status fields use `<Chip>`
- Migrations numbered sequentially from current highest: **0252+**
- Test seeds: column varchars mostly 20 chars. Use `'XX-T-'.substr(uniqid(), -5)` for unique values
- Read `docs/PATTERNS.md` before implementing any page — copy matching template, adapt

---

## Feature 1: SPC Control Charts & Run Rules (~10 days)

### What Exists
- `SpcService` (109 lines): `compute(measurements[], usl, lsl)` → Cp/Cpk/Cpu/Cpl. Pure math, no persistence.
- `SpcService::computeForSpec(specId)` → loops InspectionSpecItems, pulls measurements from DB, returns keyed array.
- Route: `GET /quality/inspection-specs/{spec}/spc` → returns Cp/Cpk per spec item.
- Models: `InspectionMeasurement` (with `measured_value`, `tolerance_min`, `tolerance_max`, `inspection_spec_item_id`), `InspectionSpecItem` (with `tolerance_min`, `tolerance_max`, `parameter_name`).
- Events: `InspectionPassed`, `InspectionFailed` exist (fired after inspection completion).

### What to Build
- Control chart persistence: `spc_control_charts` + `spc_data_points` tables
- Run rules engine (Western Electric 4 rules)
- SPC alerts table + event + notification
- Auto-population listener on inspection completion
- SPC controller with CRUD + alert endpoints
- Frontend: chart list page, interactive control chart (Recharts), capability study page

### File Structure

**Create:**
- `api/database/migrations/0252_create_spc_control_charts_table.php`
- `api/database/migrations/0253_create_spc_data_points_table.php`
- `api/database/migrations/0254_create_spc_alerts_table.php`
- `api/app/Modules/Quality/Enums/SpcChartType.php`
- `api/app/Modules/Quality/Enums/SpcChartStatus.php`
- `api/app/Modules/Quality/Enums/SpcAlertRule.php`
- `api/app/Modules/Quality/Models/SpcControlChart.php`
- `api/app/Modules/Quality/Models/SpcDataPoint.php`
- `api/app/Modules/Quality/Models/SpcAlert.php`
- `api/app/Modules/Quality/Controllers/SpcController.php`
- `api/app/Modules/Quality/Resources/SpcControlChartResource.php`
- `api/app/Modules/Quality/Resources/SpcDataPointResource.php`
- `api/app/Modules/Quality/Resources/SpcAlertResource.php`
- `api/app/Modules/Quality/Requests/StoreSpcChartRequest.php`
- `api/app/Modules/Quality/Events/SpcAlertTriggered.php`
- `api/app/Modules/Quality/Listeners/AutoPopulateSpcChart.php`
- `api/app/Modules/Quality/Listeners/NotifyOnSpcAlert.php`
- `api/tests/Feature/Quality/SpcControlChartTest.php`
- `api/tests/Unit/Quality/SpcRunRulesTest.php`
- `spa/src/api/quality/spc.ts`
- `spa/src/types/quality/spc.ts`
- `spa/src/pages/quality/spc/index.tsx`
- `spa/src/pages/quality/spc/chart-detail.tsx`
- `spa/src/pages/quality/spc/capability-study.tsx`

**Modify:**
- `api/app/Modules/Quality/Services/SpcService.php` — add control chart methods, run rules engine
- `api/app/Modules/Quality/routes.php` — add SPC routes
- `api/app/Providers/AppServiceProvider.php` — register SPC event listeners
- `api/database/seeders/RolePermissionSeeder.php` — add `quality.spc.view`, `quality.spc.manage`
- `spa/src/App.tsx` (or routes file) — add SPC routes

### Task 1: Migrations + Enums + Models

**Files:**
- Create: `api/database/migrations/0252_create_spc_control_charts_table.php`
- Create: `api/database/migrations/0253_create_spc_data_points_table.php`
- Create: `api/database/migrations/0254_create_spc_alerts_table.php`
- Create: `api/app/Modules/Quality/Enums/SpcChartType.php`
- Create: `api/app/Modules/Quality/Enums/SpcChartStatus.php`
- Create: `api/app/Modules/Quality/Enums/SpcAlertRule.php`
- Create: `api/app/Modules/Quality/Models/SpcControlChart.php`
- Create: `api/app/Modules/Quality/Models/SpcDataPoint.php`
- Create: `api/app/Modules/Quality/Models/SpcAlert.php`
- Modify: `api/database/seeders/RolePermissionSeeder.php` — add SPC permission slugs

**Interfaces:**
- Produces: `SpcControlChart` model with relationships `dataPoints()`, `alerts()`, `product()`, `specItem()`
- Produces: `SpcDataPoint` model with `controlChart()` relationship
- Produces: `SpcAlert` model with `controlChart()`, `dataPoint()` relationships
- Produces: Enums `SpcChartType` (xbar_r, imr, p_chart), `SpcChartStatus` (active, monitoring, suspended), `SpcAlertRule` (rule_1_beyond_3sigma, rule_2_two_of_three_beyond_2sigma, rule_3_four_of_five_beyond_1sigma, rule_4_eight_same_side)

- [ ] **Step 1: Create SpcChartType enum**

```php
// api/app/Modules/Quality/Enums/SpcChartType.php
<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

enum SpcChartType: string
{
    case XbarR = 'xbar_r';
    case Imr = 'imr';
    case PChart = 'p_chart';
}
```

- [ ] **Step 2: Create SpcChartStatus enum**

```php
// api/app/Modules/Quality/Enums/SpcChartStatus.php
<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

enum SpcChartStatus: string
{
    case Active = 'active';
    case Monitoring = 'monitoring';
    case Suspended = 'suspended';
}
```

- [ ] **Step 3: Create SpcAlertRule enum**

```php
// api/app/Modules/Quality/Enums/SpcAlertRule.php
<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

enum SpcAlertRule: string
{
    case BeyondThreeSigma = 'rule_1_beyond_3sigma';
    case TwoOfThreeBeyondTwoSigma = 'rule_2_two_of_three_beyond_2sigma';
    case FourOfFiveBeyondOneSigma = 'rule_3_four_of_five_beyond_1sigma';
    case EightSameSide = 'rule_4_eight_same_side';
}
```

- [ ] **Step 4: Create migration 0252_create_spc_control_charts_table.php**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spc_control_charts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('spec_item_id')->constrained('inspection_spec_items')->cascadeOnDelete();
            $table->string('chart_type', 20);
            $table->unsignedSmallInteger('subgroup_size')->default(5);
            $table->decimal('ucl', 15, 6)->nullable();
            $table->decimal('lcl', 15, 6)->nullable();
            $table->decimal('center_line', 15, 6)->nullable();
            $table->decimal('ucl_range', 15, 6)->nullable();
            $table->decimal('lcl_range', 15, 6)->nullable();
            $table->decimal('center_range', 15, 6)->nullable();
            $table->boolean('limits_locked')->default(false);
            $table->unsignedInteger('limits_sample_count')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['product_id', 'spec_item_id', 'chart_type'], 'spc_charts_product_spec_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spc_control_charts');
    }
};
```

- [ ] **Step 5: Create migration 0253_create_spc_data_points_table.php**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spc_data_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_chart_id')->constrained('spc_control_charts')->cascadeOnDelete();
            $table->unsignedInteger('subgroup_number');
            $table->decimal('subgroup_mean', 15, 6)->nullable();
            $table->decimal('subgroup_range', 15, 6)->nullable();
            $table->decimal('subgroup_std_dev', 15, 6)->nullable();
            $table->decimal('individual_value', 15, 6)->nullable();
            $table->decimal('moving_range', 15, 6)->nullable();
            $table->json('sample_values');
            $table->timestamp('recorded_at');
            $table->json('alerts')->nullable();
            $table->json('inspection_ids')->nullable();
            $table->timestamps();

            $table->index(['control_chart_id', 'subgroup_number']);
            $table->index(['control_chart_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spc_data_points');
    }
};
```

- [ ] **Step 6: Create migration 0254_create_spc_alerts_table.php**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spc_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_chart_id')->constrained('spc_control_charts')->cascadeOnDelete();
            $table->foreignId('data_point_id')->constrained('spc_data_points')->cascadeOnDelete();
            $table->string('rule_code', 50);
            $table->string('severity', 20)->default('warning');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['control_chart_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spc_alerts');
    }
};
```

- [ ] **Step 7: Create SpcControlChart model**

```php
// api/app/Modules/Quality/Models/SpcControlChart.php
<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasHashId;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\SpcChartStatus;
use App\Modules\Quality\Enums\SpcChartType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpcControlChart extends Model
{
    use HasHashId;

    protected $fillable = [
        'product_id', 'spec_item_id', 'chart_type', 'subgroup_size',
        'ucl', 'lcl', 'center_line', 'ucl_range', 'lcl_range', 'center_range',
        'limits_locked', 'limits_sample_count', 'status',
    ];

    protected $casts = [
        'chart_type'          => SpcChartType::class,
        'status'              => SpcChartStatus::class,
        'subgroup_size'       => 'integer',
        'ucl'                 => 'decimal:6',
        'lcl'                 => 'decimal:6',
        'center_line'         => 'decimal:6',
        'ucl_range'           => 'decimal:6',
        'lcl_range'           => 'decimal:6',
        'center_range'        => 'decimal:6',
        'limits_locked'       => 'boolean',
        'limits_sample_count' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function specItem(): BelongsTo
    {
        return $this->belongsTo(InspectionSpecItem::class, 'spec_item_id');
    }

    public function dataPoints(): HasMany
    {
        return $this->hasMany(SpcDataPoint::class, 'control_chart_id')->orderBy('subgroup_number');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(SpcAlert::class, 'control_chart_id');
    }

    public function unresolvedAlerts(): HasMany
    {
        return $this->alerts()->whereNull('resolved_at');
    }
}
```

- [ ] **Step 8: Create SpcDataPoint model**

```php
// api/app/Modules/Quality/Models/SpcDataPoint.php
<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpcDataPoint extends Model
{
    use HasHashId;

    protected $fillable = [
        'control_chart_id', 'subgroup_number', 'subgroup_mean', 'subgroup_range',
        'subgroup_std_dev', 'individual_value', 'moving_range',
        'sample_values', 'recorded_at', 'alerts', 'inspection_ids',
    ];

    protected $casts = [
        'subgroup_number'  => 'integer',
        'subgroup_mean'    => 'decimal:6',
        'subgroup_range'   => 'decimal:6',
        'subgroup_std_dev' => 'decimal:6',
        'individual_value' => 'decimal:6',
        'moving_range'     => 'decimal:6',
        'sample_values'    => 'array',
        'alerts'           => 'array',
        'inspection_ids'   => 'array',
        'recorded_at'      => 'datetime',
    ];

    public function controlChart(): BelongsTo
    {
        return $this->belongsTo(SpcControlChart::class, 'control_chart_id');
    }
}
```

- [ ] **Step 9: Create SpcAlert model**

```php
// api/app/Modules/Quality/Models/SpcAlert.php
<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\SpcAlertRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpcAlert extends Model
{
    use HasHashId;

    protected $fillable = [
        'control_chart_id', 'data_point_id', 'rule_code', 'severity',
        'acknowledged_by', 'acknowledged_at', 'resolved_at', 'notes',
    ];

    protected $casts = [
        'rule_code'        => SpcAlertRule::class,
        'acknowledged_at'  => 'datetime',
        'resolved_at'      => 'datetime',
    ];

    public function controlChart(): BelongsTo
    {
        return $this->belongsTo(SpcControlChart::class, 'control_chart_id');
    }

    public function dataPoint(): BelongsTo
    {
        return $this->belongsTo(SpcDataPoint::class, 'data_point_id');
    }

    public function acknowledgedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
```

- [ ] **Step 10: Add SPC permissions to RolePermissionSeeder**

Find the quality permission block in `api/database/seeders/RolePermissionSeeder.php` and add:

```php
'quality.spc.view' => 'View SPC control charts and capability studies',
'quality.spc.manage' => 'Create/modify SPC charts and acknowledge alerts',
```

Grant `quality.spc.view` to: `qc_inspector`, `production_manager`, `system_admin`.
Grant `quality.spc.manage` to: `qc_inspector`, `system_admin`.

- [ ] **Step 11: Run migrations**

```bash
cd api && php artisan migrate
```

- [ ] **Step 12: Commit**

```bash
git add -A && git commit -m "feat(spc): add control chart migrations, enums, and models"
```

### Task 2: SPC Run Rules Engine + Control Chart Service

**Files:**
- Modify: `api/app/Modules/Quality/Services/SpcService.php`
- Create: `api/tests/Unit/Quality/SpcRunRulesTest.php`

**Interfaces:**
- Consumes: `SpcControlChart`, `SpcDataPoint`, `SpcAlert` models from Task 1
- Produces: `SpcService::createChart()`, `::recordDataPoint()`, `::evaluateRunRules()`, `::recalculateLimits()`, `::computeCapabilityStudy()`

- [ ] **Step 1: Write failing test for run rules**

```php
// api/tests/Unit/Quality/SpcRunRulesTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Quality;

use App\Modules\Quality\Enums\SpcAlertRule;
use App\Modules\Quality\Services\SpcService;
use Tests\TestCase;

class SpcRunRulesTest extends TestCase
{
    private SpcService $spc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spc = app(SpcService::class);
    }

    public function test_rule_1_beyond_three_sigma(): void
    {
        // UCL=30, LCL=10, center=20 → σ ≈ 3.33
        $violations = $this->spc->evaluateRunRulesFromValues(
            recentMeans: [20.0, 19.5, 20.5, 31.0],
            centerLine: 20.0,
            ucl: 30.0,
            lcl: 10.0
        );

        $this->assertContains(SpcAlertRule::BeyondThreeSigma, $violations);
    }

    public function test_rule_4_eight_same_side(): void
    {
        $violations = $this->spc->evaluateRunRulesFromValues(
            recentMeans: [21.0, 21.5, 22.0, 21.0, 20.5, 21.0, 22.0, 21.5],
            centerLine: 20.0,
            ucl: 30.0,
            lcl: 10.0
        );

        $this->assertContains(SpcAlertRule::EightSameSide, $violations);
    }

    public function test_no_violations_for_normal_data(): void
    {
        $violations = $this->spc->evaluateRunRulesFromValues(
            recentMeans: [20.1, 19.8, 20.3, 19.9, 20.0],
            centerLine: 20.0,
            ucl: 30.0,
            lcl: 10.0
        );

        $this->assertEmpty($violations);
    }

    public function test_rule_2_two_of_three_beyond_two_sigma(): void
    {
        // 2σ zone: 13.33–16.67 and 23.33–26.67
        $violations = $this->spc->evaluateRunRulesFromValues(
            recentMeans: [20.0, 27.0, 19.0, 28.0],
            centerLine: 20.0,
            ucl: 30.0,
            lcl: 10.0
        );

        $this->assertContains(SpcAlertRule::TwoOfThreeBeyondTwoSigma, $violations);
    }

    public function test_rule_3_four_of_five_beyond_one_sigma(): void
    {
        // 1σ zone boundary: 16.67 and 23.33
        $violations = $this->spc->evaluateRunRulesFromValues(
            recentMeans: [24.0, 25.0, 20.0, 24.0, 25.0],
            centerLine: 20.0,
            ucl: 30.0,
            lcl: 10.0
        );

        $this->assertContains(SpcAlertRule::FourOfFiveBeyondOneSigma, $violations);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd api && php artisan test --filter=SpcRunRulesTest
```

Expected: FAIL — `evaluateRunRulesFromValues` method does not exist.

- [ ] **Step 3: Extend SpcService with run rules and control chart methods**

Replace the entire `api/app/Modules/Quality/Services/SpcService.php` with the extended version. Keep the existing `compute()` and `computeForSpec()` methods unchanged. Add these new methods:

```php
// Add after the existing computeForSpec() method:

/**
 * X-bar/R control limit constants (A2, D3, D4) keyed by subgroup size.
 * Source: ASTM E2587 / Montgomery "Introduction to SPC" Table VI.
 */
private const XBAR_R_CONSTANTS = [
    2 => ['A2' => 1.880, 'D3' => 0.000, 'D4' => 3.267, 'd2' => 1.128],
    3 => ['A2' => 1.023, 'D3' => 0.000, 'D4' => 2.574, 'd2' => 1.693],
    4 => ['A2' => 0.729, 'D3' => 0.000, 'D4' => 2.282, 'd2' => 2.059],
    5 => ['A2' => 0.577, 'D3' => 0.000, 'D4' => 2.114, 'd2' => 2.326],
    6 => ['A2' => 0.483, 'D3' => 0.000, 'D4' => 2.004, 'd2' => 2.534],
    7 => ['A2' => 0.419, 'D3' => 0.076, 'D4' => 1.924, 'd2' => 2.704],
    8 => ['A2' => 0.373, 'D3' => 0.136, 'D4' => 1.864, 'd2' => 2.847],
    9 => ['A2' => 0.337, 'D3' => 0.184, 'D4' => 1.816, 'd2' => 2.970],
    10 => ['A2' => 0.308, 'D3' => 0.223, 'D4' => 1.777, 'd2' => 3.078],
];

public function createChart(int $productId, int $specItemId, SpcChartType $type, int $subgroupSize = 5): SpcControlChart
{
    return SpcControlChart::create([
        'product_id'    => $productId,
        'spec_item_id'  => $specItemId,
        'chart_type'    => $type,
        'subgroup_size' => $subgroupSize,
        'status'        => SpcChartStatus::Active,
    ]);
}

public function recordDataPoint(SpcControlChart $chart, array $measurements, array $inspectionIds = []): SpcDataPoint
{
    $nextSubgroup = ($chart->dataPoints()->max('subgroup_number') ?? 0) + 1;

    $values = array_values(array_filter($measurements, fn ($v) => $v !== null && is_numeric($v)));
    $values = array_map(fn ($v) => (float) $v, $values);

    $mean = count($values) > 0 ? array_sum($values) / count($values) : 0;
    $range = count($values) > 1 ? max($values) - min($values) : 0;
    $stdDev = count($values) > 1 ? $this->stdDev($values) : null;

    $point = SpcDataPoint::create([
        'control_chart_id' => $chart->id,
        'subgroup_number'  => $nextSubgroup,
        'subgroup_mean'    => round($mean, 6),
        'subgroup_range'   => round($range, 6),
        'subgroup_std_dev' => $stdDev !== null ? round($stdDev, 6) : null,
        'individual_value' => $chart->chart_type === SpcChartType::Imr ? $values[0] ?? null : null,
        'moving_range'     => null,
        'sample_values'    => $values,
        'recorded_at'      => now(),
        'inspection_ids'   => $inspectionIds,
    ]);

    if ($chart->chart_type === SpcChartType::Imr && $nextSubgroup > 1) {
        $prevPoint = $chart->dataPoints()->where('subgroup_number', $nextSubgroup - 1)->first();
        if ($prevPoint && $prevPoint->individual_value !== null && $point->individual_value !== null) {
            $point->update(['moving_range' => round(abs((float) $point->individual_value - (float) $prevPoint->individual_value), 6)]);
        }
    }

    if (!$chart->limits_locked && $chart->center_line !== null) {
        $violations = $this->evaluateRunRules($chart, $point);
        if (!empty($violations)) {
            $point->update(['alerts' => array_map(fn ($r) => $r->value, $violations)]);
            foreach ($violations as $rule) {
                $severity = $rule === SpcAlertRule::BeyondThreeSigma ? 'critical' : 'warning';
                SpcAlert::create([
                    'control_chart_id' => $chart->id,
                    'data_point_id'    => $point->id,
                    'rule_code'        => $rule,
                    'severity'         => $severity,
                ]);
            }
            event(new \App\Modules\Quality\Events\SpcAlertTriggered($chart, $point, $violations));
        }
    }

    if (!$chart->limits_locked) {
        $totalPoints = $chart->dataPoints()->count();
        if ($totalPoints >= 25 && ($totalPoints % 5 === 0 || $chart->center_line === null)) {
            $this->recalculateLimits($chart);
        }
    }

    return $point->fresh();
}

public function recalculateLimits(SpcControlChart $chart): void
{
    $points = $chart->dataPoints()->orderBy('subgroup_number', 'desc')->limit(50)->get();
    if ($points->count() < 20) {
        return;
    }

    if ($chart->chart_type === SpcChartType::XbarR) {
        $constants = self::XBAR_R_CONSTANTS[$chart->subgroup_size] ?? self::XBAR_R_CONSTANTS[5];
        $grandMean = $points->avg('subgroup_mean');
        $avgRange = $points->avg('subgroup_range');

        $chart->update([
            'center_line'         => round((float) $grandMean, 6),
            'ucl'                 => round((float) $grandMean + $constants['A2'] * (float) $avgRange, 6),
            'lcl'                 => round((float) $grandMean - $constants['A2'] * (float) $avgRange, 6),
            'center_range'        => round((float) $avgRange, 6),
            'ucl_range'           => round($constants['D4'] * (float) $avgRange, 6),
            'lcl_range'           => round($constants['D3'] * (float) $avgRange, 6),
            'limits_sample_count' => $points->count(),
        ]);
    } elseif ($chart->chart_type === SpcChartType::Imr) {
        $values = $points->pluck('individual_value')->filter()->values();
        $mRanges = $points->pluck('moving_range')->filter()->values();
        $mean = $values->avg();
        $avgMR = $mRanges->avg();

        $chart->update([
            'center_line'         => round((float) $mean, 6),
            'ucl'                 => round((float) $mean + 2.66 * (float) $avgMR, 6),
            'lcl'                 => round((float) $mean - 2.66 * (float) $avgMR, 6),
            'center_range'        => round((float) $avgMR, 6),
            'ucl_range'           => round(3.267 * (float) $avgMR, 6),
            'lcl_range'           => 0,
            'limits_sample_count' => $values->count(),
        ]);
    }
}

/**
 * Evaluate Western Electric run rules for a new data point.
 *
 * @return SpcAlertRule[]
 */
public function evaluateRunRules(SpcControlChart $chart, SpcDataPoint $point): array
{
    if ($chart->center_line === null || $chart->ucl === null || $chart->lcl === null) {
        return [];
    }

    $recentPoints = $chart->dataPoints()
        ->where('subgroup_number', '<=', $point->subgroup_number)
        ->orderBy('subgroup_number', 'desc')
        ->limit(8)
        ->pluck('subgroup_mean')
        ->map(fn ($v) => (float) $v)
        ->toArray();

    return $this->evaluateRunRulesFromValues(
        recentMeans: $recentPoints,
        centerLine: (float) $chart->center_line,
        ucl: (float) $chart->ucl,
        lcl: (float) $chart->lcl,
    );
}

/**
 * Pure function: evaluate run rules from values (unit-testable without DB).
 *
 * @param  float[]  $recentMeans  Most recent first (index 0 = current point)
 * @return SpcAlertRule[]
 */
public function evaluateRunRulesFromValues(array $recentMeans, float $centerLine, float $ucl, float $lcl): array
{
    $violations = [];
    $sigma = ($ucl - $centerLine) / 3;
    if ($sigma <= 0 || count($recentMeans) === 0) {
        return [];
    }

    $current = $recentMeans[0];

    // Rule 1: one point beyond 3σ (UCL/LCL)
    if ($current > $ucl || $current < $lcl) {
        $violations[] = SpcAlertRule::BeyondThreeSigma;
    }

    // Rule 2: 2 of 3 consecutive points beyond 2σ (same side)
    if (count($recentMeans) >= 3) {
        $twoSigmaUpper = $centerLine + 2 * $sigma;
        $twoSigmaLower = $centerLine - 2 * $sigma;

        $aboveCount = 0;
        $belowCount = 0;
        for ($i = 0; $i < 3; $i++) {
            if ($recentMeans[$i] > $twoSigmaUpper) $aboveCount++;
            if ($recentMeans[$i] < $twoSigmaLower) $belowCount++;
        }
        if ($aboveCount >= 2 || $belowCount >= 2) {
            $violations[] = SpcAlertRule::TwoOfThreeBeyondTwoSigma;
        }
    }

    // Rule 3: 4 of 5 consecutive points beyond 1σ (same side)
    if (count($recentMeans) >= 5) {
        $oneSigmaUpper = $centerLine + $sigma;
        $oneSigmaLower = $centerLine - $sigma;

        $aboveCount = 0;
        $belowCount = 0;
        for ($i = 0; $i < 5; $i++) {
            if ($recentMeans[$i] > $oneSigmaUpper) $aboveCount++;
            if ($recentMeans[$i] < $oneSigmaLower) $belowCount++;
        }
        if ($aboveCount >= 4 || $belowCount >= 4) {
            $violations[] = SpcAlertRule::FourOfFiveBeyondOneSigma;
        }
    }

    // Rule 4: 8 consecutive points on same side of center line
    if (count($recentMeans) >= 8) {
        $aboveCount = 0;
        $belowCount = 0;
        for ($i = 0; $i < 8; $i++) {
            if ($recentMeans[$i] > $centerLine) $aboveCount++;
            if ($recentMeans[$i] < $centerLine) $belowCount++;
        }
        if ($aboveCount === 8 || $belowCount === 8) {
            $violations[] = SpcAlertRule::EightSameSide;
        }
    }

    return $violations;
}

public function computeCapabilityStudy(int $productId, int $specItemId, int $sampleSize = 50): ?array
{
    $specItem = InspectionSpecItem::find($specItemId);
    if (!$specItem || $specItem->tolerance_min === null || $specItem->tolerance_max === null) {
        return null;
    }

    $measurements = DB::table('inspection_measurements')
        ->where('inspection_spec_item_id', $specItemId)
        ->whereNotNull('measured_value')
        ->orderByDesc('id')
        ->limit($sampleSize)
        ->pluck('measured_value')
        ->map(fn ($v) => (float) $v)
        ->toArray();

    $result = $this->compute($measurements, (float) $specItem->tolerance_max, (float) $specItem->tolerance_min);
    if ($result === null) {
        return null;
    }

    $result['histogram'] = $this->buildHistogram($measurements, (float) $specItem->tolerance_min, (float) $specItem->tolerance_max);

    return $result;
}

private function buildHistogram(array $values, float $lsl, float $usl, int $bins = 20): array
{
    if (empty($values)) return [];

    $min = min(min($values), $lsl);
    $max = max(max($values), $usl);
    $range = $max - $min;
    if ($range <= 0) return [];

    $binWidth = $range / $bins;
    $histogram = array_fill(0, $bins, 0);
    $binEdges = [];

    for ($i = 0; $i <= $bins; $i++) {
        $binEdges[] = round($min + $i * $binWidth, 4);
    }

    foreach ($values as $v) {
        $idx = min((int) floor(($v - $min) / $binWidth), $bins - 1);
        $histogram[$idx]++;
    }

    return [
        'bins'      => $histogram,
        'bin_edges' => $binEdges,
        'lsl'       => $lsl,
        'usl'       => $usl,
    ];
}

private function stdDev(array $values): float
{
    $n = count($values);
    if ($n < 2) return 0;
    $mean = array_sum($values) / $n;
    $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / ($n - 1);
    return sqrt($variance);
}
```

Also add the necessary imports at the top of SpcService:

```php
use App\Modules\Quality\Enums\SpcAlertRule;
use App\Modules\Quality\Enums\SpcChartStatus;
use App\Modules\Quality\Enums\SpcChartType;
use App\Modules\Quality\Models\SpcAlert;
use App\Modules\Quality\Models\SpcControlChart;
use App\Modules\Quality\Models\SpcDataPoint;
```

- [ ] **Step 4: Run tests**

```bash
cd api && php artisan test --filter=SpcRunRulesTest
```

Expected: All 5 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(spc): add run rules engine and control chart service methods"
```

### Task 3: SPC Controller + Routes + Event Listeners

**Files:**
- Create: `api/app/Modules/Quality/Controllers/SpcController.php`
- Create: `api/app/Modules/Quality/Resources/SpcControlChartResource.php`
- Create: `api/app/Modules/Quality/Resources/SpcDataPointResource.php`
- Create: `api/app/Modules/Quality/Resources/SpcAlertResource.php`
- Create: `api/app/Modules/Quality/Requests/StoreSpcChartRequest.php`
- Create: `api/app/Modules/Quality/Events/SpcAlertTriggered.php`
- Create: `api/app/Modules/Quality/Listeners/AutoPopulateSpcChart.php`
- Create: `api/app/Modules/Quality/Listeners/NotifyOnSpcAlert.php`
- Modify: `api/app/Modules/Quality/routes.php`
- Modify: `api/app/Providers/AppServiceProvider.php`
- Create: `api/tests/Feature/Quality/SpcControlChartTest.php`

**Interfaces:**
- Consumes: `SpcService` methods from Task 2
- Produces: REST endpoints for SPC chart CRUD, data retrieval, alert acknowledgment

Due to length, the controller/resource/request/test code follows standard patterns from `docs/PATTERNS.md`. Key endpoints:

```
GET    /quality/spc/charts              — list charts (filter by product, status)
POST   /quality/spc/charts              — create chart (product_id, spec_item_id, chart_type)
GET    /quality/spc/charts/{chart}      — chart detail with recent data points
GET    /quality/spc/charts/{chart}/data — paginated data points
POST   /quality/spc/charts/{chart}/recalculate — force limit recalculation
POST   /quality/spc/capability          — run capability study
GET    /quality/spc/alerts              — unresolved alerts
POST   /quality/spc/alerts/{alert}/acknowledge
```

- [ ] **Step 1: Create SpcAlertTriggered event**

```php
// api/app/Modules/Quality/Events/SpcAlertTriggered.php
<?php

declare(strict_types=1);

namespace App\Modules\Quality\Events;

use App\Modules\Quality\Enums\SpcAlertRule;
use App\Modules\Quality\Models\SpcControlChart;
use App\Modules\Quality\Models\SpcDataPoint;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SpcAlertTriggered implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly SpcControlChart $chart,
        public readonly SpcDataPoint $dataPoint,
        public readonly array $violations,
    ) {}
}
```

- [ ] **Step 2: Create AutoPopulateSpcChart listener**

Listens to `InspectionPassed` and `InspectionFailed` events. When an inspection completes, checks if any active SPC chart exists for the inspected product+spec item. If so, pulls the new measurements and records a data point.

```php
// api/app/Modules/Quality/Listeners/AutoPopulateSpcChart.php
<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Modules\Quality\Enums\SpcChartStatus;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\SpcControlChart;
use App\Modules\Quality\Services\SpcService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class AutoPopulateSpcChart implements ShouldQueue
{
    public function __construct(private readonly SpcService $spc) {}

    public function handle(object $event): void
    {
        try {
            $inspection = $event->inspection;
            if (!$inspection || !$inspection->product_id) {
                return;
            }

            $charts = SpcControlChart::where('product_id', $inspection->product_id)
                ->where('status', SpcChartStatus::Active)
                ->get();

            foreach ($charts as $chart) {
                $measurements = InspectionMeasurement::where('inspection_id', $inspection->id)
                    ->where('inspection_spec_item_id', $chart->spec_item_id)
                    ->whereNotNull('measured_value')
                    ->pluck('measured_value')
                    ->map(fn ($v) => (float) $v)
                    ->toArray();

                if (count($measurements) >= $chart->subgroup_size) {
                    $subgroup = array_slice($measurements, 0, $chart->subgroup_size);
                    $this->spc->recordDataPoint($chart, $subgroup, [$inspection->id]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SPC auto-populate failed: ' . $e->getMessage());
        }
    }
}
```

- [ ] **Step 3: Create NotifyOnSpcAlert listener**

```php
// api/app/Modules/Quality/Listeners/NotifyOnSpcAlert.php
<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Events\SpcAlertTriggered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnSpcAlert implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(SpcAlertTriggered $event): void
    {
        try {
            $chart = $event->chart->load('product', 'specItem');
            $recipients = User::whereHas('role', fn ($q) => $q->whereIn('slug', ['qc_inspector', 'production_manager']))
                ->where('is_active', true)
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            $ruleNames = array_map(fn ($r) => $r->value, $event->violations);

            $this->notifications->send($recipients, 'spc_alert', [
                'title'   => 'SPC Alert: ' . ($chart->product->name ?? 'Unknown') . ' — ' . ($chart->specItem->parameter_name ?? ''),
                'message' => 'Control chart violation detected: ' . implode(', ', $ruleNames),
                'url'     => '/quality/spc/charts/' . $chart->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SPC alert notification failed: ' . $e->getMessage());
        }
    }
}
```

- [ ] **Step 4: Create StoreSpcChartRequest, Resources, Controller**

Follow the pattern from `docs/PATTERNS.md` for controller/request/resource. The controller delegates to `SpcService` for all business logic.

- [ ] **Step 5: Add routes to Quality routes.php**

Add inside the `Route::middleware(['auth:sanctum', 'feature:quality'])->prefix('quality')` group:

```php
/* ─── SPC — Statistical Process Control ─── */
Route::get('/spc/charts',                     [SpcController::class, 'index'])      ->middleware('permission:quality.spc.view');
Route::post('/spc/charts',                    [SpcController::class, 'store'])      ->middleware('permission:quality.spc.manage');
Route::get('/spc/charts/{chart}',             [SpcController::class, 'show'])       ->middleware('permission:quality.spc.view');
Route::get('/spc/charts/{chart}/data',        [SpcController::class, 'data'])       ->middleware('permission:quality.spc.view');
Route::post('/spc/charts/{chart}/recalculate',[SpcController::class, 'recalculate'])->middleware('permission:quality.spc.manage');
Route::post('/spc/capability',                [SpcController::class, 'capability']) ->middleware('permission:quality.spc.view');
Route::get('/spc/alerts',                     [SpcController::class, 'alerts'])     ->middleware('permission:quality.spc.view');
Route::post('/spc/alerts/{alert}/acknowledge',[SpcController::class, 'acknowledgeAlert'])->middleware('permission:quality.spc.manage');
```

- [ ] **Step 6: Register event listeners in AppServiceProvider**

Add to `boot()` method:

```php
Event::listen(InspectionPassed::class, [AutoPopulateSpcChart::class, 'handle']);
Event::listen(InspectionFailed::class, [AutoPopulateSpcChart::class, 'handle']);
Event::listen(SpcAlertTriggered::class, [NotifyOnSpcAlert::class, 'handle']);
```

- [ ] **Step 7: Write feature test**

Test: create chart → record 25+ data points → verify limits calculated → record OOC point → verify alert created.

- [ ] **Step 8: Run tests**

```bash
cd api && php artisan test --filter=SpcControlChartTest
```

- [ ] **Step 9: Commit**

```bash
git add -A && git commit -m "feat(spc): add controller, routes, event listeners, and feature tests"
```

### Task 4: SPC Frontend — Types, API, Pages

**Files:**
- Create: `spa/src/types/quality/spc.ts`
- Create: `spa/src/api/quality/spc.ts`
- Create: `spa/src/pages/quality/spc/index.tsx`
- Create: `spa/src/pages/quality/spc/chart-detail.tsx`
- Create: `spa/src/pages/quality/spc/capability-study.tsx`
- Modify: Routes file to add SPC routes
- Modify: Sidebar to add SPC nav item under Quality

**Interfaces:**
- Consumes: SPC API endpoints from Task 3
- Produces: Three SPC pages for the Quality section

Follow `docs/PATTERNS.md` list page + detail page patterns. Key UI components:

**Chart Detail Page (`chart-detail.tsx`):**
- Recharts `LineChart` with:
  - X-axis: subgroup number
  - Y-axis: measurement value
  - UCL/LCL/CL as `ReferenceLine` components (dashed, colored)
  - Zone shading via `ReferenceArea` for 1σ/2σ/3σ bands
  - Data points as `Scatter` dots, color-coded by violation status
  - Tooltip showing sample values and any rule violations
- Below the main chart: Range chart (same layout with range UCL/LCL/CL)
- Date range filter
- "Recalculate Limits" button

**Capability Study Page (`capability-study.tsx`):**
- Select product + spec item dropdowns
- Run study button → shows:
  - Histogram (`BarChart`) with LSL/USL `ReferenceLine` overlays
  - Cp/Cpk values with traffic light chip (green ≥1.33, yellow 1.0-1.33, red <1.0)
  - Sample count, mean, std dev in stats row

- [ ] Steps follow standard frontend implementation pattern from PATTERNS.md. Each page is lazy-loaded, wrapped in `PermissionGuard`, uses `useQuery` for data fetching.

- [ ] **Commit**

```bash
git add -A && git commit -m "feat(spc): add SPC frontend pages with control charts and capability study"
```

---

## Feature 2: COPQ Analytics Dashboard (~5 days)

### What Exists
- `CopqService` (209 lines): full snapshot computation with PAF categories, `compute()` and `snapshot()` methods
- `CopqSnapshot` model with breakdown JSON field
- `CopqController` with `trend()` endpoint
- `CopqSnapshotComputed` event + `AlertOnCopqSpike` listener
- `CopqWidget.tsx` dashboard widget
- `copq:snap-monthly` cron
- Existing `DefectParetoService` with `defectPareto` and `paretoDrillDown` endpoints

### What to Build
- COPQ analytics dashboard page with PAF breakdown charts
- Product-level and supplier-level cost ranking endpoints
- Pareto by cost (not just count) visualization

### Task 5: COPQ Backend Extensions

**Files:**
- Modify: `api/app/Modules/Quality/Controllers/CopqController.php` — add `byProduct()`, `bySupplier()`, `summary()`
- Modify: `api/app/Modules/Quality/Services/CopqService.php` — add aggregation methods
- Modify: `api/app/Modules/Quality/routes.php` — add new COPQ routes

Add 3 new endpoints:
```
GET /quality/copq/summary          — current month + YTD totals
GET /quality/copq/by-product       — product-level cost ranking
GET /quality/copq/by-supplier      — supplier quality cost ranking
```

- [ ] Steps: extend CopqService with `getByProduct()`, `getBySupplier()`, `getSummary()` methods. Add controller methods. Add routes. Write tests.

- [ ] **Commit**

### Task 6: COPQ Frontend Analytics Page

**Files:**
- Create: `spa/src/pages/quality/copq/index.tsx`
- Create: `spa/src/api/quality/copq.ts`

**Page layout:**
1. PAF stacked bar chart (monthly, Recharts `BarChart` with `stackId`)
2. COPQ % revenue trend line (`LineChart` with target reference line)
3. Pareto chart (horizontal `BarChart` + cumulative `Line`)
4. Product cost table (DataTable with sparkline column)
5. Supplier quality table
6. Period selector (month range picker)

- [ ] Follow PATTERNS.md dashboard page pattern. Lazy-load. Add route and sidebar entry.

- [ ] **Commit**

---

## Feature 3: Traceability Enhancements (~2 days)

### What Exists
- `TraceabilityService` (285 lines): full `search()` with forward/backward/material_lot tracing
- `TraceabilityController` with search endpoint
- Frontend `traceability.tsx` page with full tree rendering
- `ShipmentLot` model, lot columns on `grn_items` and `work_orders`

### What to Build (minimal)
- Recall simulation endpoint (given a material lot, find all affected customers)
- "Trace" button on Complaint detail page linking to traceability search

### Task 7: Recall Simulation Endpoint

**Files:**
- Modify: `api/app/Modules/Quality/Services/TraceabilityService.php` — add `simulateRecall(string $lotNumber)`
- Modify: `api/app/Modules/Quality/Controllers/TraceabilityController.php` — add `recallSimulation()`
- Modify: `api/app/Modules/Quality/routes.php`
- Create: `api/tests/Feature/Quality/TraceabilityRecallTest.php`

```php
// New method on TraceabilityService
public function simulateRecall(string $lotNumber): array
{
    $trace = $this->search($lotNumber);
    if (!$trace['found']) {
        return ['found' => false, 'affected_customers' => [], 'affected_deliveries' => []];
    }

    // Aggregate forward trace to find all affected customers
    $customers = [];
    $deliveries = [];
    // ... extract from $trace['trace']['forward']

    return [
        'found' => true,
        'lot_number' => $lotNumber,
        'affected_customers' => $customers,
        'affected_deliveries' => $deliveries,
        'total_affected_qty' => array_sum(array_column($deliveries, 'quantity')),
    ];
}
```

- [ ] **Commit**

### Task 8: Trace Button on Complaint Detail

**Files:**
- Modify: `spa/src/pages/crm/complaints/detail.tsx` — add "Trace" button that navigates to `/quality/traceability?term={batch_number}`

One-line change: add a `<Button>` in the complaint detail header that links to the traceability page pre-filled with the complaint's batch number.

- [ ] **Commit**

---

## Feature 4: Production Routing & Operations (~14 days)

### What Exists
- `WorkOrder` model with full lifecycle (draft → confirmed → started → paused → completed → closed)
- `WorkOrderOutput`, `WorkOrderMaterial`, `WorkOrderDefect`, `DefectType` models
- `WorkOrderService`, `WorkOrderOutputService`, `OeeService`
- `ProductionSchedule` model (but no routing-level scheduling)
- Frontend: WO list, create, detail, record-output pages, schedule page, OEE page

### What to Build
- Product routing: define operation sequences per product
- WO operations: per-operation tracking (status, operator, times, output)
- Production event log
- Enhanced schedule board
- Operator performance view

### Task 9: Routing Migrations + Models

**Files:**
- Create: `api/database/migrations/0255_create_product_routings_table.php`
- Create: `api/database/migrations/0256_create_routing_operations_table.php`
- Create: `api/database/migrations/0257_create_wo_operations_table.php`
- Create: `api/database/migrations/0258_create_production_logs_table.php`
- Create: `api/app/Modules/Production/Enums/WoOperationStatus.php`
- Create: `api/app/Modules/Production/Enums/ProductionLogEvent.php`
- Create: `api/app/Modules/Production/Models/ProductRouting.php`
- Create: `api/app/Modules/Production/Models/RoutingOperation.php`
- Create: `api/app/Modules/Production/Models/WoOperation.php`
- Create: `api/app/Modules/Production/Models/ProductionLog.php`

Follow the data model from the design spec. Migration numbers 0255-0258.

- [ ] Create all migrations, enums, models
- [ ] Run migrations
- [ ] **Commit**

### Task 10: Routing Service + Controller

**Files:**
- Create: `api/app/Modules/Production/Services/ProductionRoutingService.php`
- Create: `api/app/Modules/Production/Controllers/ProductionRoutingController.php`
- Create: `api/app/Modules/Production/Requests/StoreRoutingRequest.php`
- Create: `api/app/Modules/Production/Resources/ProductRoutingResource.php`
- Create: `api/app/Modules/Production/Resources/RoutingOperationResource.php`
- Modify: `api/app/Modules/Production/routes.php` — add routing CRUD routes
- Create: `api/tests/Feature/Production/RoutingTest.php`

Endpoints:
```
GET    /production/routings                    — list routings (filter by product)
POST   /production/routings                    — create routing with operations
GET    /production/routings/{routing}          — show with operations
PUT    /production/routings/{routing}          — update
POST   /production/routings/{routing}/duplicate — create new version
```

- [ ] Steps: TDD — write test for routing CRUD, implement service, controller, routes.
- [ ] **Commit**

### Task 11: WO Operation Lifecycle Service

**Files:**
- Create: `api/app/Modules/Production/Services/WoOperationService.php`
- Create: `api/app/Modules/Production/Controllers/WoOperationController.php`
- Modify: `api/app/Modules/Production/Services/WorkOrderService.php` — generate operations from routing on WO creation
- Modify: `api/app/Modules/Production/routes.php`
- Create: `api/tests/Feature/Production/WoOperationTest.php`

Key methods:
```php
WoOperationService::generateFromRouting(WorkOrder $wo): void  // copies routing ops
WoOperationService::startOperation(WoOperation $op, Employee $operator): void
WoOperationService::recordOutput(WoOperation $op, float $qty, float $scrap, ?string $reason): void
WoOperationService::completeOperation(WoOperation $op): void
WoOperationService::getScheduleByMachine(Carbon $from, Carbon $to): Collection
```

- [ ] Steps: TDD — test WO operation lifecycle (start → record → complete), implement service + controller.
- [ ] **Commit**

### Task 12: Production Frontend — Routing Editor + WO Operations

**Files:**
- Create: `spa/src/pages/production/routings/index.tsx`
- Create: `spa/src/pages/production/routings/editor.tsx`
- Create: `spa/src/api/production/routings.ts`
- Create: `spa/src/types/production/routing.ts`
- Modify: `spa/src/pages/production/work-orders/detail.tsx` — add operation timeline

Routing editor: sortable list of operations with add/remove/reorder. WO detail page gets a new "Operations" tab showing per-operation status, operator, progress.

- [ ] **Commit**

---

## Feature 5: KPI Scorecard (~9 days)

### What Exists
Nothing. Fully new.

### Task 13: KPI Migrations + Models + Seeder

**Files:**
- Create: `api/database/migrations/0259_create_kpi_definitions_table.php`
- Create: `api/database/migrations/0260_create_kpi_snapshots_table.php`
- Create: `api/app/Modules/Dashboard/Models/KpiDefinition.php`
- Create: `api/app/Modules/Dashboard/Models/KpiSnapshot.php`
- Create: `api/database/seeders/KpiDefinitionSeeder.php`

Seed 12 default KPIs from the design spec (OEE, DPPM, first pass yield, on-time delivery, etc.).

- [ ] **Commit**

### Task 14: KPI Calculators + Snapshot Service

**Files:**
- Create: `api/app/Modules/Dashboard/Services/KpiSnapshotService.php`
- Create: `api/app/Console/Commands/ComputeMonthlyKpis.php`
- Modify: `api/routes/console.php` — schedule monthly on 2nd at 03:00
- Create: `api/tests/Feature/Dashboard/KpiSnapshotTest.php`

`KpiSnapshotService::computeAll(year, month)` — iterates all active KPI definitions, dispatches to the matching calculator method, stores snapshots. Each calculator queries its source data (e.g., OEE from `WorkOrderOutput`, DPPM from `Inspection`, on-time delivery from `Delivery`).

- [ ] Steps: TDD — test each calculator, implement service, create cron command.
- [ ] **Commit**

### Task 15: KPI Controller + Frontend

**Files:**
- Create: `api/app/Modules/Dashboard/Controllers/KpiController.php`
- Create: `api/app/Modules/Dashboard/Resources/KpiSnapshotResource.php`
- Modify: `api/app/Modules/Dashboard/routes.php` (or create if needed)
- Create: `spa/src/pages/dashboard/scorecard.tsx`
- Create: `spa/src/api/dashboards/kpi.ts`
- Create: `spa/src/types/dashboard/kpi.ts`

Frontend: grid of KPI cards with traffic light indicators, sparklines (Recharts `Sparkline`), month selector, PDF export button.

- [ ] **Commit**

---

## Feature 6: Document Control Frontend (~3 days)

### What Exists
- Full backend: `ControlledDocument`, `DocumentRevision`, `DocumentAcknowledgment` models
- `DocumentService`, `DocumentReviewService`, `DocumentAcknowledgmentService`
- `DocumentController` with CRUD + revision publishing + mark reviewed
- Routes: full CRUD under `/quality/documents/...`
- Self-service acknowledgment routes

### What to Build
- Admin-facing frontend pages for document catalog management

### Task 16: Document Control Frontend Pages

**Files:**
- Create: `spa/src/pages/quality/documents/index.tsx` — document catalog list
- Create: `spa/src/pages/quality/documents/detail.tsx` — document detail with revision timeline
- Create: `spa/src/pages/quality/documents/create.tsx` — new document form
- Create: `spa/src/api/quality/documents.ts`
- Create: `spa/src/types/quality/document.ts`

Follow PATTERNS.md list + detail page. Document list shows: code, title, category chip, current revision, review status indicator. Detail page shows revision history as vertical timeline, acknowledgment progress bar, "Publish New Revision" button with file upload.

- [ ] **Commit**

---

## Feature 7: Shop Floor PWA Enhancements (~3 days)

### What Exists
- 3 factory pages: `ActiveOrders.tsx`, `QcQuickCheck.tsx`, `RecordOutput.tsx`
- 4 warehouse pages: `map.tsx`, `picking.tsx`, `stock-count.tsx`, `transfers.tsx`
- Edge module backend (6 controllers, 6 services)

### What to Build
- PWA manifest for installability
- Service worker for basic offline caching
- Touch-optimized enhancements for existing pages

### Task 17: PWA Setup

**Files:**
- Create: `spa/public/manifest.json`
- Modify: `spa/index.html` — add manifest link
- Modify: `spa/vite.config.ts` — add `@vite-pwa/vite-plugin` if not present
- Modify: existing factory/warehouse pages — increase button sizes, add viewport meta

- [ ] Install `vite-plugin-pwa`: `npm install -D vite-plugin-pwa`
- [ ] Create manifest with name "Ogami Shop Floor", icons, display: standalone
- [ ] Configure service worker for precaching static assets
- [ ] **Commit**

---

## Feature 8: API Documentation (~3 days)

### Task 18: Swagger Setup + Core Annotations

**Files:**
- Modify: `api/composer.json` — require `darkaonline/l5-swagger`
- Create: `api/config/l5-swagger.php` (via publish)
- Modify: Auth, HR Employee, Production WO, Quality Inspection controllers — add `@OA\` annotations

- [ ] Install: `cd api && composer require darkaonline/l5-swagger`
- [ ] Publish config: `php artisan vendor:publish --provider="L5Swagger\L5SwaggerServiceProvider"`
- [ ] Add `@OA\Info` annotation to a base file
- [ ] Annotate priority controllers (start with Auth login/logout, Employees CRUD)
- [ ] Generate: `php artisan l5-swagger:generate`
- [ ] Verify at `/api/documentation`
- [ ] **Commit**

---

## Summary

| # | Feature | Tasks | New Files | Days |
|---|---------|-------|-----------|------|
| 1 | SPC Control Charts | 1-4 | ~25 | 10 |
| 2 | COPQ Analytics | 5-6 | ~3 | 5 |
| 3 | Traceability | 7-8 | ~2 | 2 |
| 4 | Production Routing | 9-12 | ~20 | 14 |
| 5 | KPI Scorecard | 13-15 | ~12 | 9 |
| 6 | Document Control UI | 16 | ~5 | 3 |
| 7 | Shop Floor PWA | 17 | ~2 | 3 |
| 8 | API Documentation | 18 | ~1 | 3 |
| **Total** | | **18 tasks** | **~70** | **~49 days** |

Actual effort reduced from 75 to ~49 days due to existing implementations.
