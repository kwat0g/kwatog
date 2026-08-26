# OGAMI ERP — Claude Code Master Context

> Claude Code reads this automatically on every command. Read completely before executing any task.
> References: `docs/README.md`, `docs/DESIGN-SYSTEM.md`, `docs/SCHEMA.md`, `docs/SEEDS.md`

## PROJECT

Production-grade ERP for **Philippine Ogami Corporation** — Japanese-owned plastic injection molding manufacturer (200+ employees, FCIE Dasmariñas, Cavite). Makes wiper bushings, pivot caps, relay covers for Toyota, Nissan, Honda, Suzuki, Yamaha. IATF 16949 certified. Thesis project, 8 months, solo developer.

## THE THREE CHAINS (this is what matters)

Every feature serves one of three end-to-end business processes. When building, always ask: "which chain does this serve?"

```
CHAIN 1 — ORDER TO CASH
  CRM Sales Order → MRP Plan → MRP II Schedule → Work Order → QC (in-process) →
  Finished Goods → QC (outgoing AQL) → Delivery → Customer Confirm → Invoice → Collection → GL

CHAIN 2 — PROCURE TO PAY
  Material Shortage (MRP) → Purchase Request → Approval → PO → Supplier →
  Shipment (ImpEx) → Receive (GRN) → QC (incoming) → Inventory → Bill → Payment → GL

CHAIN 3 — HIRE TO RETIRE
  Hire → Profile → Shift Assignment → Biometric CSV → DTR Computation →
  Leave/OT Approvals → Payroll → Payslip → Bank File → GL → Separation → Clearance → Final Pay
```

## IATF 16949 QUALITY (woven through every chain, not a separate module)

Quality is the thesis differentiator. Four touchpoints:

1. **Incoming QC** (Chain 2, after GRN) — verify resin certs, moisture before accepting inventory
2. **In-process QC** (Chain 1, during production) — periodic sampling between operations
3. **Outgoing QC** (Chain 1, before delivery) — AQL 0.65 Level II sampling, measurements vs spec tolerances
4. **NCR feedback loop** (any chain) — failure creates corrective action work order; replacement WO auto-generated; defect data flows to Pareto analysis

Every product has **inspection specs** (dimensions + tolerances). Every inspection records **actual measurements**. Every failure becomes a traceable **NCR**. Every shipment gets a **Certificate of Conformance** auto-generated from inspection data.

## ARCHITECTURE

```
React 18 SPA (Vite + TypeScript) ←HTTP-only cookies→ Laravel 11 REST API (PHP 8.3)
                                              │
              PostgreSQL 16 · Redis 7 · Laravel Reverb (WebSocket)
```

Fully decoupled. API at `/api/v1/*`, SPA at `/*`, WebSocket at `/ws`. Docker Compose.

## MODULES (17)

| # | Module | Chains |
|---|---|---|
| 1 | HR (employees, departments, positions, separation, clearance) | 3 |
| 2 | Attendance (shifts, DTR, OT, biometric import) | 3 |
| 3 | Leave (types, balances, approval workflow) | 3 |
| 4 | Payroll (engine, gov deductions, payslip, bank file, 13th month) | 3 |
| 5 | Loans (company loan + cash advance, auto-deduction) | 3 |
| 6 | Accounting (COA, JE, AP, AR, VAT, financial statements, **budgeting + budget transfers + fiscal year**) | all |
| 7 | Inventory (items, warehouse, GRN, issue, stock) | 1, 2 |
| 8 | Purchasing (PR, PO, approval, 3-way match) | 2 |
| 9 | Supply Chain (shipments, import docs, fleet, delivery) | 1, 2 |
| 10 | Production (work orders, output, machine downtime, OEE) | 1 |
| 11 | MRP / MRP II (BOM, material planning, capacity, Gantt, molds) | 1, 2 |
| 12 | CRM (customers, price agreements, sales orders, complaints, 8D) | 1 |
| 13 | B2B Portal (supplier + customer self-service portals) | 1, 2 |
| 14 | Forecasting (demand forecasts) | 1, 2 |
| 15 | Return Management (RMA / return requests) | 1, 2 |
| 16 | Assets (fixed assets, depreciation, QR tracking) | 2 |
| 17 | Budgeting (budgets, budget line items, revisions, transfers, fiscal year) | 6 |

> **Scope note (2026-06):** Modules 13–17 were added beyond the original 12. They
> are intentional scope expansion and are maintained/tested like the rest.
> Budgeting lives inside the Accounting module namespace; it is listed separately
> here for visibility.

Plus: **Quality** (specs, inspections, NCR, CoC at 4 chain touchpoints, not a module), **Maintenance** (machine breakdowns, mold shot tracking, preventive schedules), **Dashboard** (live KPIs, chain stage breakdown, alerts, Pareto).

## NOT BUILDING (cut scope — scope discipline ships this thesis)

- ❌ Cost accounting, cash flow forecasts
- ❌ Bank reconciliation, closing wizards, fiscal period locking
- ❌ Tax compliance calendar
- ❌ Customizable dashboards (react-grid-layout), saved views scheduling, automation rule builder UI
- ❌ Setup wizard, guided tours, onboarding system, announcements, changelog, feedback form
- ❌ System health monitoring dashboard
- ❌ Import center with mapping/preview (simple CSV upload is enough)
- ❌ Activity feeds on every record (only on SO, PO, WO, NCR)
- ❌ RFQ process, per-shot mold depreciation

## SECURITY (production-grade, mandatory)

### Authentication: Sanctum SPA mode with HTTP-only cookies

**NEVER use Bearer tokens. NEVER store auth in localStorage/sessionStorage.**

```
1. SPA → GET /sanctum/csrf-cookie → receives XSRF-TOKEN cookie
2. SPA → POST /api/v1/auth/login (credentials + X-XSRF-TOKEN header)
3. Server validates → creates session → sets HTTP-only session cookie
4. All subsequent requests carry session cookie automatically
5. JavaScript cannot read HTTP-only cookies → immune to XSS token theft
```

### URL ID Obfuscation (HashIDs)

**NEVER expose integer IDs in URLs or API responses.**

```php
// composer require vinkla/hashids
// app/Common/Traits/HasHashId.php
trait HasHashId {
    public function resolveRouteBinding($value, $field = null) {
        $decoded = app('hashids')->decode($value);
        if (empty($decoded)) abort(404);
        return $this->where('id', $decoded[0])->firstOrFail();
    }
    public function getHashIdAttribute(): string {
        return app('hashids')->encode($this->id);
    }
}

// Every model uses HasHashId
// Every API Resource returns hash_id, NEVER raw id:
public function toArray($request) {
    return ['id' => $this->hash_id, /* ... */];  // 'yR3kLm' not 42
}
```

### Route Guards (3 layers on frontend)

```typescript
// 1. AuthGuard — redirects to /login if no session
// 2. ModuleGuard — shows "module disabled" if feature toggle off
// 3. PermissionGuard — shows 403 if user lacks permission

<Route element={<AuthGuard><AppLayout /></AuthGuard>}>
  <Route element={<ModuleGuard module="production"><Outlet /></ModuleGuard>}>
    <Route element={<PermissionGuard permission="production.wo.view"><Outlet /></PermissionGuard>}>
      <Route path="/production/work-orders" element={<WorkOrderList />} />
    </Route>
  </Route>
</Route>
```

**Backend enforces permissions independently via middleware.** Frontend guards are UX only.

### Security Headers (Nginx)

Required on every response: HSTS, `X-Frame-Options: DENY`, `X-Content-Type-Options`,
`Referrer-Policy`, `Permissions-Policy`, and a CSP. Live values in
`docker/nginx/security-headers-dev.conf` and `security-headers-prod.conf` — edit those,
never inline headers into a server block.

### Rate Limiting & Account Protection

```php
RateLimiter::for('auth', fn($r) => Limit::perMinute(5)->by($r->ip()));
RateLimiter::for('api', fn($r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));
RateLimiter::for('sensitive', fn($r) => Limit::perMinute(10)->by($r->user()?->id));

// Account lockout: 5 failed logins → lock 15 min
// Password expiry: 90 days (forced change on next login)
// Password history: cannot reuse last 3
// Policy: min 8 + uppercase + number + special
// bcrypt cost: 12
```

### Data Protection

```php
// Encrypted at rest:
protected $casts = [
    'sss_no' => 'encrypted',
    'philhealth_no' => 'encrypted',
    'pagibig_no' => 'encrypted',
    'tin' => 'encrypted',
    'bank_account_no' => 'encrypted',
];

// Data masking in API Resources (non-HR users see "***-**-4567")
// Row-level filtering ALWAYS server-side (never trust frontend)
```

### Other rules

- Every controller action checks permissions via middleware OR `FormRequest::authorize()`
- Every financial operation wrapped in `DB::transaction()`
- `SanitizeInput` middleware strips tags on all string inputs
- File uploads: validate MIME server-side, random filenames, stored outside web root, served via controller with permission check
- `APP_DEBUG=false` in production, generic errors to client
- Never use `DB::raw()` with user input
- Session timeout: Employee 15 min, others 30 min
- All auth + financial events logged with IP + user agent

## FILE STRUCTURE

Two apps: `api/` (Laravel 11) and `spa/` (React 18 + Vite), plus `docker/`, `docs/`, `Makefile`.

Only convention worth stating (the rest, run `ls`): every module under `api/app/Modules/<Name>/`
gets its own `Controllers/ Models/ Services/ Requests/ Resources/ Jobs/ routes.php` — a new module
that puts models or services anywhere else is wrong even if it works.

## URL ROUTING CONVENTION (Modular Monolith)

The SPA mirrors the modular-monolith backend structure. Every employee-facing
business module lives under a top-level path that names its umbrella module:

```
/hr/employees           Sprint 2 — HR / People
/hr/departments
/hr/positions
/hr/attendance          Sprint 2 — HR / Attendance subdomain
/hr/attendance/import
/hr/attendance/shifts
/hr/attendance/holidays
/hr/attendance/overtime
/hr/leaves              Sprint 2 — HR / Leaves
/hr/loans               Sprint 2 — HR / Loans

/payroll/periods        Sprint 3 — HR / Payroll (kept under /payroll for clarity)

/inventory/...          Operations modules — own top-level prefix
/purchasing/...
/supply-chain/...
/production/...
/mrp/...
/crm/...
/quality/...
/maintenance/...
/accounting/...

/admin/settings         Cross-cutting admin
/admin/roles
/admin/audit-logs
```

**Rules**

1. Every URL must start with the umbrella module slug (`/hr`, `/inventory`, …).
   No bare resource paths like `/leaves` or `/loans` — they collide as the
   product grows and obscure ownership in audit logs.
2. Sub-resources nest under the parent module, e.g. `/hr/attendance/overtime`,
   not `/overtime`. The sidebar's longest-prefix match in
   [`Sidebar`](spa/src/components/layout/Sidebar.tsx:1) relies on this so only
   the most specific item lights up.
3. The filesystem layout under `spa/src/pages/` does NOT have to mirror the
   URL exactly — it follows the React component tree (e.g. `pages/attendance/`
   is fine even though the URL is `/hr/attendance`). Keep imports stable.
4. Backend API paths (`/api/v1/leaves/requests`, etc.) are independent of the
   SPA route prefix — do not rename them when restructuring SPA URLs.

## CODE CONVENTIONS

### PHP / Laravel

Full templates in `docs/PATTERNS.md` — copy from there, don't improvise. The rules those
templates encode (not obvious from reading one file):

- `declare(strict_types=1);` at the top of every PHP file.
- Enums for **all** status/type fields — never a bare string column.
- Controllers are thin: constructor-inject the Service, return a Resource. **All** business
  logic lives in the Service, wrapped in `DB::transaction()` when it touches money.
- Authorization goes in the FormRequest's `authorize()`, never in the controller body.
- Resources return `hash_id` and mask sensitive fields per-permission.

### TypeScript / React

Full templates in `docs/PATTERNS.md`. Non-obvious parts:

- `id` is always a `string` (a HashID), never a number.
- **Decimals arrive as strings**, not numbers — `basic_monthly_salary: string`. Parsing them
  into JS floats reintroduces the rounding error `decimal(15,2)` exists to prevent.
- Data fetching is TanStack Query; forms are React Hook Form + Zod with the Zod schema
  mirroring the backend FormRequest rules.

### Database Conventions

- PKs: `id` bigint auto-increment (exposed as HashIDs)
- Money: `decimal(15, 2)` — **NEVER float**
- FKs: `{table_singular}_id`
- Soft deletes: employees, vendors, customers, users, items, machines, molds, products
- Sensitive fields: Laravel `encrypted` cast
- Migrations numbered: `0001_`, `0002_`, ...

### Number formats (monthly reset, `document_sequences` table)

```
Employee       OGM-YYYY-NNNN     OGM-2026-0142
Purchase Order PO-YYYYMM-NNNN    PO-202604-0015
Invoice        INV-YYYYMM-NNNN   INV-202604-0008
Journal Entry  JE-YYYYMM-NNNN    JE-202604-0032
Work Order     WO-YYYYMM-NNNN    WO-202604-0006
NCR            NCR-YYYYMM-NNNN   NCR-202604-0002
GRN            GRN-YYYYMM-NNNN   GRN-202604-0011
Sales Order    SO-YYYYMM-NNNN    SO-202604-0003
Return (RMA)   RMA-YYYYMM-NNNN   RMA-202604-0004
Leave Request  LR-YYYYMM-NNNN    LR-202604-0045
Inspection     QC-YYYYMM-NNNN    QC-202604-0012
```

## KEY BUSINESS RULES (quick reference)

- **Currency:** Philippine Peso only (₱)
- **Payroll:** Semi-monthly. Gov deductions on 1st period only
- **Pay types:** `monthly` (basic_monthly_salary ÷ 2 per cutoff) and `semi_monthly`
  (`semi_monthly_rate`, a flat per-cutoff figure). Both are FLAT — basic pay never
  multiplies by days worked. `daily` was retired by migration 0437: its days-worked
  basic disagreed with the monthly gov-contribution basis, so any absence withheld a
  full month of deductions from a partial month of pay (zero net + anomaly flags that
  block finalize). Use `Employee::monthlyEquivalentSalary()` — the ONE place the two
  pay types are reconciled (`semi_monthly_rate × 2`) — never read a rate column directly.
- **Payroll period scope:** A period may be limited to employment types, pay types
  and/or departments (`scope_*` columns, all ANDed; all null = company-wide). Two
  periods may share dates only if their scopes are disjoint — enforced against real
  employee sets, not by comparing filter arrays.
- **No double pay:** `payroll_cycle_claims` has UNIQUE (employee_id, cycle_key) where
  cycle_key is `YYYY-MM-H1|H2` / `YYYY-13TH`. One employee is payable at most once per
  cutoff, across ALL periods. This is the race-proof guard — application checks alone
  cannot close it (two workers, two transactions). Voiding a period releases its claims
  so a replacement run can pay those people.
- **The half is DERIVED, never chosen** (`PayrollPeriod::deriveIsFirstHalf()`: day 1–15 =
  first half). It was an operator checkbox, which let the label contradict the dates —
  enter Aug 16–31, tick "1st half" — inverting the cycle key so the guard saw two
  different cycles and paid one employee twice for the month, and moving gov
  contributions onto the wrong cutoff. A cutoff must also stay inside ONE half of ONE
  month; straddling windows (Aug 10–20, Aug 20–Sep 10) are refused, since their key
  would describe only the half they start in. Migration 0440 reconciled existing rows.
  `is_first_half` remains a list FILTER, not a create input.
- **payroll_date is load-bearing, not cosmetic.** It selects the effective-dated gov
  contribution tables, the de-minimis month, and the GL posting date. Only
  `>= period_end` was enforced, so a 2029 cutoff could carry a 2034 date and be
  computed against another year's SSS schedule (~₱100/employee between the 2024 and
  2025 tables). Now bounded to `period_start … period_end + N` days
  (`payroll.payroll_date.max_days_after_period_end`, default 45).
- **Partial employment prorates BOTH ends.** Basic pay is flat per cutoff, which is only
  right for someone employed the whole cutoff. `employedDayFraction()` scales it by the
  days actually covered — hire date OR separation date (`clearances.separation_date`,
  earliest wins). Without the separation half a leaver banked the full half-month and
  `FinalPayService::lastSalaryProRated()` reads `payroll.basic_pay` verbatim, so it flowed
  straight into final pay (~₱6,880 on a ₱9,460 cutoff).
- **The gov-contribution basis follows ACTUAL compensation**, i.e. monthly equivalent ×
  employed fraction. Using the nominal salary on a partial cutoff assessed a full month's
  contributions against part of a month's pay — an 86% deduction ratio that clamped net to
  near zero and raised `high_deduction`, blocking finalize. Same class of defect as the old
  daily pay type. Note this predated the semi-monthly work: mid-period HIRES were always
  assessed this way.
- **13th month is maker-checker gated.** `computeAndPay()` lands on **Computed** and links
  accruals but does NOT set `is_paid`; payment is recognised only in `finalize()`
  (synchronously, inside its transaction — not a queued listener that swallows failures).
  It used to flip `is_paid` at Draft, so the year read as settled before any checker saw
  it, and since `accrue()` skips a paid accrual a re-run then wiped the payroll rows and
  rebuilt **nothing** — an empty period nobody gets paid from. Voiding reopens the accruals.
- **OT:** Min 30min, Max 4hrs. Extended shift (6AM–6PM) = auto-OT
- **Night diff:** 10% premium for 10PM–6AM ONLY
- **Loans:** Zero interest. Max 1 month salary. 1 loan + 1 CA at a time
- **Inventory valuation:** Weighted average cost
- **Outgoing QC:** AQL 0.65 Level II. Actual measurements for critical dimensions
- **Approval chain:** Staff → Dept Head → Manager → Officer → VP (4 levels)
- **Mold shot count:** Auto-increment. Alert at 80% of max
- **Weighted avg cost:** Recalculated on every purchase receipt
- **Payroll corrections:** Never unlock finalized. Adjustment in next period

## DESIGN SYSTEM QUICK REFERENCE

Full spec in `docs/DESIGN-SYSTEM.md`. Brand: **Atelier** — editorial, warm, unhurried.

- **Font:** Instrument Serif (display / page titles) + Public Sans (UI) + Spline Sans Mono (numbers, IDs, tables)
- **Canvas:** Warm paper (`#fdfcfa`) with espresso ink (`#1f1b16`). Surfaces are **opaque** — no translucency, no `backdrop-blur`
- **Accent:** Clay (`#b4542a`). Semantics are brand-hued, not Tailwind defaults: moss, ochre, oxide red, slate blue, plum
- **Three palettes:** `:root` light · `[data-theme="dark"]` espresso · `[data-theme="floor"]` high-contrast for the shop-floor PWAs (route-forced by `TouchShell`, never user-selectable)
- **Applied only to:** Primary buttons, status chips, KPI deltas, alert dots, links
- **Tables:** `h-row` (`--row-height`) — 32px office / 48px floor; monospace tabular figures for numbers
- **Sidebar:** Collapsible (240px ↔ 56px rail)
- **Radius:** 8px `sm` / 10px `md` / 14px `lg`
- **Hierarchy comes from borders, not shadows.** Shadows are for true overlays only (menu, modal, toast) and warm-tinted
- **Animations:** Minimal — loading, progress, status changes only
- **Dark mode:** First-class
- **Never hardcode a colour.** Every value lives in `spa/src/styles/tokens.css`; `npm run audit:tokens` enforces it in CI

## TASK EXECUTION PROTOCOL

### MANDATORY: Before writing ANY code, read `docs/PATTERNS.md`

PATTERNS.md contains exact, copy-paste code templates for every page type, every component usage, every API call, and every error state. **DO NOT improvise structure.** Find the matching pattern, copy it, change entity names and fields. This is how we prevent sloppy code.

### Steps for every task:

1. Read this file (CLAUDE.md) — already happening
2. **Read `docs/PATTERNS.md`** — find the matching template (list page, form page, detail page, controller, service, etc.)
3. Read the current issue/request and `docs/SYSTEM-IMPROVEMENT-ROADMAP-2026-08-13.md` when it is audit-related
4. Read relevant sections of `docs/SCHEMA.md` for table specs
5. Read `docs/DESIGN-SYSTEM.md` if task involves UI
6. Read `docs/SEEDS.md` if task involves seed data
7. **Copy the matching pattern from PATTERNS.md and adapt it** — do NOT write from scratch
8. Pattern for full module: Migration → Enum → Model → Service → Request → Resource → Controller → Routes → Types → API → Pages → Components

### Rules (NEVER violate):

- Every model gets `HasHashId` trait
- Every API Resource returns `hash_id`, never raw integer `id`
- Every financial operation wrapped in `DB::transaction()`
- Every list page handles 5 states: loading (skeleton), error (retry), empty (message), data (table), stale (placeholder)
- Every form has: Zod schema matching backend, submit button disabled while pending, loading text, server-side error mapping, cancel button
- Every mutation has: toast.success on success, toast.error on failure, queryClient.invalidateQueries
- Every page is lazy-loaded with `React.lazy()`
- Every route wrapped in `AuthGuard` + `ModuleGuard` + `PermissionGuard`
- Numbers always use `font-mono tabular-nums`
- Status fields always use `<Chip>` with semantic variant mapping
- Never use Bearer tokens — HTTP-only cookies only
- Never store auth in localStorage/sessionStorage
- Git commit after each task: `feat: task N — description`

### Final checklist (run mentally before finishing any task):

See the complete checklist at the bottom of `docs/PATTERNS.md`. Every checkbox must pass.

## SESSION-LEARNED PATTERNS (recurring gotchas)

### Test environment
- PHP mem default 128M OOMs full suite. Bump: `docker compose exec -T -u root api bash -c "echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/zz-mem.ini"`
- TEST seeds: column varchars are mostly 20 chars. Use `'XX-T-'.substr(uniqid(), -5)` (10 chars). NEVER `'XX-TEST-'.uniqid()` (21 chars → truncation).
- User+role seed: `User::factory()->create(['role_id' => Role::query()->where('slug', X)->value('id')])`. NEVER `assignRole()` (Spatie API not used here).
- `Storage::fake('local')` for delivery photos / SOP files (NOT `'public'`).
- SQLite-only `PRAGMA foreign_keys = ON` is removed — PG enforces FKs natively.

### Mass-assignment hardening (WIP convention)
- Many models removed `status` from `$fillable` (Loan, LeaveRequest, PR, PO, PayrollPeriod, NCR, EmployeeTraining, etc.). Service writes use `$m->forceFill(['status' => X])->save()`. Tests passing `status` to `::create([…])` get `MassAssignmentException`.
- Audit-row hygiene: prefer `$m->fill([…]); $m->status = E::Foo; $m->save();` (single save → one audit row) over `update() + forceFill()->save()` (two rows for one logical action).

### HasAuditLog + custom guards
Under non-web guards (e.g. `auth:edge_device`, `auth:supplier_portal`), `Auth::id()` returns the non-User PK → `audit_logs` FK violation. Wrap writes via `App\Modules\Edge\Services\EdgeSystemUserResolver::impersonate(callable)` which pins `Auth::shouldUse('web')` + `onceUsingId($systemUserId)` for the call.

### Model namespace gotchas
- `Customer` → `App\Modules\Accounting\Models\Customer` (NOT CRM)
- `Machine` → `App\Modules\MRP\Models\Machine` (NOT Production)
- `Product` → `App\Modules\CRM\Models\Product` (finished goods); raw materials = `App\Modules\Inventory\Models\Item`
- `MaintenanceWorkOrder` is polymorphic via `maintainable_type/_id`, NOT a direct `machine_id` FK.

### Enum value gotchas
- `InspectionParameterType` = Dimensional | Visual | Functional (no Numeric — Dimensional IS the numeric/tolerance path)
- `NcrSource` = inspection_fail | customer_complaint
- `NcrDisposition` = scrap | rework | use_as_is | return_to_supplier
- `GrnStatus` = pending_qc | accepted | partial_accepted | rejected (no `draft`)

### Seeded roles (slugs)
system_admin, hr_officer, finance_officer, production_manager, ppc_head, purchasing_officer, warehouse_staff, qc_inspector, maintenance_tech, impex_officer, department_head, employee, driver. **NO plant_manager** — use production_manager.

### Routing + middleware
- Literal route segments declared BEFORE `{model}` bindings (e.g. `/vendors/ranking` before `/vendors/{vendor}/performance`) else they get param-bound.
- Schedule entries → `api/routes/console.php` (Laravel 11). `Console\Kernel` does NOT exist.
- Sanctum 4 abilities: `'ability' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class` registered in `bootstrap/app.php` aliases.
- Module routes auto-mount under `/api/v1` via `App\Providers\ModuleServiceProvider`.
- Frontend: NEVER set `'Content-Type': 'multipart/form-data'` on axios FormData requests — strips boundary. Let browser auto-set.

### Event/listener wiring
Explicit `Event::listen($EventClass, [$ListenerClass, 'handle'])` in `AppServiceProvider::boot()`. No auto-discovery. Listeners default to `ShouldQueue` w/ try/catch + `Log::warning` so a failure never blocks the dispatcher.

**That pattern hides dead subsystems — never wrap the failure-recorder in it too.**
On 2026-08-26 the 8D SLA escalation ledger was found to be 100% dead code: its
model inferred a table name that does not exist, so every read and write threw
`SQLSTATE[42P01]`. `advanceOne()` caught it into `Log::warning`, and
`recordFailure()` — the thing whose job was to record that failure — hit the same
missing table inside its *own* `catch (Throwable) → Log::error`. The scheduled
command therefore printed `d3=0 d4=0 finalize=0` and exited **SUCCESS** every 15
minutes, so a subsystem that had never once worked was indistinguishable from a
healthy idle one. No IATF-required escalation notification was ever delivered.

So when you use this convention:
- The **work** may be caught-and-logged. Its **failure path** must not swallow its
  own errors — if `recordFailure()` cannot record, that has to surface.
- A command that did nothing because everything threw must not exit 0 with a
  zero-count summary. Distinguish "nothing to do" from "everything failed."
- A `catch (Throwable)` around a whole transaction will also swallow schema and
  binding errors, which are programmer bugs, not transient runtime ones.

### NCR.actions() / GROUP BY trap
`NonConformanceReport::actions()` defines a default `orderBy('performed_at')`. Aggregate queries (`->selectRaw(... GROUP BY ...)`) must call `->reorder()` inside the closure or PG throws `SQLSTATE[42803]`.

### Shared helpers — REUSE before reinventing
- `App\Modules\Edge\Services\EdgeSystemUserResolver` — guard impersonation (T2.x ingest paths).
- `App\Modules\Production\Services\WorkOrderOutputService::record()` — idempotent output recording (mold shots + scrap rate + event). Reuse from any new output path.
- `App\Modules\Maintenance\Services\PredictiveMaintenanceService::recordAndEvaluate()` — condition reading + breach gate + corrective MWO.
- `App\Modules\Quality\Services\InspectionService::recordMeasurements()` — tolerance auto-eval + status transition + defect counting.
- `App\Common\Services\NotificationService::send($recipients, string $type, array $data)` — single notification entry point. Recipients = `User|Collection|array`.

### Dashboards are permission-derived — never add a role-name branch
- **Landing page:** `DashboardDispatchService::resolve()` picks the bespoke dashboard from `DashboardCatalog` (keyed by permission). When several qualify, rarest permission wins — rarity is counted live from `role_permissions`. A new role needs no code change.
- **Widget visibility:** a `dashboard_widgets` row declares four independent things — `permission` (who), `render_kind` (how it draws), `link_path` (where "Open →" goes), `module` (picker grouping). None names a role. `DashboardLayoutService` strips anything the caller's `hasPermission` refuses, on both the plain and rich paths.
- **Adding a widget:** row in `DashboardWidgetSeeder` (+ `LINK_BY_KEY` entry) → scalar arm in `DashboardWidgetDataService` → rich provider in `Services/Analytics/*` if non-scalar → register the provider in BOTH `WidgetAnalyticsService::providers()` and `WidgetSeedIntegrityTest::handledKeys()`.
- `WidgetSeedIntegrityTest` is the drift guard: rich↔provider bijection, every row has a `link_path`, KPI widgets match `kpi_definitions`, KPI gates match `KpiSnapshotService::MODULE_PERMISSIONS`, and no role default references a widget that role cannot see.
- Role defaults (`DashboardRoleLayoutSeeder`) are a UX seed, NOT an access decision. A leaky default is stripped at render, so it fails silently — the test above is what catches it.

### Cron inventory

Authoritative list is `api/routes/console.php` (43 scheduled commands as of 2026-08-25) —
read it rather than trusting a copy here. One scheduling gotcha worth knowing without looking:

- `kpi:compute-monthly` (2nd @ 03:00) is the **only** thing that fills `kpi_snapshots`, so every
  `kpi.*` dashboard widget reads one month behind. A "missing" KPI early in the month is expected,
  not a bug.

### Migration numbering
Recent additions use 4-digit numbered (`0186_*`, `0187_*`, …). Highest as of 2026-08-27 = **0478** (`0475_harden_activity_events` … `0478_harden_ncr_capa_contracts` arrived from audit sessions and were committed in `167de85e`; the "0474" this line used to claim was already stale). Confirm the real max before using it — `ls api/database/migrations | grep -E '^04' | sort | tail -3` — rather than trusting this number, which goes out of date exactly when several sessions are landing work. The sequence is contiguous through 0478: `0472_add_link_path_to_dashboard_widgets` used to be missing from `main` because it lived on an unmerged frontend branch, and that branch has now merged. Mixed timestamp-style migrations (`2026_06_09_*`, `2026_08_16_*`, and a run of `2026_08_26_*`) coexist for older HR/Payroll changes, recent BOM-costing work, and anything that must run after a timestamp-named migration — see the dependency rule below.

**"highest + 1" is WRONG when your migration depends on a timestamp-named one.**
The migrator sorts by full filename, and `'0'` < `'2'`, so **every** `0NNN_` file
runs before **every** `2026_*` file — regardless of when you wrote it. A new
`0475_` that touches a table created by, or a column added by, a `2026_*`
migration therefore runs *before* its dependency exists. Two sessions hit this on
2026-08-26 and it does not always fail loudly: one added four constraints and
**three were silently skipped** (`ADD CONSTRAINT IF NOT EXISTS`-style guards and
`Schema::hasColumn()` checks no-op when the column isn't there yet), so
`migrate:fresh` went green with the guards absent.

So pick the name by dependency, not by convention:

| your migration touches | use |
|---|---|
| only tables from `0NNN_` migrations | `0NNN_`, next unused prefix |
| anything created/altered by a `2026_*` migration | `2026_MM_DD_HHMMSS_*`, dated after it |

Check before writing, don't trust a max: `ls api/database/migrations | grep '^0475_'`
for the numbered case, and `grep -rln '<table_or_column>' api/database/migrations | sort | tail -1`
to find what actually creates the thing you depend on.

**Four prefixes are used twice, and must NOT be renamed:**

| prefix | the two files |
|---|---|
| `0198` | `add_label_description_to_settings`, `create_accounting_periods_table` |
| `0427` | `seed_company_coordinates`, `seed_payroll_compute_stale_threshold` |
| `0442` | `seed_maintenance_dashboard_window_setting`, `update_company_plant_location_settings` |
| `0450` | `add_render_kind_to_dashboard_widgets`, `rename_edge_system_user_settings` |

All four pairs are pre-existing on `origin/main`, so they are not new breakage, and
**ordering is unaffected** — the migrator sorts by full filename, so a duplicated
numeric prefix still orders deterministically by the rest of the name. The
timestamp-style files collide the same way and for the same reason —
`2026_08_13_110000_*` names two files and `2026_08_13_120000_*` names four — and
are equally safe to leave alone.

Renaming them would be **destructive**. Laravel records the filename (without
`.php`) in the `migrations` table and matches on it, so a renamed migration that
has already run looks new and is re-run — re-applying schema operations against a
deployed database. Leave them alone.

The cost is human, not mechanical: two files claiming one sequence number make
"highest + 1" ambiguous and invite a fifth collision. Derive the next number by
confirming the prefix is unused (`ls api/database/migrations | grep '^0475_'`)
rather than trusting a max.

### Test runner + suite size
Full suite as of 2026-08-17: **1900 tests / 0 fail / ~28 min runtime**. Use `--filter='Foo|Bar'` for tight loops. Re-run full suite only at end of feature.

**Two agents cannot share `ogami_test`.** `RefreshDatabase` runs `migrate:fresh`, so a second suite tears the schema down under the first: you get `relation "roles" does not exist`, `column roles.deleted_at does not exist`, `SQLSTATE[40P01] deadlock detected` — hundreds of failures with ZERO assertion failures among them, which is the tell. Run on your own database instead of guessing:
```
docker compose exec -T db psql -U ogami -d postgres -c "CREATE DATABASE ogami_test_verify OWNER ogami;"
docker compose exec -T -e DB_DATABASE=ogami_test_verify api php artisan test
```
`phpunit.xml` hardcodes `DB_DATABASE=ogami_test` but PHPUnit `<env>` does not force, so an existing env var wins.

### Browser engine: Lightpanda where it fits, Chromium where it must

`lightpanda` (installed at `~/.local/bin/lightpanda`) is a Zig headless engine that
speaks CDP, so `chromium.connectOverCDP('http://127.0.0.1:9222')` works after
`lightpanda serve --host 127.0.0.1 --port 9222`. It is ~9x faster and ~16x lighter
than Chrome, and worth using for anything crawl-shaped.

**It has no layout engine.** That is the deciding fact, measured against
`1.0.0-nightly.5266`, not inferred from the docs:

| probe | Lightpanda result |
|---|---|
| `connectOverCDP`, `goto`, DOM queries | work |
| `getBoundingClientRect()` on `<body>` | `{w: 1920, h: 100000000}` — fabricated |
| `getComputedStyle(el).display` | returns the initial value, not the cascade |
| `page.title()` on example.com | `""` |
| `page.setContent()` | closes the target |
| `page.screenshot()` | fails |

**Use Chromium (required) when the check measures rendering:**
- the whole Playwright suite in `spa/e2e/` — 93 `page.route` mocks, 8
  `setViewportSize`, plus `getComputedStyle`/`getBoundingClientRect`/
  `toBeInViewport` in `ux-hardening-visual.spec.ts`. A UI/UX suite measures
  layout, which is precisely what Lightpanda does not compute.
- `scripts/defense-smoke-walk.js` — takes screenshots.

**Lightpanda is a fit (DOM presence and text only, no geometry, no screenshots):**
- `scripts/panel-gate-browser-check.js`
- `scripts/dynamic-spa-route-audit.js`

Do not "fix" a geometry assertion to make it pass under Lightpanda — a passing
`getBoundingClientRect` there is a fabricated number, so the test would assert
nothing. Reach for Chromium instead.

## Agent skills

### Issue tracker

Issues live as GitHub issues in `kwat0g/kwatog`, driven by the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default five-role vocabulary, label string equal to role name. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `CONTEXT.md` + `docs/adr/` at the repo root. See `docs/agents/domain.md`.
