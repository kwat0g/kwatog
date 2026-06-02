# OGAMI ERP — Full Audit Remediation & Enhancement Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make OGAMI ERP correct, deployable, secure, and tested — fix every CRITICAL/HIGH finding from the 2026-06-01 system audit and then pay down the structural debt (god-service, monolith router, scope reconciliation), in six independently-shippable phases.

**Architecture:** Laravel 11 modular monolith (PostgreSQL 16 in prod, SQLite `:memory:` in tests) + React 18/TS/Vite SPA, Reverb WebSockets, Docker Compose. Each phase below is a self-contained plan that leaves the system in a working, testable state.

**Tech Stack:** PHP 8.3, Laravel 11, PostgreSQL 16, Redis, Reverb, PHPUnit 11; React 18, TypeScript, TanStack Query v5, Vitest.

---

## EXECUTION GROUND RULES (read once, apply to every task)

1. **Edit-only — DO NOT `git commit` or `git add`.** The repo has ~342 intentionally-uncommitted files; your edits join that working set. The user commits when ready. Ignore any `git commit` step you see elsewhere.
2. **All tests run in Docker** (host lacks `pdo_sqlite`; the `ogami-api`/`ogami-db` containers are up):
   - Backend: `docker compose exec -T api php artisan test --filter <name>` (from repo root `/home/kwat0g/Desktop/kwatog`).
   - SPA test: `docker compose exec -T spa npx vitest run <path>` · typecheck: `docker compose exec -T spa npx tsc --noEmit`.
3. **Cross-DB rule (critical):** tests use **SQLite**, production uses **PostgreSQL**. NEVER write raw SQL that only one engine supports (`DATE_ADD`, `MONTH()`, `DAY()`, `IFNULL`, …). Prefer Laravel's portable query builder (`whereMonth`, `whereDay`, `whereBetween` with PHP-computed bounds). If raw SQL is unavoidable, verify it on BOTH: `docker compose exec -T db psql -U ogami -d ogami -c "<sql>"`.
4. `phpunit.xml` already sets `BROADCAST_CONNECTION=null` (so model-observer broadcasts don't error in tests). Keep it.
5. **Never edit a migration that has already run to satisfy a test** — fix the test to match the real schema instead. The one exception is the duplicate-number migrations in Task P0.2 (renaming files, not altering shipped schema).
6. **Known pre-existing baseline issue, do not be alarmed:** `spa/src/pages/crm/sales-orders/create.tsx` has 3 `tsc` errors (`so_number`/`id` on a union type). Phase P5 Task P5.6 fixes them; until then, "typecheck clean" means "no NEW errors beyond those 3".

---

## PHASE INDEX

| Phase | Theme | Tasks | Outcome |
|---|---|---|---|
| **P0** | Critical Stabilization | P0.1–P0.6 | App boots, routes cache, migrations deterministic, HR dashboard works on Postgres, uploaded docs not publicly exposed, no runtime crashes |
| **P1** | Security Hardening | P1.1–P1.6 | No raw-ID leaks, upload MIME locked, route permission gaps closed, dev-only ID bypass restricted, secrets sanitized |
| **P2** | Test Foundation | P2.1–P2.11 | Model factories + tests pinning all money-critical logic |
| **P3** | Correctness & Concurrency | P3.1–P3.8 | Race conditions, duplicate events, metric bugs fixed |
| **P4** | Structural Refactors | P4.1–P4.6 | God-service split, router split, dashboard API unified, fat controllers thinned, shared helpers |
| **P5** | Scope Reconciliation & Frontend Polish | P5.1–P5.7 | CLAUDE.md matches reality, tsc clean, dead code removed, 5-state + a11y gaps closed |

Execute phases in order. Within a phase, tasks are mostly independent and can be parallelized across subagents.

---

# PHASE P0 — CRITICAL STABILIZATION

*These fix things that are broken or exposed right now. Highest priority.*

---

### Task P0.1: Fix `route:list`/`route:cache` crash (missing controller import)

**Root cause:** `Payroll/routes.php` uses the bare class name `DisbursementProofController::class` (resolves to root namespace `\DisbursementProofController`) without a `use` import. `route:list` and `route:cache` then crash with `ReflectionException: Class "DisbursementProofController" does not exist`. This blocks production route caching.

**Files:**
- Modify: `api/app/Modules/Payroll/routes.php` (top-of-file `use` block + lines ~51-56)

- [ ] **Step 1: Reproduce the failure**

Run: `docker compose exec -T api php artisan route:list 2>&1 | grep -i "DisbursementProof"`
Expected: `Class "DisbursementProofController" does not exist`.

- [ ] **Step 2: Add the import**

Open `api/app/Modules/Payroll/routes.php`. In the top `use` block (alongside the other controller imports), add:

```php
use App\Modules\Payroll\Controllers\DisbursementProofController;
```

Confirm the route entries (around lines 53-56) reference `DisbursementProofController::class` (now resolved via the import). Do NOT change the route paths.

- [ ] **Step 3: Verify the fix**

Run: `docker compose exec -T api php artisan route:list >/dev/null 2>&1 && echo "ROUTES OK" || echo "STILL BROKEN"`
Expected: `ROUTES OK`.

- [ ] **Step 4: Verify route caching works (prod readiness)**

Run: `docker compose exec -T api php artisan route:cache 2>&1 | tail -3 && docker compose exec -T api php artisan route:clear`
Expected: "Routes cached successfully." then clear.

---

### Task P0.2: Fix duplicate migration sequence numbers

**Root cause:** Two pairs of migrations share a numeric prefix, making `migrate:fresh` ordering non-deterministic:
- `0161_create_supplier_portal_users_table.php` **and** `0161_create_transfer_orders_table.php`
- `0162_create_budgeting_tables.php` **and** `0162_create_customer_portal_users_table.php`

**Files:**
- Rename: `api/database/migrations/0161_create_transfer_orders_table.php` → next free number
- Rename: `api/database/migrations/0162_create_customer_portal_users_table.php` → next free number

- [ ] **Step 1: Find the highest used migration number**

Run: `ls api/database/migrations/ | grep -oE '^[0-9]+' | sort -n | tail -3`
Note the highest N (e.g. `0166`). The two new numbers will be `N+1` and `N+2`.

- [ ] **Step 2: Verify dependency ordering before renaming**

`transfer_orders` and `customer_portal_users` must still run AFTER the tables they FK-reference. Check each file's `constrained('...')` targets and confirm those tables' migrations have LOWER numbers than the new number you pick. (They reference `warehouses`/`warehouse_zones`/`items` and `customers` respectively — all early migrations, so any high number is safe.)

- [ ] **Step 3: Rename the two files**

```bash
cd api/database/migrations
git mv 0161_create_transfer_orders_table.php 0167_create_transfer_orders_table.php
git mv 0162_create_customer_portal_users_table.php 0168_create_customer_portal_users_table.php
```
(Use the actual `N+1`/`N+2` from Step 1 if different from 0167/0168. `git mv` keeps them tracked; this is a filename change, not a schema change — allowed.)

- [ ] **Step 4: Verify a clean migrate works end-to-end**

Run: `docker compose exec -T api php artisan migrate:fresh --seed 2>&1 | tail -15`
Expected: all migrations run in order, no "table already exists" / FK-ordering errors, seeders complete.

- [ ] **Step 5: Confirm no other duplicate numbers exist**

Run: `ls api/database/migrations/ | grep -oE '^[0-9]+' | sort | uniq -d`
Expected: empty output (no duplicates).

---

### Task P0.3: Fix PostgreSQL-incompatible SQL in HR dashboard (portable rewrite)

**Root cause:** `RoleDashboardService` uses MySQL-only functions that throw on PostgreSQL (verified against the live DB): `DATE_ADD(date_hired, INTERVAL 6 MONTH)` (line ~281, syntax error), `MONTH(birth_date)` (line ~373, "function month does not exist"), `DAY(birth_date)` (line ~374). The HR Officer dashboard (D4) 500s in production. Fix with **portable** query-builder methods that work on both SQLite (tests) and Postgres (prod).

**Files:**
- Modify: `api/app/Modules/Dashboard/Services/RoleDashboardService.php` (`hrProbationAlerts()` ~264-297, `hrCalendarEvents()` ~344-385)
- Test: `api/tests/Feature/Dashboard/RoleDashboardServiceTest.php` (APPEND)

- [ ] **Step 1: Write failing tests**

Append to `RoleDashboardServiceTest` (uses the existing `service()` helper + department/position/employee bootstrap pattern already in this file from earlier tasks):

```php
    public function test_probation_alerts_finds_employee_whose_6mo_ends_within_30_days(): void
    {
        $deptId = DB::table('departments')->insertGetId(['name' => 'Prod', 'code' => 'PRD', 'created_at' => now(), 'updated_at' => now()]);
        $posId  = DB::table('positions')->insertGetId(['title' => 'Op', 'department_id' => $deptId, 'created_at' => now(), 'updated_at' => now()]);
        // Hired ~5.5 months ago → 6-month mark falls within the next 30 days.
        DB::table('employees')->insert([
            'employee_no' => 'OGM-P-1', 'first_name' => 'Ana', 'last_name' => 'Reyes',
            'birth_date' => '1995-05-10', 'gender' => 'female', 'civil_status' => 'single',
            'department_id' => $deptId, 'position_id' => $posId, 'employment_type' => 'probationary',
            'pay_type' => 'monthly', 'date_hired' => now()->subMonths(6)->addDays(15)->toDateString(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ref = new \ReflectionClass($this->service());
        $m = $ref->getMethod('hrProbationAlerts'); $m->setAccessible(true);
        $rows = $m->invoke($this->service());

        $this->assertCount(1, $rows);
        $this->assertSame('OGM-P-1', $rows[0]['employee_no']);
    }

    public function test_calendar_events_lists_birthdays_in_current_month_sorted_by_day(): void
    {
        $deptId = DB::table('departments')->insertGetId(['name' => 'Prod', 'code' => 'PRD', 'created_at' => now(), 'updated_at' => now()]);
        $posId  = DB::table('positions')->insertGetId(['title' => 'Op', 'department_id' => $deptId, 'created_at' => now(), 'updated_at' => now()]);
        $month = (int) now()->format('m');
        $mk = function (string $no, int $day) use ($deptId, $posId, $month) {
            DB::table('employees')->insert([
                'employee_no' => $no, 'first_name' => $no, 'last_name' => 'X',
                'birth_date' => sprintf('1990-%02d-%02d', $month, $day),
                'gender' => 'male', 'civil_status' => 'single', 'department_id' => $deptId,
                'position_id' => $posId, 'employment_type' => 'regular', 'pay_type' => 'monthly',
                'date_hired' => '2024-01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $mk('B-20', 20); $mk('B-05', 5);

        $ref = new \ReflectionClass($this->service());
        $m = $ref->getMethod('hrCalendarEvents'); $m->setAccessible(true);
        $events = $m->invoke($this->service());

        $this->assertSame(2, $events['birthdays_count']);
        // Sorted ascending by day-of-month.
        $this->assertSame('B-05', $events['birthdays'][0]['name']);
        $this->assertSame('B-20', $events['birthdays'][1]['name']);
    }
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `docker compose exec -T api php artisan test --filter "test_probation_alerts_finds_employee|test_calendar_events_lists_birthdays"`
Expected: FAIL — on SQLite `DATE_ADD`/`MONTH` produce wrong results or errors; the count/sort assertions fail.

- [ ] **Step 3: Rewrite `hrProbationAlerts()` with a portable date-window**

The condition "`date_hired + 6 months` is between today and today+30d" is algebraically equivalent to "`date_hired` is between `today-6mo` and `today+30d-6mo`". Replace the `->whereRaw('DATE_ADD(...) BETWEEN ? AND ?', [...])` line with:

```php
            ->whereBetween('employees.date_hired', [
                now()->subMonths(6)->toDateString(),
                now()->addDays(30)->subMonths(6)->toDateString(),
            ])
```

Leave the rest of the method (select, the PHP `probation_end` computation via `Carbon::parse(...)->addMonths(6)`, ordering, limit) unchanged.

- [ ] **Step 4: Rewrite `hrCalendarEvents()` birthday query portably**

Replace `->whereRaw('MONTH(birth_date) = ?', [$monthNum])` with Laravel's portable `->whereMonth('birth_date', $monthNum)`, and remove the `->orderByRaw('DAY(birth_date)')` line. After the `->get([...])`, sort the mapped birthdays by day-of-month in PHP before returning. Concretely, the birthdays block becomes:

```php
        $birthdays = [];
        if (Schema::hasTable('employees')) {
            $monthNum = (int) now()->format('n');
            $birthdays = DB::table('employees')
                ->where('status', 'active')
                ->whereMonth('birth_date', $monthNum)
                ->limit(10)
                ->get(['id', 'first_name', 'last_name', 'birth_date'])
                ->sortBy(fn ($e) => (int) Carbon::parse((string) $e->birth_date)->format('j'))
                ->values()
                ->map(fn ($e) => [
                    'id'   => app('hashids')->encode((int) $e->id),
                    'name' => trim(($e->first_name ?? '').' '.($e->last_name ?? '')),
                    'date' => $e->birth_date,
                ])
                ->all();
        }
```

- [ ] **Step 5: Run, confirm PASS**

Run: `docker compose exec -T api php artisan test --filter "test_probation_alerts_finds_employee|test_calendar_events_lists_birthdays"`
Expected: PASS.

- [ ] **Step 6: Verify on real Postgres (no MySQL-isms remain)**

Run: `grep -nE "DATE_ADD|DATE_SUB|\bMONTH\(|\bDAY\(|\bYEAR\(|IFNULL|GROUP_CONCAT|CURDATE" api/app/Modules/Dashboard/Services/RoleDashboardService.php`
Expected: no matches.
Then: `grep -rnE "DATE_ADD|\bMONTH\(|\bDAY\(|IFNULL|GROUP_CONCAT|CURDATE|DATE_SUB|DATEDIFF" api/app` — expected: no matches anywhere in `app/`.

---

### Task P0.4: Stop serving uploaded business documents from the public disk

**Root cause:** Delivery proofs, shipment documents, and receipt photos are stored on the `public` disk, and prod Nginx (`docker/nginx/prod.conf:102`) serves `/storage/` as an unauthenticated static alias. Anyone can fetch `/storage/deliveries/<id>/...`. The correct pattern already exists in `DisbursementProofController` (stores on `local`, streams via a permission-checked controller action).

**Files:**
- Modify: `api/app/Modules/SupplyChain/Controllers/DeliveryProofController.php:47` (+ its download/show action)
- Modify: `api/app/Modules/SupplyChain/Services/ShipmentService.php:127`
- Modify: `api/app/Modules/SupplyChain/Services/DeliveryService.php:217`
- Reference (do not change): `api/app/Modules/Payroll/Controllers/DisbursementProofController.php` (the correct streaming pattern, ~line 93)

- [ ] **Step 1: Read the reference pattern**

Read `api/app/Modules/Payroll/Controllers/DisbursementProofController.php`. Note: it stores with `$file->store($dir, 'local')` and serves via a controller action that checks permission then returns `Storage::disk('local')->download($path)` (or a streamed response). Mirror this exactly.

- [ ] **Step 2: Switch the three upload calls to the `local` disk**

In each of the three files, change the storage disk argument from `'public'` to `'local'`:
- `DeliveryProofController.php:47` — `$file->store($dir, 'public')` → `$file->store($dir, 'local')`
- `ShipmentService.php:127` — `$file->store($folder, 'public')` → `$file->store($folder, 'local')`
- `DeliveryService.php:217` — `$file->store("deliveries/{$d->id}", 'public')` → `$file->store("deliveries/{$d->id}", 'local')`

- [ ] **Step 3: Add/confirm a permission-checked streaming action for each document type**

For delivery proofs and shipment documents, ensure there is a controller action (route gated with the relevant `permission:` middleware) that resolves the model, checks `$request->user()->can(...)`, and returns `Storage::disk('local')->download($model->file_path)`. If `DeliveryProofController` already has a `show`/`download` action serving from `public`, point it at `'local'`. If shipment docs lack a streaming action, add one mirroring `DisbursementProofController` and register a gated route in `SupplyChain/routes.php`.

- [ ] **Step 4: Write a test proving access control + local storage**

Create/append `api/tests/Feature/SupplyChain/DocumentAccessTest.php` (use `Storage::fake('local')` and `RefreshDatabase`):

```php
    public function test_delivery_proof_is_stored_on_local_disk_not_public(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::fake('public');
        // ... create a delivery + authenticated user with supply_chain.view, upload a proof ...
        // Assert the file exists on 'local' and NOT on 'public':
        \Illuminate\Support\Facades\Storage::disk('public')->assertDirectoryEmpty('/');
    }
```
(Fill the delivery/user setup using the FK-bootstrap pattern from existing tests. The pinned assertions: file present on `local`, absent on `public`; an unauthenticated download request returns 401/403.)

- [ ] **Step 5: Run the test, confirm PASS**

Run: `docker compose exec -T api php artisan test --filter DocumentAccessTest`
Expected: PASS.

- [ ] **Step 6: Verify no upload handler still targets `public`**

Run: `grep -rn "store(.*'public'\|storeAs(.*'public'\|disk('public')" api/app`
Expected: no upload writes to `public` for sensitive documents (logos/static assets, if any, are acceptable — judge per case and note them).

---

### Task P0.5: Fix `hashId()` runtime crash in Budget resources

**Root cause:** Several Accounting/Budget resources call `$this->hashId()` — a method that exists on neither `HasHashId` (which exposes `hash_id` attribute / `getHashIdAttribute()`) nor `JsonResource`. Any request hitting these resources throws `BadMethodCallException`.

**Files:**
- Modify: every resource calling `$this->hashId()` (grep to find them)

- [ ] **Step 1: Find all offenders**

Run: `grep -rn "->hashId()" api/app`
Expected: a list (BudgetResource, BudgetRevisionResource, BudgetTransferResource, etc.).

- [ ] **Step 2: Replace `$this->hashId()` with `$this->hash_id`**

For each match, replace `$this->hashId()` → `$this->hash_id`. For nested relations using `$relation->hashId()`, replace with `$relation->hash_id`. (The `HasHashId` trait exposes `hash_id` as an attribute accessor.)

- [ ] **Step 3: Verify none remain**

Run: `grep -rn "->hashId()" api/app`
Expected: empty.

- [ ] **Step 4: Smoke-test a budget endpoint**

Run: `docker compose exec -T api php artisan test --filter "Budget"` (if a Budget test exists) OR add a minimal feature test that GETs the budget index as an authorized user and asserts 200 + a `hash_id`-shaped `id` (string, non-numeric) in the payload.
Expected: PASS / 200 with string IDs.

---

### Task P0.6: Phase-P0 regression gate

**Files:** none (verification only).

- [ ] **Step 1: Full backend suite green**

Run: `docker compose exec -T api php artisan test 2>&1 | tail -6`
Expected: all pass (baseline was 175; this phase adds tests — expect ≥180, 0 failures).

- [ ] **Step 2: Routes + migrate health**

Run: `docker compose exec -T api php artisan route:list >/dev/null 2>&1 && echo OK` and `docker compose exec -T api php artisan migrate:fresh --seed 2>&1 | tail -3`
Expected: `OK` + clean migrate.

---

# PHASE P1 — SECURITY HARDENING

---

### Task P1.1: Stop leaking raw integer IDs in API Resources

**Root cause:** 11+ resources emit raw integer `id`/FKs instead of `hash_id`, defeating ID obfuscation.

**Files (each under `api/app/Modules/`):**
- `Inventory/Resources/StockCountSessionResource.php` (lines 16,21,26)
- `Inventory/Resources/TransferOrderResource.php` (16,19,24,29)
- `Inventory/Resources/WarehouseMapResource.php` (15,21,28,37)
- `Inventory/Resources/StockCountItemResource.php` (16,19,24 + `session_id`)
- `Inventory/Resources/GrnItemResource.php` (15)
- `Inventory/Resources/MaterialIssueSlipItemResource.php` (15)
- `Purchasing/Resources/PurchaseRequestItemResource.php` (15)
- `Purchasing/Resources/PurchaseOrderItemResource.php` (15,16 incl. `purchase_request_item_id`)
- `Purchasing/Resources/PurchaseRequestResource.php` (42, `template->id`)
- `Accounting/Resources/InvoiceItemResource.php` (15)
- `Accounting/Resources/BillItemResource.php` (15)

- [ ] **Step 1: Replace root + nested ids**

In each file: `'id' => $this->id` (or `(int) $this->id`) → `'id' => $this->hash_id`. For nested relation ids `$rel->id` → `$rel->hash_id`. For raw FK integers (`session_id`, `purchase_request_item_id`), encode: `$this->session_id ? app('hashids')->encode($this->session_id) : null`.

- [ ] **Step 2: Sweep for any remaining raw-id leaks**

Run: `grep -rn "'id' => \$this->id\b\|=> (int) \$this->id\b" api/app/Modules/*/Resources`
Expected: empty (every resource returns `hash_id`).

- [ ] **Step 3: Write a guard test for one representative resource**

Add a feature test that fetches a GRN (with items) as an authorized user and asserts each item `id` is a non-numeric string (hash). Run in Docker; expect PASS.

---

### Task P1.2: Encode `model_id` in AuditLogResource

**Files:** `api/app/Modules/Admin/Resources/AuditLogResource.php:14`

- [ ] **Step 1:** Replace `'model_id' => $this->model_id,` with:
```php
            'model_id' => $this->model_id ? app('hashids')->encode((int) $this->model_id) : null,
```
- [ ] **Step 2:** Run `docker compose exec -T api php artisan test --filter AuditLog` (or the Admin suite); expect PASS. If a resource test asserts integer `model_id`, update that assertion to expect a string.

---

### Task P1.3: Lock down file-upload MIME types

**Files:**
- `api/app/Modules/SupplyChain/Controllers/ShipmentController.php:70`
- `api/app/Modules/SupplyChain/Controllers/DeliveryController.php:51`

- [ ] **Step 1:** ShipmentController — change `'file' => ['required', 'file', 'max:20480']` to `'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx,csv', 'max:20480']`.
- [ ] **Step 2:** DeliveryController — change `'file' => ['required', 'image', 'max:10240']` to `'file' => ['required', 'mimes:jpg,jpeg,png,webp', 'max:10240']` (drops SVG/BMP/GIF → no inline-SVG XSS).
- [ ] **Step 3:** Add/extend a feature test uploading a `.svg` (DeliveryController) and a `.php` (ShipmentController) and asserting `422`. Run in Docker; expect PASS.

---

### Task P1.4: Add permission middleware to the document-vault `show` route

**Files:** `api/app/Modules/Admin/routes.php:107`

- [ ] **Step 1:** Append `->middleware('permission:admin.audit_logs.view')` (matching the sibling `view`/`download` routes) to the `Route::get('{document}', [DocumentController::class, 'show'])` line.
- [ ] **Step 2:** Add a test: a user WITHOUT that permission GETs the document show route → `403`. Run in Docker; expect PASS.

---

### Task P1.5: Restrict the raw-integer route-binding bypass to the `testing` env only

**Root cause:** `HasHashId::resolveRouteBinding` accepts bare integer IDs in ALL non-production envs (`local`, `staging`), so staging pentests miss enumeration issues.

**Files:** `api/app/Common/Traits/HasHashId.php:32-33`

- [ ] **Step 1:** Change `if (! app()->environment('production') && ctype_digit((string) $value))` to `if (app()->environment('testing') && ctype_digit((string) $value))`.
- [ ] **Step 2:** Run `docker compose exec -T api php artisan test --filter HasHashId`; expect PASS (tests run in `testing` env so the bypass still works there).

---

### Task P1.6: Sanitize `.env.example` + add prod debug guard

**Files:** `api/.env.example`; `api/app/Providers/AppServiceProvider.php` (`boot()`)

- [ ] **Step 1:** In `.env.example`, replace real-looking dev values (`DB_PASSWORD=ogami_dev_pw`, `MEILISEARCH_KEY=...`, `REVERB_APP_KEY/SECRET=...`) with `CHANGEME` placeholders.
- [ ] **Step 2:** In `AppServiceProvider::boot()`, add a guard so a misconfigured prod aborts loudly:
```php
        if ($this->app->isProduction() && config('app.debug')) {
            throw new \RuntimeException('APP_DEBUG must be false in production.');
        }
```
- [ ] **Step 3:** Run the full suite (`docker compose exec -T api php artisan test 2>&1 | tail -4`); expect no regressions (tests run in `testing`, not `production`).

---

# PHASE P2 — TEST FOUNDATION

*The single biggest testability blocker is that only `UserFactory` exists. Build factories first, then pin money-critical logic. Each test task: write failing test → run (fail) → it should pass against EXISTING correct code, or expose a bug to fix.*

> Note: these tests pin behavior of code that already exists. Where a test reveals a real bug, fix the code (cite it) — otherwise the test simply locks in current correct behavior.

---

### Task P2.1: Create core model factories

**Files (create under `api/database/factories/`):** `EmployeeFactory.php`, `DepartmentFactory.php`, `PositionFactory.php`, `ItemFactory.php`, `ItemCategoryFactory.php`, `WarehouseFactory.php`, `WarehouseZoneFactory.php`, `WarehouseLocationFactory.php`, `StockLevelFactory.php`.

- [ ] **Step 1:** For each model, confirm the module namespace and required (non-null, no-default) columns from its migration (the audit + earlier work documented most). Each model already uses `HasFactory`; Laravel resolves `Database\Factories\<Model>Factory` — for module-namespaced models add a `newFactory()` method OR place the factory and use the `#[UseFactory]` attribute / `protected static function newFactory()`. Simplest robust approach used in this codebase: add to each model:
```php
    protected static function newFactory() { return \Database\Factories\EmployeeFactory::new(); }
```
- [ ] **Step 2:** Write each factory with sensible defaults satisfying NOT-NULL columns. Example `EmployeeFactory.php`:
```php
<?php
declare(strict_types=1);
namespace Database\Factories;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    protected $model = Employee::class;
    public function definition(): array
    {
        return [
            'employee_no'    => 'OGM-'.fake()->unique()->numerify('####'),
            'first_name'     => fake()->firstName(),
            'last_name'      => fake()->lastName(),
            'birth_date'     => fake()->dateTimeBetween('-50 years', '-20 years')->format('Y-m-d'),
            'gender'         => fake()->randomElement(['male', 'female']),
            'civil_status'   => 'single',
            'department_id'  => \App\Modules\HR\Models\Department::factory(),
            'position_id'    => \App\Modules\HR\Models\Position::factory(),
            'employment_type'=> 'regular',
            'pay_type'       => 'monthly',
            'basic_monthly_salary' => 20000,
            'date_hired'     => fake()->dateTimeBetween('-3 years', '-1 month')->format('Y-m-d'),
            'status'         => 'active',
        ];
    }
}
```
Repeat the pattern for the other factories (Department: `name`+`code`; Position: `title`+`department_id`; Item: code/name/category_id/item_type/unit_of_measure/standard_cost/reorder_method/reorder_point/…; WarehouseLocation: zone_id/code/is_active/current_quantity/is_blocked; StockLevel: item_id/location_id/quantity/reserved_quantity/weighted_avg_cost/lock_version).
- [ ] **Step 3:** Smoke test: add `api/tests/Feature/FactorySmokeTest.php` that creates one of each via factory and asserts it persists. Run `docker compose exec -T api php artisan test --filter FactorySmokeTest`; expect PASS.

---

### Task P2.2: Pin `StockMovementService::move()` weighted-average-cost

**Files:** `api/app/Modules/Inventory/Services/StockMovementService.php:39` · Test: `api/tests/Feature/Inventory/WeightedAvgCostTest.php` (create)

- [ ] **Step 1: Write failing/locking tests** covering: (a) first receipt into empty stock sets WAC = unit cost (no divide-by-zero); (b) second receipt blends correctly `((oldQty*oldWAC + recvQty*unitCost)/newQty)`; (c) an issue does NOT change WAC; (d) `InsufficientStockException` thrown when available < requested; (e) `lock_version` increments on write. Use the factories from P2.1. Pin exact decimal expectations (e.g. receive 100@10 then 100@12 → WAC `11.0000`).
- [ ] **Step 2:** Run `--filter WeightedAvgCostTest` → expect FAIL initially only if a bug exists; otherwise PASS (locks behavior). If divide-by-zero or precision bug surfaces, fix in `StockMovementService` and re-run.

---

### Task P2.3: Pin `MrpEngineService` net-requirement netting

**Files:** `api/app/Modules/MRP/Services/MrpEngineService.php:65` · Test: `api/tests/Feature/MRP/MrpNettingTest.php` (create)

- [ ] **Step 1:** Tests: (a) sufficient on-hand → no shortage/PR; (b) shortage → PR created with correct net qty `max(0, gross - onHand + reserved - inTransit)`; (c) in-transit deducted; (d) reserved adds to demand; (e) multi-line SO sums correctly. Use factories.
- [ ] **Step 2:** Run `--filter MrpNettingTest`; fix any netting bug found; expect PASS.

---

### Task P2.4: Pin `ThreeWayMatchService::matchForPo()` variance logic

**Files:** `api/app/Modules/Purchasing/Services/ThreeWayMatchService.php:17` · Test: `api/tests/Feature/Purchasing/ThreeWayMatchTest.php` (create)

- [ ] **Step 1:** Tests: exact match → `matched`; within-tolerance qty/price → `has_variances`; above tolerance → `blocked`; both out → `both`; tolerance pulled from `SettingsService` (set a config/setting and assert boundary `>` vs `>=`).
- [ ] **Step 2:** Run `--filter ThreeWayMatchTest`; fix boundary/percentage-direction bugs if found; expect PASS.

---

### Task P2.5: Pin `InvoiceService::recordCollection()` + `aging()`

**Files:** `api/app/Modules/Accounting/Services/InvoiceService.php:228` · Test: `api/tests/Feature/Accounting/InvoiceCollectionTest.php` (create)

- [ ] **Step 1:** Tests: full collection → status `paid`, balance 0, posts balanced JE (Dr Cash / Cr AR); partial → `partial`; overpayment rejected; second collection on paid invoice rejected; `aging()` buckets by days-past-due correctly.
- [ ] **Step 2:** Run `--filter InvoiceCollectionTest`; fix if needed; expect PASS.

---

### Task P2.6: Pin `ApprovalService` approve/reject lifecycle

**Files:** `api/app/Common/Services/ApprovalService.php:51` · Test: `api/tests/Unit/ApprovalServiceTest.php` (APPEND — file exists)

- [ ] **Step 1:** Add cases: wrong-role `approve()` rejected; multi-step — step-1 approve does NOT flip `isFullyApproved()`; final step flips it; `reject()` cancels downstream steps; `userMayActFor()` permission check.
- [ ] **Step 2:** Run `--filter ApprovalServiceTest`; fix authorization bugs if found; expect PASS.

---

### Task P2.7: Pin `PayrollCalculatorService` OT + night-diff + adjustments

**Files:** `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:77,421` · Test: `api/tests/Feature/Payroll/PayrollCalculatorServiceTest.php` (APPEND)

- [ ] **Step 1:** Add cases: OT + night-diff stacking same day (both pay types); daily-rate employee on a regular holiday (200%); `applyApprovedAdjustments()` adds/subtracts correctly and reverses on recompute. Use factories + the existing `makeEmployee()` helper.
- [ ] **Step 2:** Run `--filter PayrollCalculatorServiceTest`; fix if needed; expect PASS.

---

### Task P2.8: Pin `ThirteenthMonthService::computeAndPay()`

**Files:** `api/app/Modules/Payroll/Services/ThirteenthMonthService.php:90` · Test: `api/tests/Feature/Payroll/ThirteenthMonthTest.php` (create)

- [ ] **Step 1:** Tests: full-year employee `accrued_amount = sum(basic)/12`; partial-year proportional; `is_paid` set after run; re-running same year doesn't double-pay.
- [ ] **Step 2:** Run `--filter ThirteenthMonthTest`; fix if needed; expect PASS.

---

### Task P2.9: Pin `FinalPayService::compute()`

**Files:** `api/app/Modules/HR/Services/FinalPayService.php:34` · Test: `api/tests/Feature/HR/FinalPayTest.php` (create)

- [ ] **Step 1:** Tests: pro-rated salary = (days worked/period days)×monthly; unused VL converts at daily rate; open loan deducted; negative total clamp behavior defined+asserted; `postJournalEntry()` balanced.
- [ ] **Step 2:** Run `--filter FinalPayTest`; fix if needed; expect PASS.

---

### Task P2.10: Pin `InspectionService::complete()` → NCR auto-open

**Files:** `api/app/Modules/Quality/Services/InspectionService.php:265`, `NcrService.php:119` · Test: `api/tests/Feature/Quality/InspectionNcrTest.php` (create)

- [ ] **Step 1:** Tests: failed outgoing inspection creates a linked NCR (correct `source_type`/`source_id`, `nonconforming_qty` = failure count); replacement WO auto-created when disposition = rework; passed inspection creates NO NCR.
- [ ] **Step 2:** Run `--filter InspectionNcrTest`; fix if needed; expect PASS.

---

### Task P2.11: Pin `LeaveBalanceService::consume()` / `restore()`

**Files:** `api/app/Modules/Leave/Services/LeaveBalanceService.php:26` · Test: `api/tests/Feature/Leave/LeaveBalanceTest.php` (create)

- [ ] **Step 1:** Tests: consume within balance decrements; consume beyond balance throws; `restore()` increments back; cannot go below zero.
- [ ] **Step 2:** Run `--filter LeaveBalanceTest`; fix if needed; expect PASS.

---

# PHASE P3 — CORRECTNESS & CONCURRENCY

---

### Task P3.1: Fix delivery `confirm()` TOCTOU double-confirm

**Files:** `api/app/Modules/SupplyChain/Services/DeliveryService.php:263`

- [ ] **Step 1:** Write a test that calls `confirm()` semantics twice on the same delivery and asserts only ONE confirmation/draft-invoice results (simulate by asserting a second call returns early / throws when already confirmed).
- [ ] **Step 2:** Move the `$d->proofs()->count() === 0` guard INSIDE the `DB::transaction`, and add `DB::table('deliveries')->where('id',$d->id)->lockForUpdate()->first()` (or `$d->newQuery()->lockForUpdate()->find($d->id)`) at the top of the transaction; re-check status and return early if already confirmed.
- [ ] **Step 3:** Run the test in Docker; expect PASS.

---

### Task P3.2: Don't orphan uploaded files on transaction rollback

**Files:** `api/app/Modules/SupplyChain/Services/DeliveryService.php:217`

- [ ] **Step 1:** Refactor `uploadReceiptPhoto`: call `$file->store(...)` BEFORE opening the transaction, capture `$path`; inside the transaction only write DB rows; wrap in try/catch so that on exception you `Storage::disk('local')->delete($path)` then rethrow.
- [ ] **Step 2:** Test (with `Storage::fake('local')`): force the DB write to fail (e.g. invalid FK) and assert the stored file was deleted. Run in Docker; expect PASS.

---

### Task P3.3: Fix supplier quality-score under-count

**Files:** `api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:164,171`

- [ ] **Step 1:** Test: a vendor with 8 passed + 1 in_progress + 1 draft inspection → pass rate should be `88.9%` (8/9 terminal), not `80%`.
- [ ] **Step 2:** Filter the denominator join to terminal statuses: `->whereIn('i.status', ['passed', 'failed'])` so open inspections don't dilute the score.
- [ ] **Step 3:** Run test in Docker; expect PASS.

---

### Task P3.4: Separate disbursement notification from finalize notification

**Files:** `api/app/Modules/Payroll/Services/PayrollPeriodService.php:208,344`; create `api/app/Modules/Payroll/Events/PayrollPeriodDisbursed.php`

- [ ] **Step 1:** Test: calling `finalize()` then `markDisbursed()` dispatches `PayrollPeriodFinalized` exactly ONCE (use `Event::fake`); a separate `PayrollPeriodDisbursed` fires on disbursement.
- [ ] **Step 2:** Create `PayrollPeriodDisbursed` event (mirror `PayrollPeriodFinalized`); change `markDisbursed()` to fire `PayrollPeriodDisbursed` instead of re-firing `PayrollPeriodFinalized`. If a disbursement notification is desired, add a listener; otherwise leave it event-only.
- [ ] **Step 3:** Run in Docker; expect PASS. Confirm no existing listener double-fires.

---

### Task P3.5: Wrap `PayrollPeriodService::finalize()` in a transaction

**Files:** `api/app/Modules/Payroll/Services/PayrollPeriodService.php:321`

- [ ] **Step 1:** Wrap the `finalize()` body in `DB::transaction(function () use ($period) { ... })` (matching `compute`/`approve`). The event dispatch should fire AFTER the transaction commits (move `event(...)` out of the closure or use `DB::afterCommit`).
- [ ] **Step 2:** Run `--filter PayrollPeriodLifecycleTest` (existing) in Docker; expect still-PASS.

---

### Task P3.6: Stop fast-complete GRN rejections from polluting the NCR queue

**Files:** `api/app/Modules/Inventory/Services/GrnService.php:352`

- [ ] **Step 1:** Test: a GRN rejected for a NON-quality reason (e.g. wrong part) via `receiveWithQc(passed=false)` should NOT auto-open an NCR; a genuine quality failure should.
- [ ] **Step 2:** Distinguish the two paths: add a `reason`/`disposition` parameter so `fastCompleteInspection(false)` for a logistics rejection does not flip all measurement rows to `is_pass=false` (which triggers the auto-NCR). Only quality failures should create NCRs. Implement the minimal branching.
- [ ] **Step 3:** Run in Docker; expect PASS.

---

### Task P3.7: Make outgoing-QC creation idempotent under concurrency

**Files:** `api/app/Modules/Quality/Listeners/TriggerOutgoingQC.php:54`; new migration adding a unique index

- [ ] **Step 1:** Add a migration (next free number) creating a UNIQUE index on `inspections (stage, entity_type, entity_id)` (partial/where-not-null as appropriate). Verify `migrate:fresh` ordering.
- [ ] **Step 2:** In the listener, wrap the check-then-insert so a duplicate insert is caught (catch `QueryException` unique-violation and no-op) OR use `firstOrCreate`.
- [ ] **Step 3:** Test: dispatching `WorkOrderCompleted` twice for the same WO yields exactly ONE outgoing inspection. Run in Docker; expect PASS.

---

### Task P3.8: Bound `RoleService::lastModifiedFor()` query

**Files:** `api/app/Modules/Admin/Services/RoleService.php:59`

- [ ] **Step 1:** Replace the unbounded `audit_logs WHERE model_id IN (...) ORDER BY created_at DESC` (loads all rows into PHP) with a per-role latest-row query: a subquery/`DISTINCT ON (model_id)` for Postgres, or a grouped `MAX(created_at)` join. Keep it portable (test on SQLite) — a correlated subquery selecting the latest timestamp per role id is portable.
- [ ] **Step 2:** Test: a role with many audit rows returns only its latest modification; run `--filter RoleManagement` (existing) in Docker; expect still-PASS.

---

# PHASE P4 — STRUCTURAL REFACTORS

*Pure extractions — zero behavior change. Each is guarded by the existing/new tests staying green.*

---

### Task P4.1: Split `RoleDashboardService` (1,385 lines) into focused services

**Files:** create `api/app/Modules/Dashboard/Services/{PlantManagerDashboardService,HrDashboardService,PpcDashboardService,PurchasingWarehouseDashboardService,QualityDashboardService}.php`; modify `DashboardController.php`

- [ ] **Step 1:** Extract by public-method group (no logic change). Move shared private helpers (`kpi`, `safeCount`, `safeSum`, `chainStageBreakdown`, `alerts`, `machineUtilization`, `defectPareto`, hashid encoding) into a shared `DashboardSupport` trait or a `Common/` helper used by all. Each new service owns its public method(s) + private helpers used only by it.
- [ ] **Step 2:** Update `DashboardController` to inject the relevant service per action.
- [ ] **Step 3:** Run `--filter "RoleDashboardServiceTest|BadgeControllerTest|Dashboard"` in Docker after the move — all existing dashboard tests must stay green (they invoke methods via the service container, so update the reflection targets to the new class). Expect PASS.

> Note: the existing `RoleDashboardServiceTest` uses reflection on `RoleDashboardService`. After the split, update each test's `service()` target to the new owning service. Keep assertions identical.

---

### Task P4.2: Split `App.tsx` (1,026 lines) into per-module route files

**Files:** create `spa/src/routes/{hrRoutes,inventoryRoutes,accountingRoutes,productionRoutes,crmRoutes,...}.tsx`; modify `spa/src/App.tsx`

- [ ] **Step 1:** For each module, move its `<Route>` subtree (and the matching `lazy()` imports) into `spa/src/routes/<module>Routes.tsx` exporting a `<Route>...</Route>` fragment or an array. `App.tsx` composes them inside `<Routes>` and keeps only the layout shell + guards.
- [ ] **Step 2:** After extraction, `App.tsx` should be ~100-150 lines. Run `docker compose exec -T spa npx tsc --noEmit` — no NEW errors. Run `docker compose exec -T spa npm run build 2>&1 | tail -5` — build succeeds.
- [ ] **Step 3:** Spot-check a few routes still resolve (start dev or rely on build success + route file structure).

---

### Task P4.3: Unify dashboard API prefix + relocate FinanceDashboardService

**Files:** `api/app/Modules/Dashboard/routes.php`, `api/app/Modules/Accounting/routes.php`, move `Accounting/Services/FinanceDashboardService.php` → `Dashboard/Services/FinanceDashboardService.php`; SPA `spa/src/api/accounting/dashboard.ts`

- [ ] **Step 1:** Decide the canonical prefix (`/dashboards/*`). Move the finance dashboard route under `dashboards` (e.g. `GET /dashboards/finance`). Update the SPA `financeDashboardApi` URL to match.
- [ ] **Step 2:** Move `FinanceDashboardService` to the Dashboard module namespace; update its `use`/namespace and the controller import.
- [ ] **Step 3:** Run finance dashboard-related tests + `tsc` + a manual GET; expect PASS / 200.

> Keep the legacy `/dashboard/finance` route as a temporary alias (302) for one release if the SPA can't be fully updated in lockstep; otherwise update both atomically.

---

### Task P4.4: Thin `SelfServiceController` — extract `home()` to a service

**Files:** `api/app/Modules/HR/Controllers/SelfServiceController.php:53-130`; create `api/app/Modules/HR/Services/SelfServiceHomeService.php`

- [ ] **Step 1:** Move the 5 inline `DB::table()` dashboard queries from `home()` into `SelfServiceHomeService::summary(Employee $e): array`. Controller calls the service and returns a resource/json.
- [ ] **Step 2:** Add a feature test hitting the self-service home endpoint as an employee → 200 with expected shape. Run in Docker; expect PASS.

---

### Task P4.5: Convert inline-validation controllers to FormRequests

**Files:** FormRequests for `CRM/ComplaintController` (store/update), `Forecasting/DemandForecastController`, `ReturnManagement/ReturnRequestController`, `Admin/RoleController:73`, `Payroll/DisbursementProofController:36`

- [ ] **Step 1:** For each `$request->validate([...])` call, create a `FormRequest` with the same rules + an `authorize()` returning the correct `permission` check. Replace the inline validate with the typed request param.
- [ ] **Step 2:** Run the relevant module tests in Docker; expect PASS. Add a 403 test for one converted endpoint (unauthorized user blocked by `authorize()`).

---

### Task P4.6: Add shared helpers to kill duplication

**Files:** create `api/app/Common/Support/HashId.php`; extend dashboard support trait/helper with `safeQuery()`

- [ ] **Step 1:** Add `HashId::encode(int $id): string` and `HashId::decode(string $h): ?int` wrapping `app('hashids')`. Replace the ~30 scattered `app('hashids')->encode((int) ...)` call sites in dashboard/feed/stock-card services with `HashId::encode(...)`.
- [ ] **Step 2:** Add `safeQuery(string $table, Closure $fn, mixed $default = [])` to the dashboard support and collapse the repeated `if (Schema::hasTable(...))` guards.
- [ ] **Step 3:** Run the full backend suite in Docker; expect no regressions.

---

# PHASE P5 — SCOPE RECONCILIATION & FRONTEND POLISH

---

### Task P5.1: Update CLAUDE.md to reflect the real module set

**Files:** `CLAUDE.md`

- [ ] **Step 1:** Per the user's decision (keep all), update the MODULES table to add: **B2B Portal** (supplier + customer), **Budgeting** (budgets, budget transfers, fiscal year), **Demand Forecasting**, **Return Management (RMA)**, **Fixed Assets** (with depreciation). Move the corresponding bullets out of the "NOT BUILDING" list and note any that remain cut (e.g. "per-shot mold depreciation" stays cut).
- [ ] **Step 2:** Add one line per new module to the chains table indicating which chain(s) it serves.

---

### Task P5.2: Fix the 3 pre-existing `tsc` errors in sales-order create

**Files:** `spa/src/pages/crm/sales-orders/create.tsx:105-106`

- [ ] **Step 1:** The mutation response type is a union `SalesOrder | { data: SalesOrder; chain_result }`. Narrow it: type the API client method to return one concrete shape (unwrap `.data` in the api layer) so `so_number`/`id` are accessible. Update `spa/src/api/crm/*` accordingly.
- [ ] **Step 2:** `docker compose exec -T spa npx tsc --noEmit` → **zero** errors (the 3 baseline errors gone).

---

### Task P5.3: Remove dead code

**Files:** `api/app/Modules/Dashboard/Services/*` (`revenueWeek()`, `productionWeek()` now unused after the time-range refactor); `spa/src/pages/dashboard/accounting.tsx` (dead alias page)

- [ ] **Step 1:** Confirm `revenueWeek()`/`productionWeek()` have no callers (`grep -rn "revenueWeek\|productionWeek" api/app`) and delete them.
- [ ] **Step 2:** Confirm `spa/src/pages/dashboard/accounting.tsx` is unreachable (route now redirects to `/dashboard/finance`) and delete it + its lazy import.
- [ ] **Step 3:** `tsc` + backend suite green in Docker.

---

### Task P5.4: Close 5-state gaps on sampled pages

**Files:** the worst offenders identified by the frontend audit (loading/error/empty/data/stale)

- [ ] **Step 1:** For each flagged list/detail page lacking a state, add the missing `<SkeletonTable/SkeletonDetail>` (loading), `<EmptyState … action=Retry>` (error), and contextual empty state — following `docs/PATTERNS.md` §10/§19 exactly.
- [ ] **Step 2:** `tsc` clean; spot-check renders.

---

### Task P5.5: Accessibility quick wins

**Files:** icon-only buttons missing `aria-label`; inputs missing label association (per frontend audit list)

- [ ] **Step 1:** Add `aria-label` to icon-only buttons; associate labels with inputs (`htmlFor`/`id`). Fix the flagged examples.
- [ ] **Step 2:** `tsc` clean.

---

### Task P5.6: Lint + analyse gate

**Files:** none (verification + targeted fixes)

- [ ] **Step 1:** Run `docker compose exec -T spa npm run lint 2>&1 | tail -30` and `docker compose exec -T api ./vendor/bin/phpstan analyse --memory-limit=1G 2>&1 | tail -40`. Triage and fix high-signal violations introduced/uncovered (don't chase pre-existing noise beyond reason — note counts).
- [ ] **Step 2:** Record before/after counts in the task notes.

---

### Task P5.7: Final full-system verification

**Files:** none.

- [ ] **Step 1:** `docker compose exec -T api php artisan test 2>&1 | tail -6` → all pass.
- [ ] **Step 2:** `docker compose exec -T api php artisan migrate:fresh --seed 2>&1 | tail -3` → clean; `route:list` OK; `route:cache`/`route:clear` OK.
- [ ] **Step 3:** `docker compose exec -T spa npx tsc --noEmit` → 0 errors; `docker compose exec -T spa npm run test -- --run` → all pass; `docker compose exec -T spa npm run build 2>&1 | tail -5` → success.
- [ ] **Step 4:** Use **superpowers:requesting-code-review** on the cumulative diff before the user commits.

---

## Self-Review (against the audit)

- **CRITICAL coverage:** route:list (P0.1), duplicate migrations (P0.2), Postgres SQL (P0.3), public-disk exposure (P0.4), hashId() crash (P0.5), git risk (ground-rule: edit-only, user commits). ✅
- **HIGH coverage:** raw-ID leaks (P1.1/P1.2), upload MIME (P1.3), doc-vault perm (P1.4), scope creep (P5.1 keep-all per decision), test gaps + factories (P2.1–P2.11), fat controllers (P4.4/P4.5). ✅
- **MEDIUM coverage:** god-service split (P4.1), App.tsx split (P4.2), dashboard prefix/FinanceDashboardService (P4.3), duplication helpers (P4.6), supplier quality bug (P3.3), confirm() TOCTOU (P3.1), upload-rollback orphan (P3.2), duplicate payroll event (P3.4), finalize tx (P3.5), GRN NCR pollution (P3.6), QC idempotency (P3.7), RoleService query (P3.8), tsc/dead-code/a11y/lint (P5.2–P5.6). ✅
- **No-placeholder note:** P0/P1/P3 tasks carry exact code or exact mechanical instructions + verification commands. P2 test tasks specify exact behaviors to pin with example assertions and the factory scaffolding to write them; P4 are mechanical extractions with explicit boundaries and green-test gates. No `TBD`/`handle edge cases`-style placeholders.
- **Cross-DB consistency:** every SQL-touching task (P0.3, P3.8) mandates portable query-builder forms verified on both SQLite and Postgres — consistent with the ground rules.
