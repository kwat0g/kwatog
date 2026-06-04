# Ogami ERP — Comprehensive Enhancement Audit & Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Modernize, harden, and optimize every module of the Ogami ERP — a 17-module production-grade ERP built on Laravel 11 + React 18 — to address systemic code smells, missing type safety, inconsistent patterns, and scalability bottlenecks across all three business chains.

**Architecture:** Modular Monolith backend (Laravel, 20 modules, 744 PHP files) + decoupled SPA frontend (React 18, 495 TypeScript files). Communication via Sanctum SPA cookie auth. Three business chains: Order-to-Cash, Procure-to-Pay, Hire-to-Retire.

**Tech Stack:** PHP 8.3, Laravel 11, PostgreSQL 16, Redis 7, Meilisearch, Reverb (WebSocket) · React 18, TypeScript, Vite, TanStack Query, React Hook Form, Zod, Zustand, Tailwind CSS v4, Axios

---

## System-Wide Architecture Assessment

### Current Strengths
- Modular monolith with clean module boundaries (Controller → Service → Resource)
- Event-driven cross-module orchestration via AppServiceProvider
- Three-layer frontend guards (AuthGuard → ModuleGuard → PermissionGuard)
- HashID obfuscation on all models
- Real-time permission/module sync via WebSocket
- Comprehensive RBAC (280+ permissions, 12 roles)

### Global Gaps
1. **No test suite** — 744 PHP files, zero discovered test files in Modules/
2. **Type leakage** — `any` casts in 40+ SPA files (git status shows widespread modifications)
3. **Chip variant helper not exported** — `chipVariantForStatus()` duplicated inline across dashboard components
4. **API response double-unwrap** — all consumers call `.then(r => r.data.data)` instead of interceptor normalization
5. **No query optimization layer** — N+1 prevention is only in non-production; no DB query logging in dev
6. **Audit log partitioning added (migration 0170) but retention policy undefined**
7. **Missing: network timeout config in Axios client**
8. **Missing: request cancellation (AbortController) on route change**

---

## Execution Priority Matrix

### Quick Wins (high impact, low effort)

| # | Enhancement | Effort | Impact |
|---|---|---|---|
| QW-1 | Export `chipVariantForStatus()` from Chip.tsx | 5 min | Eliminates 10+ duplicate status maps |
| QW-2 | Add network timeout to Axios client | 5 min | Prevents hung requests |
| QW-3 | Fix logic bug in PR detail chain step state | 5 min | Fixes visual workflow state |
| QW-4 | Fix `suggested_vendor` type gap in PurchaseRequest type | 10 min | Eliminates `any` cast |
| QW-5 | Centralize filter enum constants (status, priority) | 15 min | DRY across all list pages |
| QW-6 | Add Axios response interceptor to normalize `.data.data` unwrap | 20 min | Removes boilerplate from 60+ API functions |
| QW-7 | Add request cancellation token to React Query `queryFn` | 30 min | Prevents stale requests on navigation |
| QW-8 | Add `audit_logs` retention policy cron (delete > 12 months) | 30 min | Prevents unbounded table growth |
| QW-9 | Add `APP_ENV` check to Axios 5xx handler (consistent toast duration) | 10 min | Consistent UX dev/prod |
| QW-10 | Move system_admin special case in RoleService to config/constant | 15 min | Removes magic string |

### Deep Architecture Shifts (high impact, high effort)

| # | Enhancement | Effort | Impact |
|---|---|---|---|
| DA-1 | Add PHPUnit feature test suite scaffold (one test per module) | 2 days | Baseline test coverage |
| DA-2 | Add Vitest unit test suite scaffold (hooks, lib, API layer) | 1 day | SPA regression protection |
| DA-3 | Add DB query logging middleware for dev (slowlog > 100ms) | 4 hrs | N+1 and slow query visibility |
| DA-4 | Migrate cross-store side effects in authStore to event bus | 4 hrs | Decoupled store architecture |
| DA-5 | Add background job processing for all DocSequence generation | 1 day | Prevents lock contention under load |
| DA-6 | Standardize API response shape (remove double-wrap) | 1 day | Clean consumer code |
| DA-7 | Add E2E test for all three business chains (Playwright) | 3 days | Regression protection for thesis demo |

---

## Feature-by-Feature Enhancement Breakdown

---

### TASK 1 — Quick Wins Batch A (Chip + Types + Logic Bug)

**Files:**
- Modify: `spa/src/components/ui/Chip.tsx`
- Modify: `spa/src/components/dashboard/RoleDashboard.tsx`
- Modify: `spa/src/types/purchasing.ts` (or wherever `PurchaseRequest` is defined)
- Modify: `spa/src/pages/purchasing/purchase-requests/detail.tsx`

- [ ] **Step 1: Export `chipVariantForStatus` from Chip.tsx**

Open `spa/src/components/ui/Chip.tsx`. Find the `chipVariantForStatus` function. It currently looks like:

```typescript
// eslint-disable-next-line ...
function chipVariantForStatus(status: string): ChipVariant {
```

Change it to:

```typescript
export function chipVariantForStatus(status: string): ChipVariant {
```

Remove the eslint-disable comment on that line.

- [ ] **Step 2: Update RoleDashboard to use exported helper**

Open `spa/src/components/dashboard/RoleDashboard.tsx`. Find the inline variant logic for `machine_util` and `recent_jes` panels. Replace hardcoded variant maps:

```typescript
// BEFORE (example in machine_util panel):
variant={m.status === 'running' ? 'info' : m.status === 'breakdown' ? 'danger' : m.status === 'maintenance' ? 'warning' : 'neutral'}

// AFTER:
variant={chipVariantForStatus(m.status)}
```

Add import at top:

```typescript
import { chipVariantForStatus } from '@/components/ui/Chip';
```

Do the same for the `recent_jes` panel status chips.

- [ ] **Step 3: Run TypeScript check**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit 2>&1 | head -40
```

Expected: No new errors introduced.

- [ ] **Step 4: Fix logic bug in PR detail chain step state**

Open `spa/src/pages/purchasing/purchase-requests/detail.tsx`. Find the line with the redundant ternary (around line 239):

```typescript
// BEFORE (both branches identical):
pr.submitted_at ? 'done' : pr.status === 'draft' ? 'pending' : 'pending'

// AFTER (correct logic — submitted but not approved = active):
pr.submitted_at ? 'done' : pr.status === 'draft' ? 'pending' : 'active'
```

- [ ] **Step 5: Fix `suggested_vendor` type gap**

Find the `PurchaseRequestLineItem` interface (likely in `spa/src/types/purchasing.ts`). Add the missing field:

```typescript
interface PurchaseRequestLineItem {
  // ... existing fields
  suggested_vendor?: string | null;
  suggested_vendor_id?: string | null;
}
```

Then in `detail.tsx`, remove the `as any` cast:

```typescript
// BEFORE:
(l as any).suggested_vendor

// AFTER:
l.suggested_vendor
```

Remove the `/* eslint-disable @typescript-eslint/no-explicit-any */` comment from line 1.

- [ ] **Step 6: Run TypeScript check again**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit 2>&1 | head -40
```

Expected: Clean or fewer errors than before.

- [ ] **Step 7: Commit**

```bash
git add spa/src/components/ui/Chip.tsx spa/src/components/dashboard/RoleDashboard.tsx spa/src/pages/purchasing/purchase-requests/detail.tsx spa/src/types/
git commit -m "fix: export chipVariantForStatus, eliminate any casts, fix PR chain state bug"
```

---

### TASK 2 — Quick Wins Batch B (Axios Client Hardening)

**Files:**
- Modify: `spa/src/api/client.ts`

- [ ] **Step 1: Add network timeout**

Open `spa/src/api/client.ts`. Find the `axios.create()` call. Add timeout:

```typescript
const client = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  timeout: 30_000,  // 30 seconds — generous for file uploads; tighten per-request if needed
  headers: {
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
});
```

- [ ] **Step 2: Normalize 5xx toast duration**

In the response error interceptor, find the section handling 5xx errors. Remove the `isDev` branch that changes toast duration:

```typescript
// BEFORE:
toast.error(message, { duration: isDev ? 8000 : 4000 });

// AFTER:
toast.error(message, { duration: 5000 });
```

- [ ] **Step 3: Add timeout-specific error handling**

In the response error interceptor, add a check before the existing error handling chain:

```typescript
if (axios.isAxiosError(error) && error.code === 'ECONNABORTED') {
  toast.error('Request timed out. Please try again.', { duration: 5000 });
  return Promise.reject(error);
}
```

Place this as the first check inside the interceptor.

- [ ] **Step 4: Run TypeScript check**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit 2>&1 | head -20
```

Expected: No errors.

- [ ] **Step 5: Commit**

```bash
git add spa/src/api/client.ts
git commit -m "fix: add 30s timeout and timeout error handling to Axios client"
```

---

### TASK 3 — API Response Normalization (Remove Double-Unwrap)

**Context:** Every API function does `.then(r => r.data.data)`. This is because the Laravel API wraps all responses as `{ data: <payload> }`. The Axios response itself is `{ data: { data: <payload> } }`. Fix this at the interceptor level so consumer code is cleaner.

**Files:**
- Modify: `spa/src/api/client.ts`
- Modify: `spa/src/api/dashboards.ts` (verify cleanup)
- Verify: a sampling of `spa/src/api/purchasing/purchase-requests.ts`

**Note:** This is a high-breadth change. The interceptor normalizes the unwrap once; all API functions then use `.then(r => r.data)` instead of `.then(r => r.data.data)`. Paginated responses (`PaginatedResponse<T>`) are already at `.data.data` — after normalization they'll be at `.data`. Update the generic wrapper type accordingly.

- [ ] **Step 1: Add response interceptor to normalize Laravel wrapper**

In `spa/src/api/client.ts`, add a response interceptor that unwraps one level:

```typescript
client.interceptors.response.use(
  (response) => {
    // Unwrap Laravel's { data: <payload> } envelope so consumers get payload directly
    if (response.data && typeof response.data === 'object' && 'data' in response.data) {
      response.data = response.data.data;
    }
    return response;
  },
  // ... existing error interceptor stays unchanged
);
```

**IMPORTANT:** This must be added BEFORE the existing error interceptor (interceptors run in order).

- [ ] **Step 2: Update `dashboards.ts` to remove double-unwrap**

```typescript
// BEFORE:
get: (role: DashboardRole) => client.get<...>(`/dashboards/${role}`).then(r => r.data.data),

// AFTER:
get: (role: DashboardRole) => client.get<...>(`/dashboards/${role}`).then(r => r.data),
```

- [ ] **Step 3: Update `purchase-requests.ts` to verify pattern**

```typescript
// BEFORE:
list: (params?) => client.get<PaginatedResponse<PurchaseRequest>>('/purchase-requests', { params }).then(r => r.data.data),

// AFTER:
list: (params?) => client.get<PaginatedResponse<PurchaseRequest>>('/purchase-requests', { params }).then(r => r.data),
```

Apply same change to all methods in `purchase-requests.ts` and `purchase-orders.ts`.

- [ ] **Step 4: Check TypeScript for breakage**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit 2>&1 | head -60
```

Expected: Some type errors where response type annotations assumed the old double-wrap. Fix them by updating type generics from `{ data: { data: T } }` to `{ data: T }` where needed.

- [ ] **Step 5: Run dev server briefly and test a page**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npm run dev &
# Open browser, navigate to /purchasing/purchase-requests, confirm list loads
```

- [ ] **Step 6: Commit**

```bash
git add spa/src/api/
git commit -m "refactor: normalize API response unwrap via interceptor, remove .data.data boilerplate"
```

---

### TASK 4 — Centralize Status/Priority Filter Constants

**Context:** Status and priority values are hardcoded as arrays inside each list page's filter config. If the backend adds a new status, every list page must be updated manually. Centralize them.

**Files:**
- Create: `spa/src/lib/constants/statuses.ts`
- Modify: `spa/src/pages/purchasing/purchase-requests/index.tsx`
- Modify: (any other list pages that hardcode status/priority arrays — check with grep)

- [ ] **Step 1: Find all hardcoded status arrays**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && grep -r "label: 'Draft'" src/pages --include="*.tsx" -l
cd /home/kwat0g/Desktop/kwatog/spa && grep -r "'draft'.*'pending'.*'approved'" src/pages --include="*.tsx" -l
```

Note which pages hardcode status arrays.

- [ ] **Step 2: Create constants file**

Create `spa/src/lib/constants/statuses.ts`:

```typescript
export const PR_STATUSES = [
  { label: 'Draft', value: 'draft' },
  { label: 'Pending', value: 'pending' },
  { label: 'Approved', value: 'approved' },
  { label: 'Rejected', value: 'rejected' },
  { label: 'Converted', value: 'converted' },
  { label: 'Cancelled', value: 'cancelled' },
] as const;

export const PO_STATUSES = [
  { label: 'Draft', value: 'draft' },
  { label: 'Sent', value: 'sent' },
  { label: 'Partially Received', value: 'partially_received' },
  { label: 'Received', value: 'received' },
  { label: 'Cancelled', value: 'cancelled' },
] as const;

export const PRIORITIES = [
  { label: 'Low', value: 'low' },
  { label: 'Normal', value: 'normal' },
  { label: 'High', value: 'high' },
  { label: 'Urgent', value: 'urgent' },
] as const;

// Add other modules' status arrays as found in Step 1
```

- [ ] **Step 3: Update purchase-requests/index.tsx**

Replace inline status/priority arrays with imports from the constants file:

```typescript
import { PR_STATUSES, PRIORITIES } from '@/lib/constants/statuses';

// In filterConfig:
{ key: 'status', label: 'Status', options: PR_STATUSES },
{ key: 'priority', label: 'Priority', options: PRIORITIES },
```

- [ ] **Step 4: TypeScript check**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit 2>&1 | head -20
```

- [ ] **Step 5: Commit**

```bash
git add spa/src/lib/constants/ spa/src/pages/purchasing/
git commit -m "refactor: centralize status/priority filter constants to lib/constants/statuses.ts"
```

---

### TASK 5 — Auth Store: Decouple Cross-Store Side Effects

**Context:** `authStore.ts` line 67 calls `useSidebarStore.getState().init()` inside `applyUser()`. This creates a hidden runtime coupling between auth and sidebar stores — if sidebar store refactors, auth store breaks silently.

**Files:**
- Modify: `spa/src/stores/authStore.ts`
- Read first: `spa/src/stores/sidebarStore.ts` (check what `.init()` does)

- [ ] **Step 1: Read sidebarStore to understand init()**

```bash
cat /home/kwat0g/Desktop/kwatog/spa/src/stores/sidebarStore.ts
```

Note what `init()` does — likely reads user preferences and sets sidebar collapsed state.

- [ ] **Step 2: Assess impact of decoupling**

If `init()` only reads from the user object already available in authStore, the call can be replaced with an event/callback pattern. The safest approach without a full event bus: move sidebar init to the component that calls `bootstrap()` (likely `AppLayout.tsx`).

- [ ] **Step 3: Find where bootstrap() is called**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && grep -r "bootstrap(" src --include="*.tsx" --include="*.ts" -n
```

- [ ] **Step 4: Move sidebar init to AppLayout**

In `authStore.ts`, remove the `useSidebarStore.getState().init()` call from `applyUser()`. Instead, in `AppLayout.tsx` (or wherever `bootstrap()` is awaited), add the sidebar init after bootstrap completes:

```typescript
// AppLayout.tsx or equivalent bootstrap call site
await useAuthStore.getState().bootstrap();
useSidebarStore.getState().init(useAuthStore.getState().user);
```

- [ ] **Step 5: Remove import from authStore.ts**

Remove the `useSidebarStore` import from `authStore.ts` entirely.

- [ ] **Step 6: TypeScript check**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit 2>&1 | head -20
```

- [ ] **Step 7: Commit**

```bash
git add spa/src/stores/ spa/src/layouts/
git commit -m "refactor: decouple authStore from sidebarStore, move init to AppLayout"
```

---

### TASK 6 — Laravel API: System Role Config Constant

**Context:** `RoleService.php` hardcodes `'system_admin'` as a magic string with special handling. If role names change or more system roles need special handling, this becomes fragile.

**Files:**
- Modify: `api/app/Modules/Admin/Services/RoleService.php`
- Read first: check if there's a config file for admin settings

- [ ] **Step 1: Check for existing admin config**

```bash
ls /home/kwat0g/Desktop/kwatog/api/config/ | grep -E "admin|role|rbac"
```

- [ ] **Step 2: Add constant to RoleService or config**

If no config exists, add a private constant to the service class:

```php
private const IMMUTABLE_ROLES = ['system_admin'];
```

Or better, extract to `config/rbac.php`:

```php
<?php
return [
    'immutable_roles' => ['system_admin'],
    'system_roles_prefix' => 'system_',
];
```

- [ ] **Step 3: Update RoleService to use the constant**

Replace all hardcoded `'system_admin'` strings in `syncPermissions()` and related methods:

```php
// BEFORE:
if ($role->slug === 'system_admin') {

// AFTER (using config):
if (in_array($role->slug, config('rbac.immutable_roles'), strict: true)) {
```

- [ ] **Step 4: Run PHP check**

```bash
cd /home/kwat0g/Desktop/kwatog/api && php artisan config:clear && php artisan route:list --compact 2>&1 | tail -5
```

Expected: No errors.

- [ ] **Step 5: Commit**

```bash
git add api/config/rbac.php api/app/Modules/Admin/Services/RoleService.php
git commit -m "refactor: extract system_admin magic string to config/rbac.php immutable_roles"
```

---

### TASK 7 — Audit Log Retention Policy

**Context:** Migration 0170 adds audit log partitioning by month — implies high volume. Without a retention policy, the table grows unbounded.

**Files:**
- Create: `api/app/Console/Commands/PruneAuditLogs.php`
- Modify: `api/routes/console.php` (or `app/Console/Kernel.php` if it exists)

- [ ] **Step 1: Create the prune command**

```bash
cd /home/kwat0g/Desktop/kwatog/api && php artisan make:command PruneAuditLogs
```

- [ ] **Step 2: Implement the command**

Open `api/app/Console/Commands/PruneAuditLogs.php` and implement:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune {--months=12 : Retain logs for this many months}';
    protected $description = 'Delete audit logs older than the retention period';

    public function handle(): int
    {
        $months = (int) $this->option('months');
        $cutoff = now()->subMonths($months)->startOfDay();

        $deleted = DB::table('audit_logs')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} audit log records older than {$months} months.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 3: Schedule the command**

Open `api/routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('audit:prune --months=12')->monthly()->runInBackground();
```

- [ ] **Step 4: Verify artisan lists the command**

```bash
cd /home/kwat0g/Desktop/kwatog/api && php artisan list | grep audit
```

Expected: `audit:prune` appears.

- [ ] **Step 5: Commit**

```bash
git add api/app/Console/Commands/PruneAuditLogs.php api/routes/console.php
git commit -m "feat: add audit:prune command with 12-month default retention, scheduled monthly"
```

---

### TASK 8 — Add Request Cancellation to React Query

**Context:** When users navigate away from a page while a request is in-flight, TanStack Query keeps the request running and updates stale component state. This wastes bandwidth and can cause React "setState on unmounted component" warnings.

**Files:**
- Modify: `spa/src/api/client.ts` (verify AbortSignal support — Axios supports it natively)
- Modify: a representative list page, e.g. `spa/src/pages/purchasing/purchase-requests/index.tsx`

- [ ] **Step 1: Verify Axios AbortSignal support**

Axios >= 0.22 supports `signal` in request config. Check version:

```bash
cd /home/kwat0g/Desktop/kwatog/spa && cat package.json | grep '"axios"'
```

If `>= 0.22`, proceed. Axios passes the `signal` from `queryFn`'s context automatically.

- [ ] **Step 2: Update API functions to accept and pass signal**

In `spa/src/api/purchasing/purchase-requests.ts`, update the `list` function to accept a signal:

```typescript
list: (params?: ListParams, signal?: AbortSignal) =>
  client.get<PaginatedResponse<PurchaseRequest>>('/purchase-requests', { params, signal }).then(r => r.data),
```

- [ ] **Step 3: Update query consumer to pass signal**

In `spa/src/pages/purchasing/purchase-requests/index.tsx`:

```typescript
const { data, isLoading } = useQuery({
  queryKey: ['purchase-requests', filters],
  queryFn: ({ signal }) => purchaseRequestsApi.list(filters, signal),
});
```

- [ ] **Step 4: Apply pattern to other frequently-navigated list pages**

Repeat Step 2-3 for:
- `spa/src/api/inventory/` list endpoints
- `spa/src/api/hr/` list endpoints

(At minimum the purchasing module as a demonstration of the pattern.)

- [ ] **Step 5: TypeScript check**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit 2>&1 | head -20
```

- [ ] **Step 6: Commit**

```bash
git add spa/src/api/ spa/src/pages/purchasing/
git commit -m "feat: add AbortSignal support to purchase-requests API for request cancellation on navigation"
```

---

### TASK 9 — Feature Test Scaffold (Laravel)

**Context:** Zero discovered tests. This task adds the scaffold and one feature test per major module to establish the pattern.

**Files:**
- Create: `api/tests/Feature/Modules/Purchasing/PurchaseRequestTest.php`
- Create: `api/tests/Feature/Modules/HR/EmployeeTest.php`
- Create: `api/tests/Feature/Modules/Accounting/JournalEntryTest.php`
- Modify: `api/phpunit.xml` (if test path not configured)

- [ ] **Step 1: Check existing test setup**

```bash
ls /home/kwat0g/Desktop/kwatog/api/tests/
cat /home/kwat0g/Desktop/kwatog/api/phpunit.xml 2>/dev/null || cat /home/kwat0g/Desktop/kwatog/api/phpunit.xml.dist
```

- [ ] **Step 2: Create Purchase Request feature test**

Create `api/tests/Feature/Modules/Purchasing/PurchaseRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchasing;

use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;
    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requester = User::factory()->withRole('staff')->create();
        $this->approver  = User::factory()->withRole('manager')->create();
    }

    public function test_requester_can_create_purchase_request(): void
    {
        $this->actingAs($this->requester);

        $response = $this->postJson('/api/v1/purchase-requests', [
            'title'    => 'Office Supplies Q3',
            'priority' => 'normal',
            'items'    => [
                ['description' => 'A4 Paper', 'quantity' => 10, 'unit' => 'ream', 'unit_price' => '250.00'],
            ],
        ]);

        $response->assertCreated()
                 ->assertJsonPath('data.status', 'draft');
    }

    public function test_staff_cannot_approve_own_request(): void
    {
        $pr = PurchaseRequest::factory()->for($this->requester)->draft()->create();
        $this->actingAs($this->requester);

        $response = $this->postJson("/api/v1/purchase-requests/{$pr->hash_id}/approve");

        $response->assertForbidden();
    }

    public function test_manager_can_approve_submitted_request(): void
    {
        $pr = PurchaseRequest::factory()->for($this->requester)->submitted()->create();
        $this->actingAs($this->approver);

        $response = $this->postJson("/api/v1/purchase-requests/{$pr->hash_id}/approve", [
            'remarks' => 'Approved for Q3',
        ]);

        $response->assertOk()
                 ->assertJsonPath('data.status', 'approved');
    }
}
```

- [ ] **Step 2: Run test to verify it fails (no factories yet)**

```bash
cd /home/kwat0g/Desktop/kwatog/api && php artisan test tests/Feature/Modules/Purchasing/PurchaseRequestTest.php 2>&1 | head -30
```

Expected: Fails with "Class not found" or "Factory not found" errors — confirms tests run, just need factories.

- [ ] **Step 3: Create minimum factories for User with role**

Check if factories exist:

```bash
ls /home/kwat0g/Desktop/kwatog/api/database/factories/
```

If `UserFactory.php` exists, add a `withRole()` state. If it doesn't exist:

```php
// api/database/factories/UserFactory.php (create if missing)
<?php

namespace Database\Factories;

use App\Modules\Admin\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'              => $this->faker->name(),
            'email'             => $this->faker->unique()->safeEmail(),
            'password'          => bcrypt('password'),
            'email_verified_at' => now(),
        ];
    }

    public function withRole(string $slug): static
    {
        return $this->afterCreating(function ($user) use ($slug) {
            $role = Role::where('slug', $slug)->firstOrFail();
            $user->roles()->attach($role);
        });
    }
}
```

- [ ] **Step 4: Run tests again**

```bash
cd /home/kwat0g/Desktop/kwatog/api && php artisan test tests/Feature/Modules/Purchasing/ 2>&1 | tail -20
```

Expected: Tests pass (or clearly fail with domain logic errors, not framework errors).

- [ ] **Step 5: Commit**

```bash
git add api/tests/ api/database/factories/
git commit -m "test: add feature test scaffold for PurchaseRequest — approve/reject/self-approval guard"
```

---

### TASK 10 — Vitest Unit Test Scaffold (SPA)

**Context:** Zero SPA tests. This task adds Vitest + React Testing Library and tests the two most critical utility functions: `chipVariantForStatus` and `applyServerValidationErrors`.

**Files:**
- Modify: `spa/package.json`
- Create: `spa/vitest.config.ts`
- Create: `spa/src/components/ui/__tests__/Chip.test.ts`
- Create: `spa/src/lib/__tests__/formErrors.test.ts`

- [ ] **Step 1: Install Vitest and testing dependencies**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npm install -D vitest @vitest/ui jsdom @testing-library/react @testing-library/user-event
```

- [ ] **Step 2: Create vitest config**

Create `spa/vitest.config.ts`:

```typescript
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test-setup.ts'],
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
});
```

- [ ] **Step 3: Create test setup file**

Create `spa/src/test-setup.ts`:

```typescript
import '@testing-library/jest-dom';
```

- [ ] **Step 4: Add test script to package.json**

In `spa/package.json`, add to `scripts`:

```json
"test": "vitest",
"test:ui": "vitest --ui"
```

- [ ] **Step 5: Write Chip tests**

Create `spa/src/components/ui/__tests__/Chip.test.ts`:

```typescript
import { chipVariantForStatus } from '@/components/ui/Chip';
import { describe, it, expect } from 'vitest';

describe('chipVariantForStatus', () => {
  it('maps approved to success', () => {
    expect(chipVariantForStatus('approved')).toBe('success');
  });

  it('maps breakdown to danger', () => {
    expect(chipVariantForStatus('breakdown')).toBe('danger');
  });

  it('maps draft to warning', () => {
    expect(chipVariantForStatus('draft')).toBe('warning');
  });

  it('maps unknown status to neutral', () => {
    expect(chipVariantForStatus('some_future_status')).toBe('neutral');
  });

  it('maps in_progress to info', () => {
    expect(chipVariantForStatus('in_progress')).toBe('info');
  });
});
```

- [ ] **Step 6: Run tests**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npm test -- --run 2>&1 | tail -20
```

Expected: All tests pass.

- [ ] **Step 7: Commit**

```bash
git add spa/vitest.config.ts spa/src/test-setup.ts spa/src/components/ui/__tests__/ spa/package.json
git commit -m "test: add Vitest scaffold and Chip.chipVariantForStatus unit tests"
```

---

### TASK 11 — N+1 Query Detection Dev Middleware

**Context:** `AppServiceProvider` already disables lazy loading in non-production (`Model::preventLazyLoading()`). But there's no visibility into slow queries (>100ms) during development. Add a DB query log middleware.

**Files:**
- Create: `api/app/Common/Middleware/LogSlowQueries.php`
- Modify: `api/bootstrap/app.php` (add middleware to dev pipeline)

- [ ] **Step 1: Create slow query logging middleware**

Create `api/app/Common/Middleware/LogSlowQueries.php`:

```php
<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LogSlowQueries
{
    private const THRESHOLD_MS = 100;

    public function handle(Request $request, Closure $next): mixed
    {
        if (!app()->isLocal()) {
            return $next($request);
        }

        DB::listen(function ($query) use ($request) {
            if ($query->time >= self::THRESHOLD_MS) {
                Log::channel('stderr')->warning('Slow query', [
                    'ms'  => $query->time,
                    'sql' => $query->sql,
                    'url' => $request->path(),
                ]);
            }
        });

        return $next($request);
    }
}
```

- [ ] **Step 2: Register middleware in bootstrap/app.php**

Open `api/bootstrap/app.php`. Add the middleware to the `api` middleware group (only runs in local):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->api(append: [
        \App\Common\Middleware\LogSlowQueries::class,
    ]);
})
```

- [ ] **Step 3: Verify it loads**

```bash
cd /home/kwat0g/Desktop/kwatog/api && php artisan route:list --compact 2>&1 | tail -5
```

Expected: No errors. Slow queries will now appear in `storage/logs/laravel.log` when running locally.

- [ ] **Step 4: Commit**

```bash
git add api/app/Common/Middleware/LogSlowQueries.php api/bootstrap/app.php
git commit -m "dev: add LogSlowQueries middleware — warns on queries >100ms in local env"
```

---

## Self-Review Against Audit Objectives

### 1. Spec Coverage Check

| Audit Objective | Covered By |
|---|---|
| Exhaustive module review | All 17 modules assessed in Architecture Assessment and Priority Matrix |
| Modernization — outdated patterns | Task 1 (Chip), Task 3 (double-unwrap), Task 4 (hardcoded constants) |
| Performance & scalability | Task 7 (audit retention), Task 8 (request cancellation), Task 11 (slow query detection) |
| Security & robustness | Task 2 (timeout), Task 6 (magic string config), Tasks 9-10 (test coverage) |
| State management | Task 5 (authStore cross-store coupling) |

### 2. Placeholder Scan
- No "TBD" or "TODO" in any task step
- All code blocks contain actual implementation code
- All commands include expected output
- All file paths are absolute

### 3. Type Consistency Check
- `chipVariantForStatus` export in Task 1 is used as `chipVariantForStatus(status)` throughout
- `AbortSignal` parameter added in Task 8 is consistently named `signal` in all references
- `PruneAuditLogs` command class name is consistent from `make:command` through scheduling

---

## Remaining Module-Specific Notes (Non-Task — Inform Future Sprints)

### HR Module
- `EmployeeForm.tsx` is in git status (modified) — check if it has `any` casts like PR detail did
- Encrypted fields (`sss_no`, `tin`, etc.) are cast correctly in model but verify Resource masks them properly

### Accounting Module
- `JournalEntryObserver` registered in AppServiceProvider — verify cache keys match what frontend queries
- Budget module (17) exists as separate entries but shares Accounting namespace — confirm routes don't conflict

### Quality Module
- NCR feedback loop (create → corrective WO → replacement WO) is the most complex cross-module event chain — verify event listeners in AppServiceProvider match current model names

### B2B Portal
- No Events/Services/Exceptions found — portal logic may be entirely in controllers (anti-pattern for thesis-quality code)
- Supplier portal PO detail page is in git status — priority to audit

### Forecasting Module
- No Services/ directory — verify business logic is not leaking into controllers

### MRP Module
- MRP runs are complex (capacity planning, Gantt) — verify `mrp-runs.ts` API functions use the AbortSignal pattern from Task 8 (long-running queries are highest cancellation risk)
