# Polish S1–S2 + D1–D8 Enhancement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden the already-built sidebar (S1 consolidation, S2 badge counts) and the eight role dashboards (D1 router → D8 QC) by replacing stubbed/buggy data with real verified queries, adding real-time badge updates over WebSocket, completing missing badges, and finishing the dashboard UX (itemized alerts, time-range selector, KPI drill-downs).

**Architecture:** Laravel 11 modular monolith API (`app/Modules/Dashboard`) feeding a React 18 + TanStack Query SPA. Dashboards return a `{ kpis: [], panels: {} }` envelope; the sidebar polls `GET /dashboards/badges`. Real-time uses Laravel Reverb (Pusher protocol) over cookie-auth private channels. Badge counts get a global cache-version bump + a `badges` broadcast channel so every client refetches instantly when relevant data changes.

**Tech Stack:** PHP 8.3, Laravel 11, PostgreSQL 16, Redis, Laravel Reverb, PHPUnit 11; React 18, TypeScript, Vite, TanStack Query v5, laravel-echo + pusher-js, Tailwind (CSS-variable tokens), Vitest.

---

## Verified facts this plan depends on (do NOT re-discover)

These were confirmed by reading source + migrations. Use them verbatim.

- **`deliveries`** has `delivered_at` (timestamp, nullable) and `scheduled_date` (date). There is **no** `actual_delivery_date` column. `RoleDashboardService::otdRate()` currently references the non-existent `actual_delivery_date` — a latent bug.
- **`warehouse_locations`** has `zone_id` (FK → `warehouse_zones`), `current_item_id`, `current_quantity` (decimal 15,3, default 0), `is_blocked` (bool). There is **no** `zone` string column and **no** `is_occupied` column. `RoleDashboardService::warehouseZoneUtilization()` currently references both non-existent columns — a latent bug.
- **`warehouse_zones`** has `id`, `warehouse_id`, `name` (string 50), `code` (string 10), `zone_type` (string 30).
- **`stock_levels`** reserved column is spelled **`reserved_quantity`** (decimal 15,3), plus `quantity` (decimal 15,3), `item_id`, `location_id`.
- **`items`**: `code`, `name`, `reorder_point` (decimal 15,3), `is_active` (bool).
- **`work_orders`**: `wo_number`, `planned_start` (dateTime), `planned_end` (dateTime), `machine_id`, `mold_id`, `status` (enum: planned, confirmed, in_progress, paused, completed, closed, cancelled), `quantity_target`.
- **`machines`**: `machine_code`, `name`, `status` (enum: running, idle, maintenance, breakdown, offline), `current_work_order_id`.
- **`maintenance_schedules`** (polymorphic): `maintainable_type` (enum: machine, mold), `maintainable_id`, `next_due_at` (timestamp, nullable), `is_active` (bool).
- **`machine_downtimes`**: `machine_id`, `start_time`, `end_time` (nullable), `category` (enum incl. `breakdown`, `planned_maintenance`).
- **`purchase_order_items`**: `purchase_order_id`, `item_id`, `quantity`. **`purchase_orders`**: `expected_delivery_date` (date, nullable).
- **`mrp_runs`** table exists (migration `0110`). **`molds`**: `current_shot_count`, `max_shots_before_maintenance`.
- Backend tests: **PHPUnit 11** (not Pest). Existing test: `api/tests/Feature/Dashboard/BadgeControllerTest.php` (extends `Tests\TestCase`, uses `RefreshDatabase`). SPA tests: **Vitest**.
- Component props: `StatCard({ label, value, helper?, delta?, linkTo?, className? })`; `Panel({ title?, meta?, actions?, bodyClassName?, noPadding?, children })`.
- `spa/src/lib/dashboardLinks.ts` exposes `kpiLink(label)`, `chainStageLink(key)`, `alertLink(kind)` — `kpiLink` is a `switch` returning `undefined` for unmapped labels (card stays non-clickable).
- Echo singleton: `spa/src/lib/echo.ts` exports `echo` (auto-connected). Channel auth closures live in `api/routes/channels.php`.
- Badge API contract: `GET /dashboards/badges` → `{ data: { <key>: { count: number, severity: 'warning'|'danger'|'neutral' } } }`. SPA hook `useBadges()` keys the query `['sidebar','badges']`.

---

## File Structure

### Backend (`api/`)

| File | Responsibility | Action |
|---|---|---|
| `app/Modules/Dashboard/Services/RoleDashboardService.php` | All role dashboard aggregation | Modify — fix `otdRate`, `warehouseZoneUtilization`, `warehouseLowStockAlerts`, `lowStockItemCount`; rewrite `machineAvailabilityGrid`, `productionGantt`, `alerts`, `purchasingUpcomingDeliveries`, `warehouseIncomingQueue`; add `rangeBounds`/time-range support to `plantManager` |
| `app/Modules/Dashboard/Services/BadgeService.php` | Badge counts + severity + cache | Modify — add `payroll`/`work_orders`/`deliveries` badges, align `low_stock`, config-driven severity, version-keyed cache, `touch()` |
| `config/badges.php` | Badge severity thresholds | Create |
| `app/Modules/Dashboard/Events/BadgesChanged.php` | Broadcast event for real-time badge refresh | Create |
| `app/Modules/Dashboard/Observers/BadgeInvalidationObserver.php` | Bumps badge cache version + broadcasts on relevant model writes | Create |
| `app/Modules/Dashboard/DashboardServiceProvider.php` (or existing module provider) | Register observer + config | Modify/verify |
| `routes/channels.php` | Channel auth | Modify — add `badges` channel |
| `app/Modules/Dashboard/Controllers/DashboardController.php` | Plant-manager range param passthrough | Modify |
| `tests/Feature/Dashboard/RoleDashboardServiceTest.php` | Cover the fixed/real queries | Create |
| `tests/Feature/Dashboard/BadgeRealtimeTest.php` | Cover new badges + version bump + event | Create |

### Frontend (`spa/`)

| File | Responsibility | Action |
|---|---|---|
| `src/hooks/useBadges.ts` | Badge polling + real-time invalidation | Modify — subscribe to `badges` channel |
| `src/components/layout/Sidebar.tsx` | Nav + badge wiring | Modify — add `badgeKey` for work-orders / payroll / deliveries |
| `src/api/badges.ts` | Badge types | Modify — extend `BadgeSeverity`/doc only if needed (no change to shape) |
| `src/lib/dashboardLinks.ts` | KPI/alert drill-down URLs | Modify — add purchasing/warehouse/quality KPI labels + new alert kinds |
| `src/pages/dashboard/plant-manager.tsx` | D2 | Modify — itemized alerts, time-range selector, KPI links |
| `src/pages/dashboard/ppc.tsx` | D3 | Modify — itemized alerts render, richer gantt cell labels |
| `src/pages/dashboard/purchasing.tsx` | D6 | Modify — render real `items_count`, KPI links |
| `src/pages/dashboard/warehouse.tsx` | D7 | Modify — KPI links (zone util now real from backend) |
| `src/pages/dashboard/quality.tsx` | D8 | Modify — KPI links |
| `src/hooks/useBadges.test.tsx` | Vitest for real-time invalidation | Create |

---

## PART A — Backend correctness: fix bugs + kill stubs

### Task A1: Fix `otdRate()` — use real `delivered_at` column

**Files:**
- Modify: `api/app/Modules/Dashboard/Services/RoleDashboardService.php:449-463`
- Test: `api/tests/Feature/Dashboard/RoleDashboardServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `api/tests/Feature/Dashboard/RoleDashboardServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Services\RoleDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): RoleDashboardService
    {
        return app(RoleDashboardService::class);
    }

    /** OTD must read `delivered_at`, not the non-existent `actual_delivery_date`. */
    public function test_otd_rate_counts_on_time_deliveries_using_delivered_at(): void
    {
        $soId = DB::table('sales_orders')->insertGetId([
            'so_number' => 'SO-TEST-0001', 'status' => 'delivered',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // 1 on-time (delivered_at <= scheduled_date), 1 late.
        DB::table('deliveries')->insert([
            ['delivery_number' => 'DLV-1', 'sales_order_id' => $soId, 'status' => 'delivered',
             'scheduled_date' => now()->subDays(2)->toDateString(), 'delivered_at' => now()->subDays(2),
             'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['delivery_number' => 'DLV-2', 'sales_order_id' => $soId, 'status' => 'delivered',
             'scheduled_date' => now()->subDays(5)->toDateString(), 'delivered_at' => now()->subDays(2),
             'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $rate = (new \ReflectionClass($this->service()));
        $m = $rate->getMethod('otdRate');
        $m->setAccessible(true);

        $this->assertSame('50.0', $m->invoke($this->service()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd api && ./vendor/bin/phpunit --filter test_otd_rate_counts_on_time_deliveries_using_delivered_at`
Expected: FAIL — SQL error `column "actual_delivery_date" does not exist`.

- [ ] **Step 3: Apply the fix**

Replace `RoleDashboardService::otdRate()` (lines 449-463) with:

```php
    private function otdRate(): string
    {
        if (! Schema::hasTable('deliveries')) return '0.0';
        $base = fn () => DB::table('deliveries')
            ->whereIn('status', ['delivered', 'confirmed'])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [now()->subMonth(), now()]);

        $total = (int) $base()->count();
        if ($total === 0) return '0.0';

        $onTime = (int) $base()
            ->whereColumn('delivered_at', '<=', 'scheduled_date')
            ->count();

        return number_format(($onTime * 100.0) / $total, 1, '.', '');
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd api && ./vendor/bin/phpunit --filter test_otd_rate_counts_on_time_deliveries_using_delivered_at`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Dashboard/Services/RoleDashboardService.php api/tests/Feature/Dashboard/RoleDashboardServiceTest.php
git commit -m "fix(dashboard): OTD rate reads delivered_at not missing actual_delivery_date"
```

---

### Task A2: Fix `warehouseZoneUtilization()` — real `zone_id` + occupancy

**Files:**
- Modify: `api/app/Modules/Dashboard/Services/RoleDashboardService.php:1059-1073`
- Test: `api/tests/Feature/Dashboard/RoleDashboardServiceTest.php`

- [ ] **Step 1: Write the failing test**

Append to `RoleDashboardServiceTest.php`:

```php
    public function test_zone_utilization_uses_zone_name_and_current_quantity(): void
    {
        $whId = DB::table('warehouses')->insertGetId([
            'code' => 'WH1', 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $zoneId = DB::table('warehouse_zones')->insertGetId([
            'warehouse_id' => $whId, 'name' => 'Raw Materials', 'code' => 'A',
            'zone_type' => 'raw_materials', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // 1 occupied (current_quantity > 0), 1 empty → 50%.
        DB::table('warehouse_locations')->insert([
            ['zone_id' => $zoneId, 'code' => 'A-1', 'is_active' => true, 'current_quantity' => 12.5,
             'is_blocked' => false, 'created_at' => now(), 'updated_at' => now()],
            ['zone_id' => $zoneId, 'code' => 'A-2', 'is_active' => true, 'current_quantity' => 0,
             'is_blocked' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $ref = new \ReflectionClass($this->service());
        $m = $ref->getMethod('warehouseZoneUtilization');
        $m->setAccessible(true);
        $rows = $m->invoke($this->service());

        $this->assertCount(1, $rows);
        $this->assertSame('Raw Materials', $rows[0]['name']);
        $this->assertSame('A', $rows[0]['zone']);
        $this->assertSame(50, $rows[0]['percent']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd api && ./vendor/bin/phpunit --filter test_zone_utilization_uses_zone_name_and_current_quantity`
Expected: FAIL — SQL error `column "zone" does not exist` (and/or `is_occupied`).

- [ ] **Step 3: Apply the fix**

Replace `warehouseZoneUtilization()` (lines 1059-1073) with:

```php
    /**
     * Zone occupancy = locations holding stock (current_quantity > 0) over
     * total active locations, grouped by zone. Joins warehouse_zones for the
     * human label (the `zone` string column never existed; zone is an FK).
     *
     * @return array<int, array{zone: string, name: string, occupied: int, total: int, percent: int}>
     */
    private function warehouseZoneUtilization(): array
    {
        if (! Schema::hasTable('warehouse_locations') || ! Schema::hasTable('warehouse_zones')) return [];

        return DB::table('warehouse_locations as wl')
            ->join('warehouse_zones as wz', 'wz.id', '=', 'wl.zone_id')
            ->where('wl.is_active', true)
            ->groupBy('wz.id', 'wz.code', 'wz.name')
            ->orderBy('wz.code')
            ->select(
                'wz.code as zone',
                'wz.name as name',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN wl.current_quantity > 0 THEN 1 ELSE 0 END) as occupied'),
            )
            ->get()
            ->map(fn ($r) => [
                'zone'    => $r->zone,
                'name'    => $r->name,
                'occupied' => (int) $r->occupied,
                'total'   => (int) $r->total,
                'percent' => (int) round(((int) $r->occupied * 100) / max(1, (int) $r->total)),
            ])
            ->all();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd api && ./vendor/bin/phpunit --filter test_zone_utilization_uses_zone_name_and_current_quantity`
Expected: PASS.

- [ ] **Step 5: Verify the SPA renderer still matches** — `spa/src/pages/dashboard/warehouse.tsx` `ZoneUtilizationPanel` reads `zone`, `name`, `percent`. The new shape still includes all three (adds `occupied`/`total`). No SPA change required. Confirm by reading the panel; if it destructures only those keys, leave it.

- [ ] **Step 6: Commit**

```bash
git add api/app/Modules/Dashboard/Services/RoleDashboardService.php api/tests/Feature/Dashboard/RoleDashboardServiceTest.php
git commit -m "fix(dashboard): zone utilization uses zone_id join + current_quantity occupancy"
```

---

### Task A3: Fix low-stock — single query (no N+1) + align with `reserved_quantity`

**Files:**
- Modify: `api/app/Modules/Dashboard/Services/RoleDashboardService.php:966-973` (`lowStockItemCount`) and `:1025-1054` (`warehouseLowStockAlerts`)
- Test: `api/tests/Feature/Dashboard/RoleDashboardServiceTest.php`

**Rationale:** `lowStockItemCount()` compares `reorder_point` to `SUM(quantity)`, but `BadgeService.low_stock` compares to `SUM(quantity - reserved_quantity)`. Align both on **available** stock. `warehouseLowStockAlerts()` runs a `DB::table('stock_levels')->sum()` per row inside `->map()` (N+1). Replace with a correlated subquery selected in the main query.

- [ ] **Step 1: Write the failing test**

Append to `RoleDashboardServiceTest.php`:

```php
    public function test_low_stock_alerts_use_available_quantity_in_one_query(): void
    {
        $catId = DB::table('item_categories')->insertGetId([
            'code' => 'RM', 'name' => 'Raw', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId = DB::table('items')->insertGetId([
            'code' => 'RC-001', 'name' => 'Resin C', 'category_id' => $catId,
            'item_type' => 'raw_material', 'unit_of_measure' => 'kg', 'standard_cost' => 0,
            'reorder_method' => 'manual', 'reorder_point' => 200, 'safety_stock' => 0,
            'minimum_order_quantity' => 0, 'lead_time_days' => 0, 'is_critical' => false,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $whId = DB::table('warehouses')->insertGetId(['code' => 'WH1', 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        $zoneId = DB::table('warehouse_zones')->insertGetId(['warehouse_id' => $whId, 'name' => 'A', 'code' => 'A', 'zone_type' => 'raw_materials', 'created_at' => now(), 'updated_at' => now()]);
        $locId = DB::table('warehouse_locations')->insertGetId(['zone_id' => $zoneId, 'code' => 'A-1', 'is_active' => true, 'current_quantity' => 0, 'is_blocked' => false, 'created_at' => now(), 'updated_at' => now()]);
        // qty 150, reserved 60 → available 90 < reorder 200 → low.
        DB::table('stock_levels')->insert([
            'item_id' => $itemId, 'location_id' => $locId, 'quantity' => 150, 'reserved_quantity' => 60,
            'weighted_avg_cost' => 0, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ref = new \ReflectionClass($this->service());
        $countM = $ref->getMethod('lowStockItemCount'); $countM->setAccessible(true);
        $alertsM = $ref->getMethod('warehouseLowStockAlerts'); $alertsM->setAccessible(true);

        $this->assertSame(1, $countM->invoke($this->service()));
        $rows = $alertsM->invoke($this->service());
        $this->assertCount(1, $rows);
        $this->assertSame('RC-001', $rows[0]['item_code']);
        $this->assertSame('90.00', $rows[0]['current_stock']);   // available, not gross 150
        $this->assertSame('110.00', $rows[0]['shortage']);        // 200 - 90
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd api && ./vendor/bin/phpunit --filter test_low_stock_alerts_use_available_quantity_in_one_query`
Expected: FAIL — `current_stock` is `150.00` (gross) not `90.00`.

- [ ] **Step 3: Apply the fix**

Replace `lowStockItemCount()` (lines 966-973):

```php
    private function lowStockItemCount(): int
    {
        if (! Schema::hasTable('items') || ! Schema::hasTable('stock_levels')) return 0;
        return (int) DB::table('items')
            ->where('is_active', true)
            ->where('reorder_point', '>', 0)
            ->whereRaw('items.reorder_point > COALESCE((SELECT SUM(quantity - reserved_quantity) FROM stock_levels WHERE stock_levels.item_id = items.id), 0)')
            ->count();
    }
```

Replace `warehouseLowStockAlerts()` (lines 1025-1054):

```php
    /**
     * @return array<int, array{item_code: string, item_name: string, current_stock: string, reorder_point: string, shortage: string, supplier_id: string|null, supplier_name: string|null}>
     */
    private function warehouseLowStockAlerts(): array
    {
        if (! Schema::hasTable('items') || ! Schema::hasTable('stock_levels')) return [];

        $availableSub = '(SELECT COALESCE(SUM(quantity - reserved_quantity), 0) FROM stock_levels WHERE stock_levels.item_id = items.id)';

        $query = DB::table('items')
            ->where('items.is_active', true)
            ->where('items.reorder_point', '>', 0)
            ->whereRaw("items.reorder_point > {$availableSub}")
            ->select(
                'items.id',
                'items.code',
                'items.name',
                'items.reorder_point',
                DB::raw("{$availableSub} as available"),
            );

        // Best-effort first approved supplier per item — left join, no N+1.
        if (Schema::hasTable('approved_suppliers') && Schema::hasTable('vendors')) {
            $query->leftJoin('approved_suppliers as ap', 'ap.item_id', '=', 'items.id')
                  ->leftJoin('vendors as v', 'v.id', '=', 'ap.vendor_id')
                  ->addSelect('v.id as vendor_id', 'v.name as vendor_name')
                  ->groupBy('items.id', 'items.code', 'items.name', 'items.reorder_point', 'v.id', 'v.name');
        }

        return $query
            ->orderBy('items.name')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                $available = (float) $r->available;
                $reorder   = (float) $r->reorder_point;
                return [
                    'item_code'     => $r->code,
                    'item_name'     => $r->name,
                    'current_stock' => number_format($available, 2, '.', ''),
                    'reorder_point' => number_format($reorder, 2, '.', ''),
                    'shortage'      => number_format(max(0.0, $reorder - $available), 2, '.', ''),
                    'supplier_id'   => isset($r->vendor_id) && $r->vendor_id ? app('hashids')->encode((int) $r->vendor_id) : null,
                    'supplier_name' => $r->vendor_name ?? null,
                ];
            })
            ->all();
    }
```

> Note: `groupBy` with the supplier join collapses multiple approved suppliers to one row per (item, vendor); the `limit(10)` + `orderBy('items.name')` keeps the panel bounded. This matches the existing UI which already shows "first supplier only".

- [ ] **Step 4: Run test to verify it passes**

Run: `cd api && ./vendor/bin/phpunit --filter test_low_stock_alerts_use_available_quantity_in_one_query`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Dashboard/Services/RoleDashboardService.php api/tests/Feature/Dashboard/RoleDashboardServiceTest.php
git commit -m "fix(dashboard): low-stock uses available qty (qty - reserved) in a single query"
```

---

### Task A4: Real `machineAvailabilityGrid()` — per-day from scheduled WOs + maintenance

**Files:**
- Modify: `api/app/Modules/Dashboard/Services/RoleDashboardService.php:770-793`
- Test: `api/tests/Feature/Dashboard/RoleDashboardServiceTest.php`

**Rationale:** Current grid copies one `machine.status` across all 7 days (every day identical). Compute real per-day status: `maintenance` if a `maintenance_schedules.next_due_at` falls on that day for that machine, else `busy` if a confirmed/in-progress WO's `[planned_start, planned_end]` covers that day, else `available`.

- [ ] **Step 1: Write the failing test**

Append to `RoleDashboardServiceTest.php`:

```php
    public function test_machine_availability_grid_marks_busy_days_from_planned_wo_window(): void
    {
        $machineId = DB::table('machines')->insertGetId([
            'machine_code' => 'IM-001', 'name' => '150T', 'machine_type' => 'injection',
            'operators_required' => 1, 'available_hours_per_day' => 8, 'status' => 'running',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $prodId = DB::table('products')->insertGetId([
            'code' => 'WB-001', 'name' => 'Wiper Bushing', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // WO occupies today + tomorrow.
        DB::table('work_orders')->insert([
            'wo_number' => 'WO-1', 'product_id' => $prodId, 'machine_id' => $machineId,
            'quantity_target' => 100, 'quantity_produced' => 0, 'quantity_good' => 0, 'quantity_rejected' => 0,
            'scrap_rate' => 0, 'planned_start' => now()->startOfDay(), 'planned_end' => now()->addDay()->endOfDay(),
            'status' => 'confirmed', 'priority' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ref = new \ReflectionClass($this->service());
        $m = $ref->getMethod('machineAvailabilityGrid'); $m->setAccessible(true);
        $rows = $m->invoke($this->service());

        $today = now()->toDateString();
        $day3  = now()->addDays(3)->toDateString();
        $busyToday = collect($rows)->first(fn ($r) => $r['machine'] === 'IM-001' && $r['date'] === $today);
        $freeDay3  = collect($rows)->first(fn ($r) => $r['machine'] === 'IM-001' && $r['date'] === $day3);

        $this->assertSame('busy', $busyToday['status']);
        $this->assertSame('available', $freeDay3['status']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd api && ./vendor/bin/phpunit --filter test_machine_availability_grid_marks_busy_days_from_planned_wo_window`
Expected: FAIL — current impl returns the same status for every day; also it emits `day` as a weekday label (`'Mon'`), not an ISO `date`, so `$r['date']` is undefined.

- [ ] **Step 3: Apply the fix**

Replace `machineAvailabilityGrid()` (lines 770-793) with:

```php
    /**
     * Real 7-day availability per machine. For each (machine, day):
     *   - 'maintenance' if a preventive schedule is due that day
     *   - 'busy'        if a confirmed/in-progress WO window covers that day
     *   - 'available'   otherwise
     * Emits both ISO `date` and short `label` so the SPA can show weekday
     * headers without re-parsing.
     *
     * @return array<int, array{machine: string, date: string, label: string, status: string}>
     */
    private function machineAvailabilityGrid(): array
    {
        if (! Schema::hasTable('machines') || ! Schema::hasTable('work_orders')) return [];

        $machines = DB::table('machines')
            ->select('id', 'machine_code')
            ->orderBy('machine_code')
            ->limit(12)
            ->get();
        if ($machines->isEmpty()) return [];

        $start = now()->startOfDay();
        $end   = $start->copy()->addDays(6)->endOfDay();

        $wos = DB::table('work_orders')
            ->whereIn('status', ['confirmed', 'in_progress'])
            ->whereNotNull('machine_id')
            ->whereNotNull('planned_start')
            ->whereNotNull('planned_end')
            ->where('planned_start', '<=', $end)
            ->where('planned_end', '>=', $start)
            ->get(['machine_id', 'planned_start', 'planned_end']);

        $maint = collect();
        if (Schema::hasTable('maintenance_schedules')) {
            $maint = DB::table('maintenance_schedules')
                ->where('maintainable_type', 'machine')
                ->where('is_active', true)
                ->whereNotNull('next_due_at')
                ->whereBetween('next_due_at', [$start, $end])
                ->get(['maintainable_id', 'next_due_at']);
        }

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = $start->copy()->addDays($i);
        }

        $rows = [];
        foreach ($machines as $m) {
            foreach ($days as $day) {
                $dayStart = $day->copy()->startOfDay();
                $dayEnd   = $day->copy()->endOfDay();

                $isMaint = $maint->contains(fn ($s) => (int) $s->maintainable_id === (int) $m->id
                    && Carbon::parse((string) $s->next_due_at)->betweenIncluded($dayStart, $dayEnd));

                $isBusy = $wos->contains(fn ($wo) => (int) $wo->machine_id === (int) $m->id
                    && Carbon::parse((string) $wo->planned_start) <= $dayEnd
                    && Carbon::parse((string) $wo->planned_end) >= $dayStart);

                $status = $isMaint ? 'maintenance' : ($isBusy ? 'busy' : 'available');

                $rows[] = [
                    'machine' => $m->machine_code,
                    'date'    => $day->toDateString(),
                    'label'   => $day->format('D'),
                    'status'  => $status,
                ];
            }
        }
        return $rows;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd api && ./vendor/bin/phpunit --filter test_machine_availability_grid_marks_busy_days_from_planned_wo_window`
Expected: PASS.

- [ ] **Step 5: Update the SPA grid to use `date`/`label`**

In `spa/src/pages/dashboard/ppc.tsx`, the `GanttRow` interface (lines 59-63) becomes:

```tsx
interface GanttRow {
  machine: string;
  date: string;
  label: string;
  status: string;
}
```

In `MachineAvailabilityGrid` (lines 387-437), replace the day derivation + header + cell lookup:

```tsx
  const machines = [...new Set(rows.map((r) => r.machine))];
  const days = [...new Map(rows.map((r) => [r.date, r.label])).entries()]
    .sort(([a], [b]) => a.localeCompare(b)); // [date, label][]

  return (
    <Panel title="Machine Availability (7-day)">
      <div className="overflow-x-auto">
        <table className="w-full text-xs border-collapse">
          <thead>
            <tr>
              <th className="text-left pr-2 py-1 text-2xs uppercase tracking-wider text-muted font-medium">Machine</th>
              {days.map(([date, label]) => (
                <th key={date} className="text-center px-1 py-1 text-2xs uppercase tracking-wider text-muted font-medium">{label}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {machines.map((m) => (
              <tr key={m} className="border-t border-subtle">
                <td className="pr-2 py-1.5 font-mono text-xs">{m}</td>
                {days.map(([date]) => {
                  const cell = rows.find((r) => r.machine === m && r.date === date);
                  const cls = cell?.status === 'available' ? 'bg-success/20'
                    : cell?.status === 'busy' ? 'bg-info/30'
                    : 'bg-danger/20';
                  return (
                    <td
                      key={`${m}-${date}`}
                      className={`text-center px-1 py-1.5 rounded-sm ${cls}`}
                      aria-label={`${m} on ${date}: ${cell?.status ?? 'unknown'}`}
                    >
                      {cell?.status === 'available' ? '✓' : cell?.status === 'busy' ? '●' : '✗'}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Panel>
  );
```

- [ ] **Step 6: Run SPA typecheck**

Run: `cd spa && npx tsc --noEmit`
Expected: no errors related to `ppc.tsx`.

- [ ] **Step 7: Commit**

```bash
git add api/app/Modules/Dashboard/Services/RoleDashboardService.php api/tests/Feature/Dashboard/RoleDashboardServiceTest.php spa/src/pages/dashboard/ppc.tsx
git commit -m "feat(dashboard): real per-day machine availability from WO windows + maintenance"
```

---

### Task A5: Enrich `productionGantt()` cells with WO number

**Files:**
- Modify: `api/app/Modules/Dashboard/Services/RoleDashboardService.php:709-739`
- Modify: `spa/src/pages/dashboard/ppc.tsx` `ProductionGanttPanel`
- Test: `api/tests/Feature/Dashboard/RoleDashboardServiceTest.php`

**Rationale:** Gantt is real but emits only `status`. Add `wo_number` per occupied cell so the PPC head sees *which* WO occupies a slot (tooltip).

- [ ] **Step 1: Write the failing test**

Append:

```php
    public function test_production_gantt_includes_wo_number_for_occupied_cells(): void
    {
        $machineId = DB::table('machines')->insertGetId([
            'machine_code' => 'IM-002', 'name' => '150T', 'machine_type' => 'injection',
            'operators_required' => 1, 'available_hours_per_day' => 8, 'status' => 'running',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $prodId = DB::table('products')->insertGetId(['code' => 'P1', 'name' => 'Part', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('work_orders')->insert([
            'wo_number' => 'WO-202604-0006', 'product_id' => $prodId, 'machine_id' => $machineId,
            'quantity_target' => 100, 'quantity_produced' => 0, 'quantity_good' => 0, 'quantity_rejected' => 0,
            'scrap_rate' => 0, 'planned_start' => now()->startOfDay(), 'planned_end' => now()->endOfDay(),
            'status' => 'in_progress', 'priority' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ref = new \ReflectionClass($this->service());
        $m = $ref->getMethod('productionGantt'); $m->setAccessible(true);
        $rows = $m->invoke($this->service());

        $today = now()->toDateString();
        $cell = collect($rows)->first(fn ($r) => $r['machine'] === 'IM-002' && $r['day'] === $today);
        $this->assertSame('running', $cell['status']);
        $this->assertSame('WO-202604-0006', $cell['wo_number']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd api && ./vendor/bin/phpunit --filter test_production_gantt_includes_wo_number_for_occupied_cells`
Expected: FAIL — `wo_number` key missing.

- [ ] **Step 3: Apply the fix**

Replace `productionGantt()` (lines 709-739) with:

```php
    /**
     * @return array<int, array{machine: string, day: string, status: string, wo_number: string|null}>
     */
    private function productionGantt(): array
    {
        if (! Schema::hasTable('work_orders') || ! Schema::hasTable('machines')) return [];

        $machines = DB::table('machines')->select('id', 'machine_code')->orderBy('machine_code')->limit(8)->get();
        $wos = DB::table('work_orders')
            ->whereIn('status', ['confirmed', 'in_progress'])
            ->whereNotNull('machine_id')
            ->whereNotNull('planned_start')
            ->whereNotNull('planned_end')
            ->get(['machine_id', 'planned_start', 'planned_end', 'status', 'wo_number']);

        $today = now()->startOfDay();
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = $today->copy()->addDays($i)->toDateString();
        }

        $rows = [];
        foreach ($machines as $m) {
            foreach ($days as $d) {
                $status = 'available';
                $woNumber = null;
                foreach ($wos as $wo) {
                    if ((int) $wo->machine_id !== (int) $m->id) continue;
                    if (Carbon::parse((string) $wo->planned_start)->toDateString() <= $d
                        && Carbon::parse((string) $wo->planned_end)->toDateString() >= $d) {
                        $status = $wo->status === 'in_progress' ? 'running' : 'planned';
                        $woNumber = $wo->wo_number;
                        break;
                    }
                }
                $rows[] = ['machine' => $m->machine_code, 'day' => $d, 'status' => $status, 'wo_number' => $woNumber];
            }
        }
        return $rows;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd api && ./vendor/bin/phpunit --filter test_production_gantt_includes_wo_number_for_occupied_cells`
Expected: PASS.

- [ ] **Step 5: Surface `wo_number` as a cell tooltip in the SPA**

In `spa/src/pages/dashboard/ppc.tsx`, the production gantt uses a separate row type from availability. Define a distinct interface and update the panel. Add near the other interfaces:

```tsx
interface ProductionGanttRow {
  machine: string;
  day: string;
  status: string;
  wo_number: string | null;
}
```

Change `PpcDashboardData.panels.production_gantt` type from `GanttRow[]` to `ProductionGanttRow[]`, and `ProductionGanttPanel`'s prop to `rows: ProductionGanttRow[]`. In its cell `<td>`, add `title={cell?.wo_number ?? undefined}`:

```tsx
                  return (
                    <td
                      key={`${m}-${d}`}
                      className={`text-center px-1 py-1.5 rounded-sm ${cls}`}
                      title={cell?.wo_number ?? undefined}
                      aria-label={`${m} on ${d}: ${cell?.status ?? 'available'}${cell?.wo_number ? ` (${cell.wo_number})` : ''}`}
                    >
                      {cell?.status === 'running' ? '▶' : cell?.status === 'planned' ? '○' : '·'}
                    </td>
                  );
```

- [ ] **Step 6: Run SPA typecheck**

Run: `cd spa && npx tsc --noEmit`
Expected: no new errors.

- [ ] **Step 7: Commit**

```bash
git add api/app/Modules/Dashboard/Services/RoleDashboardService.php api/tests/Feature/Dashboard/RoleDashboardServiceTest.php spa/src/pages/dashboard/ppc.tsx
git commit -m "feat(dashboard): production gantt cells carry WO number for tooltip"
```

---

### Task A6: Itemize `alerts()` into actionable rows with entity links

**Files:**
- Modify: `api/app/Modules/Dashboard/Services/RoleDashboardService.php:504-526`
- Modify: `spa/src/pages/dashboard/plant-manager.tsx` `AlertsPanel`
- Modify: `spa/src/pages/dashboard/ppc.tsx` `AlertsPanel`
- Test: `api/tests/Feature/Dashboard/RoleDashboardServiceTest.php`

**Rationale:** Spec D2 wants named, clickable alerts (e.g. "IM-003 Breakdown", "Resin C critical"). Current `alerts()` returns only aggregate counts per kind. Change it to return up to N **itemized** rows each with `kind`, `severity`, `label`, `ref` (entity type), `ref_id` (hash id, nullable), keeping a trailing aggregate summary row only when items exceed the cap.

- [ ] **Step 1: Write the failing test**

Append:

```php
    public function test_alerts_return_itemized_rows_with_entity_refs(): void
    {
        $machineId = DB::table('machines')->insertGetId([
            'machine_code' => 'IM-003', 'name' => '200T', 'machine_type' => 'injection',
            'operators_required' => 1, 'available_hours_per_day' => 8, 'status' => 'breakdown',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('machine_downtimes')->insert([
            'machine_id' => $machineId, 'start_time' => now()->subHour(), 'end_time' => null,
            'category' => 'breakdown', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ref = new \ReflectionClass($this->service());
        $m = $ref->getMethod('alerts'); $m->setAccessible(true);
        $rows = $m->invoke($this->service());

        $breakdown = collect($rows)->first(fn ($r) => $r['kind'] === 'breakdown');
        $this->assertNotNull($breakdown);
        $this->assertSame('danger', $breakdown['severity']);
        $this->assertStringContainsString('IM-003', $breakdown['label']);
        $this->assertSame('machine', $breakdown['ref']);
        $this->assertNotNull($breakdown['ref_id']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd api && ./vendor/bin/phpunit --filter test_alerts_return_itemized_rows_with_entity_refs`
Expected: FAIL — current rows have no `label` naming the machine, no `ref`/`ref_id`.

- [ ] **Step 3: Apply the fix**

Replace `alerts()` (lines 504-526) with:

```php
    /**
     * Itemized, actionable alerts (named + linkable) with a per-kind cap.
     * Each row: { kind, severity, label, ref, ref_id }. `ref`/`ref_id` may be
     * null for purely aggregate rows (e.g. an "urgent PRs" summary).
     *
     * @return array<int, array{kind: string, severity: string, label: string, ref: string|null, ref_id: string|null}>
     */
    private function alerts(): array
    {
        $rows = [];

        // Active machine breakdowns — name each machine.
        if (Schema::hasTable('machine_downtimes') && Schema::hasTable('machines')) {
            $breakdowns = DB::table('machine_downtimes as md')
                ->join('machines as m', 'm.id', '=', 'md.machine_id')
                ->where('md.category', 'breakdown')
                ->whereNull('md.end_time')
                ->orderByDesc('md.start_time')
                ->limit(5)
                ->get(['m.id', 'm.machine_code']);
            foreach ($breakdowns as $b) {
                $rows[] = [
                    'kind'     => 'breakdown',
                    'severity' => 'danger',
                    'label'    => "{$b->machine_code} breakdown",
                    'ref'      => 'machine',
                    'ref_id'   => app('hashids')->encode((int) $b->id),
                ];
            }
        }

        // Open NCRs — name each NCR.
        if (Schema::hasTable('non_conformance_reports')) {
            $ncrs = DB::table('non_conformance_reports')
                ->whereIn('status', ['open', 'in_progress'])
                ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'major' THEN 1 ELSE 2 END")
                ->limit(5)
                ->get(['id', 'ncr_number', 'severity']);
            foreach ($ncrs as $n) {
                $rows[] = [
                    'kind'     => 'ncr_open',
                    'severity' => $n->severity === 'critical' ? 'danger' : 'warning',
                    'label'    => "{$n->ncr_number} ({$n->severity})",
                    'ref'      => 'ncr',
                    'ref_id'   => app('hashids')->encode((int) $n->id),
                ];
            }
        }

        // Molds nearing shot limit — name each mold.
        if (Schema::hasTable('molds')) {
            $molds = DB::table('molds')
                ->whereRaw('current_shot_count >= (max_shots_before_maintenance * 0.8)')
                ->orderByRaw('(current_shot_count * 1.0 / NULLIF(max_shots_before_maintenance, 0)) DESC')
                ->limit(5)
                ->get(['id', 'mold_code', 'current_shot_count', 'max_shots_before_maintenance']);
            foreach ($molds as $mold) {
                $pct = $mold->max_shots_before_maintenance > 0
                    ? (int) round(($mold->current_shot_count * 100) / $mold->max_shots_before_maintenance)
                    : 0;
                $rows[] = [
                    'kind'     => 'mold_limit',
                    'severity' => 'warning',
                    'label'    => "{$mold->mold_code} at {$pct}% shot limit",
                    'ref'      => 'mold',
                    'ref_id'   => app('hashids')->encode((int) $mold->id),
                ];
            }
        }

        // Auto-generated urgent PRs — aggregate summary (no single ref).
        if (Schema::hasTable('purchase_requests')) {
            $urgent = (int) DB::table('purchase_requests')
                ->where('is_auto_generated', true)->where('status', 'pending')->count();
            if ($urgent > 0) {
                $rows[] = [
                    'kind'     => 'urgent_pr',
                    'severity' => 'warning',
                    'label'    => "{$urgent} auto-generated urgent PR".($urgent === 1 ? '' : 's'),
                    'ref'      => null,
                    'ref_id'   => null,
                ];
            }
        }

        return $rows;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd api && ./vendor/bin/phpunit --filter test_alerts_return_itemized_rows_with_entity_refs`
Expected: PASS.

- [ ] **Step 5: Update `alertLink()` in the SPA to resolve refs**

In `spa/src/lib/dashboardLinks.ts`, add an exported helper (place after `alertLink`):

```ts
/** Resolve an itemized alert row to its entity detail URL (or list fallback). */
export function alertRefLink(ref: string | null, refId: string | null, kind: string): string {
  if (ref && refId) {
    switch (ref) {
      case 'machine': return `/mrp/machines/${refId}`;
      case 'ncr':     return `/quality/ncrs/${refId}`;
      case 'mold':    return `/mrp/molds/${refId}`;
    }
  }
  // Fall back to the existing kind→list mapping.
  return alertLink(kind) ?? '/alerts';
}
```

- [ ] **Step 6: Render itemized alerts (Plant Manager)**

In `spa/src/pages/dashboard/plant-manager.tsx`:

Change the `alerts` panel type (lines 31, inside `PlantManagerData`) to:

```tsx
    alerts: Array<{ kind: string; severity: string; label: string; ref: string | null; ref_id: string | null }>;
```

Replace `AlertsPanel` (lines 218-249) with:

```tsx
function AlertsPanel({ alerts }: { alerts: PlantManagerData['panels']['alerts'] }) {
  const sevDot: Record<string, string> = {
    danger: 'bg-danger',
    warning: 'bg-warning',
    success: 'bg-success',
    neutral: 'bg-muted',
  };
  return (
    <Panel
      title="Alerts & Attention"
      meta={alerts.length ? String(alerts.length) : undefined}
      actions={<Link className="text-xs text-link hover:underline" to="/alerts">All alerts →</Link>}
    >
      {alerts.length === 0 ? (
        <p className="text-sm text-muted">No active alerts.</p>
      ) : (
        <ul className="divide-y divide-subtle">
          {alerts.map((a, i) => (
            <li key={`${a.kind}-${i}`} className="py-2">
              <Link
                to={alertRefLink(a.ref, a.ref_id, a.kind)}
                className="flex items-center gap-2 text-sm rounded-sm -mx-1 px-1 hover:bg-subtle transition-colors duration-fast"
              >
                <span className={`inline-block h-1.5 w-1.5 rounded-full shrink-0 ${sevDot[a.severity] ?? 'bg-muted'}`} aria-hidden />
                <span className="truncate">{a.label}</span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}
```

Add the import at the top of the file (merge into existing `dashboardLinks` import if present, else add):

```tsx
import { alertRefLink } from '@/lib/dashboardLinks';
```

- [ ] **Step 7: Render itemized alerts (PPC)**

In `spa/src/pages/dashboard/ppc.tsx`:

Change `AlertItem` interface (lines 44-49) to:

```tsx
interface AlertItem {
  kind: string;
  severity: string;
  label: string;
  ref: string | null;
  ref_id: string | null;
}
```

Replace `AlertsPanel` (lines 162-203) so the row links via `alertRefLink` and drops the now-removed `a.count`:

```tsx
function AlertsPanel({ alerts }: { alerts: AlertItem[] }) {
  if (alerts.length === 0) return null;
  return (
    <Panel title="Alerts" meta={alerts.length.toString()}>
      <ul className="divide-y divide-subtle">
        {alerts.map((a, i) => (
          <li key={`${a.kind}-${i}`} className="py-2">
            <Link
              to={alertRefLink(a.ref, a.ref_id, a.kind)}
              className="flex items-center gap-2 w-full text-sm rounded-sm px-1 -mx-1 hover:bg-subtle transition-colors duration-fast"
              aria-label={`View ${a.label}`}
            >
              <span className={alertDotClass(a.severity)} aria-hidden="true" />
              <span className="truncate">{a.label}</span>
            </Link>
          </li>
        ))}
      </ul>
    </Panel>
  );
}
```

Update the import line (line 26) to add `alertRefLink`:

```tsx
import { chainStageLink, alertRefLink, kpiLink } from '@/lib/dashboardLinks';
```

(Remove `alertLink` from that import if it is no longer referenced elsewhere in the file; run typecheck to confirm.)

- [ ] **Step 8: Run SPA typecheck**

Run: `cd spa && npx tsc --noEmit`
Expected: no errors in `plant-manager.tsx`, `ppc.tsx`, `dashboardLinks.ts`.

- [ ] **Step 9: Commit**

```bash
git add api/app/Modules/Dashboard/Services/RoleDashboardService.php api/tests/Feature/Dashboard/RoleDashboardServiceTest.php spa/src/lib/dashboardLinks.ts spa/src/pages/dashboard/plant-manager.tsx spa/src/pages/dashboard/ppc.tsx
git commit -m "feat(dashboard): itemized, linkable alerts (named breakdowns/NCRs/molds)"
```

---

### Task A7: Real `items_count` for upcoming/incoming deliveries

**Files:**
- Modify: `api/app/Modules/Dashboard/Services/RoleDashboardService.php:916-937` (`purchasingUpcomingDeliveries`) and `:978-997` (`warehouseIncomingQueue`)
- Modify: `spa/src/pages/dashboard/warehouse.tsx` incoming queue (add count) — only if it currently omits it
- Test: `api/tests/Feature/Dashboard/RoleDashboardServiceTest.php`

**Rationale:** `purchasingUpcomingDeliveries()` hardcodes `items_count => 0`. Replace with a `withCount`-style subquery from `purchase_order_items`. Add the same to the warehouse incoming queue for parity.

- [ ] **Step 1: Write the failing test**

Append:

```php
    public function test_upcoming_deliveries_count_po_line_items(): void
    {
        $vendorId = DB::table('vendors')->insertGetId([
            'code' => 'V1', 'name' => 'Taiwan Plastics', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-202604-0015', 'vendor_id' => $vendorId, 'status' => 'sent',
            'expected_delivery_date' => now()->addDays(2)->toDateString(),
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $catId = DB::table('item_categories')->insertGetId(['code' => 'RM', 'name' => 'Raw', 'created_at' => now(), 'updated_at' => now()]);
        $itemId = DB::table('items')->insertGetId([
            'code' => 'RB-001', 'name' => 'Resin B', 'category_id' => $catId, 'item_type' => 'raw_material',
            'unit_of_measure' => 'kg', 'standard_cost' => 0, 'reorder_method' => 'manual', 'reorder_point' => 0,
            'safety_stock' => 0, 'minimum_order_quantity' => 0, 'lead_time_days' => 0, 'is_critical' => false,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_order_items')->insert([
            ['purchase_order_id' => $poId, 'item_id' => $itemId, 'description' => 'Resin B', 'quantity' => 500,
             'unit_price' => 0, 'total' => 0, 'quantity_received' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['purchase_order_id' => $poId, 'item_id' => $itemId, 'description' => 'Resin B2', 'quantity' => 200,
             'unit_price' => 0, 'total' => 0, 'quantity_received' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $ref = new \ReflectionClass($this->service());
        $m = $ref->getMethod('purchasingUpcomingDeliveries'); $m->setAccessible(true);
        $rows = $m->invoke($this->service());

        $this->assertSame('PO-202604-0015', $rows[0]['po_number']);
        $this->assertSame(2, $rows[0]['items_count']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd api && ./vendor/bin/phpunit --filter test_upcoming_deliveries_count_po_line_items`
Expected: FAIL — `items_count` is `0`.

- [ ] **Step 3: Apply the fix**

In `purchasingUpcomingDeliveries()` (lines 916-937), add a counted subquery to the select and map it. Replace the method body's query with:

```php
        $itemsCountSub = Schema::hasTable('purchase_order_items')
            ? '(SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id)'
            : '0';

        return DB::table('purchase_orders as po')
            ->leftJoin('vendors as v', 'v.id', '=', 'po.vendor_id')
            ->whereIn('po.status', ['approved', 'sent', 'partial'])
            ->whereNotNull('po.expected_delivery_date')
            ->whereBetween('po.expected_delivery_date', [today(), today()->addDays(7)])
            ->select('po.id', 'po.po_number', 'v.name as vendor_name', 'po.expected_delivery_date', 'po.status',
                DB::raw("{$itemsCountSub} as items_count"))
            ->orderBy('po.expected_delivery_date')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'id'             => app('hashids')->encode((int) $r->id),
                'po_number'      => $r->po_number,
                'vendor'         => $r->vendor_name ?? '—',
                'items_count'    => (int) $r->items_count,
                'expected_date'  => $r->expected_delivery_date,
                'status'         => $r->status,
            ])
            ->all();
```

Apply the same `items_count` subquery to `warehouseIncomingQueue()` (lines 978-997): add `DB::raw("{$itemsCountSub} as items_count")` to its select and `'items_count' => (int) $r->items_count,` to its map (reuse the same `$itemsCountSub` definition at the top of that method).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd api && ./vendor/bin/phpunit --filter test_upcoming_deliveries_count_po_line_items`
Expected: PASS.

- [ ] **Step 5: Surface `items_count` in the warehouse incoming panel**

In `spa/src/pages/dashboard/warehouse.tsx`, locate the incoming-queue row type and `IncomingQueuePanel`. Add `items_count: number;` to the incoming row interface and render it next to the vendor (e.g. `<span className="text-xs text-muted font-mono tabular-nums">{row.items_count} items</span>`). If the purchasing dashboard already renders `items_count` (it has the column), no change needed there beyond the now-real value.

- [ ] **Step 6: Run typecheck + commit**

Run: `cd spa && npx tsc --noEmit` → expect clean.

```bash
git add api/app/Modules/Dashboard/Services/RoleDashboardService.php api/tests/Feature/Dashboard/RoleDashboardServiceTest.php spa/src/pages/dashboard/warehouse.tsx
git commit -m "feat(dashboard): real PO line-item counts on delivery queues"
```

---

## PART B — Badge system: missing badges, config severity, real-time

### Task B1: Config-driven badge severity thresholds

**Files:**
- Create: `api/config/badges.php`
- Modify: `api/app/Modules/Dashboard/Services/BadgeService.php:38-42, 189-194`
- Test: `api/tests/Feature/Dashboard/BadgeControllerTest.php` (existing — extend)

- [ ] **Step 1: Create the config file**

`api/config/badges.php`:

```php
<?php

declare(strict_types=1);

return [
    /*
     | Severity thresholds for sidebar badge counts.
     | count >  danger  → 'danger'
     | count >  warning → 'warning'
     | otherwise        → 'neutral'
     */
    'severity' => [
        'danger'  => (int) env('BADGE_SEVERITY_DANGER', 20),
        'warning' => (int) env('BADGE_SEVERITY_WARNING', 0),
    ],

    // Per-user badge cache TTL (seconds). Real-time bumps invalidate sooner.
    'cache_ttl' => (int) env('BADGE_CACHE_TTL', 30),
];
```

- [ ] **Step 2: Write the failing test**

Append to `api/tests/Feature/Dashboard/BadgeControllerTest.php`:

```php
    public function test_severity_thresholds_come_from_config(): void
    {
        config()->set('badges.severity.danger', 2);
        config()->set('badges.severity.warning', 0);

        $svc = app(\App\Modules\Dashboard\Services\BadgeService::class);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('severity');
        $m->setAccessible(true);

        $this->assertSame('danger', $m->invoke($svc, 3));   // > 2
        $this->assertSame('warning', $m->invoke($svc, 1));   // > 0, <= 2
        $this->assertSame('neutral', $m->invoke($svc, 0));
    }
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd api && ./vendor/bin/phpunit --filter test_severity_thresholds_come_from_config`
Expected: FAIL — `severity(3)` returns `'warning'` (hardcoded danger threshold is 20).

- [ ] **Step 4: Apply the fix**

In `BadgeService.php` delete the two `private const SEVERITY_*` lines (39-42) and replace `severity()` (189-194) with:

```php
    private function severity(int $count): string
    {
        $danger  = (int) config('badges.severity.danger', 20);
        $warning = (int) config('badges.severity.warning', 0);

        if ($count > $danger)  return 'danger';
        if ($count > $warning) return 'warning';
        return 'neutral';
    }
```

Also change the cache TTL source: replace `private const TTL_SECONDS = 30;` (line 36) usage in `for()` with `(int) config('badges.cache_ttl', 30)` (see Task B3 where `for()` is rewritten — if doing B3 next, fold this in there; otherwise update `for()` now).

- [ ] **Step 5: Run test to verify it passes**

Run: `cd api && ./vendor/bin/phpunit --filter test_severity_thresholds_come_from_config`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add api/config/badges.php api/app/Modules/Dashboard/Services/BadgeService.php api/tests/Feature/Dashboard/BadgeControllerTest.php
git commit -m "refactor(badges): config-driven severity thresholds + cache TTL"
```

---

### Task B2: Add `payroll`, `work_orders`, `deliveries` badges + align `low_stock`

**Files:**
- Modify: `api/app/Modules/Dashboard/Services/BadgeService.php:84-176`
- Test: `api/tests/Feature/Dashboard/BadgeRealtimeTest.php` (create)

**Rationale:** Spec S2 lists Payroll, Work Orders, and Deliveries badges that don't exist. Add them with correct permissions and counters. Keep `low_stock` definition (already `quantity - reserved_quantity`) but expose it on the sidebar in Part C.

Counters:
- `payroll` — periods awaiting action: `PayrollPeriod` where status in (`pending_hr`, `pending_finance`, `computed`). Permission `payroll.view`.
- `work_orders` — overdue WOs: status in (`confirmed`,`in_progress`) AND `planned_end < now()`. Permission `production.work_orders.view`.
- `deliveries` — in-transit deliveries needing update: status in (`loading`,`in_transit`). Permission `supply_chain.view`.

- [ ] **Step 1: Confirm enum/status spellings (read-only)**

Run: `cd api && grep -rn "case " app/Modules/Payroll/Enums/PayrollPeriodStatus.php app/Modules/SupplyChain/Enums/DeliveryStatus.php 2>/dev/null; grep -rn "payroll.view\|supply_chain.view\|production.work_orders.view" database/seeders/RolePermissionSeeder.php | head`
Expected: confirm exact status case values + that the three permission slugs exist. If `PayrollPeriodStatus` differs, use the actual `pending_*`/`computed` values it defines.

- [ ] **Step 2: Write the failing test**

Create `api/tests/Feature/Dashboard/BadgeRealtimeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Services\BadgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BadgeRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_definitions_include_payroll_work_orders_deliveries_keys(): void
    {
        $user = \App\Modules\Auth\Models\User::factory()->create();
        $svc = app(BadgeService::class);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('definitions');
        $m->setAccessible(true);
        $defs = $m->invoke($svc, $user);

        foreach (['payroll', 'work_orders', 'deliveries'] as $key) {
            $this->assertArrayHasKey($key, $defs, "Missing badge definition: {$key}");
        }
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd api && ./vendor/bin/phpunit --filter test_definitions_include_payroll_work_orders_deliveries_keys`
Expected: FAIL — keys absent.

- [ ] **Step 4: Apply the fix**

Add imports at the top of `BadgeService.php` (after existing `use` lines), using the **verified** model/enum paths from Step 1:

```php
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\SupplyChain\Models\Delivery;
```

Inside `definitions()` return array, before the closing `];`, add:

```php
            // Payroll > Periods awaiting HR/Finance action.
            'payroll' => [
                'permissions' => ['payroll.view'],
                'counter'     => fn (): int => PayrollPeriod::query()
                    ->whereIn('status', ['pending_hr', 'pending_finance', 'computed'])
                    ->count(),
            ],

            // Production > Work orders — overdue (planned_end passed, not done).
            'work_orders' => [
                'permissions' => ['production.work_orders.view'],
                'counter'     => fn (): int => WorkOrder::query()
                    ->whereIn('status', ['confirmed', 'in_progress'])
                    ->whereNotNull('planned_end')
                    ->where('planned_end', '<', now())
                    ->count(),
            ],

            // Supply chain > Deliveries in transit needing an update.
            'deliveries' => [
                'permissions' => ['supply_chain.view'],
                'counter'     => fn (): int => Delivery::query()
                    ->whereIn('status', ['loading', 'in_transit'])
                    ->count(),
            ],
```

> If Step 1 showed different status spellings, substitute them here verbatim.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd api && ./vendor/bin/phpunit --filter test_definitions_include_payroll_work_orders_deliveries_keys`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add api/app/Modules/Dashboard/Services/BadgeService.php api/tests/Feature/Dashboard/BadgeRealtimeTest.php
git commit -m "feat(badges): add payroll, work_orders, deliveries badge counters"
```

---

### Task B3: Version-keyed badge cache + `touch()` (foundation for real-time)

**Files:**
- Modify: `api/app/Modules/Dashboard/Services/BadgeService.php:53-60`
- Test: `api/tests/Feature/Dashboard/BadgeRealtimeTest.php`

**Rationale:** Real-time refresh requires busting every user's badge cache without enumerating users. Key the cache by a global version integer; `touch()` increments it (instant global invalidation) and is the single call sites use.

- [ ] **Step 1: Write the failing test**

Append to `BadgeRealtimeTest.php`:

```php
    public function test_touch_bumps_global_version_so_cache_recomputes(): void
    {
        $user = \App\Modules\Auth\Models\User::factory()->create();
        $svc = app(BadgeService::class);

        $svc->for($user); // primes cache at version v
        $v1 = (int) \Illuminate\Support\Facades\Cache::get('badges.version', 1);

        BadgeService::touch();
        $v2 = (int) \Illuminate\Support\Facades\Cache::get('badges.version', 1);

        $this->assertSame($v1 + 1, $v2);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd api && ./vendor/bin/phpunit --filter test_touch_bumps_global_version_so_cache_recomputes`
Expected: FAIL — `BadgeService::touch()` undefined.

- [ ] **Step 3: Apply the fix**

Replace `for()` (lines 53-60) and add `touch()` + `version()`:

```php
    public function for(User $user): array
    {
        $ttl = (int) config('badges.cache_ttl', 30);
        $version = self::version();

        return Cache::remember(
            "badges.user.{$user->id}.v{$version}",
            $ttl,
            fn () => $this->compute($user),
        );
    }

    /** Current global cache version (defaults to 1). */
    public static function version(): int
    {
        return (int) Cache::get('badges.version', 1);
    }

    /**
     * Invalidate every user's cached badge map instantly by bumping the
     * global version. Called whenever badge-affecting data changes.
     */
    public static function touch(): void
    {
        if (Cache::has('badges.version')) {
            Cache::increment('badges.version');
        } else {
            Cache::forever('badges.version', 2); // 1 was the implicit default
        }
    }
```

> Remove the now-unused `private const TTL_SECONDS = 36;` line if still present.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd api && ./vendor/bin/phpunit --filter test_touch_bumps_global_version_so_cache_recomputes`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Dashboard/Services/BadgeService.php api/tests/Feature/Dashboard/BadgeRealtimeTest.php
git commit -m "feat(badges): version-keyed cache with touch() for instant global invalidation"
```

---

### Task B4: `BadgesChanged` broadcast event + `badges` channel

**Files:**
- Create: `api/app/Modules/Dashboard/Events/BadgesChanged.php`
- Modify: `api/routes/channels.php`
- Test: `api/tests/Feature/Dashboard/BadgeRealtimeTest.php`

- [ ] **Step 1: Write the failing test**

Append to `BadgeRealtimeTest.php`:

```php
    public function test_badges_changed_broadcasts_on_private_badges_channel(): void
    {
        $event = new \App\Modules\Dashboard\Events\BadgesChanged();
        $channels = $event->broadcastOn();
        $this->assertSame('private-badges', $channels[0]->name);
        $this->assertSame('BadgesChanged', $event->broadcastAs());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd api && ./vendor/bin/phpunit --filter test_badges_changed_broadcasts_on_private_badges_channel`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the event**

`api/app/Modules/Dashboard/Events/BadgesChanged.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Polish Task S2 (real-time) — fired whenever badge-affecting data changes.
 * Carries no payload; clients simply refetch their own permission-scoped
 * counts from GET /dashboards/badges (which is now cache-busted via the
 * version bump in BadgeService::touch()).
 */
class BadgesChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('badges')];
    }

    public function broadcastAs(): string
    {
        return 'BadgesChanged';
    }
}
```

- [ ] **Step 4: Register the channel**

In `api/routes/channels.php`, before the final closing of the file (after the `user.{userId}` channel), add:

```php
// Sidebar badge counts — any authenticated user may listen; the payload is
// empty and each client refetches only the keys it has permission to see.
Broadcast::channel('badges', fn (User $user): bool => true);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd api && ./vendor/bin/phpunit --filter test_badges_changed_broadcasts_on_private_badges_channel`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add api/app/Modules/Dashboard/Events/BadgesChanged.php api/routes/channels.php api/tests/Feature/Dashboard/BadgeRealtimeTest.php
git commit -m "feat(badges): BadgesChanged broadcast event on private badges channel"
```

---

### Task B5: Observer wiring — bump + broadcast on relevant model writes

**Files:**
- Create: `api/app/Modules/Dashboard/Observers/BadgeInvalidationObserver.php`
- Modify: a service provider that boots on every request (e.g. `api/app/Modules/Dashboard/Providers/*ServiceProvider.php` — locate the existing one; if the Dashboard module has no provider, register in `api/app/Providers/AppServiceProvider.php`)
- Test: `api/tests/Feature/Dashboard/BadgeRealtimeTest.php`

**Rationale:** Centralize invalidation: one observer registered against every model whose changes alter a badge count. On `created`/`updated`/`deleted` it calls `BadgeService::touch()` and dispatches `BadgesChanged`.

- [ ] **Step 1: Locate the boot provider (read-only)**

Run: `cd api && ls app/Modules/Dashboard/Providers/ 2>/dev/null; grep -rln "Observer\|::observe(" app/Providers app/Modules/*/Providers 2>/dev/null | head`
Expected: identify where model observers are registered in this codebase (mirror that pattern). Note the file path for Step 4.

- [ ] **Step 2: Write the failing test**

Append to `BadgeRealtimeTest.php`:

```php
    public function test_creating_a_badge_relevant_model_touches_version_and_fires_event(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Modules\Dashboard\Events\BadgesChanged::class]);
        $v1 = \App\Modules\Dashboard\Services\BadgeService::version();

        // ProfileUpdateRequest backs the `profile_requests` badge.
        \App\Modules\HR\Models\ProfileUpdateRequest::query()->create([
            'employee_id'     => \App\Modules\HR\Models\Employee::factory()->create()->id,
            'field_name'      => 'mobile_number',
            'requested_value' => '09170000000',
            'status'          => 'pending',
        ]);

        $this->assertSame($v1 + 1, \App\Modules\Dashboard\Services\BadgeService::version());
        \Illuminate\Support\Facades\Event::assertDispatched(\App\Modules\Dashboard\Events\BadgesChanged::class);
    }
```

> If `ProfileUpdateRequest`/`Employee` factories or fillable differ, adjust the create payload to satisfy required columns (read the model's `$fillable`). Keep the two assertions unchanged.

- [ ] **Step 3: Run test to verify it fails**

Run: `cd api && ./vendor/bin/phpunit --filter test_creating_a_badge_relevant_model_touches_version_and_fires_event`
Expected: FAIL — version unchanged, event not dispatched.

- [ ] **Step 4: Create the observer**

`api/app/Modules/Dashboard/Observers/BadgeInvalidationObserver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Observers;

use App\Modules\Dashboard\Events\BadgesChanged;
use App\Modules\Dashboard\Services\BadgeService;
use Illuminate\Database\Eloquent\Model;

/**
 * Polish Task S2 (real-time) — generic observer registered against every
 * model that backs a sidebar badge. Any create/update/delete bumps the badge
 * cache version (instant global invalidation) and broadcasts BadgesChanged so
 * connected clients refetch immediately.
 */
class BadgeInvalidationObserver
{
    public function created(Model $model): void { $this->invalidate(); }
    public function updated(Model $model): void { $this->invalidate(); }
    public function deleted(Model $model): void { $this->invalidate(); }

    private function invalidate(): void
    {
        BadgeService::touch();
        BadgesChanged::dispatch();
    }
}
```

- [ ] **Step 5: Register the observer on badge-backing models**

In the provider identified in Step 1 (its `boot()` method), register:

```php
use App\Modules\Dashboard\Observers\BadgeInvalidationObserver;

// ...inside boot():
$badgeModels = [
    \App\Common\Models\ApprovalRecord::class,
    \App\Modules\Purchasing\Models\PurchaseRequest::class,
    \App\Modules\Leave\Models\LeaveRequest::class,
    \App\Modules\Attendance\Models\OvertimeRequest::class,
    \App\Modules\Maintenance\Models\MaintenanceWorkOrder::class,
    \App\Modules\Quality\Models\NonConformanceReport::class,
    \App\Modules\HR\Models\ProfileUpdateRequest::class,
    \App\Modules\Production\Models\WorkOrder::class,
    \App\Modules\SupplyChain\Models\Delivery::class,
    \App\Modules\Payroll\Models\PayrollPeriod::class,
];
foreach ($badgeModels as $model) {
    $model::observe(BadgeInvalidationObserver::class);
}
```

> Verify each class path resolves (some were confirmed via BadgeService imports). Remove any class that does not exist in this codebase rather than leave a broken reference. `low_stock` (StockLevel) is intentionally excluded — stock writes are high-frequency; its 30s cache + 60s poll is sufficient and avoids broadcast storms.

- [ ] **Step 6: Run test to verify it passes**

Run: `cd api && ./vendor/bin/phpunit --filter test_creating_a_badge_relevant_model_touches_version_and_fires_event`
Expected: PASS.

- [ ] **Step 7: Run the full dashboard test group**

Run: `cd api && ./vendor/bin/phpunit tests/Feature/Dashboard`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add api/app/Modules/Dashboard/Observers/BadgeInvalidationObserver.php <provider-file> api/tests/Feature/Dashboard/BadgeRealtimeTest.php
git commit -m "feat(badges): observer bumps cache + broadcasts BadgesChanged on relevant writes"
```

---

### Task B6: SPA — subscribe `useBadges` to real-time invalidation

**Files:**
- Modify: `spa/src/hooks/useBadges.ts`
- Test: `spa/src/hooks/useBadges.test.tsx` (create)

- [ ] **Step 1: Write the failing test**

Create `spa/src/hooks/useBadges.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import React from 'react';

const listen = vi.fn().mockReturnThis();
const stopListening = vi.fn().mockReturnThis();
const leaveChannel = vi.fn();
vi.mock('@/lib/echo', () => ({
  echo: {
    private: vi.fn(() => ({ listen, stopListening })),
    leaveChannel,
  },
}));
vi.mock('@/api/badges', () => ({
  badgesApi: { get: vi.fn().mockResolvedValue({}) },
}));

import { useBadges } from './useBadges';
import { echo } from '@/lib/echo';

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient();
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useBadges real-time', () => {
  beforeEach(() => vi.clearAllMocks());

  it('subscribes to the private badges channel and listens for BadgesChanged', () => {
    renderHook(() => useBadges(), { wrapper });
    expect(echo.private).toHaveBeenCalledWith('badges');
    expect(listen).toHaveBeenCalledWith('.BadgesChanged', expect.any(Function));
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd spa && npx vitest run src/hooks/useBadges.test.tsx`
Expected: FAIL — `echo.private` not called (hook has no subscription yet).

- [ ] **Step 3: Apply the fix**

Replace `spa/src/hooks/useBadges.ts` with:

```ts
import { useEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { badgesApi, type BadgePayload } from '@/api/badges';
import { echo } from '@/lib/echo';

const POLL_MS = 60_000;

/**
 * Polish Task S2 — sidebar badge count system.
 *
 * Polls `/dashboards/badges` every 60s as a safety net AND subscribes to the
 * private `badges` channel: when the server broadcasts `BadgesChanged` (after
 * any badge-affecting write) we invalidate the query for an instant refresh.
 * The server cache is version-busted server-side so the refetch is always
 * fresh.
 */
export function useBadges(): {
  getBadge: (key: string | undefined) => BadgePayload | undefined;
} {
  const queryClient = useQueryClient();

  const { data } = useQuery({
    queryKey: ['sidebar', 'badges'],
    queryFn: () => badgesApi.get(),
    refetchInterval: POLL_MS,
    refetchIntervalInBackground: false,
    staleTime: 15_000,
  });

  useEffect(() => {
    const channel = echo.private('badges');
    channel.listen('.BadgesChanged', () => {
      queryClient.invalidateQueries({ queryKey: ['sidebar', 'badges'] });
    });
    return () => {
      channel.stopListening('.BadgesChanged');
      // Leave the channel so a remount re-subscribes cleanly.
      // echo.leave is safe even if already left.
      echo.leave('private-badges');
    };
  }, [queryClient]);

  return {
    getBadge: (key) => (key ? data?.[key] : undefined),
  };
}
```

> The leading dot in `.BadgesChanged` tells Echo this is a custom `broadcastAs` name (not a namespaced class). If `echo.leave` is unavailable in the test mock, the cleanup still no-ops; the test only asserts subscription. If `echo.leave` signature differs in this codebase, match the real Echo API (`echo.leaveChannel('private-badges')`).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd spa && npx vitest run src/hooks/useBadges.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add spa/src/hooks/useBadges.ts spa/src/hooks/useBadges.test.tsx
git commit -m "feat(badges): SPA subscribes to BadgesChanged for instant sidebar refresh"
```

---

## PART C — Sidebar: surface the new badges (S1/S2)

### Task C1: Wire `work_orders`, `payroll`, `deliveries` badge keys into nav items

**Files:**
- Modify: `spa/src/components/layout/Sidebar.tsx:87, 110, 134`

**Rationale:** New backend badges have no sidebar consumer yet. Attach `badgeKey` to the matching primary entries. (The badge only renders when count > 0 and the user has the gating permission — already handled by `useBadges`/`NavLink`.)

- [ ] **Step 1: Apply the edits**

In `SECTIONS`:

- Production → "Work orders" (line 87): add `badgeKey: 'work_orders'`:
```tsx
      { to: '/production/work-orders', label: 'Work orders',      icon: FileText,      feature: 'production', permission: 'production.work_orders.view', badgeKey: 'work_orders' },
```
- Supply Chain → "Deliveries" (line 110): add `badgeKey: 'deliveries'`:
```tsx
      { to: '/supply-chain/deliveries', label: 'Deliveries', icon: Truck, feature: 'supply_chain', permission: 'supply_chain.view', badgeKey: 'deliveries' },
```
- Human Resources → "Payroll" (line 134): add `badgeKey: 'payroll'`:
```tsx
      { to: '/payroll/hub',          label: 'Payroll',           icon: Wallet,    feature: 'payroll', permission: 'payroll.view', badgeKey: 'payroll' },
```

- [ ] **Step 2: Typecheck**

Run: `cd spa && npx tsc --noEmit`
Expected: clean.

- [ ] **Step 3: Manual sanity (optional)** — confirm a `work_orders` badge appears for a user with an overdue WO and `production.work_orders.view`.

- [ ] **Step 4: Commit**

```bash
git add spa/src/components/layout/Sidebar.tsx
git commit -m "feat(sidebar): surface work_orders, payroll, deliveries badges"
```

---

## PART D — Dashboard UX: time-range selector + KPI drill-downs

### Task D1: Plant Manager time-range selector (Today / Week / Month / Quarter)

**Files:**
- Modify: `api/app/Modules/Dashboard/Controllers/DashboardController.php` (`plantManager` passes `range`)
- Modify: `api/app/Modules/Dashboard/Services/RoleDashboardService.php` (`plantManager` + KPI helpers accept bounds)
- Modify: `spa/src/pages/dashboard/plant-manager.tsx` (selector + query key)
- Test: `api/tests/Feature/Dashboard/RoleDashboardServiceTest.php`

**Rationale:** Spec D2 calls for a "Today / Week / Month / Quarter" range selector; KPIs currently hardcode "week". Parameterize revenue + production KPIs by a date window; cache key includes the range.

- [ ] **Step 1: Write the failing test**

Append to `RoleDashboardServiceTest.php`:

```php
    public function test_plant_manager_respects_range_param_for_revenue_window(): void
    {
        DB::table('invoices')->insert([
            // Today
            ['invoice_number' => 'INV-1', 'status' => 'finalized', 'date' => now()->toDateString(),
             'total_amount' => 1000, 'balance' => 0, 'created_at' => now(), 'updated_at' => now()],
            // 20 days ago (in month, not in today/week)
            ['invoice_number' => 'INV-2', 'status' => 'finalized', 'date' => now()->subDays(20)->toDateString(),
             'total_amount' => 5000, 'balance' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $user = \App\Modules\Auth\Models\User::factory()->create();

        $today = $this->service()->plantManager($user, 'today');
        $month = $this->service()->plantManager($user, 'month');

        $revToday = collect($today['kpis'])->firstWhere('label', 'Revenue · Today')['value'];
        $revMonth = collect($month['kpis'])->firstWhere('label', 'Revenue · Month')['value'];

        $this->assertSame('1000.00', $revToday);
        $this->assertSame('6000.00', $revMonth);
    }
```

> The invoice `date` column / status values are used elsewhere in this service (`revenueWeek`, `topOverdueArCustomers`) so they are known-good. If `invoices` requires more non-null columns, add them to the inserts.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd api && ./vendor/bin/phpunit --filter test_plant_manager_respects_range_param_for_revenue_window`
Expected: FAIL — `plantManager()` takes only `$user`; labels are "· Week".

- [ ] **Step 3: Apply the backend change**

Add a range resolver to `RoleDashboardService` (place near `kpi()`):

```php
    /**
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon, 2: string}
     *         [start, end, humanLabel]
     */
    private function rangeBounds(string $range): array
    {
        return match ($range) {
            'today'   => [now()->startOfDay(),     now()->endOfDay(),     'Today'],
            'month'   => [now()->startOfMonth(),   now()->endOfMonth(),   'Month'],
            'quarter' => [now()->startOfQuarter(), now()->endOfQuarter(), 'Quarter'],
            default   => [now()->startOfWeek(),    now()->endOfWeek(),    'Week'], // 'week'
        };
    }

    private function revenueInRange(\Illuminate\Support\Carbon $start, \Illuminate\Support\Carbon $end): string
    {
        if (! Schema::hasTable('invoices')) return '0.00';
        $sum = (float) DB::table('invoices')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->sum('total_amount');
        return number_format($sum, 2, '.', '');
    }

    private function productionInRange(\Illuminate\Support\Carbon $start, \Illuminate\Support\Carbon $end): string
    {
        if (! Schema::hasTable('work_order_outputs')) return '0';
        return (string) (int) DB::table('work_order_outputs')
            ->whereBetween('recorded_at', [$start, $end])
            ->sum('good_count');
    }
```

Replace the `plantManager()` signature + KPI block (lines 30-49) with:

```php
    public function plantManager(User $user, string $range = 'week'): array
    {
        $range = in_array($range, ['today', 'week', 'month', 'quarter'], true) ? $range : 'week';

        return Cache::remember("dashboard:plant_manager:{$user->id}:{$range}", self::CACHE_TTL, function () use ($range) {
            [$start, $end, $label] = $this->rangeBounds($range);

            return [
                'kpis' => [
                    $this->kpi("Revenue · {$label}",    $this->revenueInRange($start, $end),    'PHP'),
                    $this->kpi("Production · {$label}", $this->productionInRange($start, $end), 'units'),
                    $this->kpi('OEE · Today',           $this->oeeToday(),                       'pct'),
                    $this->kpi('On-Time Delivery',      $this->otdRate(),                        'pct'),
                ],
                'panels' => [
                    'chain_stages'       => $this->chainStageBreakdown(),
                    'alerts'             => $this->alerts(),
                    'machine_util'       => $this->machineUtilization(),
                    'defect_pareto'      => $this->defectPareto(),
                    'financial_snapshot' => $this->plantFinancialSnapshot(),
                    'range'              => $range,
                ],
            ];
        });
    }
```

- [ ] **Step 4: Pass `range` through the controller**

In `api/app/Modules/Dashboard/Controllers/DashboardController.php`, change the `plantManager` action to read the query param:

```php
    public function plantManager(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'data' => $this->service->plantManager($request->user(), (string) $request->query('range', 'week')),
        ]);
    }
```

> Match the existing method's exact return/Resource style in that controller; if it uses a different response helper, mirror it but add the `range` argument.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd api && ./vendor/bin/phpunit --filter test_plant_manager_respects_range_param_for_revenue_window`
Expected: PASS.

- [ ] **Step 6: Add the selector in the SPA**

In `spa/src/pages/dashboard/plant-manager.tsx`:

Add a `useState` for the range and wire it into the query key + URL. At the top of the component (after `const { can } = usePermission();`):

```tsx
  const [range, setRange] = useState<'today' | 'week' | 'month' | 'quarter'>('week');

  const q = useQuery({
    queryKey: ['dashboard', 'plant-manager', range],
    queryFn: (): Promise<PlantManagerData> =>
      client
        .get<ApiSuccess<PlantManagerData>>('/dashboards/plant-manager', { params: { range } })
        .then((r) => r.data.data),
    refetchInterval: 60_000,
    placeholderData: (prev) => prev,
  });
```

Add `useState` to the React import. Add the selector to `PageHeader` via an `actions` slot (the page currently passes only title/subtitle — add `actions`):

```tsx
      <PageHeader
        title="Plant Manager Dashboard"
        subtitle="Production, quality, and financial overview."
        actions={
          <div className="inline-flex rounded-md border border-default overflow-hidden text-sm" role="group" aria-label="Time range">
            {(['today', 'week', 'month', 'quarter'] as const).map((r) => (
              <button
                key={r}
                type="button"
                onClick={() => setRange(r)}
                className={`px-3 py-1.5 capitalize transition-colors duration-fast ${
                  range === r ? 'bg-accent text-accent-fg' : 'bg-canvas text-secondary hover:bg-elevated'
                }`}
                aria-pressed={range === r}
              >
                {r}
              </button>
            ))}
          </div>
        }
      />
```

> Confirm `PageHeader` accepts an `actions` prop (it does per DESIGN-SYSTEM page-header pattern). If its prop name differs, match it.

- [ ] **Step 7: Typecheck**

Run: `cd spa && npx tsc --noEmit`
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add api/app/Modules/Dashboard/Services/RoleDashboardService.php api/app/Modules/Dashboard/Controllers/DashboardController.php api/tests/Feature/Dashboard/RoleDashboardServiceTest.php spa/src/pages/dashboard/plant-manager.tsx
git commit -m "feat(dashboard): Plant Manager time-range selector (today/week/month/quarter)"
```

---

### Task D2: KPI drill-down links for Purchasing, Warehouse, Quality

**Files:**
- Modify: `spa/src/lib/dashboardLinks.ts` (`kpiLink` switch)
- Modify: `spa/src/pages/dashboard/purchasing.tsx`, `warehouse.tsx`, `quality.tsx` (pass `linkTo={kpiLink(k.label)}` to `StatCard`)

**Rationale:** D6/D7/D8 KPI cards link to generic pages or nothing. Add stable label→URL entries and wire `linkTo` so KPIs drill into filtered lists (consistent with PPC/Plant which already use `kpiLink`).

- [ ] **Step 1: Extend `kpiLink`**

In `spa/src/lib/dashboardLinks.ts`, inside the `kpiLink` switch, add (use the exact labels emitted by `RoleDashboardService`):

```ts
    // ─── Purchasing (D6) ───────────────────────────────────
    case 'PRs Pending Action':
      return `/purchasing/purchase-requests?status=pending`;
    case 'Open POs':
      return `/purchasing/purchase-orders?status=sent`;
    case 'Overdue Deliveries':
      return `/purchasing/purchase-orders?overdue=1`;
    case 'Suppliers Due Review':
      return `/purchasing/suppliers?below_score=80`;

    // ─── Warehouse (D7) ────────────────────────────────────
    case 'Pending GRNs':
      return `/inventory/grn?status=pending`;
    case 'Issues Today':
      return `/inventory/material-issues?date=today`;
    case 'Low Stock Items':
      return `/inventory/items?below_reorder=1`;
    case 'Pending Transfers':
      return `/inventory/stock-movements?type=transfer&pending=1`;

    // ─── Quality (D8) ──────────────────────────────────────
    case 'Pending Inspections':
      return `/quality/inspections?status=in_progress`;
    case 'Pass Rate Today':
      return `/quality/inspections?date=today`;
    case 'Open NCRs':
      return `/quality/ncrs?status=open`;
    case 'CoCs Gen. MTD':
      return `/quality/certificates`;
```

> These URLs assume list pages read the query params as filters (the established `useUrlFilters` pattern). If a target route differs in this codebase, use the actual route; an unmapped label simply leaves the card non-clickable (safe).

- [ ] **Step 2: Wire `linkTo` in the three pages**

In each of `purchasing.tsx`, `warehouse.tsx`, `quality.tsx`, find where KPIs map to `<StatCard ... />` and add `linkTo={kpiLink(k.label)}` (import `kpiLink` from `@/lib/dashboardLinks` if not already imported). Example for the KPI map:

```tsx
{q.data.kpis.map((k) => (
  <StatCard key={k.label} label={k.label} value={k.value} helper={k.unit !== 'count' ? k.unit : undefined} linkTo={kpiLink(k.label)} />
))}
```

- [ ] **Step 3: Typecheck**

Run: `cd spa && npx tsc --noEmit`
Expected: clean.

- [ ] **Step 4: Commit**

```bash
git add spa/src/lib/dashboardLinks.ts spa/src/pages/dashboard/purchasing.tsx spa/src/pages/dashboard/warehouse.tsx spa/src/pages/dashboard/quality.tsx
git commit -m "feat(dashboard): KPI drill-down links for purchasing, warehouse, quality"
```

---

## PART E — Verification

### Task E1: Full backend + frontend verification

**Files:** none (verification only)

- [ ] **Step 1: Run the full dashboard test suite**

Run: `cd api && ./vendor/bin/phpunit tests/Feature/Dashboard`
Expected: all tests pass (RoleDashboardServiceTest, BadgeControllerTest, BadgeRealtimeTest).

- [ ] **Step 2: Run the broader API suite for regressions**

Run: `cd api && ./vendor/bin/phpunit`
Expected: no new failures introduced by the changes.

- [ ] **Step 3: Run SPA typecheck + unit tests**

Run: `cd spa && npx tsc --noEmit && npx vitest run`
Expected: clean typecheck; `useBadges.test.tsx` passes.

- [ ] **Step 4: Manual smoke (both themes)** — log in as a Plant Manager: confirm KPIs change with the range selector, alerts are itemized and clickable, machine availability differs per day. Log in as PPC: confirm gantt tooltips show WO numbers. Trigger a leave request as an employee and confirm the approver's sidebar `leaves`/`approvals` badge updates within ~1s (WebSocket) — verify in dark mode too.

- [ ] **Step 5: Commit any final fixes, then use superpowers:requesting-code-review before merge.**

---

## Self-Review Notes

- **Spec coverage:** S1 (consolidation) is already shipped and correct — this plan touches it only to surface new badges (C1). S2 badges: missing keys added (B2), severity config (B1), real-time (B3–B6) — matches the `BadgeCountChanged`/WebSocket spec. D1 router unchanged (works). D2 alerts itemized + time-range selector (A6, D1). D3 gantt/availability made real (A4, A5). D6 items_count real (A7). D7 zone util + low-stock fixed (A2, A3). D8 KPI links (D2). D4/D5 (HR/Finance) were already real and were not flagged — intentionally untouched.
- **Type consistency:** `GanttRow` split into availability (`date`,`label`) vs `ProductionGanttRow` (`day`,`wo_number`) to avoid the prior shared-shape mismatch; `AlertItem` shape changed identically in both `plant-manager.tsx` and `ppc.tsx` (`label`,`ref`,`ref_id`, no `count`); `alertRefLink` added once and imported in both. `BadgeService::touch()`/`version()` referenced consistently across B3–B6.
- **No placeholders:** every code step shows complete, runnable code. Where a model/enum spelling must be confirmed against the live codebase (PayrollPeriod status, provider location, factory fillable), an explicit read-only verification step precedes the edit and says exactly what to substitute.
