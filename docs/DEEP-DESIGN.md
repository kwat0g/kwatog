# OGAMI ERP — Deep Design: 4 Next-Generation Modules

> Architecture grounded in the existing codebase. Every model, service, and
> route extends patterns already established. Every FK, cast, enum, and
> permission slug follows conventions from `CLAUDE.md` and `PATTERNS.md`.

---

## Module 1 — Supplier PPAP & APQP Tracking

**Business domain:** IATF 16949 requires Production Part Approval Process (PPAP)
for every new or changed part from every supplier. APQP (Advanced Product
Quality Planning) is the upstream planning framework. Currently the system has
no visibility into a supplier's approval status per part.

**Thesis narrative:** "The system ensures no non-PPAP-approved part enters
production — a gate that existing ERPs treat as a manual process."

### Models

#### PpapSubmission (new model)

```
Table: ppap_submissions
Traits: HasFactory, HasHashId, HasAuditLog

FKs:
  vendor_id           -> vendors.id             (not nullable)
  item_id             -> items.id               (not nullable, raw material)
  product_id          -> products.id            (nullable, finished good)
  purchase_order_id   -> purchase_orders.id     (nullable, the PO driving this PPAP)
  submitted_by        -> users.id               (who submitted)

Columns:
  ppap_level         enum PpapLevel            1..5 (IATF standard levels)
  submission_date     date
  status             enum PpapStatus           draft | submitted | under_review | approved | rejected | expired
  submission_document_path  string             stored file reference
  rejection_reason    string (nullable)
  reviewed_by         -> users.id (nullable)
  reviewed_at         datetime (nullable)
  approved_by         -> users.id (nullable)
  approved_at         datetime (nullable)
  expires_at          datetime (nullable)      # PPAP approvals have expiry
  revision            integer   default 1
  notes               text (nullable)

Relationships:
  vendor()      BelongsTo Vendor
  item()        BelongsTo Item (Inventory)
  product()     BelongsTo Product (CRM)
  purchaseOrder() BelongsTo PurchaseOrder (Purchasing)
  submitter()   BelongsTo User
  reviewer()    BelongsTo User
  approver()    BelongsTo User
  elements()    HasMany PpapElement
```

#### PpapElement (new model)

```
Table: ppap_elements
Traits: HasFactory, HasHashId

FKs:
  ppap_submission_id  -> ppap_submissions.id

Columns:
  element_type        enum PpapElementType
    VALUES: design_record | engineering_change | customer_approval |
            dfmea | process_flow | pfmea | control_plan |
            msa | dimensional_results | material_test |
            performance_test | initial_process_study |
            qualified_laboratory | appearance_approval |
            sample_product | master_sample | checking_aids |
            records_of_compliance | part_submission_warrant
  status              enum PpapElementStatus   pending | submitted | accepted | rejected
  document_path       string (nullable)
  notes               text (nullable)
```

#### PpapStatus and other enums

```php
enum PpapLevel: string {
    case Level1 = '1';   // Part Submission Warrant only
    case Level2 = '2';   // PSW + limited supporting data
    case Level3 = '3';   // PSW + full supporting data (most common)
    case Level4 = '4';   // PSW + other customer-specific requirements
    case Level5 = '5';   // Full PPAP reviewed at supplier location
}

enum PpapStatus: string {
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
```

### Service

#### PpapService

```php
class PpapService {
    public function list(array $filters, ?User $user): LengthAwarePaginator;
    public function show(PpapSubmission $ppap): PpapSubmission;
    public function create(array $data, User $by): PpapSubmission;
    public function submit(PpapSubmission $ppap): PpapSubmission;
    public function review(PpapSubmission $ppap, User $by, ?string $notes): PpapSubmission;
    public function approve(PpapSubmission $ppap, User $by): PpapSubmission;
    public function reject(PpapSubmission $ppap, string $reason, User $by): PpapSubmission;
    public function updateElement(PpapElement $el, array $data): PpapElement;
    public function expireOverdue(): int;  // cron — flips expired PPAPs
}
```

**Business rules:**
- `submit()`: ppap_level must be set; at least the PSW element must be attached; status → submitted
- `approve()`: all elements must be `accepted` or `not_applicable` (add status); sets `approved_at` + `expires_at = approved_at + 3 years`; fires `PpapApproved` event
- `expireOverdue()`: any approved PPAP with `expires_at < now()` → status `expired`; fires `PpapExpired` event
- Cannot create PO for an item whose vendor has no active PPAP (integration point into `PurchaseOrderService` — add a guard call that checks `PpapService::vendorHasActivePpap(vendor_id, item_id)`)

### Routes

```
prefix: api/v1/quality/ppap
middleware: auth:sanctum, feature:quality

GET    /                      PpapController@index     permission:quality.ppap.view
POST   /                      PpapController@store     permission:quality.ppap.manage
GET    /{ppap}                PpapController@show      permission:quality.ppap.view
PUT    /{ppap}                PpapController@update    permission:quality.ppap.manage
PATCH  /{ppap}/submit         PpapController@submit    permission:quality.ppap.manage
PATCH  /{ppap}/review         PpapController@review    permission:quality.ppap.manage
PATCH  /{ppap}/approve        PpapController@approve   permission:quality.ppap.manage
PATCH  /{ppap}/reject         PpapController@reject    permission:quality.ppap.manage
PATCH  /{ppap}/elements/{el}  PpapController@updateElement permission:quality.ppap.manage

Permissions (in RolePermissionSeeder):
  quality.ppap.view   — granted to: qc_inspector, purchasing_officer,
                         production_manager, ppc_head, system_admin
  quality.ppap.manage — granted to: qc_inspector, purchasing_officer,
                         system_admin
```

### Integration points

| Point | Module | What changes |
|---|---|---|
| PO approval gate | Purchasing | `PurchaseOrderService::approve()` checks `PpapService::vendorHasActivePpap()` before approving a PO to a vendor for an item that exists in PPAP registrations. Block with 422 "Vendor {name} has no active PPAP for item {name}." |
| Vendor detail page | Accounting | VendorResource includes `ppap_count` and `ppap_approved_count` via `Vendor::withCount(['ppapSubmissions'])` |
| Supplier portal | B2B | Supplier can view their own PPAP submissions and upload element documents |
| NCR integration | Quality | NCR with `source = customer_complaint` can trigger a PPAP review requirement (optional link) |

### SPA pages

- `/quality/ppap` — List: vendor filter, item filter, status chips, level badges
- `/quality/ppap/:id` — Detail: element checklist matrix, submission timeline, document viewer
- `/quality/ppap/create` — Form: vendor autocomplete, item selector, level dropdown, element configurator
- Supplier portal: `/portal/supplier/ppap` — View-only of own submissions + upload

### Migration

```
php artisan make:migration 0223_create_ppap_tables
  - ppap_submissions table
  - ppap_elements table
  - Index on (vendor_id, item_id, status) for the PO-approval lookup
```

### Events + Listeners

| Event | Listeners |
|---|---|
| `PpapSubmitted` | Notify Purchasing officers + QC inspectors |
| `PpapApproved` | Update ApprovedSupplier (Purchasing), notify PPC |
| `PpapExpired` | Notify Purchasing officers |
| `PpapRejected` | Notify submitter + Purchasing officers |

---

## Module 2 — Corrective Action Effectiveness Tracking

**Business domain:** IATF 16949 Clause 10.2.1 requires that corrective actions are
verified for effectiveness. Currently the `NcrAction` model has `verified_at`
and `verified_by` columns that exist but are **never populated** by any code
path (`NcrService::addAction()` doesn't touch them). There is no verification
workflow, no effectiveness check schedule, and no recurrence-prevention proof.

**Thesis narrative:** "The system doesn't just close NCRs — it proves the fix
worked. Recurrence checking at 30/60/90 days closes the IATF PDCA loop in
software."

### Schema changes (extend existing models — NO new models)

#### Add to `ncr_actions` table

```sql
-- Already exist but unused: verified_at, verified_by, due_date, owner_id
-- Add:
ALTER TABLE ncr_actions ADD COLUMN effectiveness_status
    VARCHAR(20) DEFAULT NULL  -- pending_verification | effective | ineffective | not_applicable
ALTER TABLE ncr_actions ADD COLUMN effectiveness_checked_at TIMESTAMP NULL;
ALTER TABLE ncr_actions ADD COLUMN effectiveness_notes TEXT NULL;
ALTER TABLE ncr_actions ADD COLUMN effectiveness_check_count INTEGER DEFAULT 0;
ALTER TABLE ncr_actions ADD COLUMN next_effectiveness_check_at DATE NULL;
```

#### Add to `NonConformanceReport` model

```sql
ALTER TABLE non_conformance_reports ADD COLUMN effectiveness_status
    VARCHAR(20) DEFAULT NULL  -- pending | verified_effective | verified_ineffective
ALTER TABLE non_conformance_reports ADD COLUMN effectiveness_closed_at TIMESTAMP NULL;
```

### New enum

```php
enum EffectivenessStatus: string {
    case PendingVerification = 'pending_verification';
    case Effective = 'effective';
    case Ineffective = 'ineffective';
    case NotApplicable = 'not_applicable';
}
```

### Service: EffectivenessService (new)

```php
class EffectivenessService
{
    // ── Close-time: schedule verification checks ──

    /**
     * Called from NcrService::close(). For every corrective and preventive action,
     * set due_date = now + 30d, owner_id = closed_by,
     * next_effectiveness_check_at = now + 30d,
     * effectiveness_status = pending_verification.
     */
    public function scheduleVerification(NonConformanceReport $ncr): void;

    // ── Verification check (manual, triggered from detail page) ──

    /**
     * Record that a corrective action has been verified effective or ineffective.
     * Updates verified_at, verified_by, effectiveness_status,
     * effectiveness_notes, effectiveness_check_count++.
     * If ineffective: set next_effectiveness_check_at = now + 30d (re-check).
     * If effective after count > 0: no more checks.
     */
    public function verifyAction(
        NcrAction $action,
        User $by,
        EffectivenessStatus $status,
        string $notes
    ): NcrAction;

    // ── Recurrence detection at verification time ──

    /**
     * When an action is verified INEFFECTIVE, scan for new NCRs with the same
     * product + similar defect since the original NCR was closed.
     * Returns any recurrence evidence.
     */
    public function checkRecurrence(NcrAction $action): array;

    // ── NCR-level aggregation ──

    /**
     * After all corrective+preventive actions have been verified:
     *   - If ANY is ineffective → NCR.effectiveness_status = verified_ineffective
     *   - If ALL are effective/NA → NCR.effectiveness_status = verified_effective
     *   - Sets NCR.effectiveness_closed_at = now
     */
    public function updateNcrEffectiveness(NonConformanceReport $ncr): void;

    // ── Scheduled job (cron: every day at 02:00) ──

    /**
     * Finds all ncr_actions where:
     *   - effectiveness_status = pending_verification
     *   - next_effectiveness_check_at <= now
     * Notifies the action.owner with type 'effectiveness_due'.
     * If the check is overdue by > 14 days, escalates to production_manager.
     */
    public function notifyOverdueChecks(): int;   // returns count notified
}
```

### Modified: NcrService::close() (preexisting method)

```php
// In NcrService::close(), AFTER the existing checks pass and
// AFTER setting status -> closed, ADD:

if ($ncr->actions()->whereIn('action_type', ['corrective', 'preventive'])->exists()) {
    app(EffectivenessService::class)->scheduleVerification($ncr);
}
```

### Modified: NcrAction model

```php
// Add casts:
'effectiveness_status' => EffectivenessStatus::class,
'effectiveness_checked_at' => 'datetime',
'next_effectiveness_check_at' => 'date',
'verified_at' => 'datetime',
'due_date' => 'date',
```

### Modified: NcrService::addAction()

```php
// Now populates the previously-dead columns:
$action->due_date = $data['due_date'] ?? now()->addDays(30);
$action->owner_id = $data['owner_id'] ?? $by->id;
// verified_at and verified_by remain null until verification
```

### Routes (new — within existing quality prefix)

```
PATCH  /ncrs/{ncr}/actions/{action}/verify  EffectivenessController@verify
\   permission: quality.ncr.manage

GET    /ncrs/effectiveness/due              EffectivenessController@dueIndex
\   permission: quality.ncr.view

POST   /ncrs/{ncr}/effectiveness/assess     EffectivenessController@assess
\   permission: quality.ncr.manage
```

The existing routes `/ncrs/{ncr}/actions` and `/ncrs/{ncr}/close` are modified
(service-side, not route-side) to schedule verification on close.

### Cron registration (bootstrap/app.php or routes/console.php)

```php
// Schedule::command('ncr:check-effectiveness')->dailyAt('02:00');
// Or via a Job dispatched from the existing alerts:run (every 15m)
```

### NCR detail page changes (existing)

The NCR detail page already shows `actions`. Add:
- Effectiveness status badge per action (pending/effective/ineffective)
- "Verify" button per action (opens modal with effective/ineffective toggle + notes)
- NCR-level effectiveness summary at the top (when all actions verified)
- Timeline showing: NCR opened → actions added → NCR closed → effectiveness verified → effective/ineffective

### Events + notifications

| Event | Notifies |
|---|---|
| `EffectivenessVerificationDue` | Action owner (at 30d mark) |
| `EffectivenessVerificationOverdue` | Production manager (at 44d mark) |
| `ActionVerifiedEffective` | NCR creator, QC inspector |
| `ActionVerifiedIneffective` | NCR creator, production manager, QC inspector |
| `NcrEffectivenessComplete` | NCR closer, production manager |

### Demo narrative

```
Jun 23: WO-606 completed → outgoing QC → flash dimension out of tolerance
       → NCR-060 auto-opens (HIGH, disposition=rework)
       → Rework WO auto-created
Jun 24: Rework completed → re-inspection passes
Jun 26: NCR-060 closed with 1 corrective + 1 preventive action
       → Effectiveness verification scheduled for Jul 26

Jul 26: System notifies QC inspector: "Verify corrective action for NCR-060"
Jul 27: QC inspector verifies: checks re-inspection data, checks production
        logs, confirms zero recurrences → marks "Effective"
Aug 25: Second verification check (60d) → still no recurrences
       → NCR-060 marked "Verified Effective"

Adjudicator sees: "The system tracked effectiveness through 2 verification
cycles with zero recurrences. PDCA loop closed."
```

---

## Module 3 — Mold Lifecycle Manager

**Business domain:** Molds are the single most expensive consumable in injection
molding. A single mold costs $5,000-$50,000. Tracking its full lifecycle —
design → commission → production use → maintenance → retirement — prevents
surprise costs and production stoppages.

**Thesis narrative:** "The system manages the mold as a first-class production
asset, not an afterthought. Shot-life tracking + predictive maintenance +
cost amortization turns mold management from reactive firefighting into
scheduled, budgeted operations."

### What already exists (DON'T rebuild)

- `Mold` model + `MoldStatus` enum (Available/InUse/Maintenance/Retired)
- `MoldService` with atomic `incrementShots()` + `resetShotCount()`
- `MoldHistory` model tracking every lifecycle event
- `MoldShotLimitNearing` / `MoldShotLimitReached` events + broadcasts
- `MaintenanceSchedule` with `interval_type = 'shots'`
- `GeneratePreventiveMaintenanceJob` that creates PM WOs from shot schedules
- Mold-Machine compatibility matrix

### What's missing (build these)

#### 1. Mold register enhancements

**Add to `molds` table:**

```sql
ALTER TABLE molds ADD COLUMN commissioned_at DATE NULL;
ALTER TABLE molds ADD COLUMN decommissioned_at DATE NULL;
ALTER TABLE molds ADD COLUMN last_maintenance_at DATE NULL;
ALTER TABLE molds ADD COLUMN maintenance_count INTEGER DEFAULT 0;
ALTER TABLE molds ADD COLUMN total_maintenance_cost DECIMAL(15,2) DEFAULT 0;
ALTER TABLE molds ADD COLUMN acquisition_cost DECIMAL(15,2) DEFAULT 0;
ALTER TABLE molds ADD COLUMN estimated_replacement_cost DECIMAL(15,2) DEFAULT 0;
ALTER TABLE molds ADD COLUMN supplier_id BIGINT NULL REFERENCES vendors(id);
ALTER TABLE molds ADD COLUMN drawing_number VARCHAR(50) NULL;
ALTER TABLE molds ADD COLUMN storage_location VARCHAR(100) NULL;
ALTER TABLE molds ADD COLUMN maintenance_frequency_shots INTEGER NULL;  -- preventive interval
```

**New cast on Mold model (add to existing `$casts`):**

```php
'commissioned_at' => 'date',
'decommissioned_at' => 'date',
'last_maintenance_at' => 'date',
'maintenance_count' => 'integer',
'total_maintenance_cost' => 'decimal:2',
'acquisition_cost' => 'decimal:2',
'estimated_replacement_cost' => 'decimal:2',
```

**New relationships on Mold:**

```php
public function supplier(): BelongsTo { return $this->belongsTo(Vendor::class); }
public function maintenanceWorkOrders(): MorphMany {
    return $this->morphMany(MaintenanceWorkOrder::class, 'maintainable');
}
public function productionWorkOrders(): HasMany {
    return $this->hasMany(WorkOrder::class, 'mold_id');
}
```

#### 2. MoldDashboardWidget (new service)

```php
class MoldDashboardWidget
{
    /**
     * Returns per-mold health summary for the mold register page.
     * Each mold gets: name, code, product, status, shot_percentage,
     * days_since_last_maintenance, estimated_shots_remaining,
     * total_parts_produced, cost_per_shot (acquisition / lifetime_total_shots),
     * next_scheduled_maintenance.
     */
    public function registerSummary(array $filters): Collection;

    /**
     * Mold that will hit its shot limit soonest (for dashboard alert).
     */
    public function nextMoldAtRisk(): ?Mold;

    /**
     * Monthly maintenance cost trend per mold (for the detail page chart).
     */
    public function costTrend(Mold $mold, int $months = 12): array;

    /**
     * Total mold-related downtime hours for the period.
     * Links: mold → WorkOrder → MachineDowntime (via work_order_id).
     * This gives "how much production did we lose due to mold issues."
     */
    public function downtimeHours(Mold $mold, Carbon $from, Carbon $to): float;
}
```

#### 3. Mold auto-maintenance schedule creation

When a mold is commissioned (`commissioned_at` is set), the service
auto-creates a `MaintenanceSchedule` record:

```php
// In MoldService::commission(Mold $mold):
MaintenanceSchedule::create([
    'maintainable_type' => MaintainableType::Mold->value,
    'maintainable_id'   => $mold->id,
    'schedule_type'     => 'preventive',
    'interval_type'     => 'shots',
    'interval_value'    => $mold->maintenance_frequency_shots
        ?? $mold->max_shots_before_maintenance,
    'description'       => "Auto PM: {$mold->name} every "
        . ($mold->maintenance_frequency_shots ?? $mold->max_shots_before_maintenance)
        . " shots",
    'is_active'         => true,
    'next_due_at'       => null, // shot-based — computed from shot count
]);
```

#### 4. Mold detail page (new SPA page)

URL: `/mrp/molds/:id`
Reuses existing `MoldController@show` (which already loads product + compatibleMachines).

**New sections on the detail page:**

```
┌─────────────────────────────────────────────────┐
│ Mold: WB-001  4-Cav Steel Mold A                │
│ Status: In Use  ·  Shot: 4,230/10,000 (42%)     │
│ Product: Wiper Bushing (OGM-P-0012)             │
├─────────────────────────────────────────────────┤
│ [Shot Gauge ████░░░░░░░░░░░░░░ 42%]             │
│ 80% alert at 8,000 · 100% lock at 10,000         │
│ Est. shots remaining: 5,770 · ~28 production days│
├─────────────────────────────────────────────────┤
│ 📊 Production  │  🛠 Maintenance  │  💰 Cost     │
│ Total parts:   │  Last PM: Jun 1   │  Acquired:  │
│   847,230      │  Next PM: 5,000   │    ₱280,000 │
│ This cycle:    │   shots            │  Cost/shot: │
│   423,000      │  Maint. count: 38  │    ₱0.33    │
│                │  Total cost:       │  Est. repl: │
│                │    ₱42,500         │    ₱310,000 │
├─────────────────────────────────────────────────┤
│ Maintenance History                             │
│ Jun 1, 2026  PM #38  2h · cleaned + polished     │
│ Mar 15, 2026 PM #37  1h · cavity inspection      │
│ Jan 8, 2026  Retired → Repaired → Recommissioned │
│ Dec 12, 2025 Repair · Cavity #2 replaced         │
├─────────────────────────────────────────────────┤
│ Production History                              │
│ Used on 142 work orders · 847,230 total shots    │
│ Avg scrap rate: 1.2%                            │
│ Compatible machines: Press #3, Press #7           │
└─────────────────────────────────────────────────┘
```

#### 5. Mold retirement + amortization

```php
// MoldService::retire(Mold $mold, string $reason):
// Sets status -> Retired, decommissioned_at = now.
// Calculates total lifecycle cost:
//   acquisition_cost + total_maintenance_cost
// Calculates cost per shot: lifecycle_cost / lifetime_total_shots
// Writes final MoldHistory entry with event_type = Retired
// Fires MoldRetired event → notifies production_manager + maintenance_tech
```

### Integration points

| Point | What changes |
|---|---|
| WO output recording | Already exists — `incrementShots()` called in `WorkOrderOutputService::record()`. No changes needed. |
| MO completion | Already exists — `MaintenanceWorkOrderService::complete()` resets `current_shot_count` for mold-targeting WOs. No changes needed. |
| Machine dashboard | The production dashboard already shows mold events via WebSocket. Add mold-health summary card. |
| Preventive job | Already runs daily. Add auto-schedule on commission. |

### Enhanced Mold routes (add to existing)

```
PATCH  /mrp/molds/{mold}/commission      MoldController@commission
\   permission: production.molds.manage
PATCH  /mrp/molds/{mold}/decommission    MoldController@decommission
\   permission: production.molds.manage
GET    /mrp/molds/{mold}/cost-trend       MoldController@costTrend
\   permission: mrp.molds.view
GET    /mrp/molds/{mold}/downtime         MoldController@downtime
\   permission: mrp.molds.view
```

---

## Module 4 — Mobile Factory Floor App (PWA)

**Business domain:** Nobody on a factory floor walks to a desktop. QC inspectors
measure parts at the machine. Production operators record output on the line.
Maintenance techs receive breakdown alerts on their phone. The current
`DriverLayout` PWA already proves the pattern works — extend it to 3 new roles.

**Thesis narrative:** "The same system serves the boardroom dashboard and the
factory floor — a single source of truth, usable where the work actually
happens."

### Architecture (extends existing DriverLayout PWA pattern)

```
spa/src/
├── layouts/
│   ├── DriverLayout.tsx          ← existing pattern, mobile-first, no sidebar
│   └── FactoryFloorLayout.tsx    ← NEW: extends same pattern, adds role header
├── routes/
│   ├── driverRoutes.tsx          ← existing
│   └── factoryFloorRoutes.tsx    ← NEW
├── pages/
│   ├── driver/                   ← existing
│   ├── factory/                  ← NEW
│   │   ├── ProductionDashboard.tsx
│   │   ├── RecordOutput.tsx
│   │   ├── QcInspect.tsx
│   │   ├── QcMeasurements.tsx
│   │   ├── MaintenanceAlerts.tsx
│   │   └── MaintenanceComplete.tsx
│   └── ...
├── api/
│   └── factory.ts                ← NEW (typed API functions)
└── types/
    └── factory.ts                ← NEW (TypeScript interfaces)
```

### FactoryFloorLayout.tsx

```tsx
// Extends the DriverLayout pattern exactly:
// - Mobile-first (max-w-2xl, px-4, no sidebar)
// - Sticky top bar with: app label ("Ogami Floor"), role badge, user name, logout
// - Scrollable content area below
// - Uses AuthGuard + PermissionGuard per child route
// - All touch targets ≥ 44px (WCAG mobile)

export default function FactoryFloorLayout() {
  return (
    <AuthGuard>
      <div className="min-h-screen bg-canvas">
        <header className="sticky top-0 z-50 ...">
          <span>Ogami Floor</span>
          <span>{user.role.name}</span>
          <button onClick={logout}>Logout</button>
        </header>
        <main className="px-2 py-3 max-w-2xl mx-auto">
          <Outlet />
        </main>
      </div>
    </AuthGuard>
  );
}
```

### factoryFloorRoutes.tsx

```tsx
export const factoryFloorRoutes = (
  <Route element={<FactoryFloorLayout />}>
    {/* Production Operator */}
    <Route element={<PermissionGuard permission="production.wo.record">
      <Outlet /></PermissionGuard>}>
      <Route path="/floor/production" element={<ProductionDashboard />} />
      <Route path="/floor/production/:wo/record" element={<RecordOutput />} />
    </Route>

    {/* QC Inspector */}
    <Route element={<PermissionGuard permission="quality.inspections.manage">
      <Outlet /></PermissionGuard>}>
      <Route path="/floor/qc" element={<QcDashboard />} />
      <Route path="/floor/qc/inspect/:inspectionId" element={<QcMeasurements />} />
    </Route>

    {/* Maintenance Tech */}
    <Route element={<PermissionGuard permission="maintenance.wo.create">
      <Outlet /></PermissionGuard>}>
      <Route path="/floor/maintenance" element={<MaintenanceAlerts />} />
      <Route path="/floor/maintenance/:mwo/complete" element={<MaintenanceComplete />} />
    </Route>
  </Route>
);
```

### Page designs (3 role-specific views)

#### 1. Production Operator — Record Output (primary flow)

```
┌──────────────────────────────────┐
│ ← Floor        Production        │
├──────────────────────────────────┤
│ WO #WO-202606-0006               │
│ Wiper Bushing · Press #3         │
│ Mold: WB-001 · Target: 500 pcs   │
├──────────────────────────────────┤
│ This shift                        │
│ ✅ 423 produced · 12 rejected     │
│ Scrap rate: 2.8%                  │
│ [Record Output]                   │
├──────────────────────────────────┤
│ Quick Record:                     │
│ Good: [___]  Reject: [___]        │
│ [Submit]                          │
├──────────────────────────────────┤
│ Today's WOs:                      │
│ WO-606 · 423/500 (84%) · Active   │
│ WO-605 · 500/500 (100%) · Done ✓  │
└──────────────────────────────────┘
```

**API calls:** `POST /api/v1/production/work-orders/{wo}/outputs`
The existing endpoint. No new backend needed — just a mobile UI for it.

**Key UX:** The "Quick Record" form accepts good_count + reject_count only.
If reject_count > 0, it navigates to a defect-entry screen (optional, can be
skipped for simple cases). The SPA sends the minimal valid payload:
```json
{"good_count": 38, "reject_count": 2, "defects": [
  {"defect_type": "flash", "count": 1},
  {"defect_type": "short_shot", "count": 1}
]}
```

#### 2. QC Inspector — Record Measurements

```
┌──────────────────────────────────┐
│ ← Floor          QC Inspection   │
├──────────────────────────────────┤
│ QC-202606-0008 · Outgoing        │
│ WO-202606-0006 · Wiper Bushing   │
│ Sample: 5/200 pcs (AQL 0.65 LII) │
├──────────────────────────────────┤
│ [1] Outer Diameter               │
│     Nom: 22.50  Tol: ±0.05       │
│     22.48 ✓  [22.49]  [22.51]    │
│     22.47 ✓   22.50 ✓            │
│                                   │
│ [2] Inner Bore                    │
│     Nom: 10.00  Tol: ±0.02       │
│     9.98 ✓  [9.97]  [10.01]      │
│     ✗ 9.96! CRITICAL FAIL        │
│                                   │
│ [3] Flash Height (Visual)         │
│     ✓ No Flash                   │
├──────────────────────────────────┤
│ [Complete Inspection]             │
│ ⚠️ 1 critical failure detected    │
│ NCR will be auto-generated        │
└──────────────────────────────────┘
```

**API calls:**
- `POST /api/v1/quality/inspections/{id}/measurements` (existing route)
- `POST /api/v1/quality/inspections/{id}/complete` (existing route — triggers NCR auto-open if failed)

This is purely a mobile UI wrapping existing endpoints. The key UX win:
measurements are shown per-parameter with tolerance bands, and failures are
flagged red inline. The "Complete" button warns about auto-NCR.

#### 3. Maintenance Tech — Alerts + Complete

```
┌──────────────────────────────────┐
│ ← Floor      Maintenance         │
├──────────────────────────────────┤
│ 🔴 MWO-202606-0003 · Critical    │
│ Press #3 · Hydraulic leak        │
│ Opened: 14:32 · 2h ago           │
│ [Start]                          │
├──────────────────────────────────┤
│ 🟡 MWO-202606-0002 · Medium     │
│ WB-001 Mold · PM due at 5,000    │
│ To do: Clean + inspect cavities  │
│ [Start]                          │
├──────────────────────────────────┤
│ ✅ MWO-202606-0001 · Completed   │
│ WB-001 · PM #38 · 1.5h           │
├──────────────────────────────────┤
│ [Open MWO] → navigate to detail  │
│ [Complete] → downtime + parts    │
└──────────────────────────────────┘
```

**API calls:**
- `GET /api/v1/maintenance/work-orders?status=open,assigned`
- `PATCH /api/v1/maintenance/work-orders/{mwo}/start`
- `PATCH /api/v1/maintenance/work-orders/{mwo}/complete`

### Auth strategy

DriverLayout uses the MAIN session cookie (drivers log in via `/login`).
Factory floor workers use the **same pattern** — they are internal employees
with system accounts. No separate auth guard needed.

Each route is gated by the employee's standard permissions:
- Production operator: `production.wo.record`
- QC inspector: `quality.inspections.manage`
- Maintenance tech: `maintenance.wo.create` + `maintenance.wo.complete`

### WebSocket integration (live alerts)

The existing Reverb channels push real-time events:
- `mold.shot_limit_nearing` — maintenance tech gets notified when a mold crosses 80%
- `mold.shot_limit_reached` — production operator + maintenance tech
- `machine.breakdown` — maintenance tech gets notified immediately
- `ncr.created` — QC inspector gets notified

The mobile PWA listens to these channels and shows push-notification-style
toasts (using the browser's Notification API if granted).

### SPA router integration

```tsx
// In App.tsx, ADD (outside the main AuthGuard wrapper, like driverRoutes):
{factoryFloorRoutes}
```

The `FactoryFloorLayout` performs its own auth check (like `DriverLayout` does).

### What needs new backend endpoints: ZERO

All 3 mobile views use **existing API endpoints**. The only new code is SPA
components rendering the same API data in a mobile-first layout. This is
a pure frontend feature.

---

## Implementation sequence (build order for a solo developer)

| Week | Build | Why this order |
|---|---|---|
| 1 | **Mold Lifecycle Manager** | Most backend work, least SPA work. Adds columns + service. Mold detail page is one page. Reuses 90% of existing infrastructure. Quickest to demo. |
| 2 | **CAPA Effectiveness** | Zero new models — just service + column additions. Closes the IATF loop. The demo narrative (NCR → verify → recurrences → effective) is your strongest thesis defense moment. |
| 3 | **PPAP & APQP** | Two new models + 18-element checklist. The PO integration point is one guard call. Supplier portal PPAP view is a read-only page. |
| 4 | **Mobile Factory Floor** | Pure frontend. Buys you the "look, it works on the factory floor" demo. Sits on top of existing endpoints. |
| 5-6 | Buffer + testing + defense prep | Run full suite, rehearse demo narratives, write thesis chapter |

---

## Migration numbers (reserve these)

```
0223 — ppap_submissions + ppap_elements tables
0224 — ncr_actions effectiveness columns (ALTER — no new table)
0225 — non_conformance_reports effectiveness columns (ALTER — no new table)
0226 — molds lifecycle columns (ALTER — no new table)
```

---

## Permission catalog additions

```php
// In RolePermissionSeeder::permissionCatalog():

'quality' => [
    // ... existing ...
    ['slug' => 'quality.ppap.view',   'name' => 'View PPAP Submissions'],
    ['slug' => 'quality.ppap.manage', 'name' => 'Manage PPAP Submissions'],
],
```

---

## Role permission grants

```php
// In RolePermissionSeeder::roleCatalog():

'qc_inspector' => [
    // ... add to existing permissions array:
    'quality.ppap.view',
    'quality.ppap.manage',
],
'purchasing_officer' => [
    // ... add:
    'quality.ppap.view',
],
'production_manager' => [
    // ... add:
    'quality.ppap.view',
],
```
