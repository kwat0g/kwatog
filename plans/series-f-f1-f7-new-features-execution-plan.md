# Series F (F1–F7) — New Features Execution Plan

> Source: [`docs/NEW-TASKS-V2.md`](../docs/NEW-TASKS-V2.md:1215) Series F.
> Conventions: [`CLAUDE.md`](../CLAUDE.md:1), [`docs/PATTERNS.md`](../docs/PATTERNS.md:1), [`docs/DESIGN-SYSTEM.md`](../docs/DESIGN-SYSTEM.md:1).
> Schema reference: [`docs/SCHEMA.md`](../docs/SCHEMA.md:1).

This plan covers seven independent-but-related features. Most are **read-mostly aggregation** features on top of existing tables — only F7 (activity feed) and F4 (supplier performance) need a small amount of new persisted state. Execute F1 → F7 in order; each feature is shippable on its own.

---

## Cross-cutting prerequisites (do once before F1)

These pieces are reused by multiple F-tasks. Build them first.

- [ ] [`api/app/Common/Enums/ActivityType.php`](../api/app/Common/Enums/ActivityType.php:1) — backed enum (`transaction`, `approval`, `automation`, `alert`, `auth`) used by F7.
- [ ] [`api/app/Common/Services/ActivityFeedService.php`](../api/app/Common/Services/ActivityFeedService.php:1) — thin reader over [`audit_logs`](../docs/SCHEMA.md:30) joining `users`/`employees`. Drives F7 and the activity tab on detail pages.
- [ ] [`spa/src/components/ui/Calendar.tsx`](../spa/src/components/ui/Calendar.tsx:1) — primitive month/week/day grid (no events knowledge), used by F1 and F2 (Kanban backlog uses week view variant) and any future scheduling page. Pure grayscale canvas; event dots use semantic chip variants only.
- [ ] [`spa/src/components/ui/Kanban.tsx`](../spa/src/components/ui/Kanban.tsx:1) — generic column board primitive (columns + draggable cards, no domain logic). Used by F2.
- [ ] [`spa/src/components/ui/SlideOver.tsx`](../spa/src/components/ui/SlideOver.tsx:1) — right-side panel, 480px, used by F1 event detail, F5 employee card, F7 activity detail.
- [ ] [`spa/src/api/client.ts`](../spa/src/api/client.ts:1) audit: confirm `withCredentials: true` and CSRF flow already in place per [`CLAUDE.md`](../CLAUDE.md:82) (no Bearer tokens, no localStorage). No changes if compliant.
- [ ] [`spa/src/components/layout/Sidebar.tsx`](../spa/src/components/layout/Sidebar.tsx:1) — add 4 new top-level nav entries (Calendar, Approvals, Directory, Activity) wrapped in [`<CanDo>`](../spa/src/components/guards/CanDo.tsx:1) per [`docs/NEW-TASKS-V2.md`](../docs/NEW-TASKS-V2.md:937) Task R3.

---

## F1 — Calendar View (Cross-Module)

**Goal:** company-wide aggregated calendar; read-only window onto existing tables.

### Backend (no new tables — read-only aggregator)

- [ ] [`api/app/Modules/Calendar/Services/CalendarAggregatorService.php`](../api/app/Modules/Calendar/Services/CalendarAggregatorService.php:1) — single service that, given `from`/`to` and a layer set, returns a flat `CalendarEvent[]` from:
  - [`holidays`](../docs/SCHEMA.md:111) (filter by date range, including recurring expansion)
  - [`leave_requests`](../docs/SCHEMA.md:124) where `status = 'approved'` and `start_date <= to AND end_date >= from`, eager-load employee + department
  - [`deliveries`](../docs/SCHEMA.md:265) by `scheduled_date`
  - [`maintenance_work_orders`](../docs/SCHEMA.md:369) by `started_at`/`completed_at`
  - [`payroll_periods`](../docs/SCHEMA.md:131) `payroll_date`
  - [`work_orders`](../docs/SCHEMA.md:275) `planned_end`
  Each event normalized to `{ id (hash), type, title, start, end, link, color_variant, meta }`.
- [ ] [`api/app/Modules/Calendar/Resources/CalendarEventResource.php`](../api/app/Modules/Calendar/Resources/CalendarEventResource.php:1) — never returns raw integer IDs; `link` is the SPA URL (e.g. `/hr/leaves/{hash_id}`).
- [ ] [`api/app/Modules/Calendar/Requests/ListCalendarEventsRequest.php`](../api/app/Modules/Calendar/Requests/ListCalendarEventsRequest.php:1) — `from` / `to` (required, max 90-day window), `layers[]` (in: holiday/leave/delivery/maintenance/payroll/wo), `department_id?`. `authorize()` returns true for any authenticated user; per-layer permission filtering happens in the service (a user without `hr.leaves.view` does not get leave events).
- [ ] [`api/app/Modules/Calendar/Controllers/CalendarController.php`](../api/app/Modules/Calendar/Controllers/CalendarController.php:1) — single `GET /api/v1/calendar/events` action.
- [ ] [`api/app/Modules/Calendar/routes.php`](../api/app/Modules/Calendar/routes.php:1) — `auth:sanctum` only (per-layer permission inside service); register in [`api/app/Providers/ModuleServiceProvider.php`](../api/app/Providers/ModuleServiceProvider.php:1).
- [ ] Permission seed: add `calendar.view` to [`api/database/seeders/RolePermissionSeeder.php`](../api/database/seeders/RolePermissionSeeder.php:1) for all role slugs that already have any event-source view permission.
- [ ] Tests: [`api/tests/Feature/Calendar/CalendarAggregatorTest.php`](../api/tests/Feature/Calendar/CalendarAggregatorTest.php:1) — covers (a) returns events from each source, (b) respects `layers[]`, (c) **403 case**: user lacking `hr.leaves.view` does not see leave events, (d) date-window upper bound rejected at >90 days.

### Frontend

- [ ] [`spa/src/types/calendar.ts`](../spa/src/types/calendar.ts:1) — `CalendarEvent`, `CalendarLayer`, `CalendarRange` matching the resource exactly.
- [ ] [`spa/src/api/calendar.ts`](../spa/src/api/calendar.ts:1) — `calendarApi.events(params)`.
- [ ] [`spa/src/pages/calendar/index.tsx`](../spa/src/pages/calendar/index.tsx:1) — list/calendar page using the [`Calendar`](../spa/src/components/ui/Calendar.tsx:1) primitive. Mandatory 5 states per [`docs/PATTERNS.md`](../docs/PATTERNS.md:1522): skeleton → error → empty → data → stale (`placeholderData`).
  - Header: title, month/week/day toggle, Today button, prev/next, layer chips toggle, department filter [`Select`](../spa/src/components/ui/Select.tsx:1).
  - Events: 9px dots colored by [`Chip`](../spa/src/components/ui/Chip.tsx:1) variant mapping in [`docs/PATTERNS.md`](../docs/PATTERNS.md:843). Holiday=info, leave=neutral, delivery=info, maintenance=warning, payroll=success, wo_due=warning. **No raw colors in canvas.** Numbers and dates use `font-mono tabular-nums`.
  - Click event → [`SlideOver`](../spa/src/components/ui/SlideOver.tsx:1) with details and a "Open record" button → `navigate(event.link)`.
- [ ] [`spa/src/App.tsx`](../spa/src/App.tsx:1) — lazy import + route `/calendar` wrapped in `<AuthGuard>` + `<PermissionGuard permission="calendar.view">` (no module guard — calendar is cross-module).
- [ ] Tests: [`spa/src/pages/calendar/index.test.tsx`](../spa/src/pages/calendar/index.test.tsx:1) — render skeleton, render empty, render with events, layer toggle reissues query.

---

## F2 — Kanban Views for Approvals

**Goal:** central board for the current user's approvals across all approvable types.

### Backend

- [ ] [`api/app/Modules/Approvals/Services/ApprovalBoardService.php`](../api/app/Modules/Approvals/Services/ApprovalBoardService.php:1) — reads [`approval_records`](../docs/SCHEMA.md:40) joined to source ([`leave_requests`](../docs/SCHEMA.md:124), [`purchase_requests`](../docs/SCHEMA.md:238), [`purchase_orders`](../docs/SCHEMA.md:244), [`employee_loans`](../docs/SCHEMA.md:157), [`payroll_periods`](../docs/SCHEMA.md:131)). Returns 5 columns:
  - `submitted` — pending records before any approval step
  - `my_action` — records where the next pending step's `role_slug` is in current user's roles AND prior steps approved
  - `awaiting_others` — records the current user submitted that are still pending
  - `approved` — last 30 days, current user submitted or approved
  - `rejected` — last 30 days, current user submitted or actioned
  Each card: `{ id (hash), type, number, title, summary, urgency_age_days, requestor_name, amount?, link, actions: ['approve'|'reject'|null] }`.
- [ ] [`api/app/Modules/Approvals/Resources/ApprovalCardResource.php`](../api/app/Modules/Approvals/Resources/ApprovalCardResource.php:1).
- [ ] [`api/app/Modules/Approvals/Controllers/ApprovalBoardController.php`](../api/app/Modules/Approvals/Controllers/ApprovalBoardController.php:1) — `GET /api/v1/approvals/board?type=all|leave|pr|po|loan|payroll&sort=urgency|date`.
- [ ] **Reuse** existing approve/reject actions on each module (do NOT centralize the action endpoints — the per-entity controllers already enforce per-type permission and run the workflow). The Kanban only POSTs to those existing endpoints.
- [ ] [`api/app/Modules/Approvals/routes.php`](../api/app/Modules/Approvals/routes.php:1) — single GET endpoint, `auth:sanctum` + `permission:approvals.board.view`.
- [ ] Permission seed: `approvals.board.view` for every role that has at least one approve permission.
- [ ] Tests: [`api/tests/Feature/Approvals/ApprovalBoardTest.php`](../api/tests/Feature/Approvals/ApprovalBoardTest.php:1) — column placement correctness; 403 for user without `approvals.board.view`.

### Frontend

- [ ] [`spa/src/types/approvals.ts`](../spa/src/types/approvals.ts:1) — `ApprovalCard`, `ApprovalColumn`, `ApprovalBoard`.
- [ ] [`spa/src/api/approvals.ts`](../spa/src/api/approvals.ts:1) — `approvalsApi.board(params)`, plus thin wrappers `approvalsApi.approve(type, id)` / `reject(type, id, remarks)` that route to the existing per-type endpoint.
- [ ] [`spa/src/pages/approvals/index.tsx`](../spa/src/pages/approvals/index.tsx:1) — Kanban using the new primitive. All 5 page states. Filters: type pills, sort dropdown.
  - Cards: number monospace, status chip, age in days mono. Approve/Reject inline buttons gated by [`<CanDo>`](../spa/src/components/guards/CanDo.tsx:1) per type. Reject opens [`ReasonDialog`](../spa/src/components/ui/ReasonDialog.tsx:1) (already exists). On success: `queryClient.invalidateQueries(['approvals','board'])` + toast.
  - WebSocket: subscribe to `private-approvals.user.{hashId}` channel (broadcast on approve/reject events) for live updates. Falls back to 30s polling if no Echo.
- [ ] [`spa/src/App.tsx`](../spa/src/App.tsx:1) — lazy `/approvals` route with `<PermissionGuard permission="approvals.board.view">`.
- [ ] Tests: [`spa/src/pages/approvals/index.test.tsx`](../spa/src/pages/approvals/index.test.tsx:1).

---

## F3 — Inventory Stock Card

**Goal:** per-item movement ledger with running balance.

### Backend (no schema change — already have [`stock_movements`](../docs/SCHEMA.md:222))

- [ ] [`api/app/Modules/Inventory/Services/StockCardService.php`](../api/app/Modules/Inventory/Services/StockCardService.php:1):
  - `card(Item $item, Carbon $from, Carbon $to, ?int $locationId = null): array`
  - Computes opening balance = sum of all movements before `$from`.
  - Streams movements in `$from..$to` ordered ASC, computes running balance + running weighted-avg cost (per [`docs/PATTERNS.md`](../docs/PATTERNS.md:212) Service Pattern, eager-load `fromLocation`, `toLocation`, `createdBy`, polymorphic `reference`).
  - Closing balance = last running balance.
  - Each row resolves a `reference_url` from `reference_type` + `reference_id` so the SPA can link.
- [ ] [`api/app/Modules/Inventory/Resources/StockCardRowResource.php`](../api/app/Modules/Inventory/Resources/StockCardRowResource.php:1) and `StockCardResource` — `id` is `hash_id`; quantities as decimal strings.
- [ ] [`api/app/Modules/Inventory/Controllers/StockCardController.php`](../api/app/Modules/Inventory/Controllers/StockCardController.php:1) — `GET /api/v1/inventory/items/{item}/stock-card?from=&to=&location_id=`.
- [ ] [`api/app/Modules/Inventory/Exports/StockCardExport.php`](../api/app/Modules/Inventory/Exports/StockCardExport.php:1) (Maatwebsite\Excel) — same shape as the page; per [`docs/NEW-TASKS-V2.md`](../docs/NEW-TASKS-V2.md:721) Task E2 column conventions (human-readable headers, resolved relations, formatted dates).
- [ ] Routes: `inventory.items.stock_card.view` permission middleware on the existing items route group.
- [ ] Permission seed: add to roles already holding `inventory.items.view`.
- [ ] Tests: [`api/tests/Feature/Inventory/StockCardServiceTest.php`](../api/tests/Feature/Inventory/StockCardServiceTest.php:1) — opening balance correctness, running balance, weighted-avg recalculation on receipt, reference URL resolution.

### Frontend

- [ ] [`spa/src/types/inventory.ts`](../spa/src/types/inventory.ts:1) — extend with `StockCardRow`, `StockCardSummary`.
- [ ] [`spa/src/api/inventory.ts`](../spa/src/api/inventory.ts:1) — `inventoryApi.stockCard(itemId, params)`.
- [ ] [`spa/src/pages/inventory/items/[id]/stock-card.tsx`](../spa/src/pages/inventory/items/[id]/stock-card.tsx:1) — uses [`PageHeader`](../spa/src/components/layout/PageHeader.tsx:1) with date-range, location filter, Export CSV, Print buttons. Table: 32-px rows; columns Date, Ref No (mono link), Movement, In, Out, Balance — all numerics `font-mono tabular-nums` right-aligned. Opening/Closing summary boxes use [`StatCard`](../spa/src/components/ui/StatCard.tsx:1). All 5 page states.
- [ ] [`spa/src/App.tsx`](../spa/src/App.tsx:1) — route `/inventory/items/:id/stock-card` with `<PermissionGuard permission="inventory.items.stock_card.view">`. Add a "Stock Card" tab/link from the existing item detail page.
- [ ] Tests: skeleton, empty (no movements in range), data with computed running balance.

---

## F4 — Supplier Performance Dashboard

**Goal:** per-supplier KPIs computed from existing [`purchase_orders`](../docs/SCHEMA.md:244), [`goods_receipt_notes`](../docs/SCHEMA.md:225), [`inspections`](../docs/SCHEMA.md:351). Score persisted for fast list rendering and alerting.

### Backend

- [ ] Migration `0NNN_create_supplier_performance_snapshots_table.php` (next free number after `0031`):
  ```
  id, vendor_id (FK vendors cascade), period_year (int), period_month (int),
  on_time_delivery_rate (decimal 5,2 nullable),
  quality_pass_rate (decimal 5,2 nullable),
  price_variance_pct (decimal 5,2 nullable),
  lead_time_variance_days (decimal 5,2 nullable),
  overall_score (decimal 5,2 nullable),
  po_count (int default 0),
  grn_count (int default 0),
  computed_at (timestamp),
  timestamps,
  UNIQUE(vendor_id, period_year, period_month),
  INDEX(period_year, period_month),
  INDEX(overall_score)
  ```
  Implement `down()`. Update [`docs/SCHEMA.md`](../docs/SCHEMA.md:233) Purchasing section.
- [ ] [`api/app/Modules/Purchasing/Models/SupplierPerformanceSnapshot.php`](../api/app/Modules/Purchasing/Models/SupplierPerformanceSnapshot.php:1) with [`HasHashId`](../api/tests/Unit/HasHashIdTest.php:1) + [`HasAuditLog`](../api/app/Common/Traits/HasAuditLog.php:1) traits, decimal casts.
- [ ] [`api/app/Modules/Purchasing/Services/SupplierPerformanceService.php`](../api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:1):
  - `compute(Vendor $vendor, int $year, int $month): SupplierPerformanceSnapshot` — wrapped in `DB::transaction()`. Formulas per [`docs/NEW-TASKS-V2.md`](../docs/NEW-TASKS-V2.md:1314).
  - `forVendor(Vendor $vendor, int $months = 6): Collection` — last N snapshots for trend.
  - `recomputeAll(int $year, int $month)` — used by scheduled job.
  - Emits `SupplierScoreDropped` event when overall_score < 80.
- [ ] [`api/app/Modules/Purchasing/Jobs/RecomputeSupplierPerformanceJob.php`](../api/app/Modules/Purchasing/Jobs/RecomputeSupplierPerformanceJob.php:1) — monthly schedule in [`api/routes/console.php`](../api/routes/console.php:1) on the 1st at 02:00.
- [ ] [`api/app/Modules/Purchasing/Resources/SupplierPerformanceResource.php`](../api/app/Modules/Purchasing/Resources/SupplierPerformanceResource.php:1) — hash IDs, percentages as numbers.
- [ ] [`api/app/Modules/Purchasing/Controllers/SupplierPerformanceController.php`](../api/app/Modules/Purchasing/Controllers/SupplierPerformanceController.php:1):
  - `GET /api/v1/purchasing/vendors/{vendor}/performance?months=6`
  - `POST /api/v1/purchasing/vendors/{vendor}/performance/recompute` (admin only)
- [ ] Routes wired in [`api/app/Modules/Purchasing/routes.php`](../api/app/Modules/Purchasing/routes.php:1) with `permission:purchasing.suppliers.performance.view`.
- [ ] Listener: `SupplierScoreDroppedNotification` → in-app + email to users with `purchasing.alerts.receive`.
- [ ] Permission seed: `purchasing.suppliers.performance.view` for purchasing_officer, finance_officer, plant_manager, system_admin.
- [ ] Tests: [`api/tests/Feature/Purchasing/SupplierPerformanceServiceTest.php`](../api/tests/Feature/Purchasing/SupplierPerformanceServiceTest.php:1) — formulas (on-time, quality pass, price variance, lead-time, overall), event fires when score < 80, idempotent recompute.

### Frontend

- [ ] [`spa/src/types/purchasing.ts`](../spa/src/types/purchasing.ts:1) — `SupplierPerformance`, `SupplierPerformanceSnapshot`.
- [ ] [`spa/src/api/purchasing.ts`](../spa/src/api/purchasing.ts:1) — `purchasingApi.supplierPerformance(vendorId, months)`.
- [ ] [`spa/src/pages/purchasing/suppliers/[id]/performance.tsx`](../spa/src/pages/purchasing/suppliers/[id]/performance.tsx:1) — KPI row of [`StatCard`](../spa/src/components/ui/StatCard.tsx:1)s (4 metrics + overall), 6-month trend line chart, comparison bar chart. All 5 states. Numbers `font-mono tabular-nums`. Score uses [`Chip`](../spa/src/components/ui/Chip.tsx:1) variant: ≥95 success, 85–94 info, 80–84 warning, <80 danger.
- [ ] Update [`spa/src/pages/purchasing/suppliers/index.tsx`](../spa/src/pages/purchasing/suppliers/index.tsx:1) (existing approved supplier list) to add a "Score" column showing latest snapshot score chip.
- [ ] [`spa/src/App.tsx`](../spa/src/App.tsx:1) — route `/purchasing/suppliers/:id/performance` with `<ModuleGuard module="purchasing">` + `<PermissionGuard permission="purchasing.suppliers.performance.view">`.
- [ ] Charting: use existing chart library (whatever the codebase already uses for dashboard); do NOT add a new dep.
- [ ] Tests: [`spa/src/pages/purchasing/suppliers/[id]/performance.test.tsx`](../spa/src/pages/purchasing/suppliers/[id]/performance.test.tsx:1).

---

## F5 — Employee Directory & Org Chart

**Goal:** searchable directory with grid/list/org-chart views; reuses [`employees`](../docs/SCHEMA.md:81), [`departments`](../docs/SCHEMA.md:75), [`positions`](../docs/SCHEMA.md:78).

### Backend

- [ ] Add a thin `directory` action on the existing employee module rather than a parallel module:
  - [`api/app/Modules/HR/Controllers/EmployeeDirectoryController.php`](../api/app/Modules/HR/Controllers/EmployeeDirectoryController.php:1) — `GET /api/v1/hr/directory` returns flat list (paginated 100/page, eager-load department + position) and `GET /api/v1/hr/directory/org-chart` returns nested tree built from `departments.head_employee_id` + `positions.department_id`.
- [ ] [`api/app/Modules/HR/Services/DirectoryService.php`](../api/app/Modules/HR/Services/DirectoryService.php:1) — search across `first_name`/`last_name`/`employee_no`/`position.title`/`department.name`. Status filter (`active` only by default).
- [ ] [`api/app/Modules/HR/Resources/EmployeeDirectoryResource.php`](../api/app/Modules/HR/Resources/EmployeeDirectoryResource.php:1) — **slim** projection: `id` (hash), `full_name`, `employee_no`, `position`, `department`, `photo_path`, `extension`, `email_company`, `mobile` masked, `status`. Sensitive fields (SSS/TIN/bank) NOT included regardless of permission.
- [ ] Routes wired in [`api/app/Modules/HR/routes.php`](../api/app/Modules/HR/routes.php:1) under `permission:hr.directory.view` (cheap permission distinct from full `hr.employees.view`; allows lighter-trust roles to see the directory without reading the full employee record).
- [ ] Permission seed: `hr.directory.view` for ALL non-employee internal roles + `employee` (yes — workers can look up coworker extension).
- [ ] Tests: [`api/tests/Feature/HR/DirectoryTest.php`](../api/tests/Feature/HR/DirectoryTest.php:1) — search hits, sensitive-fields-not-leaked, employee role can list.

### Frontend

- [ ] [`spa/src/types/hr.ts`](../spa/src/types/hr.ts:1) — extend with `DirectoryEmployee`, `OrgNode`.
- [ ] [`spa/src/api/hr.ts`](../spa/src/api/hr.ts:1) — `hrApi.directory(params)`, `hrApi.orgChart()`.
- [ ] [`spa/src/pages/hr/directory/index.tsx`](../spa/src/pages/hr/directory/index.tsx:1):
  - View toggle: Grid / List / Org Chart.
  - Search box (debounced 250ms), department filter.
  - Grid: cards grouped by department (visual grouping only — backend stays flat). Card shows photo via [`Avatar`](../spa/src/components/ui/Avatar.tsx:1) with initials fallback, name, position, shift name (joined client-side from current shift assignment if cheap, else omit), status [`Chip`](../spa/src/components/ui/Chip.tsx:1), extension `font-mono`.
  - List: dense [`DataTable`](../spa/src/components/ui/DataTable.tsx:1) at 32px rows, columns Name, Position, Department, Extension (mono), Status.
  - Org Chart: SVG tree (no new dep — render with simple recursive divs + CSS connectors). Click node opens [`SlideOver`](../spa/src/components/ui/SlideOver.tsx:1) with directory card; "Open full profile" button gated by `<CanDo permission="hr.employees.view">`.
  - All 5 page states.
- [ ] [`spa/src/App.tsx`](../spa/src/App.tsx:1) — route `/hr/directory` with `<ModuleGuard module="hr">` + `<PermissionGuard permission="hr.directory.view">`.
- [ ] Tests: search, view-toggle, slide-over open.

---

## F6 — Bulk Operations Center

**Goal:** structured bulk actions on existing list pages. **Not** a new page — it is a pattern + a few endpoints.

### Backend

For each domain below, add a single batch endpoint that accepts an array of hash IDs, validates each, and runs the action inside one `DB::transaction()`. Return a per-id success/failure map (HTTP 207 multi-status if any fail).

- [ ] **HR**:
  - `POST /api/v1/hr/employees/bulk-shift-assign` — body: `{ employee_ids: string[], shift_id, effective_date }`. Service [`api/app/Modules/HR/Services/BulkShiftAssignmentService.php`](../api/app/Modules/HR/Services/BulkShiftAssignmentService.php:1) writes [`employee_shift_assignments`](../docs/SCHEMA.md:103) closing prior open assignments.
  - `POST /api/v1/hr/employees/bulk-leave-credit` — body: `{ employee_ids, leave_type_id, year, credits, reason }`. Updates [`employee_leave_balances`](../docs/SCHEMA.md:122). Used at year-start.
  - `POST /api/v1/hr/employees/bulk-status` — body: `{ employee_ids, status, effective_date, remarks }`. Writes [`employment_history`](../docs/SCHEMA.md:87).
  - `POST /api/v1/hr/employees/bulk-salary-adjust` — body: `{ department_id, percent_increase, effective_date, reason }`. Generates an approval workflow record (does NOT apply directly — VP must approve).
- [ ] **Payroll**:
  - `POST /api/v1/payroll/periods/{period}/bulk-payslip-email` — emails finalized payslips. Service queues a job per payslip.
  - `POST /api/v1/payroll/bank-files/combine` — body: `{ period_ids: string[] }` → produces one combined CSV.
- [ ] **Inventory**:
  - `POST /api/v1/inventory/stock-adjustments/bulk` — multiple adjustments (one journal entry per warehouse).
  - `POST /api/v1/inventory/items/bulk-reorder-point` — body: `{ category_id, reorder_point, safety_stock }`.
- [ ] **Quality**:
  - `POST /api/v1/quality/ncrs/bulk-close` — body: `{ ncr_ids: string[], resolution_note }`.
- [ ] Each endpoint:
  - Has its own [`FormRequest`](../docs/PATTERNS.md:340) with `authorize()` checking the same permission as the singular action (e.g. `hr.employees.bulk_shift` mapping to `hr.shifts.assign`).
  - Wraps the loop in `DB::transaction()` and rolls back on first hard failure unless `?continue_on_error=1`.
  - Logs each affected entity to [`audit_logs`](../docs/SCHEMA.md:30).
- [ ] Permission seeds: add `*.bulk` permissions, granted to the same roles that already have the singular ones plus `system_admin`.
- [ ] Tests: one feature test per bulk endpoint, including the partial-failure 207 case.

### Frontend

- [ ] Extend [`spa/src/components/ui/DataTable.tsx`](../spa/src/components/ui/DataTable.tsx:1) with a `bulkActions?: BulkAction[]` prop. When rows are selected, render a sticky bottom bar:
  ```
  ┌────────────────────────────────────────────────┐
  │ 5 selected   [Bulk Actions ▾]   [Clear]        │
  └────────────────────────────────────────────────┘
  ```
  Bar uses canvas + 0.5px top border, no color. Buttons gated with [`<CanDo>`](../spa/src/components/guards/CanDo.tsx:1).
- [ ] [`spa/src/components/ui/BulkActionDialog.tsx`](../spa/src/components/ui/BulkActionDialog.tsx:1) — generic confirmation dialog: shows count, action summary, optional parameter form (e.g. shift selector for bulk-shift-assign), Confirm button disabled while pending, success/error toast, server-side error mapping (server returns per-id failures → render a small failure list).
- [ ] Wire bulk actions into:
  - [`spa/src/pages/hr/employees/index.tsx`](../spa/src/pages/hr/employees/index.tsx:1) — Assign Shift, Add Leave Credit, Change Status, Salary Adjustment.
  - [`spa/src/pages/payroll/periods/index.tsx`](../spa/src/pages/payroll/periods/index.tsx:1) — Email Payslips, Combine Bank Files.
  - [`spa/src/pages/inventory/items/index.tsx`](../spa/src/pages/inventory/items/index.tsx:1) — Adjust Stock, Update Reorder Points.
  - [`spa/src/pages/quality/ncrs/index.tsx`](../spa/src/pages/quality/ncrs/index.tsx:1) — Bulk Close.
- [ ] Tests: render bulk bar when rows selected, dialog opens, success path invalidates list query.

---

## F7 — System Activity Feed (Audit Dashboard)

**Goal:** real-time company-wide stream of business-relevant events. Aggregates [`audit_logs`](../docs/SCHEMA.md:30) plus a small `activity_events` table for high-level events the audit log doesn't naturally capture (chain milestones, automation runs).

### Backend

- [ ] Migration `0NNN_create_activity_events_table.php`:
  ```
  id, type (string 30 — see ActivityType enum), action (string 50),
  actor_user_id (FK users nullable), actor_type (string 20: 'user'|'system'),
  subject_type (string 100), subject_id (bigint nullable),
  summary (string 200), detail (json nullable),
  link (string 200 nullable), severity (string 10: 'info'|'success'|'warning'|'danger'),
  ip_address (string 45 nullable), created_at,
  INDEX (created_at DESC), INDEX (type), INDEX (actor_user_id), INDEX (subject_type, subject_id)
  ```
  Implement `down()`. Update [`docs/SCHEMA.md`](../docs/SCHEMA.md:1) AUTH & SYSTEM section.
- [ ] [`api/app/Common/Models/ActivityEvent.php`](../api/app/Common/Models/ActivityEvent.php:1) with [`HasHashId`](../api/tests/Unit/HasHashIdTest.php:1).
- [ ] [`api/app/Common/Services/ActivityFeedService.php`](../api/app/Common/Services/ActivityFeedService.php:1):
  - `record(string $type, string $action, $subject, string $summary, array $detail = [], ?string $link = null, string $severity = 'info'): ActivityEvent` — wraps in `DB::transaction()`, fills actor from `auth()->user()`.
  - `feed(array $filters): LengthAwarePaginator` — eager-load actor; filter by `type`, `actor_user_id`, `subject_type`, `from`/`to`, `severity`.
- [ ] Wire `record(...)` calls into the existing chain listeners ([`api/app/Modules/Quality/Listeners/CreateDeliveryDraftOnQcPass.php`](../api/app/Modules/Quality/Listeners/CreateDeliveryDraftOnQcPass.php:1), [`TriggerOutgoingQC`](../api/app/Modules/Quality/Listeners/TriggerOutgoingQC.php:1), [`TriggerIncomingQC`](../api/app/Modules/Quality/Listeners/TriggerIncomingQC.php:1), [`RejectGRNOnQcFail`](../api/app/Modules/Quality/Listeners/RejectGRNOnQcFail.php:1), and chain listeners added by Series C tasks). Each listener writes one `ActivityEvent`.
- [ ] Broadcast event `CompanyActivityRecorded` on a public channel `company.activity` (signed-broadcaster authentication; restrict on the SPA to users with `admin.activity.view`).
- [ ] [`api/app/Modules/Admin/Resources/ActivityEventResource.php`](../api/app/Modules/Admin/Resources/ActivityEventResource.php:1) — hash IDs, ISO timestamps, `actor` nested as `{ id, name, email }`.
- [ ] [`api/app/Modules/Admin/Controllers/ActivityFeedController.php`](../api/app/Modules/Admin/Controllers/ActivityFeedController.php:1) — `GET /api/v1/admin/activity` (paginated), `GET /api/v1/admin/activity/export` (CSV per Task E2 conventions).
- [ ] Routes wired with `permission:admin.activity.view`.
- [ ] Permission seed: `admin.activity.view` for `system_admin`, `auditor`, `vp` only.
- [ ] Tests: [`api/tests/Feature/Admin/ActivityFeedTest.php`](../api/tests/Feature/Admin/ActivityFeedTest.php:1) — recording, filtering, 403 for non-admin, broadcast fires.

### Frontend

- [ ] [`spa/src/types/admin.ts`](../spa/src/types/admin.ts:1) — extend with `ActivityEvent`.
- [ ] [`spa/src/api/admin.ts`](../spa/src/api/admin.ts:1) — `adminApi.activity(params)`, `adminApi.activityExport(params)`.
- [ ] [`spa/src/pages/admin/activity/index.tsx`](../spa/src/pages/admin/activity/index.tsx:1) — chronological feed.
  - Header: filters (user, module/type, action, date range), Export, "Live" indicator.
  - Stream: each row is `<ActivityRow>` — colored dot using semantic `Chip` variants (info/success/warning/danger), 11px primary text + 10px mono time + summary detail line + link. Spacing tight per [`docs/DESIGN-SYSTEM.md`](../docs/DESIGN-SYSTEM.md:642) ActivityStream spec.
  - WebSocket: subscribe to `company.activity`. On new event: `queryClient.setQueryData` to prepend, capped at 200 in-memory.
  - All 5 page states. Numbers/dates `font-mono tabular-nums`.
- [ ] [`spa/src/App.tsx`](../spa/src/App.tsx:1) — route `/admin/activity` with `<PermissionGuard permission="admin.activity.view">`.
- [ ] Tests: render feed, filter applies, websocket prepend, 403 fallback when permission missing.

---

## Verification gate (run before claiming completion of any F-task)

Per [`.roo/skills/kwatog/code-quality-gate.md`](../.roo/skills/kwatog/code-quality-gate.md) and the final checklist in [`docs/PATTERNS.md`](../docs/PATTERNS.md:1716):

- [ ] `cd api && composer lint && composer test` — green.
- [ ] `cd spa && npm run lint && npm run typecheck && npm run test` — green.
- [ ] Every model touched has [`HasHashId`](../api/tests/Unit/HasHashIdTest.php:1) and resources return `hash_id`.
- [ ] Every new endpoint has a 403-without-permission test ([`docs/NEW-TASKS-V2.md`](../docs/NEW-TASKS-V2.md:1) testing-strategy minimum).
- [ ] Every new SPA page renders the 5 mandatory states.
- [ ] Every form has Zod schema, disabled-on-submit, server-error mapping, cancel button, success/error toast.
- [ ] Every new route is wrapped in `<AuthGuard>` + (if module-scoped) `<ModuleGuard>` + `<PermissionGuard>`.
- [ ] Every numeric/ID/date in tables uses `font-mono tabular-nums`.
- [ ] Every status uses [`<Chip>`](../spa/src/components/ui/Chip.tsx:1) — no inline color in canvas/text/borders.
- [ ] Migrations are numbered after the highest existing one (`0031_*` is current top; new ones start `0032_*`).
- [ ] [`docs/SCHEMA.md`](../docs/SCHEMA.md:1) updated for any new table.
- [ ] Permission seeds added for every new permission slug in [`api/database/seeders/RolePermissionSeeder.php`](../api/database/seeders/RolePermissionSeeder.php:1) — otherwise existing prod users get 403s.

---

## Suggested execution order with checkpoints

1. **Cross-cutting prereqs** → commit `feat(ui): add Calendar/Kanban/SlideOver primitives + ActivityFeedService scaffold`.
2. **F1 Calendar** → `feat(calendar): cross-module calendar view`.
3. **F2 Approvals Kanban** → `feat(approvals): kanban board for current user approvals`.
4. **F3 Stock Card** → `feat(inventory): per-item stock card with running balance`.
5. **F4 Supplier Performance** → migration first, backend, scheduled job, frontend → `feat(purchasing): supplier performance dashboard`.
6. **F5 Directory** → `feat(hr): employee directory and org chart`.
7. **F6 Bulk Operations** → DataTable extension first, then per-domain endpoints/dialogs → `feat(ux): bulk operations on list pages`.
8. **F7 Activity Feed** → migration, service, listener wiring, frontend → `feat(admin): system activity feed`.

Each step ends with the verification gate, a green CI run, and a PR per [`.roo/skills/kwatog/commit-and-pr.md`](../.roo/skills/kwatog/commit-and-pr.md).

---

## Risks and notes

- **Architect mode cannot write code.** Implementation requires switching to `code` mode (or `superpowers-tdd` for new feature work, `kwatog-quality-gate` for the strongest gate enforcement). The user is to switch modes themselves.
- **F4** is the only F-task that adds significant new computation. The monthly recompute job must be idempotent (UNIQUE on `vendor_id, year, month`).
- **F7** broadcast channel volume could be high. Throttle on the SPA side (max 200 in-memory, debounce websocket bursts at 200ms).
- **F5** org-chart rendering via simple recursive divs is sufficient for ~250 employees. If the company grows past ~1000 employees per department, swap to a proper tree library; not needed now.
- **F6** bulk salary adjustment intentionally creates an approval workflow record instead of applying directly — mirrors existing approval discipline ([`docs/PATTERNS.md`](../docs/PATTERNS.md:1434) section 15).
- No new third-party dependencies. Reuse existing libs (Tanstack Query, react-hook-form, zod, axios, Echo/Reverb, Maatwebsite\Excel, DomPDF, lucide-react, the existing chart lib).
