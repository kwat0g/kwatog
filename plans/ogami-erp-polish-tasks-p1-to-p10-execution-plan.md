# Ogami ERP — Polish Tasks P1–P10 Execution Plan

> Source: [`docs/NEW-TASKS.md`](docs/NEW-TASKS.md:253) §"POLISH TASKS (P-Series)"
> Constraints: [`CLAUDE.md`](CLAUDE.md:1), [`docs/PATTERNS.md`](docs/PATTERNS.md:1), [`docs/DESIGN-SYSTEM.md`](docs/DESIGN-SYSTEM.md:1)
> Mode separation: this plan is produced in **Architect** mode. Implementation must run in **Code** mode.

---

## 0. Codebase reality check (informs every task)

The following primitives already exist and must be **reused, not recreated**:

| Primitive | Location | Status |
|---|---|---|
| `ChainHeader` | [`spa/src/components/chain/ChainHeader.tsx`](spa/src/components/chain/ChainHeader.tsx:1) | Exists, used on SO, WO, PR, PO, GRN, NCR, complaints, deliveries, inspections, payroll periods, separations, loans, invoices, bills, maintenance WOs |
| `LinkedRecords` | [`spa/src/components/chain/LinkedRecords.tsx`](spa/src/components/chain/LinkedRecords.tsx:1) | Exists, used on SO, WO, deliveries, NCR, complaints, payroll periods, inspections |
| `ActivityStream` | [`spa/src/components/chain/ActivityStream.tsx`](spa/src/components/chain/ActivityStream.tsx:1) | Exists |
| `ApprovalService` + `ApprovalRecord` | [`api/app/Common/Services/ApprovalService.php`](api/app/Common/Services/ApprovalService.php:1) | Exists, drives PR/PO/Loan/Leave |
| `HasApprovalWorkflow` | [`api/app/Common/Traits/HasApprovalWorkflow.php`](api/app/Common/Traits/HasApprovalWorkflow.php:1) | Exists |
| `NotificationService` + `UserNotificationService` | [`api/app/Common/Services/NotificationService.php`](api/app/Common/Services/NotificationService.php:1) | Exists |
| `NotificationBell` | [`spa/src/components/layout/NotificationBell.tsx`](spa/src/components/layout/NotificationBell.tsx:1) | Exists; needs real-time + grouping |
| `AuditLog` model + controller | [`api/app/Common/Models/AuditLog.php`](api/app/Common/Models/AuditLog.php:1), [`api/app/Modules/Admin/Controllers/AuditLogController.php`](api/app/Modules/Admin/Controllers/AuditLogController.php:1) | Exists with `buildDiff` already; needs UX polish + CSV export |
| `CommandPalette` (Cmd+K search) | [`spa/src/components/ui/CommandPalette.tsx`](spa/src/components/ui/CommandPalette.tsx:1) | Exists; needs Meilisearch backing + grouped UI |
| `OeeGauge` | [`spa/src/components/production/OeeGauge.tsx`](spa/src/components/production/OeeGauge.tsx:1) | Exists; needs full report page wrapper |
| Self-service pages | [`spa/src/pages/self-service/`](spa/src/pages/self-service/me.tsx:1) | Exist as desktop layout; need mobile pass |
| `chain` types | [`spa/src/types/chain.ts`](spa/src/types/chain.ts:1) | `ChainStep`, `LinkedGroup` shapes already defined |

**Implication:** ~70% of P-series work is auditing existing pages and filling gaps, not net-new components. Treat each task as a checklist sweep with one or two new artifacts.

---

## 1. Cross-cutting rules (apply to every task below)

Pulled from [`CLAUDE.md`](CLAUDE.md:507) §"Rules" and [`docs/PATTERNS.md`](docs/PATTERNS.md:1716) "Final Checklist":

- **Backend**: every new model gets `HasHashId` + `HasAuditLog`; every Resource returns `hash_id` (string), never raw `id`. Money columns are `decimal(15,2)`. Mutations wrapped in `DB::transaction()`. Routes go through `auth:sanctum` + `feature:<module>` + `permission:<perm>` middleware. FormRequest::authorize() checks the same permission.
- **Frontend**: every page handles all 5 states (loading skeleton, error empty-state with retry, empty empty-state with contextual CTA, data, stale via TanStack `placeholderData`). Every form has Zod schema, disabled-while-pending submit, server 422 mapping via `setError`, cancel button, success/error toast, queryClient invalidation. Every route lazy-loaded and wrapped in AuthGuard + ModuleGuard + PermissionGuard.
- **Design tokens**: only the 6 accent colors on chips/buttons/deltas/links/alert dots; canvas is grayscale. Numbers/IDs/dates use `font-mono tabular-nums`. Tables 32px row height. Headers uppercase letter-spaced 10px muted.
- **Auth**: HTTP-only Sanctum cookies; never Bearer tokens, never localStorage.
- **Git**: each task gets its own branch + PR (one PR per task), conventional commit `feat: P{N} — <description>`. Wait for CI green via `gh pr checks` before next task. Push to `OWNER/REPO` per repo-targeting rules.

Sequence per task: read NEW-TASKS.md task body → read relevant SCHEMA.md tables → identify PATTERNS.md template → backend slice (migration → enum → model → service → request → resource → controller → routes) → frontend slice (types → api → page → route registration) → manual verification of the 5 states + the design checklist → commit/PR.

---

## 2. Task-by-task plan

### P1 — ChainHeader on ALL chain records (consistency sweep)

**Goal**: every chain detail page renders a `ChainHeader` whose active step derives from `record.status` and refetches on mutation.

**Audit matrix** (verify each; add ChainHeader if missing, fix step derivation if wrong):

| Page | File to modify | Steps |
|---|---|---|
| Sales Order detail | [`spa/src/pages/crm/sales-orders/detail.tsx`](spa/src/pages/crm/sales-orders/detail.tsx:1) | Order Entered → MRP → Scheduled → In Production → QC → Delivered → Invoiced → Collected |
| Work Order detail | [`spa/src/pages/production/work-orders/detail.tsx`](spa/src/pages/production/work-orders/detail.tsx:1) | Planned → Confirmed → Materials Issued → In Progress → Completed → QC Passed → Closed |
| Purchase Order detail | [`spa/src/pages/purchasing/purchase-orders/detail.tsx`](spa/src/pages/purchasing/purchase-orders/detail.tsx:1) | PR Created → Approved → PO Sent → Shipment → GRN → QC Passed → Billed → Paid |
| GRN detail | [`spa/src/pages/inventory/grn/detail.tsx`](spa/src/pages/inventory/grn/detail.tsx:1) | Received → QC Pending → QC Passed → Stocked |
| Leave Request detail | `spa/src/pages/leaves/detail.tsx` (verify path) | Submitted → Dept Head → HR → Approved → Deducted |
| Loan detail | [`spa/src/pages/loans/detail.tsx`](spa/src/pages/loans/detail.tsx:1) | Applied → Approved → Active → Paid Off |
| NCR detail | [`spa/src/pages/quality/ncrs/detail.tsx`](spa/src/pages/quality/ncrs/detail.tsx:1) | Raised → QC Head Review → Disposition → Corrective Action → Closed |
| Delivery detail | [`spa/src/pages/supply-chain/deliveries/detail.tsx`](spa/src/pages/supply-chain/deliveries/detail.tsx:1) | Scheduled → Loading → In Transit → Delivered → Confirmed |

**Approach**:
1. Centralize chain-step builders in [`spa/src/lib/chains/`](spa/src/lib/chains/sales-order.ts:1) (one file per chain): `salesOrderChain(record)`, `workOrderChain`, `purchaseOrderChain`, `grnChain`, `leaveChain`, `loanChain`, `ncrChain`, `deliveryChain`. Each returns `ChainStep[]` with `state: 'done' | 'active' | 'pending'` derived from status enum + relevant timestamps.
2. Add `dates` (`completed_at`, `qc_passed_at`, `delivered_at`, etc.) to each detail-page API response if missing. Cross-reference [`docs/SCHEMA.md`](docs/SCHEMA.md:1) for the column; add it via API Resource only — no migrations needed since timestamps already exist on most tables.
3. Each detail page imports the builder and renders `<ChainHeader steps={builder(data)} />` in the `bottom` slot of `<PageHeader>` (same pattern already used in [`spa/src/pages/crm/sales-orders/detail.tsx`](spa/src/pages/crm/sales-orders/detail.tsx:137)).
4. **Refetch**: every mutation on the page calls `queryClient.invalidateQueries({ queryKey: [<entity>, id] })` so the chain re-derives. This is already the pattern, but verify each page.

**Files created**: `spa/src/lib/chains/{sales-order,work-order,purchase-order,grn,leave,loan,ncr,delivery}.ts` (8 files).

**Files modified**: the 8 detail pages above (only where missing/stale).

**Acceptance**:
- Visit each detail page; chain header visible in `bottom` of PageHeader.
- Active step matches `record.status`. Done steps show date underneath in `font-mono tabular-nums`.
- Approve/reject/transition action causes header to advance without page reload.

---

### P2 — LinkedRecords on ALL chain records

**Goal**: right-side panel listing all related records, grouped by type.

**Audit matrix**:

| Page | Linked groups required |
|---|---|
| Sales Order | MRP Plan, Work Orders, QC Inspections, Deliveries, Invoice |
| Purchase Order | Source PR, GRNs, QC Incoming Inspection, Bill, Payment |
| Work Order | Sales Order (parent), Material Issue Slips, Production Output entries, QC In-Process, Machine, Mold |
| Leave Request | Employee, Approval Records (already in `ApprovalTimeline` per P3), Payroll deduction (if posted) |
| Loan | Employee, Approval Records, Payroll deductions list |
| NCR | Source inspection, replacement WO (already linked), Corrective action |
| GRN | Source PO, Inspection, Inventory transactions |
| Delivery | Sales Order, Work Orders, CoC PDF |
| Invoice | Sales Order, Delivery, Collections |
| Bill | Purchase Order, GRN, Bill payments |

**Approach**:
1. Add eager-load helpers on each Service `show()`. For example [`api/app/Modules/Purchasing/Services/PurchaseOrderService.php`](api/app/Modules/Purchasing/Services/PurchaseOrderService.php:1) `show()` should `with(['purchaseRequest', 'grns.inspection', 'bill.payments'])`.
2. Extend each Resource's `toArray` with a `linked` shape — `linked: { source_pr: PurchaseRequestSummaryResource|null, grns: [...], bill: ..., payment: ... }`. Each summary resource returns `{ id, number, status, date, total }`. Avoid full nested resource trees (N+1).
3. On the frontend, build groups inline in the detail page (already the pattern in [`spa/src/pages/quality/ncrs/detail.tsx`](spa/src/pages/quality/ncrs/detail.tsx:140)): `const linkedGroups: LinkedGroup[] = []; if (data.linked.source_pr) linkedGroups.push({ label: 'Purchase Request', items: [...] });` then `<LinkedRecords groups={linkedGroups} />`.
4. Status chips inside `LinkedRecords` items use the same status→variant mapping table as the parent page. Centralize this in [`spa/src/lib/statusVariant.ts`](spa/src/lib/statusVariant.ts:1) (new file) so chips stay consistent everywhere.

**Files created**:
- `spa/src/lib/statusVariant.ts` (single source of truth for status → chip variant mapping per entity)
- One Summary Resource per linked entity that doesn't already have one (e.g., `WorkOrderSummaryResource`, `GrnSummaryResource`, `BillSummaryResource`, `MaterialIssueSlipSummaryResource`). Place under `api/app/Modules/<Module>/Resources/`.

**Files modified**:
- Backend Services: `PurchaseOrderService::show`, `WorkOrderService::show`, `LeaveRequestService::show`, `LoanService::show`, `GrnService::show`, `DeliveryService::show`, `InvoiceService::show`, `BillService::show` — add eager loads.
- Backend Resources: corresponding `*Resource::toArray` — add `linked: {...}` block.
- Frontend types: extend each detail type with optional `linked` field matching backend.
- Frontend pages: PO, WO, Leave, Loan, GRN, Delivery, Invoice, Bill detail pages — render `<LinkedRecords>` panel where missing.

**Acceptance**: every chain detail page has a right panel with at least 2 groups when data exists; empty groups are hidden (don't show empty headers).

---

### P3 — Approval Chain Visualization Component (`ApprovalTimeline`)

**Goal**: vertical timeline component showing every approval step on approvable records.

**Approach**:

1. **Component**: create [`spa/src/components/chain/ApprovalTimeline.tsx`](spa/src/components/chain/ApprovalTimeline.tsx:1).
   ```tsx
   interface ApprovalStep {
     step_order: number;
     role: string;          // 'Dept Head', 'Manager', etc.
     approver_name: string | null;
     action: 'approved' | 'rejected' | 'pending';
     acted_at: string | null; // ISO date
     remarks: string | null;
   }
   <ApprovalTimeline steps={steps} />
   ```
   Visual spec straight from NEW-TASKS.md §P3 and DESIGN-SYSTEM.md ChainHeader spec:
   - Vertical 1px line on the left, 9px dots aligned to it.
   - Done dot: `--success` filled. Pending dot: `--bg-subtle` filled. Active dot: `--accent` with subtle pulse animation (opacity 0.6 → 1 over 1.5s, respecting `prefers-reduced-motion`).
   - Each row: `text-sm` role (medium), `text-xs text-muted` approver name, `text-xs font-mono tabular-nums text-muted` date, optional rejection remarks below in `text-xs text-danger`.
   - Empty/pending rows omit name+date.

2. **Backend exposure**: Resources already include `approval_records` for PR/PO ([`PurchaseOrderResource.php`](api/app/Modules/Purchasing/Resources/PurchaseOrderResource.php:54)). Audit and add the same shape to: `LeaveRequestResource`, `LoanResource`, `PayrollPeriodResource`, `WorkOrderResource`, `NcrResource`, `SeparationResource`. Shape:
   ```json
   "approval_records": [
     { "step_order": 1, "role": "Dept Head", "approver": { "name": "..." }, "action": "approved", "acted_at": "2026-04-06T09:15:00Z", "remarks": null }
   ]
   ```

3. **Page wiring**: render `<ApprovalTimeline steps={data.approval_records} />` on:
   - [`spa/src/pages/leaves/detail.tsx`](spa/src/pages/leaves/index.tsx:1) (placement under chain header in main column, or inside the right panel above LinkedRecords)
   - [`spa/src/pages/loans/detail.tsx`](spa/src/pages/loans/detail.tsx:1)
   - [`spa/src/pages/purchasing/purchase-requests/detail.tsx`](spa/src/pages/purchasing/purchase-requests/detail.tsx:1)
   - [`spa/src/pages/purchasing/purchase-orders/detail.tsx`](spa/src/pages/purchasing/purchase-orders/detail.tsx:1)
   - [`spa/src/pages/payroll/periods/detail.tsx`](spa/src/pages/payroll/periods/detail.tsx:1)
   - [`spa/src/pages/production/work-orders/detail.tsx`](spa/src/pages/production/work-orders/detail.tsx:1)
   - [`spa/src/pages/quality/ncrs/detail.tsx`](spa/src/pages/quality/ncrs/detail.tsx:1)
   - HR Separation detail

4. **Pulse animation**: add `@keyframes pulse-dot` to [`spa/src/styles/globals.css`](spa/src/styles/globals.css:1).

**Files created**:
- `spa/src/components/chain/ApprovalTimeline.tsx`
- Update `spa/src/components/chain/index.ts` barrel
- `spa/src/types/chain.ts` — add `ApprovalStep` type

**Files modified**: 7 Resources backend, 7 detail pages frontend, `globals.css`.

---

### P4 — Notification Center Overhaul

**Goal**: bell dropdown with 8 most-recent + grouped `/notifications` page + WebSocket real-time + per-type preferences.

**Existing**: [`spa/src/components/layout/NotificationBell.tsx`](spa/src/components/layout/NotificationBell.tsx:1), [`spa/src/pages/notifications/index.tsx`](spa/src/pages/notifications/index.tsx:1), [`spa/src/pages/self-service/notification-preferences.tsx`](spa/src/pages/self-service/notification-preferences.tsx:1). Backend [`UserNotificationService`](api/app/Modules/Auth/Services/UserNotificationService.php:1) and `NotificationController` exist.

**Gaps to fill**:

1. **Bell dropdown**:
   - Show last 8 notifications via `GET /api/v1/notifications?per_page=8&unread_first=1`.
   - Each row: type icon (Lucide: `Calendar`, `Package`, `AlertCircle`, `CheckCircle2`, `FileText`, `Bell`), 13px title, 11px muted description, 10px mono `time ago` via `lib/formatDate` `timeAgo()`.
   - Unread items get a 2px left border `--accent`.
   - Bottom: "View all" link → `/notifications`.
   - Counter badge on bell shows count of unread; updates via WebSocket.

2. **`/notifications` page** ([`spa/src/pages/notifications/index.tsx`](spa/src/pages/notifications/index.tsx:1)):
   - Group notifications by `created_at` bucket: Today, Yesterday, Earlier this week, Older. Compute on the client (server returns flat list paginated; client `groupBy(notification, bucket)`).
   - Filter chips: All, Unread, Approvals (`type` IN [leave_*, pr_*, po_*, loan_*]), Alerts (`type` IN [stock_*, machine_*, qc_*]), System (`type` IN [system_*]). Chips drive `?type_group=` query param.
   - "Mark all as read" button → `PATCH /api/v1/notifications/read-all` (already exists).
   - Each notification clickable; navigates to `notification.link_to` (URL stored on notification record). If missing, no-op.
   - Apply 5 mandatory states (loading/error/empty/data/stale).

3. **Real-time** via Laravel Reverb:
   - Backend: notifications already broadcast on `private-user.{id}` channel (verify via `NotificationService`). If not, add `BroadcastsToUser` and a `NewNotification` event with `ShouldBroadcast`.
   - Frontend: in [`spa/src/lib/echo.ts`](spa/src/lib/echo.ts:1) (already exists) subscribe to `private-user.{authUser.id}` on app mount inside `AuthGuard` once authenticated. On `NotificationCreated` event:
     - Increment bell counter (Zustand `notificationsStore` — new file `spa/src/stores/notificationsStore.ts`).
     - Show toast slide-in from top-right with title.
     - Refetch `['notifications', { unreadOnly: false }]` cached query if user is on `/notifications`.

4. **Preferences**: extend [`/self-service/notification-preferences`](spa/src/pages/self-service/notification-preferences.tsx:1) with per-type rows (Approval requests, Alerts, System) × per-channel (Email, In-app) checkboxes. Backend table already exists per `notification_preferences` migration.

**Files created**:
- `spa/src/stores/notificationsStore.ts` (Zustand: unread count, last 8 cache)
- Possibly `spa/src/components/layout/NotificationDropdown.tsx` if `NotificationBell` becomes too large

**Files modified**:
- `NotificationBell.tsx` — wire to store + Echo subscription
- `pages/notifications/index.tsx` — group buckets, filter chips, "View all" navigation
- `pages/self-service/notification-preferences.tsx` — matrix UI
- Backend: ensure `NotificationService::notify` broadcasts (`broadcast(new NotificationCreated($notification))->toOthers()` removed; we want `->toUser()`). Add `app/Common/Broadcasting/NotificationCreated.php` event if absent.

---

### P5 — Employee Self-Service Mobile Experience

**Goal**: factory workers on phones; full mobile pass on `/self-service/*` (already exist in [`spa/src/pages/self-service/`](spa/src/pages/self-service/index.tsx:1)).

**Existing layout**: [`spa/src/layouts/SelfServiceLayout.tsx`](spa/src/layouts/SelfServiceLayout.tsx:1) — verify it's used for all self-service routes; if not, fix routing in `App.tsx`.

**Approach**:

1. **Bottom navigation bar** (mobile only; hidden ≥ 640px):
   - Create `spa/src/components/layout/SelfServiceBottomNav.tsx`.
   - Fixed bottom, height 56px, 5 tabs: Home, DTR, Leave, Payslip, Me.
   - Each tab 44×44px touch target, 16px Lucide icon, 10px label.
   - Active tab: `--text-primary` + 2px top accent indicator (mirror sidebar rail pattern).
   - Add safe-area inset (`pb-[env(safe-area-inset-bottom)]`).
   - Wire into `SelfServiceLayout`. Desktop layout unaffected.

2. **Per-page mobile work**:
   - [`self-service/index.tsx`](spa/src/pages/self-service/index.tsx:1) — Home: greeting (`text-lg`), today's shift card, leave balance card, pending requests count card. Stack vertically on mobile, 2-col grid ≥ 640px.
   - [`self-service/dtr.tsx`](spa/src/pages/self-service/dtr.tsx:1) — replace table with month calendar grid view: 7×6 cells, each cell `date` + colored dot (present=success, late=warning, absent=danger, leave=info, holiday=neutral).
   - [`self-service/leave.tsx`](spa/src/pages/self-service/leave.tsx:1) — balance row across top; quick-apply form with native `<input type="date">`, large button, single column.
   - [`self-service/payslips.tsx`](spa/src/pages/self-service/payslips.tsx:1) — list of payslip cards: period range + net amount in `font-mono tabular-nums` + Download button (44×44).
   - [`self-service/me.tsx`](spa/src/pages/self-service/me.tsx:1) — profile fields stacked, edit-in-place mobile number with native keyboard `inputmode="tel"`.

3. **Global mobile rules**:
   - Add `<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">` if missing in [`spa/index.html`](spa/index.html:1).
   - Tailwind: ensure `lg:` and `sm:` breakpoints used; convert any `<table>` inside self-service pages to `<DataTable>` with the new `cardOnMobile` prop or replace with custom card list when `< 640px`.
   - Test all touch targets ≥ 44×44 (Tailwind `h-11 min-w-11`).

**Files created**:
- `spa/src/components/layout/SelfServiceBottomNav.tsx`
- `spa/src/components/self-service/MonthCalendar.tsx` (DTR view)
- `spa/src/components/self-service/PayslipCard.tsx`

**Files modified**: every file in [`spa/src/pages/self-service/`](spa/src/pages/self-service/index.tsx:1), `SelfServiceLayout.tsx`, possibly `index.html` viewport.

**Acceptance**:
- DevTools mobile emulation (375×812): no horizontal scroll on any page; bottom nav present; all interactive elements ≥ 44×44.
- Date inputs trigger native picker on iOS/Android.

---

### P6 — Global Search Enhancement (Meilisearch)

**Goal**: Cmd+K opens working palette with grouped, status-aware results across all major entities.

**Existing**: [`spa/src/components/ui/CommandPalette.tsx`](spa/src/components/ui/CommandPalette.tsx:1). Verify it currently uses `/api/v1/search?q=...` or similar; backend may need `Meilisearch` wired.

**Backend approach**:

1. **Meilisearch indexes** (one per searchable model, configured via Laravel Scout's Meilisearch driver):
   - employees, sales_orders, purchase_orders, work_orders, invoices, products, items, vendors, customers
2. **Searchable trait**: add `Laravel\Scout\Searchable` to each model. Override `toSearchableArray` to return only fields listed in NEW-TASKS.md §P6.
3. **Indexer command**: `php artisan scout:import "App\Modules\HR\Models\Employee"` etc — provide a make target `make search:reindex` that runs all of them.
4. **Aggregator endpoint**: create `GET /api/v1/search?q={query}&limit=10` in [`api/app/Modules/Common`](api/app/Common/Services/) (new `app/Common/Controllers/GlobalSearchController.php`):
   - Parallel Meilisearch queries across all indexes (use `Search::multiSearch` if available or sequential — small payloads).
   - Returns:
     ```json
     {
       "groups": [
         { "type": "sales_order", "label": "Sales Orders", "items": [{ "id": "yR3kLm", "primary": "SO-202604-0003", "secondary": "Toyota Motor Phils.", "status": "in_production", "url": "/crm/sales-orders/yR3kLm" }] },
         ...
       ]
     }
     ```
   - Each item already encoded with hash_id. Permission filtering server-side: only include entities the user can `*.view`.
5. **Rate limit**: throttle `'search'` to 30 req/min per user.

**Frontend approach**:

1. Refactor `CommandPalette.tsx`:
   - Debounced query (250ms) → `searchApi.global(q)`.
   - Render groups with type icon + label header, then items as `<button>` rows: primary text in `font-mono` for IDs, secondary in `text-xs text-muted`, status chip on the right.
   - Keyboard: ↑/↓ moves selection across all items (flatten then index), Enter navigates to `item.url`, Esc closes.
   - Empty state: "No results for '{query}'" with suggestion list (recently viewed records from Zustand `recentStore` — new file).
   - Loading: shimmer skeleton inside palette.
2. Trigger: keep existing Cmd+K listener; ensure search input in topbar opens palette on click.

**Files created**:
- `api/app/Common/Controllers/GlobalSearchController.php`
- `api/app/Common/Resources/SearchResultResource.php`
- `spa/src/api/search.ts`
- `spa/src/stores/recentStore.ts` (last 10 viewed records, persisted in user settings, NOT localStorage — push to backend `/api/v1/users/me/recent`)

**Files modified**:
- All searchable models (add `Searchable` + `toSearchableArray`)
- `composer.json` — `laravel/scout`, `meilisearch/meilisearch-php`
- `routes/api.php` — register search route under `auth:sanctum`
- `CommandPalette.tsx`
- `Topbar.tsx` — wire search trigger

---

### P7 — Audit Log Enhancement

**Goal**: human-readable diffs, filters, CSV export, financial-only view, per-employee view.

**Existing**: [`api/app/Modules/Admin/Controllers/AuditLogController.php`](api/app/Modules/Admin/Controllers/AuditLogController.php:1) already has `buildDiff` and supports filters via `SearchOperator`. Frontend page may be basic.

**Backend gaps**:

1. **Human-readable diff formatter**: enhance `buildDiff()` to emit:
   ```json
   { "field": "basic_monthly_salary", "label": "Monthly Salary", "from": "₱18,000.00", "to": "₱20,000.00", "type": "money" }
   ```
   - Add field metadata map per model: `app/Common/Support/AuditFieldLabels.php` with `Employee::class => ['basic_monthly_salary' => ['label' => 'Monthly Salary', 'type' => 'money'], 'status' => ['label' => 'Status', 'type' => 'enum']]`.
   - Format helper: money → `phFormat()`, dates → `Carbon`, enums → human label, encrypted fields → masked diff (only show "changed" with no values).
2. **CSV export**: add `GET /api/v1/admin/audit-logs/export?format=csv&...filters` returning streamed CSV with columns: timestamp, user, action, model, record_id, summary. Use Laravel `StreamedResponse`. Permission: `admin.audit_logs.export`.
3. **Financial filter**: pre-set filter `?module_in=Accounting,Payroll` exposed at `/admin/audit-logs/financial` — same controller, scoped query.
4. **Per-employee report**: `GET /api/v1/admin/audit-logs/employee/{employee:hash}` — returns all AuditLog rows where (model=Employee AND record_id=ID) OR (relevant payroll/leave/loan rows for that employee).

**Frontend approach**:

1. New page [`spa/src/pages/admin/audit-logs/index.tsx`](spa/src/pages/admin/audit-logs/index.tsx:1):
   - FilterBar: User (Select with employee search), Module (Select), Action type (Create/Update/Delete chips), Date range, Entity type.
   - DataTable columns: Timestamp (mono), User, Action (chip — emerald create / blue update / red delete), Module, Entity, Summary (one-liner: "Updated salary on Maria Lopez").
   - Row click opens drawer/modal with full diff: each field as a row showing `from → to` with type-aware formatting.
   - "Export CSV" button → triggers `GET /audit-logs/export` with current filters; download via `window.location` (cookie auth carries through) OR Axios with `responseType: 'blob'`.
2. Sub-page `/admin/audit-logs/financial` — same component with `moduleFilter=['Accounting','Payroll']` pre-applied and label "Financial Audit Trail".
3. New page `/hr/employees/:id/audit` — appears as a "History" tab on employee detail.

**Files created**:
- `api/app/Common/Support/AuditFieldLabels.php`
- `api/app/Modules/Admin/Controllers/AuditLogExportController.php`
- `spa/src/api/audit-logs.ts` (if not present)
- `spa/src/pages/admin/audit-logs/index.tsx`, `financial.tsx`
- `spa/src/components/admin/AuditDiffPanel.tsx`

**Files modified**:
- `AuditLogController.php` — enrich `buildDiff` output
- Employee detail page — add History tab linking to audit page
- `App.tsx` — register new routes

---

### P8 — Dashboard Drill-Down (every number clickable)

**Goal**: every KPI value and stage count navigates to a filtered list.

**Existing dashboards**:
- [`spa/src/pages/dashboard/index.tsx`](spa/src/pages/dashboard/index.tsx:1)
- [`spa/src/pages/dashboard/plant-manager.tsx`](spa/src/pages/dashboard/plant-manager.tsx:1)
- [`spa/src/pages/dashboard/accounting.tsx`](spa/src/pages/dashboard/accounting.tsx:1)
- [`spa/src/pages/dashboard/hr.tsx`](spa/src/pages/dashboard/hr.tsx:1)
- [`spa/src/pages/dashboard/ppc.tsx`](spa/src/pages/dashboard/ppc.tsx:1)

**Approach**:

1. **Extend `<StatCard>`** ([`spa/src/components/ui/StatCard.tsx`](spa/src/components/ui/StatCard.tsx:1)) with an optional `linkTo?: string` prop. When set, the entire card becomes a `<Link>` (cursor-pointer, hover bg-elevated).
2. **Extend `<StageBreakdown>`** ([`spa/src/components/chain/StageBreakdown.tsx`](spa/src/components/chain/StageBreakdown.tsx:1)) so each row's count is a `<Link>` if the stage definition includes `linkTo`.
3. **Drill-down map** (centralize in `spa/src/lib/dashboardLinks.ts`):
   ```ts
   export const dashboardLinks = {
     totalEmployees: '/hr/employees',
     onLeaveToday: '/hr/employees?status=on_leave',
     revenueWeek: () => `/accounting/invoices?date_from=${startOfWeek()}`,
     arOutstanding: '/accounting/invoices?status=unpaid',
     lowStock: '/inventory/items?below_reorder=true',
     machineBreakdown: (id: string) => `/production/machines/${id}`,
     oeeReport: '/production/oee', // routes to P10's new page
     stageInProduction: '/production/work-orders?status=in_progress',
     // ...full table from NEW-TASKS.md §P8
   };
   ```
4. **List pages must read query params on mount and apply as filters**. For every list page used as a drill-down target:
   - Modify `useState<ListParams>` initial value to read `useSearchParams()` and seed filters.
   - When user changes filters interactively, also push to URL via `setSearchParams`. This makes URLs shareable and back-button friendly.
   - List pages affected: `/hr/employees`, `/accounting/invoices`, `/inventory/items`, `/production/work-orders`, `/production/machines`. Audit each for missing filter (e.g., `below_reorder=true` may need a new backend query option in `ItemService::list`).

**Files created**:
- `spa/src/lib/dashboardLinks.ts`
- `spa/src/hooks/useUrlFilters.ts` — utility to bind `ListParams` ↔ `useSearchParams`

**Files modified**:
- 5 dashboard pages (replace inline values with linked StatCard/StageBreakdown)
- `StatCard.tsx`, `StageBreakdown.tsx`
- 5+ list pages (URL filter sync)
- Backend: `ItemService::list` add `below_reorder` filter; `InvoiceService::list` add `status=unpaid` shortcut if missing

**Acceptance**: clicking any dashboard number lands on a filtered list whose chips show the active filter and whose URL contains the params.

---

### P9 — Printable Approval Forms (all levels)

**Goal**: every approvable document prints with a 4-tier signature block.

**Existing PDF infra**: [`api/resources/views/pdf/`](api/resources/views/pdf/) (DomPDF) per [`CLAUDE.md`](CLAUDE.md:241) file structure. Print routes likely under each module.

**Approach**:

1. **Shared partials**:
   - `api/resources/views/pdf/partials/letterhead.blade.php` — Ogami logo, address, doc number, generated date/time.
   - `api/resources/views/pdf/partials/approval-block.blade.php` — accepts `$approvals` array (from `approval_records`) and renders the 4-tier signature lines (Prepared by, Noted by, Checked by, Reviewed by, Approved by). For approved steps: typed name + date in `font-mono`. For pending: blank signature line `___________________`.
   - `api/resources/views/pdf/partials/footer.blade.php` — "Generated by {user} on {date} at {time} · Ogami ERP".
2. **Per-document templates** (audit existing, add where missing):
   - `pdf/purchase-request.blade.php`
   - `pdf/purchase-order.blade.php`
   - `pdf/cash-advance.blade.php`
   - `pdf/company-loan.blade.php`
   - `pdf/leave-request.blade.php` (single A4 page)
   - `pdf/payroll-register.blade.php`
   - `pdf/bill-payment-authorization.blade.php`
   Each `@include('pdf.partials.letterhead')` + body + `@include('pdf.partials.approval-block', ['approvals' => $approvals])` + footer.
3. **Print controllers**: each module has a `print` action returning DomPDF stream:
   - `GET /api/v1/purchase-requests/{pr}/print`, `/purchase-orders/{po}/print`, `/loans/{loan}/print`, `/leaves/{leave}/print`, `/payroll-periods/{period}/register/print`, `/bill-payments/{payment}/print`. Audit existing; add missing.
   - All routes use `auth:sanctum` + `permission:<module>.print` middleware.
4. **Frontend**: each detail page gets a "Print" ghost button in PageHeader actions. Click opens `/api/v1/<entity>/<id>/print` in a new tab (cookies travel with `withCredentials`).
5. **Approval block data shape**:
   ```php
   $approvals = [
     ['role' => 'Prepared by', 'name' => $record->createdBy?->full_name, 'date' => $record->created_at],
     ['role' => 'Noted by',    'name' => $deptHeadRecord?->approver?->full_name, 'date' => $deptHeadRecord?->acted_at],
     // etc.
   ];
   ```
   Service builds this list from `approval_records` and passes to view.

**Files created**:
- 3 partial blade templates
- Up to 7 PDF blade templates (where missing)
- Print controllers / actions on existing controllers

**Files modified**:
- Module routes — register `print` endpoints
- Detail pages — add Print button

**Acceptance**: print preview of each document type shows 4-tier signature block; approved tiers show typed name + date; pending tiers show signature line.

---

### P10 — OEE Report Page (full)

**Goal**: dedicated `/production/oee` page with date range, per-machine breakdown, gauge charts, trend, downtime breakdown, PDF export.

**Existing**: [`spa/src/components/production/OeeGauge.tsx`](spa/src/components/production/OeeGauge.tsx:1), API `spa/src/api/production/oee.ts`, backend likely [`api/app/Modules/Production/Services/OeeService.php`](api/app/Modules/Production/Services/) (verify).

**Backend approach**:

1. **OEE computation service** (audit existing; extend if needed):
   - `OeeService::report(array $filters): array` returns:
     ```json
     {
       "range": { "from": "2026-04-01", "to": "2026-04-20" },
       "machines": [
         {
           "id": "yR3kLm",
           "code": "IM-001",
           "availability": 0.92,
           "performance": 0.88,
           "quality": 0.97,
           "oee": 0.78,
           "downtime_minutes": 240,
           "downtime_breakdown": [
             { "category": "breakdown", "minutes": 90 },
             { "category": "changeover", "minutes": 80 },
             { "category": "no_order", "minutes": 40 },
             { "category": "maintenance", "minutes": 30 }
           ]
         }
       ],
       "trend": [
         { "date": "2026-04-01", "oee": 0.74 },
         { "date": "2026-04-02", "oee": 0.79 }
       ]
     }
     ```
   - Endpoint: `GET /api/v1/production/oee/report?from=&to=&machine_id=` (permission `production.oee.view`).
   - Decimal precision: OEE values to 4 decimal places; UI formats to %.
2. **PDF export**: `GET /production/oee/report.pdf?...` — DomPDF view rendering the same data + chart screenshots (server-side via DomPDF; or omit charts and provide pure tabular PDF for IATF traceability — simpler). Output: A4 landscape, monochrome, dense table.

**Frontend approach**:

1. New page [`spa/src/pages/production/oee.tsx`](spa/src/pages/production/oee.tsx:1):
   - PageHeader with title "OEE Report" + actions: date range selector (today/this week/this month/custom — use `<Select>` + 2 date inputs when custom), Export PDF button.
   - 4 KPI cards across the top: Overall OEE %, Availability, Performance, Quality (each a `<StatCard>` with no link).
   - Per-machine table: machine code, OEE % (gauge mini-chart inline using `<OeeGauge size="sm">`), availability/performance/quality columns, downtime total, "View" link → `/production/machines/{id}`.
   - Trend chart: line chart (use existing chart lib — check `package.json`; `recharts` is the most likely match). 2-week window default, OEE % on y-axis 0–100, benchmark line at 75%.
   - Downtime breakdown panel: stacked horizontal bar per category, totals in mono.
   - 5 mandatory states.

**Files created**:
- `spa/src/pages/production/oee.tsx`
- `spa/src/api/production/oee-report.ts`
- `api/resources/views/pdf/oee-report.blade.php`
- `api/app/Modules/Production/Controllers/OeeReportController.php` (and PDF action)

**Files modified**:
- `App.tsx` — register `/production/oee` route under `ModuleGuard module="production"` + `PermissionGuard permission="production.oee.view"`
- Sidebar — add Production → OEE Report nav entry
- `OeeService` — add `report()` method if missing

---

## 3. Execution order, branching, and PR strategy

Per [`CLAUDE.md`](CLAUDE.md:521) "Git commit after each task" plus the worker rules from system prompt:

1. Each task = its own branch: `feat/p1-chain-headers`, `feat/p2-linked-records`, …, `feat/p10-oee-report`.
2. Commit message: `feat: P{N} — {short description}`.
3. PR title: `feat: P{N} — {description}` against `main` of `kwat0g/kwatog`.
4. Wait for CI green via `gh pr checks` before opening the next branch.
5. Use the existing PR template if present in `.github/pull_request_template.md`; fill all sections.
6. Order recommended (matches NEW-TASKS.md week 3 + 4 split):
   - **Wave A (foundations)**: P1 → P2 → P3 (chain visualization completeness)
   - **Wave B (UX surfaces)**: P4 → P5 (notifications + mobile)
   - **Wave C (productivity)**: P6 (search) → P8 (drill-downs)
   - **Wave D (compliance/output)**: P7 (audit) → P9 (printables) → P10 (OEE report)

Wave dependencies:
- P3's `ApprovalTimeline` is consumed by P9 (signature block re-uses approval data).
- P8's URL filter helper is used implicitly by P6 (recent records can deep-link with filters).
- Otherwise tasks are independent and can run in parallel branches if multiple agents available; sequential is safer.

---

## 4. Cross-task verification checklist (run before each PR)

Mirrored from [`docs/PATTERNS.md`](docs/PATTERNS.md:1716) final checklist:

Backend
- [ ] Every new model has `HasHashId` + `HasAuditLog`; encrypted casts on sensitive fields.
- [ ] Every Service mutation wrapped in `DB::transaction`; eager loads to prevent N+1.
- [ ] Every Resource returns `hash_id` only; sensitive fields masked.
- [ ] FormRequests have `authorize()` checking permission; rules exhaustive.
- [ ] Routes guarded by `auth:sanctum` + `feature:<module>` + `permission:<perm>`.
- [ ] Controllers thin, return correct HTTP codes (201 store, 204 destroy).

Frontend
- [ ] All 5 page states (loading skeleton, error empty-state with retry, empty empty-state, data, stale via `placeholderData`).
- [ ] Forms: Zod schema, disabled-while-pending submit, server 422 → `setError`, cancel button, success/error toast, queryClient invalidation.
- [ ] Every route lazy-loaded + AuthGuard + ModuleGuard + PermissionGuard.
- [ ] All numbers/IDs/dates use `font-mono tabular-nums`.
- [ ] All status fields use `<Chip>` with semantic variant from `lib/statusVariant.ts`.
- [ ] No color outside chips/buttons/deltas/links/alert dots.
- [ ] No Bearer tokens; no localStorage auth; `withCredentials: true`.
- [ ] Tables: 32px row height, uppercase letter-spaced headers.

Repo hygiene
- [ ] PR targets `kwat0g/kwatog` `main`.
- [ ] CI green via `gh pr checks` before merge.
- [ ] Commit message follows `feat: P{N} — …`.

---

## 5. Risks and open questions

| Risk | Mitigation |
|---|---|
| Some chain detail pages may have inconsistent status enums (e.g., WO `in_progress` vs `running`). | First sub-task of P1 is to read each enum file in `api/app/Modules/.../Enums/` and document the canonical mapping in `spa/src/lib/chains/<chain>.ts` comments. |
| Real-time WebSocket (P4) may not be configured in dev/CI. | Verify Reverb is running via [`docker-compose.yml`](docker-compose.yml:1). If not, gate P4 real-time behind `VITE_REVERB_ENABLED` flag and fall back to polling. |
| Meilisearch (P6) requires running container. | Confirm presence in `docker-compose.yml`. If absent, add service before implementing scout indexing. |
| URL filter sync (P8) might break existing pages that don't expect URL state. | Wrap `useUrlFilters` rollout in feature parity tests on each affected list page; preserve existing default filters. |
| PDF rendering (P9, P10) heavy on memory if approvals or OEE history is large. | Stream large PDFs; cap OEE PDF date range to 90 days. |
| Auto-PR-per-task may overwhelm CI. | Acceptable for thesis cadence; can batch waves into one PR per wave if reviewer prefers. |

Open questions to confirm with maintainer before code starts:
1. Should P5's bottom nav appear on tablet too, or only on `< 640px`? (Recommend `< 768px`.)
2. P6: Is Meilisearch already in the docker-compose stack? If not, OK to add a `meilisearch:v1.6` service?
3. P8: Should URL filter sync be retrofitted to ALL list pages, or only those reachable from dashboards? (Plan assumes the latter to limit blast radius.)
4. P9: Is there a corporate signature image we should embed, or is the typed-name + date sufficient? Plan currently assumes typed-name only.

---

## 6. Deliverables summary

| Task | New files (approx) | Modified files (approx) |
|---|---|---|
| P1 | 8 chain builders | 8 detail pages |
| P2 | 1 status map + ~5 summary resources | 8 services + 8 resources + 8 frontend pages |
| P3 | 1 component + 1 type | 7 backend resources + 7 detail pages + globals.css |
| P4 | 1 store, 1 dropdown, 1 broadcast event | NotificationBell, NotificationsPage, NotificationService, prefs page |
| P5 | 3 components | 5 self-service pages + layout |
| P6 | 1 controller, 1 resource, 1 api file, 1 store | All searchable models, CommandPalette, Topbar, composer.json |
| P7 | 1 labels file, 1 export controller, 2 pages, 1 diff component | AuditLogController, employee detail, App.tsx |
| P8 | 2 lib files | 5 dashboards + 5 list pages + 2 ui components + 2 services |
| P9 | 3 partials + up to 7 PDF blades + print controllers | Module routes + detail pages |
| P10 | 1 page, 1 api, 1 pdf blade, 1 controller | App.tsx, Sidebar, OeeService |

Implementation switch: hand this plan to **Code mode** (`switch_mode code`) for execution wave by wave. Each wave should be a separate branch + PR.
