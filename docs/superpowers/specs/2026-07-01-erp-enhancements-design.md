# Ogami ERP — Enhancement Design Spec

> 8 features to strengthen IATF 16949 compliance, deepen manufacturing intelligence,
> and fill coverage gaps. Thesis-scoped — all achievable within 6 months by a solo dev.

## Table of Contents

1. [SPC Module](#1-statistical-process-control-spc-module)
2. [Lot Traceability](#2-lot-traceability-system)
3. [COPQ Analytics](#3-copq-analytics-dashboard)
4. [Production Deepening](#4-production-module-deepening)
5. [Document Control System](#5-document-control-system)
6. [Shop Floor PWA](#6-shop-floor-pwa)
7. [KPI Scorecard](#7-kpi-scorecard-system)
8. [API Documentation](#8-api-documentation)

---

## 1. Statistical Process Control (SPC) Module

### Purpose

Transform raw inspection measurement data into actionable process intelligence.
IATF 16949 clause 9.1.1.1 requires statistical studies; this module provides
control charts, capability indices, and out-of-control alerting.

### Data Model

```
spc_control_charts
  id                  bigint PK
  product_id          bigint FK → products
  spec_item_id        bigint FK → inspection_spec_items
  chart_type          enum: xbar_r, xbar_s, imr, p_chart
  subgroup_size       integer (default 5)
  ucl                 decimal(15,6) — upper control limit
  lcl                 decimal(15,6) — lower control limit
  center_line         decimal(15,6) — process mean
  ucl_range           decimal(15,6) — for R/S chart
  lcl_range           decimal(15,6)
  center_range        decimal(15,6)
  limits_locked       boolean (false = recalculate on new data)
  limits_sample_count integer — how many subgroups were used for limits
  status              enum: active, monitoring, suspended
  created_at, updated_at

spc_data_points
  id                  bigint PK
  control_chart_id    bigint FK
  subgroup_number     integer
  subgroup_mean       decimal(15,6)
  subgroup_range      decimal(15,6)
  subgroup_std_dev    decimal(15,6) nullable
  individual_value    decimal(15,6) nullable — for I-MR charts
  moving_range        decimal(15,6) nullable — for I-MR charts
  sample_values       json — raw measurements in subgroup
  recorded_at         timestamp
  alerts              json nullable — triggered rule violations
  inspection_ids      json — source inspection IDs for drill-down
  created_at

spc_capability_studies
  id                  bigint PK
  product_id          bigint FK
  spec_item_id        bigint FK
  study_type          enum: short_term, long_term
  sample_size         integer
  process_mean        decimal(15,6)
  process_std_dev     decimal(15,6)
  usl                 decimal(15,6) — upper spec limit (from inspection_spec_items)
  lsl                 decimal(15,6) — lower spec limit
  cp                  decimal(8,4)
  cpk                 decimal(8,4)
  pp                  decimal(8,4) nullable — long-term
  ppk                 decimal(8,4) nullable
  normality_p_value   decimal(8,6) nullable
  histogram_data      json — bin counts for frontend histogram
  study_date          date
  performed_by        bigint FK → users
  notes               text nullable
  created_at, updated_at

spc_alerts
  id                  bigint PK
  control_chart_id    bigint FK
  data_point_id       bigint FK
  rule_code           enum: rule_1_beyond_3sigma, rule_2_two_of_three_beyond_2sigma,
                            rule_3_four_of_five_beyond_1sigma, rule_4_eight_same_side
  severity            enum: warning, critical
  acknowledged_by     bigint FK → users nullable
  acknowledged_at     timestamp nullable
  resolved_at         timestamp nullable
  notes               text nullable
  created_at
```

### Backend

**Module:** `Quality` (extend, not new module — SPC is a quality tool)

**Enums:**
- `SpcChartType`: xbar_r, xbar_s, imr, p_chart
- `SpcAlertRule`: rule_1_beyond_3sigma, rule_2_two_of_three_beyond_2sigma,
  rule_3_four_of_five_beyond_1sigma, rule_4_eight_same_side
- `SpcChartStatus`: active, monitoring, suspended

**Service: `SpcService`**
- `createChart(Product $product, InspectionSpecItem $specItem, SpcChartType $type, int $subgroupSize = 5)`
- `recordDataPoint(SpcControlChart $chart, array $measurements, array $inspectionIds)` — computes subgroup stats, evaluates run rules, triggers alerts
- `recalculateLimits(SpcControlChart $chart)` — from last N subgroups (configurable, default 25)
- `evaluateRunRules(SpcControlChart $chart, SpcDataPoint $point)` — returns array of violated rules
- `computeCapability(Product $product, InspectionSpecItem $specItem, int $sampleSize = 50)` — returns Cp/Cpk/Pp/Ppk
- `getChartData(SpcControlChart $chart, ?Carbon $from, ?Carbon $to)` — for frontend rendering

**Auto-population:** Listener on `InspectionCompleted` event — if an active SPC chart exists for the inspected product+spec item, new measurements automatically feed into the chart as data points.

**Alerting:** `SpcAlertTriggered` event → notification to QC Inspector + Production Manager roles.

**Controller: `SpcController`**
- GET `/quality/spc/charts` — list charts with filters (product, status)
- POST `/quality/spc/charts` — create new chart
- GET `/quality/spc/charts/{chart}` — chart detail with data points
- GET `/quality/spc/charts/{chart}/data` — paginated data points (for large datasets)
- POST `/quality/spc/charts/{chart}/recalculate` — trigger limit recalculation
- POST `/quality/spc/capability` — run capability study
- GET `/quality/spc/capability/{study}` — study detail
- GET `/quality/spc/alerts` — unacknowledged alerts
- POST `/quality/spc/alerts/{alert}/acknowledge`

### Frontend

**Pages:**
- `/quality/spc` — chart list with product filter, status chips, last-alert indicator
- `/quality/spc/charts/:id` — interactive control chart (Recharts):
  - X-axis: subgroup number or date
  - Y-axis: measurement value
  - Lines: UCL, LCL, center line (dashed)
  - Zone shading: 1σ/2σ/3σ bands
  - Data points color-coded: green (normal), yellow (warning rule), red (critical rule)
  - Tooltip: subgroup values, rule violations
  - Date range picker for zoom
  - Toggle: show/hide R-chart or S-chart below main chart
- `/quality/spc/capability` — capability study:
  - Select product + spec item
  - Histogram with normal curve overlay and USL/LSL vertical lines
  - Cp/Cpk values with traffic light (green ≥1.33, yellow 1.0-1.33, red <1.0)
  - Recommendation text based on Cpk value

### Effort Estimate

- Migrations + Models: 0.5 day
- SpcService (stats engine + run rules): 2 days
- Auto-population listener: 0.5 day
- Controller + routes + requests: 1 day
- Frontend chart page (Recharts): 2 days
- Capability study page: 1 day
- Tests: 2 days (statistical calculations need thorough testing)
- **Total: ~9-10 days**

---

## 2. Lot Traceability System

### Purpose

Enable forward and backward traceability across the entire supply chain:
raw material lot → production batch → finished goods → customer delivery.
Required by IATF 16949 clause 8.5.2 (identification and traceability).

### Data Model

```
material_lots
  id                  bigint PK
  lot_number          varchar — auto-generated: LOT-YYYYMM-NNNN
  item_id             bigint FK → items
  grn_item_id         bigint FK → grn_items
  supplier_batch_no   varchar nullable — supplier's own batch identifier
  supplier_coa_ref    varchar nullable — certificate of analysis reference
  quantity_received   decimal(15,4)
  quantity_remaining  decimal(15,4) — decremented on consumption
  quantity_on_hold    decimal(15,4) default 0 — held for QC
  unit                varchar
  received_at         timestamp
  expiry_date         date nullable — for materials with shelf life (e.g., resin moisture sensitivity)
  qc_status           enum: pending_qc, accepted, rejected, on_hold
  inspection_id       bigint FK → inspections nullable
  storage_location    varchar nullable
  notes               text nullable
  created_at, updated_at
  deleted_at

wo_material_consumptions
  id                  bigint PK
  work_order_id       bigint FK → work_orders
  wo_operation_id     bigint FK → wo_operations nullable — which operation consumed it
  material_lot_id     bigint FK → material_lots
  item_id             bigint FK → items
  quantity_consumed   decimal(15,4)
  consumed_at         timestamp
  consumed_by         bigint FK → users
  created_at

wo_output_batches
  id                  bigint PK
  batch_number        varchar — auto-generated: BATCH-YYYYMM-NNNN
  work_order_id       bigint FK → work_orders
  product_id          bigint FK → products
  quantity_produced   decimal(15,4)
  quantity_good       decimal(15,4)
  quantity_scrapped   decimal(15,4)
  produced_at         timestamp
  qc_status           enum: pending_qc, passed, failed, on_hold
  inspection_id       bigint FK → inspections nullable
  source_lot_ids      json — array of material_lot IDs consumed (denormalized for fast lookup)
  storage_location    varchar nullable
  notes               text nullable
  created_at, updated_at

-- Extend existing tables:
delivery_items ADD batch_id bigint FK → wo_output_batches nullable
complaints ADD batch_id bigint FK → wo_output_batches nullable
inspections ADD material_lot_id bigint FK nullable
inspections ADD output_batch_id bigint FK nullable
```

### Backend

**Service: `TraceabilityService`**

```php
class TraceabilityService
{
    // Forward trace: material lot → what was produced → where was it shipped
    public function traceForward(MaterialLot $lot): TraceResult
    {
        // lot → wo_material_consumptions → work_orders → wo_output_batches
        //   → delivery_items → deliveries → customers
        // Include: QC results at each stage, timestamps, operators
    }

    // Backward trace: output batch → what materials went in → which suppliers
    public function traceBackward(WoOutputBatch $batch): TraceResult
    {
        // batch → source_lot_ids → material_lots → grn_items → grns → vendors
        // Include: supplier batch nos, CoA refs, incoming QC results
    }

    // Full trace from complaint: both directions
    public function traceFromComplaint(Complaint $complaint): TraceResult
    {
        // complaint → batch → backward (find source materials)
        //                   → forward (find other customers who got same batch)
        // This is the "containment" query: who else might be affected
    }

    // Recall simulation: given a material lot, find all affected customers
    public function simulateRecall(MaterialLot $lot): RecallSimulation
    {
        // Forward trace → aggregate affected customers, quantities, delivery dates
        // Used for IATF containment planning
    }
}
```

**Integration points:**
- GRN receipt → auto-create MaterialLot per line item
- WO material issuance → create WoMaterialConsumption, decrement lot qty
- WO output recording → create WoOutputBatch with source lot refs
- Delivery creation → link delivery items to output batches
- Complaint creation → optional batch selection for tracing
- CoC generation → include lot/batch numbers on certificate

**Controller: `TraceabilityController`**
- GET `/quality/traceability/forward/{lot}` — forward trace
- GET `/quality/traceability/backward/{batch}` — backward trace
- GET `/quality/traceability/complaint/{complaint}` — complaint trace
- GET `/quality/traceability/search` — search by lot#, batch#, supplier batch, customer PO
- GET `/quality/traceability/recall-simulation/{lot}` — recall simulation

### Frontend

- `/quality/traceability` — search page with auto-complete for lots/batches
- Trace result visualization: vertical tree/flowchart showing:
  ```
  Supplier X (Batch: SUP-2026-001)
    └── GRN-202604-0011 (received 2026-04-15, qty: 500kg)
        └── Material Lot: LOT-202604-0023 (QC: accepted)
            ├── WO-202604-0006 (consumed 200kg)
            │   └── Batch: BATCH-202604-0015 (qty: 450 pcs, QC: passed)
            │       ├── DEL-202604-0008 → Toyota PH (200 pcs)
            │       └── DEL-202604-0012 → Nissan PH (250 pcs)
            └── WO-202604-0009 (consumed 300kg)
                └── Batch: BATCH-202604-0018 (qty: 670 pcs, QC: passed)
                    └── DEL-202604-0015 → Honda PH (670 pcs)
  ```
- Integrated into existing detail pages: GRN detail shows lots, WO detail shows consumption/output, Delivery detail shows batches, Complaint detail has "Trace" button

### Effort Estimate

- Migrations + Models: 1 day
- TraceabilityService: 2 days
- Existing module integration (GRN, WO, Delivery, Complaint modifications): 2 days
- Controller + routes: 0.5 day
- Frontend trace page + tree visualization: 2 days
- Frontend integration into existing detail pages: 1 day
- Tests: 2 days (trace queries need thorough testing)
- **Total: ~10-11 days**

---

## 3. COPQ Analytics Dashboard

### Purpose

Visualize Cost of Poor Quality using the PAF (Prevention-Appraisal-Failure) model.
Transforms NCR, complaint, and inspection data into financial impact analysis.

### Data Model

```
copq_snapshots (existing, extend with category breakdown)
  ADD prevention_cost     decimal(15,2) default 0
  ADD appraisal_cost      decimal(15,2) default 0
  ADD internal_failure_cost decimal(15,2) default 0
  ADD external_failure_cost decimal(15,2) default 0
  ADD breakdown_by_product  json nullable — {product_id: cost}
  ADD breakdown_by_supplier json nullable — {vendor_id: cost}
  ADD breakdown_by_defect   json nullable — {defect_type: {count, cost}}
  ADD revenue_for_period    decimal(15,2) nullable — for COPQ % revenue calc

copq_cost_entries
  id                  bigint PK
  snapshot_id         bigint FK → copq_snapshots nullable
  category            enum: prevention, appraisal, internal_failure, external_failure
  subcategory         varchar — e.g., 'scrap', 'rework', 'inspection_labor', 'warranty'
  source_type         varchar — polymorphic: NonConformanceReport, Complaint, Inspection
  source_id           bigint
  product_id          bigint FK nullable
  vendor_id           bigint FK nullable
  amount              decimal(15,2)
  quantity            decimal(15,4) nullable
  description         text nullable
  occurred_at         date
  created_at
```

### Backend

**Service: `CopqAnalyticsService`**

- `computeMonthlySnapshot(Carbon $month)` — enhanced version of existing cron:
  - Internal failure: sum NCR costs where disposition = scrap|rework (qty × unit cost)
  - External failure: complaint resolution costs + return processing costs
  - Appraisal: inspection count × average inspection time × inspector labor rate (from settings)
  - Prevention: training hours × trainer rate (from settings), SPC program cost (fixed monthly from settings)
- `getPareto(Carbon $from, Carbon $to, string $groupBy = 'defect_type')` — Pareto analysis
- `getTrend(int $months = 12)` — monthly COPQ with PAF breakdown
- `getByProduct(Carbon $from, Carbon $to)` — product-level cost ranking
- `getBySupplier(Carbon $from, Carbon $to)` — supplier quality cost ranking
- `getCopqPercentRevenue(int $months = 12)` — COPQ as % of invoiced revenue

**Controller: `CopqController`**
- GET `/quality/copq/summary` — current month + YTD
- GET `/quality/copq/trend` — monthly trend data
- GET `/quality/copq/pareto` — Pareto data with filters
- GET `/quality/copq/by-product` — product ranking
- GET `/quality/copq/by-supplier` — supplier ranking

### Frontend

`/quality/copq` — single-page dashboard with multiple chart panels:

1. **PAF Stacked Bar Chart** — monthly, 4 colors for P/A/F-internal/F-external
2. **COPQ % Revenue Trend Line** — with target line (configurable, e.g., 2%)
3. **Pareto Chart** — horizontal bar + cumulative line, top 10 defect types by cost
4. **Product Cost Table** — ranked by total quality cost, with sparkline trends
5. **Supplier Quality Table** — supplier name, rejection rate, quality cost, trend
6. **Period Selector** — month range picker, preset buttons (YTD, Last 6mo, Last 12mo)

### Effort Estimate

- Migrations + cost entry model: 0.5 day
- CopqAnalyticsService: 2 days
- Controller + routes: 0.5 day
- Frontend dashboard (5 chart panels): 3 days
- Tests: 1 day
- **Total: ~7 days**

---

## 4. Production Module Deepening

### Purpose

Transform the thin production module into a proper manufacturing execution layer
with operation routing, scheduling, operator tracking, and real-time monitoring.

### Data Model

```
product_routings
  id                  bigint PK
  product_id          bigint FK → products
  version             integer default 1
  is_active           boolean default true
  total_cycle_time    decimal(10,2) — sum of all operation cycle times
  notes               text nullable
  created_at, updated_at

routing_operations
  id                  bigint PK
  routing_id          bigint FK → product_routings
  sequence            integer — operation order (10, 20, 30...)
  operation_name      varchar — e.g., 'Injection Molding', 'Trimming', 'Assembly'
  work_center         varchar nullable — logical grouping
  machine_id          bigint FK → machines nullable — default machine
  mold_id             bigint FK → molds nullable — for injection operations
  setup_time_minutes  decimal(8,2) default 0
  cycle_time_minutes  decimal(8,2) — time per piece
  description         text nullable
  qc_required         boolean default false — triggers in-process QC after this op
  created_at, updated_at

wo_operations
  id                  bigint PK
  work_order_id       bigint FK → work_orders
  routing_operation_id bigint FK → routing_operations nullable
  sequence            integer
  operation_name      varchar
  machine_id          bigint FK → machines nullable
  mold_id             bigint FK → molds nullable
  operator_id         bigint FK → employees nullable
  status              enum: pending, setup, in_progress, paused, completed, skipped
  planned_start       timestamp nullable
  planned_end         timestamp nullable
  actual_start        timestamp nullable
  actual_end          timestamp nullable
  setup_start         timestamp nullable
  setup_end           timestamp nullable
  qty_planned         decimal(15,4)
  qty_completed       decimal(15,4) default 0
  qty_scrapped        decimal(15,4) default 0
  scrap_reason        varchar nullable
  downtime_minutes    decimal(10,2) default 0
  notes               text nullable
  created_at, updated_at

production_logs
  id                  bigint PK
  wo_operation_id     bigint FK → wo_operations
  operator_id         bigint FK → employees
  event_type          enum: start_setup, end_setup, start_production, pause,
                            resume, record_output, record_scrap, end_production,
                            downtime_start, downtime_end
  qty_value           decimal(15,4) nullable — for output/scrap events
  downtime_reason     varchar nullable
  notes               text nullable
  recorded_at         timestamp
  created_at
```

### Backend

**Service: `ProductionScheduleService`**
- `generateOperations(WorkOrder $wo)` — copies routing operations as wo_operations
- `assignMachine(WoOperation $op, Machine $machine, ?Carbon $plannedStart)` — schedule
- `assignOperator(WoOperation $op, Employee $operator)`
- `startOperation(WoOperation $op, Employee $operator)` — validates sequence (prev must be complete)
- `recordOutput(WoOperation $op, decimal $qty, decimal $scrap, ?string $scrapReason)`
- `completeOperation(WoOperation $op)` — auto-starts QC if qc_required
- `getScheduleByMachine(Carbon $from, Carbon $to)` — for Gantt view
- `getOperatorEfficiency(Employee $operator, Carbon $from, Carbon $to)` — actual vs standard

**Service: `ProductionRoutingService`**
- `createRouting(Product $product, array $operations)` — with version management
- `duplicateRouting(ProductRouting $routing)` — for new version
- `estimateCycleTime(ProductRouting $routing, decimal $qty)` — total time for given quantity

**Events:**
- `WoOperationStarted` — for real-time board
- `WoOperationCompleted` — triggers next operation or QC
- `ProductionOutputRecorded` — updates WO progress, mold shot count

**Controllers:**
- `ProductionRoutingController` — CRUD for routings + operations
- `WoOperationController` — start/pause/complete/record operations
- `ProductionScheduleController` — schedule queries, machine assignment
- `ProductionLogController` — event log queries

### Frontend

- `/production/routings` — routing list per product, operation sequence editor (drag-to-reorder)
- `/production/work-orders/:id` — enhanced WO detail with operation timeline:
  - Vertical step list showing each operation with status, assigned machine/operator, times
  - Progress bar per operation
  - Quick action buttons: Start, Pause, Record Output, Complete
- `/production/schedule` — machine schedule board:
  - Y-axis: machines
  - X-axis: date/time
  - Gantt bars: WO operations (color by WO, hover for details)
  - Drag to reschedule (updates planned dates)
- `/production/operators` — operator performance table:
  - Columns: name, shift, ops completed, output count, efficiency %, scrap rate
  - Date range filter

### Effort Estimate

- Migrations + Models (routing, wo_operations, logs): 1.5 days
- Enums: 0.5 day
- ProductionRoutingService: 1 day
- ProductionScheduleService: 2 days
- WoOperation lifecycle: 1.5 days
- Controllers + routes + requests: 1 day
- Frontend routing editor: 1.5 days
- Frontend WO operation timeline: 1.5 days
- Frontend schedule board (Gantt): 2 days
- Frontend operator performance: 1 day
- Tests: 2.5 days
- **Total: ~16 days**

---

## 5. Document Control System

### Purpose

IATF 16949 clause 7.5 — controlled documents with revision management,
approval workflows, distribution tracking, and periodic review enforcement.

### Data Model

```
-- Extend existing controlled_documents table:
controlled_documents ADD
  document_category     enum: procedure, work_instruction, form, specification,
                              quality_manual, sop, drawing
  review_interval_days  integer default 365
  next_review_date      date nullable
  print_control         enum: controlled, uncontrolled
  linked_machine_ids    json nullable — SOPs linked to machines
  linked_product_ids    json nullable — work instructions linked to products
  linked_process        varchar nullable — e.g., 'injection_molding', 'assembly'

document_revisions
  id                  bigint PK
  document_id         bigint FK → controlled_documents
  revision_number     varchar — e.g., 'Rev A', 'Rev 01'
  status              enum: draft, in_review, approved, obsolete
  file_path           varchar — stored document file
  change_summary      text — what changed from previous revision
  authored_by         bigint FK → users
  approved_at         timestamp nullable
  effective_date      date nullable
  obsoleted_at        timestamp nullable
  created_at, updated_at

document_approvals
  id                  bigint PK
  revision_id         bigint FK → document_revisions
  reviewer_id         bigint FK → users
  status              enum: pending, approved, rejected
  comments            text nullable
  reviewed_at         timestamp nullable
  created_at

document_acknowledgments
  id                  bigint PK
  revision_id         bigint FK → document_revisions
  user_id             bigint FK → users
  acknowledged_at     timestamp nullable
  created_at

document_distributions
  id                  bigint PK
  document_id         bigint FK → controlled_documents
  distributable_type  varchar — Department or User
  distributable_id    bigint
  created_at
```

### Backend

**Service: `DocumentControlService`**
- `createRevision(ControlledDocument $doc, UploadedFile $file, string $changeSummary)`
- `submitForReview(DocumentRevision $revision, array $reviewerIds)`
- `approveRevision(DocumentRevision $revision, User $reviewer, ?string $comments)`
- `rejectRevision(DocumentRevision $revision, User $reviewer, string $comments)`
- `publishRevision(DocumentRevision $revision)` — marks as approved, obsoletes previous, sets effective date
- `getDistributionList(ControlledDocument $doc)` — users who need to acknowledge
- `checkOverdueReviews()` — for cron, returns documents past next_review_date
- `downloadWithWatermark(DocumentRevision $revision, string $controlType)` — "CONTROLLED COPY" or "UNCONTROLLED"

**Cron:** `docs:check-reviews` already exists — enhance to send email alerts and create system alerts.

**Controller: `DocumentControlController`**
- CRUD for documents + revisions
- POST `/documents/{doc}/revisions/{rev}/submit` — submit for review
- POST `/documents/{doc}/revisions/{rev}/approve`
- POST `/documents/{doc}/revisions/{rev}/reject`
- POST `/documents/{doc}/revisions/{rev}/acknowledge`
- GET `/documents/{doc}/revisions/{rev}/download`
- GET `/documents/overdue-reviews`

### Frontend

- `/admin/documents` — document list with category filter, status chips, review status indicators
- `/admin/documents/:id` — document detail with revision history timeline, current distribution, acknowledgment progress bar
- `/admin/documents/:id/revisions/new` — upload new revision with change summary
- `/admin/documents/overdue` — overdue review dashboard

### Effort Estimate

- Migrations + Models: 1 day
- DocumentControlService: 2 days
- Controller + routes: 0.5 day
- PDF watermarking: 0.5 day
- Frontend pages: 2 days
- Tests: 1 day
- **Total: ~7 days**

---

## 6. Shop Floor PWA

### Purpose

Mobile-optimized interfaces for operators, QC inspectors, and warehouse staff
on the factory floor. Builds on existing Edge module and factory/warehouse pages.

### Architecture

Single SPA with role-based routing:
- `/factory/operator` — production operator view
- `/factory/qc` — QC inspector view
- `/warehouse/mobile` — warehouse staff view

PWA manifest already partially exists (`spa/public/driver-icon-*.png`).
Add `manifest.json` for offline-capable PWA with "Add to Home Screen" support.

### Operator View

**Start Screen:** List of WO operations assigned to this operator, grouped by status.

**Active Operation Screen:**
- Large display: current WO #, operation name, target qty, completed qty
- Big buttons: "Record Output" (opens numpad), "Record Scrap" (numpad + reason dropdown)
- Pause/Resume toggle
- Timer showing operation duration
- "Complete" button (requires minimum output count)

**Data flow:** Operator actions → `ProductionLogController` → updates `wo_operations`
→ broadcasts via Reverb → updates any watching dashboards.

### QC Inspector View

**Inspection Queue:** List of pending inspections (incoming, in-process, outgoing).

**Inspection Form:**
- Product name + spec reference
- Checklist of spec items with:
  - Dimension measurements: numeric input with USL/LSL displayed, auto-pass/fail
  - Visual checks: pass/fail toggle + camera button for defect photo
  - Functional tests: pass/fail + notes
- Summary: total pass/fail count, overall decision (pass/conditional/fail)
- Submit → creates Inspection record, triggers events

### Warehouse View

**Receive Screen:**
- Search by PO # → show expected items
- For each item: enter received qty, lot # (auto-generated or manual)
- Scan barcode (camera) → auto-fill item
- Submit → creates GRN + MaterialLots

**Issue Screen:**
- Search by WO # → show required materials
- For each material: select lot (FIFO suggested), enter qty
- Submit → creates WoMaterialConsumption, decrements lot qty

**Cycle Count Screen:**
- Select warehouse location
- Scan item → enter counted qty
- System shows expected qty → highlights discrepancy
- Submit adjustment → creates stock adjustment record

### Technical Notes

- Use `@vite-pwa/vite-plugin` for PWA support
- Large touch targets (min 48px), high contrast for factory lighting
- Offline queue: if network drops, cache actions in IndexedDB, sync when online
- Camera API for barcode scanning (no native app needed)

### Effort Estimate

- PWA setup + manifest: 0.5 day
- Operator view (3 screens): 2.5 days
- QC inspector view (2 screens): 2 days
- Warehouse view (3 screens): 2.5 days
- Backend adjustments (new endpoints or extensions): 1.5 days
- Offline queue: 1 day
- Tests: 1 day
- **Total: ~11 days**

---

## 7. KPI Scorecard System

### Purpose

Monthly management dashboard with configurable targets,
trend tracking, and exportable management report PDF.

### Data Model

```
kpi_definitions
  id                  bigint PK
  code                varchar unique — e.g., 'oee', 'dppm', 'on_time_delivery'
  name                varchar — display name
  module              varchar — owning module
  unit                enum: percentage, count, currency, days, ratio
  direction           enum: higher_is_better, lower_is_better
  target_value        decimal(15,4) nullable — configurable target
  warning_threshold   decimal(15,4) nullable — yellow zone boundary
  calculation_method  varchar — service method name
  is_active           boolean default true
  display_order       integer
  created_at, updated_at

kpi_snapshots
  id                  bigint PK
  definition_id       bigint FK → kpi_definitions
  period_year         integer
  period_month        integer
  actual_value        decimal(15,4)
  target_value        decimal(15,4)
  previous_value      decimal(15,4) nullable
  trend               enum: up, down, flat
  status              enum: on_target, warning, off_target
  breakdown           json nullable — additional detail
  computed_at         timestamp
  created_at

  UNIQUE(definition_id, period_year, period_month)
```

### Default KPI Definitions

| Code | Name | Module | Unit | Direction | Default Target |
|------|------|--------|------|-----------|---------------|
| oee | Overall Equipment Effectiveness | Production | % | higher | 85 |
| dppm | Defective Parts Per Million | Quality | count | lower | 500 |
| first_pass_yield | First Pass Yield | Quality | % | higher | 98 |
| on_time_delivery | On-Time Delivery Rate | SupplyChain | % | higher | 95 |
| supplier_quality | Supplier Quality Score | Purchasing | % | higher | 90 |
| copq_pct_revenue | COPQ as % of Revenue | Quality | % | lower | 2 |
| attendance_rate | Attendance Rate | Attendance | % | higher | 96 |
| ar_aging_60d | AR Over 60 Days | Accounting | % | lower | 5 |
| budget_utilization | Budget Utilization | Accounting | % | higher | 90 |
| ncr_closure_days | Avg NCR Closure Time | Quality | days | lower | 5 |
| inventory_turnover | Inventory Turnover | Inventory | ratio | higher | 6 |
| wo_completion_rate | WO Completion Rate | Production | % | higher | 95 |

### Backend

**Service: `KpiSnapshotService`**
- `computeAll(int $year, int $month)` — computes all active KPIs for the period
- `computeKpi(KpiDefinition $def, int $year, int $month)` — dispatches to calculation method
- `getScorecard(int $year, int $month)` — returns all KPIs with status/trend
- `getTrend(string $kpiCode, int $months = 12)` — monthly values for sparkline
- `generateReport(int $year, int $month)` — PDF management report

**Calculation methods** (each KPI has a dedicated calculator):
- `computeOee()` — (Availability × Performance × Quality) from WO data
- `computeDppm()` — (defects / total inspected) × 1,000,000
- `computeFirstPassYield()` — inspections passed on first attempt / total
- etc.

**Cron:** `kpi:compute-monthly` — runs on 2nd of each month for previous month.

**Controller: `KpiController`**
- GET `/dashboard/kpi/scorecard?year=&month=`
- GET `/dashboard/kpi/:code/trend`
- GET `/dashboard/kpi/report/:year/:month` — PDF download

### Frontend

`/dashboard/scorecard`

Layout: grid of KPI cards (4 columns), each showing:
- KPI name
- Current value (large, mono font)
- Target value (smaller)
- Status indicator: green circle (on target), yellow (warning), red (off target)
- Sparkline: last 12 months trend
- Delta: vs previous month (▲ or ▼ with %)

Top bar: month/year selector + "Export PDF" button.

Drill-down: click KPI card → navigates to relevant module page with pre-filtered dates.

### Effort Estimate

- Migrations + Models + Seeder: 0.5 day
- KpiSnapshotService + 12 calculators: 3 days
- Controller + routes: 0.5 day
- Frontend scorecard page: 2 days
- PDF report template: 1 day
- Cron job: 0.5 day
- Tests: 1.5 days
- **Total: ~9 days**

---

## 8. API Documentation (Swagger/OpenAPI)

### Purpose

Auto-generated interactive API documentation from controller annotations.
Demonstrates professional API design practices for thesis panel.

### Implementation

**Package:** `darkaonline/l5-swagger`

**Scope (phased):**
1. Auth endpoints (login, logout, user, change-password)
2. HR endpoints (employees CRUD, departments, positions)
3. Production endpoints (work orders, operations)
4. Quality endpoints (inspections, NCRs, SPC)
5. Inventory endpoints (items, GRN, stock)
6. Remaining modules

**Annotation pattern:**
```php
/**
 * @OA\Get(
 *     path="/api/v1/employees",
 *     operationId="listEmployees",
 *     tags={"HR - Employees"},
 *     summary="List employees with filters",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="department_id", in="query", @OA\Schema(type="string")),
 *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"active","resigned"})),
 *     @OA\Response(response=200, description="Paginated employee list",
 *         @OA\JsonContent(type="object",
 *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Employee"))
 *         )
 *     ),
 *     @OA\Response(response=401, description="Unauthenticated"),
 *     @OA\Response(response=403, description="Forbidden")
 * )
 */
```

**Available at:** `/api/documentation` (Swagger UI)

### Effort Estimate

- Package install + config: 0.5 day
- Base schemas (common types): 0.5 day
- Annotate priority controllers (Auth, HR, Production, Quality): 2 days
- Remaining controllers: 1-2 days (can be incremental)
- **Total: ~3-4 days**

---

## Implementation Priority & Sprint Plan

### Recommended Order (by thesis impact per effort)

| Priority | Feature | Days | Thesis Impact |
|----------|---------|------|---------------|
| 1 | SPC Module | 10 | Extremely High — THE IATF differentiator |
| 2 | Lot Traceability | 11 | Extremely High — core automotive requirement |
| 3 | COPQ Analytics | 7 | High — bridges quality + finance |
| 4 | Production Deepening | 16 | High — core manufacturing module |
| 5 | KPI Scorecard | 9 | Medium-High — executive dashboard |
| 6 | Document Control | 7 | Medium-High — IATF requirement |
| 7 | Shop Floor PWA | 11 | Medium — demonstrates usability |
| 8 | API Documentation | 4 | Medium — professional practice |
| **Total** | | **~75 days** | |

### Sprint Allocation (4-week sprints)

**Sprint N+1:** SPC + COPQ (17 days) — quality intelligence package
**Sprint N+2:** Lot Traceability + Document Control (18 days) — IATF compliance package
**Sprint N+3:** Production Deepening (16 days) — manufacturing execution
**Sprint N+4:** KPI Scorecard + Shop Floor PWA + API Docs (24 days) — operations intelligence

### Dependencies

```
SPC ← Quality measurements (exists)
Lot Traceability ← Inventory items (exists), GRN (exists), WO (exists)
COPQ ← NCR data (exists), SPC alerts (after SPC)
Production Deepening ← MRP machines (exists), Products (exists)
KPI Scorecard ← most modules (exists), SPC Cpk (after SPC)
Document Control ← DocumentVault (exists)
Shop Floor PWA ← Production operations (after Production Deepening)
API Docs ← all controllers (exists)
```

Build order: SPC → COPQ (uses SPC alerts) → Lot Traceability → Production →
Shop Floor PWA (uses Production operations) → KPI (uses SPC+Production data) →
Document Control → API Docs.

---

## Design Decisions & Trade-offs

1. **SPC inside Quality module** (not standalone) — SPC is a quality tool, not a
   separate business domain. Keeps the module count at 17 and avoids cross-module
   ownership ambiguity for control charts that directly extend inspections.

2. **Lot model on material side only** — output batches link to consumed lots via
   JSON array instead of a full many-to-many pivot. Simpler, covers 95% of trace
   queries. The 5% (which specific lot produced which specific piece) would require
   serialization which is out of scope for plastic injection molding.

3. **COPQ uses PAF model** — industry standard for quality cost categorization.
   Alternative (Taguchi loss function) is theoretically stronger but harder to
   compute and explain to a thesis panel.

4. **Operation routing copied to WO** — not referenced. When routing changes,
   existing WOs keep their original plan. This is standard MES behavior:
   engineering changes don't retroactively modify in-progress production.

5. **PWA over native app** — no app store deployment, camera API covers barcode
   scanning needs, works on any device with a browser. Trade-off: slightly less
   reliable offline than native, but adequate for factory with WiFi.

6. **KPI calculations are monthly snapshots, not real-time** — real-time KPIs
   require materialized views or event-sourcing. Monthly snapshots are simple,
   sufficient for management reporting, and match the reporting cadence of
   Philippine manufacturing companies.
