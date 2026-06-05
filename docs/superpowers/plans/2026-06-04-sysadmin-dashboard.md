# System Administrator Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the 6 generic stub-link tiles the system_admin sees with a professional, data-rich dashboard showing live cross-module KPIs, user activity, approval status, chain health, and audit events.

**Architecture:** Follows the existing role-dashboard pattern exactly: one new `AdminDashboardService` (uses `DashboardQueries` trait), one new controller action injected into `DashboardController`, one new backend route, one new frontend page `spa/src/pages/dashboard/admin.tsx`, and two small wiring changes (route + role-router). No migrations needed — all data comes from existing tables.

**Tech Stack:** Laravel 11 (PHP 8.3), React 18, TypeScript 5.6, TanStack Query, Recharts (SparkLine, BarComparison, DonutBreakdown), existing StatCard/Panel/Chip/PageHeader components

---

## File Structure

```
CREATE  api/app/Modules/Dashboard/Services/AdminDashboardService.php
MODIFY  api/app/Modules/Dashboard/Controllers/DashboardController.php  (add admin() action + inject service)
MODIFY  api/app/Modules/Dashboard/routes.php                           (add GET /dashboards/admin)
CREATE  spa/src/pages/dashboard/admin.tsx
MODIFY  spa/src/routes/dashboardRoutes.tsx                             (add /dashboard/admin route)
MODIFY  spa/src/pages/dashboard/index.tsx                              (add system_admin to ROLE_DASHBOARDS)
MODIFY  spa/src/api/dashboards.ts                                      (add admin() call)
CREATE  api/tests/Feature/Dashboard/AdminDashboardServiceTest.php
```

---

## Dashboard Layout (what the admin sees)

```
┌─────────────────────────────────────────────────────────────────────┐
│  PageHeader "System Administrator Dashboard"   [Today / Week / Month]│
├──────────┬──────────┬──────────┬──────────────────────────────────  │
│ Active   │ Pending  │ Open     │ Failed logins                        │
│ Users    │ Approvals│ Alerts   │ (last 24h)                           │
├──────────┴──────────┴──────────┴──────────────────────────────────  │
│  Three-Chain Overview (horizontal stage bars — OTC · P2P · H2R)      │
├──────────────────────────┬──────────────────────────────────────────│
│  Module Activity Grid    │  User Activity                             │
│  HR · Payroll · Inventory│  Recent logins table + 7-day sparkline     │
│  Purchasing · Prod · QC  │                                            │
├──────────────────────────┼──────────────────────────────────────────│
│  Pending Approvals       │  Recent Audit Events                       │
│  (by workflow type)      │  (last 10 from audit_logs)                 │
└──────────────────────────┴──────────────────────────────────────────┘
```

---

## Task 1: Backend — AdminDashboardService

**Files:**
- Create: `api/app/Modules/Dashboard/Services/AdminDashboardService.php`
- Test: `api/tests/Feature/Dashboard/AdminDashboardServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
// api/tests/Feature/Dashboard/AdminDashboardServiceTest.php
<?php
declare(strict_types=1);
namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Services\AdminDashboardService;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_returns_expected_shape(): void
    {
        $user = User::factory()->create();
        $svc  = app(AdminDashboardService::class);

        $result = $svc->admin($user);

        $this->assertArrayHasKey('kpis', $result);
        $this->assertArrayHasKey('panels', $result);
        $this->assertCount(4, $result['kpis']);
        $this->assertArrayHasKey('chain_stages', $result['panels']);
        $this->assertArrayHasKey('module_activity', $result['panels']);
        $this->assertArrayHasKey('user_activity', $result['panels']);
        $this->assertArrayHasKey('pending_approvals', $result['panels']);
        $this->assertArrayHasKey('recent_audit', $result['panels']);
    }

    public function test_module_activity_has_all_six_modules(): void
    {
        $user = User::factory()->create();
        $svc  = app(AdminDashboardService::class);

        $result  = $svc->admin($user);
        $modules = array_column($result['panels']['module_activity'], 'key');

        $this->assertContains('hr', $modules);
        $this->assertContains('payroll', $modules);
        $this->assertContains('inventory', $modules);
        $this->assertContains('purchasing', $modules);
        $this->assertContains('production', $modules);
        $this->assertContains('quality', $modules);
    }

    public function test_user_activity_has_login_trend(): void
    {
        $user   = User::factory()->create();
        $svc    = app(AdminDashboardService::class);
        $result = $svc->admin($user);

        $ua = $result['panels']['user_activity'];
        $this->assertArrayHasKey('recent_logins', $ua);
        $this->assertArrayHasKey('login_trend_7d', $ua);
        $this->assertCount(7, $ua['login_trend_7d']); // exactly 7 days
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /home/kwat0g/Desktop/kwatog/api && php artisan test tests/Feature/Dashboard/AdminDashboardServiceTest.php --stop-on-failure 2>&1 | tail -15
```
Expected: FAIL — class not found

- [ ] **Step 3: Create AdminDashboardService**

```php
// api/app/Modules/Dashboard/Services/AdminDashboardService.php
<?php
declare(strict_types=1);
namespace App\Modules\Dashboard\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\Concerns\DashboardQueries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * System Administrator dashboard — cross-module health overview.
 * KPIs: active users, pending approvals, open alerts, failed logins (24h).
 * Panels: chain stages, module activity, user activity, pending approvals by type, recent audit.
 */
class AdminDashboardService
{
    use DashboardQueries;

    private const CACHE_TTL = 30;

    public function admin(User $user): array
    {
        return Cache::remember("dashboard:admin:{$user->id}", self::CACHE_TTL, function () {
            return [
                'kpis'   => $this->adminKpis(),
                'panels' => [
                    'chain_stages'      => $this->chainStageBreakdown(),
                    'module_activity'   => $this->moduleActivity(),
                    'user_activity'     => $this->userActivity(),
                    'pending_approvals' => $this->pendingApprovalsByType(),
                    'recent_audit'      => $this->recentAuditEvents(),
                ],
            ];
        });
    }

    // ── KPIs ────────────────────────────────────────────────────────────────

    private function adminKpis(): array
    {
        $activeUsers = $this->safeCount('sessions', fn ($q) =>
            $q->where('last_activity', '>=', now()->subMinutes(30)->timestamp)
        );

        $pendingApprovals = $this->safeCount('approval_records', fn ($q) =>
            $q->where('action', 'pending')
        );

        $openAlerts = $this->safeCount('alerts', fn ($q) =>
            $q->where('is_dismissed', false)
        );

        $failedLogins = $this->safeCount('login_history', fn ($q) =>
            $q->where('status', '!=', 'success')
             ->where('created_at', '>=', now()->subHours(24))
        );

        return [
            $this->kpi('Active Users',        (string) $activeUsers,    'users'),
            $this->kpi('Pending Approvals',   (string) $pendingApprovals, 'items'),
            $this->kpi('Open Alerts',         (string) $openAlerts,     'alerts'),
            $this->kpi('Failed Logins (24h)', (string) $failedLogins,   'attempts'),
        ];
    }

    // ── Module Activity ──────────────────────────────────────────────────────

    private function moduleActivity(): array
    {
        return [
            [
                'key'   => 'hr',
                'label' => 'HR',
                'href'  => '/hr/employees',
                'stats' => [
                    ['label' => 'Active employees', 'value' => (string) $this->safeCount('employees', fn ($q) => $q->where('status', 'active'))],
                    ['label' => 'Pending leaves',   'value' => (string) $this->safeCount('leave_requests', fn ($q) => $q->where('status', 'pending'))],
                    ['label' => 'Pending OT',        'value' => (string) $this->safeCount('overtime_requests', fn ($q) => $q->where('status', 'pending'))],
                ],
            ],
            [
                'key'   => 'payroll',
                'label' => 'Payroll',
                'href'  => '/payroll/periods',
                'stats' => [
                    ['label' => 'Draft periods',    'value' => (string) $this->safeCount('payroll_periods', fn ($q) => $q->where('status', 'draft'))],
                    ['label' => 'Approved periods', 'value' => (string) $this->safeCount('payroll_periods', fn ($q) => $q->where('status', 'approved'))],
                    ['label' => 'Anomaly flags',    'value' => (string) $this->safeCount('payroll_anomaly_flags', fn ($q) => $q->where('resolved', false))],
                ],
            ],
            [
                'key'   => 'inventory',
                'label' => 'Inventory',
                'href'  => '/inventory/items',
                'stats' => [
                    ['label' => 'Low stock items',  'value' => (string) $this->lowStockCount()],
                    ['label' => 'Pending GRNs',     'value' => (string) $this->safeCount('goods_receipt_notes', fn ($q) => $q->where('status', 'pending'))],
                    ['label' => 'Open MIS',          'value' => (string) $this->safeCount('material_issue_slips', fn ($q) => $q->whereIn('status', ['draft', 'pending']))],
                ],
            ],
            [
                'key'   => 'purchasing',
                'label' => 'Purchasing',
                'href'  => '/purchasing/purchase-requests',
                'stats' => [
                    ['label' => 'Open PRs', 'value' => (string) $this->safeCount('purchase_requests', fn ($q) => $q->whereIn('status', ['draft', 'pending', 'approved']))],
                    ['label' => 'Open POs', 'value' => (string) $this->safeCount('purchase_orders',  fn ($q) => $q->whereIn('status', ['draft', 'approved', 'sent']))],
                    ['label' => 'Overdue bills', 'value' => (string) $this->safeCount('bills', fn ($q) => $q->whereIn('status', ['unpaid', 'partial'])->whereDate('due_date', '<', now()))],
                ],
            ],
            [
                'key'   => 'production',
                'label' => 'Production',
                'href'  => '/production/work-orders',
                'stats' => [
                    ['label' => 'Active WOs',     'value' => (string) $this->safeCount('work_orders', fn ($q) => $q->where('status', 'in_progress'))],
                    ['label' => 'Planned WOs',    'value' => (string) $this->safeCount('work_orders', fn ($q) => $q->whereIn('status', ['planned', 'confirmed']))],
                    ['label' => 'Machine breakdowns', 'value' => (string) $this->safeCount('machine_downtimes', fn ($q) => $q->where('category', 'breakdown')->whereNull('end_time'))],
                ],
            ],
            [
                'key'   => 'quality',
                'label' => 'Quality',
                'href'  => '/quality/ncrs',
                'stats' => [
                    ['label' => 'Open NCRs',         'value' => (string) $this->safeCount('non_conformance_reports', fn ($q) => $q->whereIn('status', ['open', 'in_progress']))],
                    ['label' => 'Pending inspections','value' => (string) $this->safeCount('inspections', fn ($q) => $q->where('status', 'in_progress'))],
                    ['label' => 'Critical NCRs',     'value' => (string) $this->safeCount('non_conformance_reports', fn ($q) => $q->where('severity', 'critical')->whereIn('status', ['open', 'in_progress']))],
                ],
            ],
        ];
    }

    private function lowStockCount(): int
    {
        if (! Schema::hasTable('stock_levels') || ! Schema::hasTable('items')) {
            return 0;
        }
        return (int) DB::table('stock_levels as sl')
            ->join('items as i', 'i.id', '=', 'sl.item_id')
            ->whereRaw('sl.quantity <= i.reorder_point')
            ->where('i.reorder_point', '>', 0)
            ->count();
    }

    // ── User Activity ────────────────────────────────────────────────────────

    private function userActivity(): array
    {
        $recentLogins = [];
        if (Schema::hasTable('login_history') && Schema::hasTable('users')) {
            $rows = DB::table('login_history as lh')
                ->leftJoin('users as u', 'u.id', '=', 'lh.user_id')
                ->orderByDesc('lh.created_at')
                ->limit(8)
                ->select([
                    'u.name',
                    'lh.email_attempted',
                    'lh.status',
                    'lh.ip_address',
                    'lh.created_at',
                ])
                ->get();

            foreach ($rows as $row) {
                $recentLogins[] = [
                    'name'       => $row->name ?? $row->email_attempted ?? '—',
                    'status'     => $row->status,
                    'ip'         => $row->ip_address ?? '—',
                    'created_at' => $row->created_at,
                ];
            }
        }

        // 7-day daily login counts (success only)
        $loginTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day   = Carbon::today()->subDays($i)->toDateString();
            $count = $this->safeCount('login_history', fn ($q) =>
                $q->where('status', 'success')
                  ->whereDate('created_at', $day)
            );
            $loginTrend[] = $count;
        }

        $totalUsers  = $this->safeCount('users');
        $activeToday = $this->safeCount('login_history', fn ($q) =>
            $q->where('status', 'success')
              ->whereDate('created_at', today())
        );

        return [
            'recent_logins'   => $recentLogins,
            'login_trend_7d'  => $loginTrend,
            'total_users'     => $totalUsers,
            'active_today'    => $activeToday,
        ];
    }

    // ── Pending Approvals by Type ─────────────────────────────────────────────

    private function pendingApprovalsByType(): array
    {
        if (! Schema::hasTable('approval_records')) {
            return [];
        }

        $rows = DB::table('approval_records')
            ->where('action', 'pending')
            ->select('approvable_type', DB::raw('COUNT(*) as total'))
            ->groupBy('approvable_type')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $labelMap = [
            'App\\Modules\\Purchasing\\Models\\PurchaseRequest' => 'Purchase Requests',
            'App\\Modules\\Purchasing\\Models\\PurchaseOrder'   => 'Purchase Orders',
            'App\\Modules\\Leave\\Models\\LeaveRequest'         => 'Leave Requests',
            'App\\Modules\\HR\\Models\\EmployeeLoan'            => 'Loan Applications',
            'App\\Modules\\Attendance\\Models\\OvertimeRequest' => 'Overtime Requests',
            'App\\Modules\\Quality\\Models\\NonConformanceReport' => 'NCR Dispositions',
            'App\\Modules\\ReturnManagement\\Models\\ReturnRequest' => 'Return Requests',
            'App\\Modules\\Accounting\\Models\\Budget'          => 'Budget Approvals',
        ];

        $hrefMap = [
            'App\\Modules\\Purchasing\\Models\\PurchaseRequest' => '/purchasing/purchase-requests',
            'App\\Modules\\Purchasing\\Models\\PurchaseOrder'   => '/purchasing/purchase-orders',
            'App\\Modules\\Leave\\Models\\LeaveRequest'         => '/hr/leaves',
            'App\\Modules\\HR\\Models\\EmployeeLoan'            => '/hr/loans',
            'App\\Modules\\Attendance\\Models\\OvertimeRequest' => '/hr/attendance/overtime',
            'App\\Modules\\Quality\\Models\\NonConformanceReport' => '/quality/ncrs',
            'App\\Modules\\ReturnManagement\\Models\\ReturnRequest' => '/return-management',
            'App\\Modules\\Accounting\\Models\\Budget'          => '/budgeting',
        ];

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'type'  => $row->approvable_type,
                'label' => $labelMap[$row->approvable_type] ?? class_basename($row->approvable_type),
                'count' => (int) $row->total,
                'href'  => $hrefMap[$row->approvable_type] ?? '/approvals',
            ];
        }
        return $out;
    }

    // ── Recent Audit Events ──────────────────────────────────────────────────

    private function recentAuditEvents(): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        $rows = DB::table('audit_logs as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->orderByDesc('al.created_at')
            ->limit(10)
            ->select([
                'u.name as user_name',
                'al.action',
                'al.model_type',
                'al.ip_address',
                'al.created_at',
            ])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'user'       => $row->user_name ?? 'System',
                'action'     => $row->action,
                'entity'     => class_basename($row->model_type),
                'ip'         => $row->ip_address ?? '—',
                'created_at' => $row->created_at,
            ];
        }
        return $out;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
cd /home/kwat0g/Desktop/kwatog/api && php artisan test tests/Feature/Dashboard/AdminDashboardServiceTest.php -v 2>&1 | tail -20
```
Expected: 3 PASS

- [ ] **Step 5: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog && git add api/app/Modules/Dashboard/Services/AdminDashboardService.php api/tests/Feature/Dashboard/AdminDashboardServiceTest.php && git commit -m "feat: AdminDashboardService — cross-module health data for system admin"
```

---

## Task 2: Backend — Controller + Route

**Files:**
- Modify: `api/app/Modules/Dashboard/Controllers/DashboardController.php`
- Modify: `api/app/Modules/Dashboard/routes.php`
- Test: `api/tests/Feature/Dashboard/AdminDashboardServiceTest.php` (add HTTP test)

- [ ] **Step 1: Write the failing HTTP test**

Add this test method to `api/tests/Feature/Dashboard/AdminDashboardServiceTest.php`:

```php
public function test_admin_endpoint_requires_auth(): void
{
    $this->getJson('/api/v1/dashboards/admin')->assertStatus(401);
}

public function test_admin_endpoint_returns_200_for_system_admin(): void
{
    $user = User::factory()->withRole('system_admin')->create();
    $this->actingAs($user)
        ->getJson('/api/v1/dashboards/admin')
        ->assertStatus(200)
        ->assertJsonStructure(['data' => ['kpis', 'panels']]);
}
```

- [ ] **Step 2: Run to verify FAIL**

```bash
cd /home/kwat0g/Desktop/kwatog/api && php artisan test tests/Feature/Dashboard/AdminDashboardServiceTest.php --filter test_admin_endpoint --stop-on-failure 2>&1 | tail -15
```
Expected: FAIL — 404 (route does not exist)

- [ ] **Step 3: Add admin() action to DashboardController**

In `api/app/Modules/Dashboard/Controllers/DashboardController.php`:

Add import at top (after existing imports):
```php
use App\Modules\Dashboard\Services\AdminDashboardService;
```

Add `AdminDashboardService` to constructor:
```php
public function __construct(
    private readonly PlantManagerDashboardService $plantManagerService,
    private readonly HrDashboardService           $hrService,
    private readonly PpcDashboardService          $ppcService,
    private readonly PurchasingDashboardService   $purchasingService,
    private readonly WarehouseDashboardService    $warehouseService,
    private readonly QualityDashboardService      $qualityService,
    private readonly AdminDashboardService        $adminService,   // ADD
) {}
```

Add new action at the end of the class (before closing `}`):
```php
public function admin(Request $request): JsonResponse
{
    return response()->json(['data' => $this->adminService->admin($request->user())]);
}
```

- [ ] **Step 4: Add route**

In `api/app/Modules/Dashboard/routes.php`, inside the `Route::middleware('auth:sanctum')->prefix('dashboards')->group(...)` block, add after the `/quality` route:

```php
Route::get('/admin', [DashboardController::class, 'admin'])
    ->middleware('permission:dashboard.admin.view');
```

- [ ] **Step 5: Add the permission to RolePermissionSeeder**

In `api/database/seeders/RolePermissionSeeder.php`, find the `system_admin` permissions array and add `'dashboard.admin.view'`.

Also add `'dashboard.admin.view'` to the `$allPermissions` definition array at the top of the seeder.

- [ ] **Step 6: Run tests**

```bash
cd /home/kwat0g/Desktop/kwatog/api && php artisan test tests/Feature/Dashboard/AdminDashboardServiceTest.php -v 2>&1 | tail -20
```
Expected: 5 PASS

- [ ] **Step 7: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog && git add api/app/Modules/Dashboard/Controllers/DashboardController.php api/app/Modules/Dashboard/routes.php api/database/seeders/RolePermissionSeeder.php && git commit -m "feat: add /dashboards/admin endpoint with permission gate"
```

---

## Task 3: Frontend — API client + routing wiring

**Files:**
- Modify: `spa/src/api/dashboards.ts`
- Modify: `spa/src/routes/dashboardRoutes.tsx`
- Modify: `spa/src/pages/dashboard/index.tsx`

These are tiny changes. All three in one task, one commit.

- [ ] **Step 1: Add admin() to dashboards.ts**

In `spa/src/api/dashboards.ts`, add to the `dashboardsApi` object:

```typescript
admin: () => client.get<ApiSuccess<AdminDashboardData>>('/dashboards/admin').then(r => r.data.data),
```

And add the interface (place it before `dashboardsApi`):

```typescript
export interface AdminDashboardData {
  kpis: Array<{ label: string; value: string; unit: string }>;
  panels: {
    chain_stages: Array<{ key: string; label: string; color: string; count: number; percent: number }>;
    module_activity: Array<{
      key: string;
      label: string;
      href: string;
      stats: Array<{ label: string; value: string }>;
    }>;
    user_activity: {
      recent_logins: Array<{ name: string; status: string; ip: string; created_at: string }>;
      login_trend_7d: number[];
      total_users: number;
      active_today: number;
    };
    pending_approvals: Array<{ type: string; label: string; count: number; href: string }>;
    recent_audit: Array<{ user: string; action: string; entity: string; ip: string; created_at: string }>;
  };
}
```

- [ ] **Step 2: Add route in dashboardRoutes.tsx**

In `spa/src/routes/dashboardRoutes.tsx`:

Add lazy import at top with other dashboard lazy imports:
```typescript
const AdminDashboardPage = lazy(() => import('@/pages/dashboard/admin'));
```

Add route inside the `<>` fragment, after the `/dashboard/quality` route:
```tsx
<Route path="/dashboard/admin"
  element={<PermissionGuard permission="dashboard.admin.view"><AdminDashboardPage /></PermissionGuard>} />
```

- [ ] **Step 3: Add system_admin to role router in index.tsx**

In `spa/src/pages/dashboard/index.tsx`, add to `ROLE_DASHBOARDS`:
```typescript
system_admin: { path: '/dashboard/admin', permission: 'dashboard.admin.view' },
```

- [ ] **Step 4: Verify TypeScript compiles**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit 2>&1 | head -20
```
Expected: no errors (or only pre-existing errors unrelated to this task)

- [ ] **Step 5: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog && git add spa/src/api/dashboards.ts spa/src/routes/dashboardRoutes.tsx spa/src/pages/dashboard/index.tsx && git commit -m "feat: wire /dashboard/admin route and role redirect for system_admin"
```

---

## Task 4: Frontend — Admin Dashboard Page

**Files:**
- Create: `spa/src/pages/dashboard/admin.tsx`

This is the main frontend task. The page follows the `plant-manager.tsx` pattern exactly.

- [ ] **Step 1: Create the page file**

```typescript
// spa/src/pages/dashboard/admin.tsx
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Chip } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { SparkLine } from '@/components/charts/SparkLine';
import { BarComparison } from '@/components/charts/BarComparison';
import { dashboardsApi, type AdminDashboardData } from '@/api/dashboards';

type KpiUnit = 'users' | 'items' | 'alerts' | 'attempts';

const kpiColor: Record<KpiUnit, string> = {
  users:    'text-primary',
  items:    'text-warning',
  alerts:   'text-danger',
  attempts: 'text-danger',
};

export default function AdminDashboard() {
  const q = useQuery({
    queryKey: ['dashboard', 'admin'],
    queryFn: () => dashboardsApi.admin(),
    refetchInterval: 60_000,
    placeholderData: (prev) => prev,
  });

  return (
    <div>
      <PageHeader
        title="System Administrator"
        subtitle="Cross-module health, user activity, and pending work."
      />

      <div className="px-5 py-4 space-y-4">
        {q.isLoading && !q.data && <SkeletonDetail />}

        {q.isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load admin dashboard"
            description="Could not reach the admin dashboard endpoint."
            action={<Button variant="secondary" onClick={() => q.refetch()}>Retry</Button>}
          />
        )}

        {q.data && (
          <>
            {/* Row 1 — 4 KPI stat cards */}
            <div className="grid grid-cols-2 xl:grid-cols-4 gap-3">
              {q.data.kpis.map((kpi) => (
                <StatCard
                  key={kpi.label}
                  label={kpi.label}
                  value={
                    <span className={kpiColor[kpi.unit as KpiUnit] ?? 'text-primary'}>
                      {kpi.value}
                    </span>
                  }
                  helper={kpi.unit}
                />
              ))}
            </div>

            {/* Row 2 — Three-chain stage bar */}
            <Panel
              title="Three-Chain Overview"
              actions={<Link className="text-xs text-link hover:underline" to="/approvals">Approvals board →</Link>}
            >
              <ChainStageBar stages={q.data.panels.chain_stages} />
            </Panel>

            {/* Row 3 — Module activity + User activity */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
              <ModuleActivityPanel modules={q.data.panels.module_activity} />
              <UserActivityPanel activity={q.data.panels.user_activity} />
            </div>

            {/* Row 4 — Pending approvals + Recent audit */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
              <PendingApprovalsPanel approvals={q.data.panels.pending_approvals} />
              <RecentAuditPanel events={q.data.panels.recent_audit} />
            </div>
          </>
        )}
      </div>
    </div>
  );
}

/* ── Sub-panels ─────────────────────────────────────────────────────────── */

function ChainStageBar({
  stages,
}: {
  stages: AdminDashboardData['panels']['chain_stages'];
}) {
  if (stages.length === 0) {
    return <p className="text-sm text-muted">No active records in the pipeline.</p>;
  }
  const colorMap: Record<string, string> = {
    success: 'bg-success',
    info:    'bg-info',
    warning: 'bg-warning',
    danger:  'bg-danger',
  };
  return (
    <div className="space-y-2">
      {stages.map((s) => (
        <div key={s.key} className="flex items-center gap-3">
          <span className="w-40 shrink-0 text-sm text-secondary">{s.label}</span>
          <div className="flex-1 h-2.5 bg-elevated rounded-full overflow-hidden">
            <div
              className={`h-full rounded-full transition-all duration-500 ${colorMap[s.color] ?? 'bg-accent'}`}
              style={{ width: `${s.percent}%` }}
              role="progressbar"
              aria-valuenow={s.count}
              aria-valuemin={0}
              aria-valuemax={Math.max(1, ...stages.map((x) => x.count))}
              aria-label={`${s.label}: ${s.count}`}
            />
          </div>
          <span className="w-8 text-right text-sm font-mono tabular-nums">{s.count}</span>
        </div>
      ))}
    </div>
  );
}

function ModuleActivityPanel({
  modules,
}: {
  modules: AdminDashboardData['panels']['module_activity'];
}) {
  return (
    <Panel title="Module Activity" actions={<Link className="text-xs text-link hover:underline" to="/admin/audit-logs">Audit logs →</Link>}>
      <div className="grid grid-cols-2 gap-2">
        {modules.map((mod) => (
          <Link
            key={mod.key}
            to={mod.href}
            className="p-3 rounded-md border border-default bg-surface hover:bg-elevated transition-colors duration-fast"
          >
            <div className="text-xs font-semibold uppercase tracking-wider text-secondary mb-2">
              {mod.label}
            </div>
            <div className="space-y-1">
              {mod.stats.map((stat) => (
                <div key={stat.label} className="flex justify-between items-center">
                  <span className="text-2xs text-muted truncate mr-2">{stat.label}</span>
                  <span className="text-sm font-mono tabular-nums font-medium shrink-0">{stat.value}</span>
                </div>
              ))}
            </div>
          </Link>
        ))}
      </div>
    </Panel>
  );
}

function UserActivityPanel({
  activity,
}: {
  activity: AdminDashboardData['panels']['user_activity'];
}) {
  const statusVariant = (status: string): 'success' | 'danger' | 'neutral' => {
    if (status === 'success') return 'success';
    if (status.startsWith('failed')) return 'danger';
    return 'neutral';
  };

  return (
    <Panel
      title="User Activity"
      meta={`${activity.active_today} logins today`}
      actions={<Link className="text-xs text-link hover:underline" to="/admin/users">All users →</Link>}
    >
      {/* 7-day trend sparkline */}
      <div className="flex items-center justify-between mb-3 pb-3 border-b border-subtle">
        <div>
          <div className="text-2xs uppercase tracking-wider text-muted mb-0.5">7-Day Login Trend</div>
          <div className="text-xl font-mono tabular-nums font-medium">{activity.total_users} users</div>
        </div>
        <SparkLine
          data={activity.login_trend_7d}
          color="var(--color-success)"
          height={36}
          width={120}
        />
      </div>

      {/* Recent logins table */}
      {activity.recent_logins.length === 0 ? (
        <p className="text-sm text-muted">No recent logins.</p>
      ) : (
        <div className="space-y-0">
          {activity.recent_logins.map((login, i) => (
            <div
              key={i}
              className="flex items-center justify-between py-1.5 border-b border-subtle last:border-0"
            >
              <div className="flex items-center gap-2 min-w-0">
                <Chip variant={statusVariant(login.status)} className="shrink-0">
                  {login.status === 'success' ? 'ok' : 'fail'}
                </Chip>
                <span className="text-sm truncate">{login.name}</span>
              </div>
              <span className="text-2xs text-muted font-mono shrink-0 ml-2">{login.ip}</span>
            </div>
          ))}
        </div>
      )}
    </Panel>
  );
}

function PendingApprovalsPanel({
  approvals,
}: {
  approvals: AdminDashboardData['panels']['pending_approvals'];
}) {
  const total = approvals.reduce((sum, a) => sum + a.count, 0);
  return (
    <Panel
      title="Pending Approvals"
      meta={total > 0 ? String(total) : undefined}
      actions={<Link className="text-xs text-link hover:underline" to="/approvals">Open board →</Link>}
    >
      {approvals.length === 0 ? (
        <p className="text-sm text-muted">No pending approvals.</p>
      ) : (
        <>
          <BarComparison
            data={approvals.map((a) => ({ label: a.label, count: a.count }))}
            bars={[{ dataKey: 'count', color: 'var(--color-warning)', label: 'Pending' }]}
            xKey="label"
            height={160}
          />
          <ul className="mt-2 space-y-1">
            {approvals.map((a) => (
              <li key={a.type} className="flex items-center justify-between text-sm">
                <Link to={a.href} className="text-link hover:underline truncate mr-2">
                  {a.label}
                </Link>
                <span className="font-mono tabular-nums text-warning font-medium shrink-0">
                  {a.count}
                </span>
              </li>
            ))}
          </ul>
        </>
      )}
    </Panel>
  );
}

function RecentAuditPanel({
  events,
}: {
  events: AdminDashboardData['panels']['recent_audit'];
}) {
  const actionColor = (action: string): string => {
    if (action === 'deleted') return 'text-danger';
    if (action === 'created') return 'text-success';
    if (action === 'updated') return 'text-info';
    return 'text-muted';
  };

  return (
    <Panel
      title="Recent Audit Events"
      actions={<Link className="text-xs text-link hover:underline" to="/admin/audit-logs">Full log →</Link>}
    >
      {events.length === 0 ? (
        <p className="text-sm text-muted">No recent audit events.</p>
      ) : (
        <div className="space-y-0">
          {events.map((e, i) => (
            <div
              key={i}
              className="flex items-start gap-2 py-1.5 border-b border-subtle last:border-0 text-sm"
            >
              <span className={`font-mono text-2xs shrink-0 mt-0.5 ${actionColor(e.action)}`}>
                {e.action.toUpperCase()}
              </span>
              <div className="min-w-0 flex-1">
                <span className="font-medium truncate block">{e.entity}</span>
                <span className="text-2xs text-muted">{e.user} · {e.ip}</span>
              </div>
            </div>
          ))}
        </div>
      )}
    </Panel>
  );
}
```

- [ ] **Step 2: Verify TypeScript compiles clean**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit 2>&1 | head -30
```
Expected: zero new errors (fix any that appear before proceeding)

- [ ] **Step 3: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog && git add spa/src/pages/dashboard/admin.tsx && git commit -m "feat: System Administrator dashboard page — live KPIs, chain overview, module activity, user activity, approvals, audit"
```

---

## Self-Review

**Spec coverage:**
- ✅ Top KPI strip (4 cards): active users, pending approvals, open alerts, failed logins → `adminKpis()`
- ✅ Three-chain overview → reuses `chainStageBreakdown()` from `DashboardQueries` trait
- ✅ Module activity grid (6 cards, 3 stats each) → `moduleActivity()`
- ✅ User activity with recent logins + 7-day sparkline → `userActivity()`
- ✅ Pending approvals by type with bar chart → `pendingApprovalsByType()`
- ✅ Recent audit events (last 10) → `recentAuditEvents()`
- ✅ Route: `GET /api/v1/dashboards/admin`
- ✅ Permission: `dashboard.admin.view` seeded to system_admin
- ✅ Frontend route: `/dashboard/admin`
- ✅ Role router: `system_admin` maps to `/dashboard/admin`
- ✅ 60-second auto-refresh via `refetchInterval`
- ✅ Loading skeleton, error state with retry

**Placeholder scan:** None. All code is complete.

**Type consistency:**
- `AdminDashboardData` interface defined in `dashboards.ts` — matches exactly what the service returns
- `ChainStageBar` uses `AdminDashboardData['panels']['chain_stages']` — same shape as `chainStageBreakdown()` output
- `SparkLine` receives `data: number[]` — `login_trend_7d` is `number[]` ✅
- `BarComparison` receives `data: Array<{label, count}>` — `pending_approvals` mapped correctly ✅
- `kpiColor` keyed by `KpiUnit` type that matches the `unit` values returned by service ✅

---

*Plan saved: 2026-06-04*
*4 tasks, ~1 hour of work*
*All tasks sequential (each depends on previous) — use inline execution or subagent-driven*
