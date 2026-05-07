# Series C (C1–C5) — Chain Process Automation Hardening

> **Mode note:** This plan is delivered from Architect mode. Implementation must be carried out in `code` mode (preferably `superpowers-tdd` for TDD discipline and `kwatog-quality-gate` for the final lint/typecheck/test gate). The user must switch modes themselves; this plan does not request a mode switch.

---

## 0. Scope summary

C1–C5 from [`docs/NEW-TASKS-V2.md`](../docs/NEW-TASKS-V2.md:316) are **automation/wiring tasks**, not greenfield modules. Most domain services already exist:

- [`MrpEngineService`](../api/app/Modules/MRP/Services/MrpEngineService.php:1), [`CapacityPlanningService`](../api/app/Modules/MRP/Services/CapacityPlanningService.php:1)
- [`AutoPurchaseOrderService`](../api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php:1), [`PurchaseOrderService`](../api/app/Modules/Purchasing/Services/PurchaseOrderService.php:1), [`ThreeWayMatchService`](../api/app/Modules/Purchasing/Services/ThreeWayMatchService.php:1)
- [`SalesOrderService`](../api/app/Modules/CRM/Services/SalesOrderService.php:1), [`WorkOrderService`](../api/app/Modules/Production/Services/WorkOrderService.php:1)
- [`GrnService`](../api/app/Modules/Inventory/Services/GrnService.php:1), [`AutoReplenishmentService`](../api/app/Modules/Inventory/Services/AutoReplenishmentService.php:1)
- [`InspectionService`](../api/app/Modules/Quality/Services/InspectionService.php:1), [`CoCService`](../api/app/Modules/Quality/Services/CoCService.php:1)
- [`OnboardingService`](../api/app/Modules/HR/Services/OnboardingService.php:1), [`UserProvisioningService`](../api/app/Modules/HR/Services/UserProvisioningService.php:1), [`FinalPayService`](../api/app/Modules/HR/Services/FinalPayService.php:1), [`SeparationService`](../api/app/Modules/HR/Services/SeparationService.php:1)

Existing events: [`SalesOrderConfirmed`](../api/app/Modules/CRM/Events/SalesOrderConfirmed.php:1), [`WorkOrderStatusChanged`](../api/app/Modules/Production/Events/WorkOrderStatusChanged.php:1), [`StockMovementCompleted`](../api/app/Modules/Inventory/Events/StockMovementCompleted.php:1), [`DeliveryConfirmed`](../api/app/Modules/SupplyChain/Events/DeliveryConfirmed.php:1), [`MrpPlanGenerated`](../api/app/Modules/MRP/Events/MrpPlanGenerated.php:1).

The work is: add the missing events, wire listeners that orchestrate the chain, broadcast a unified `ChainStepAdvanced` event for real-time UI, and add a bottleneck-detection service + dashboard widget.

---

## 1. C1 — Order-to-Cash Auto-Chain

### 1.1 New events to add

| Event | File | When fired |
|---|---|---|
| `WorkOrderCompleted` | `api/app/Modules/Production/Events/WorkOrderCompleted.php` | When `WorkOrderService::complete()` transitions WO to `done` |
| `InspectionPassed` | `api/app/Modules/Quality/Events/InspectionPassed.php` | When `InspectionService` records a passing result |
| `InspectionFailed` | `api/app/Modules/Quality/Events/InspectionFailed.php` | When `InspectionService` records failing result |

Each event implements `ShouldBroadcast` and exposes the entity hash_id + chain context (so the C4 listener can fan-out).

### 1.2 New listeners (orchestrators)

All in `api/app/Modules/<Module>/Listeners/`. Each is `ShouldQueue`, idempotent, wrapped in `DB::transaction()`, and tagged with `chain_automation` audit reason.

| Listener | Subscribes to | Calls |
|---|---|---|
| `InitiateOrderToCashChain` | `SalesOrderConfirmed` | `MrpEngineService::runForSalesOrder()` → for each line creates a `WorkOrder` via `WorkOrderService::createForSalesOrderLine()` → `CapacityPlanningService::autoSchedule($wo)` → on schedule success calls `MaterialReservationService::reserve($wo)` → notifies Production Manager. On no-capacity, notifies PPC Head. |
| `TriggerOutgoingQC` | `WorkOrderCompleted` (only when WO has SO link) | Creates pending outgoing `Inspection` row via `InspectionService::createPending(stage: outgoing, entity: workOrder)`. Sample size from `AqlSampleSizeService`. Notifies QC team. |
| `CreateDeliveryDraftOnQcPass` | `InspectionPassed` (filter: `stage === outgoing`) | Creates `Delivery` draft (`status: scheduled`) + `DeliveryItem` rows. Calls `CoCService::generate($delivery)`. Notifies Warehouse. |
| `CreateDraftInvoiceOnDelivery` | `DeliveryConfirmed` (already exists) | Extend existing [`NotifyFinanceOnDeliveryConfirmed`](../api/app/Modules/Accounting/Listeners/NotifyFinanceOnDeliveryConfirmed.php:1) to also create draft `Invoice` via `InvoiceService::createDraftFromDelivery()`. Notifies Finance. |

### 1.3 Service additions / signatures

- `WorkOrderService::createForSalesOrderLine(SalesOrder $so, SalesOrderItem $line, MrpPlan $plan): WorkOrder` — sets `is_auto_generated=true`, `auto_generated_reason='chain_automation'`.
- `InspectionService::createPending(string $stage, Model $entity, int $batchQty): Inspection` — assigns no inspector yet.
- `InvoiceService::createDraftFromDelivery(Delivery $delivery): Invoice` — pre-fills lines from delivery items + price agreements; status = `draft`.

### 1.4 Registration

Update `api/app/Providers/EventServiceProvider.php` (or use Laravel 11 attribute discovery) to bind the four event→listener pairs.

### 1.5 Tests (PHPUnit, place in `api/tests/Feature/Chain/`)

- `OrderToCashChainTest::test_confirming_so_runs_mrp_creates_wos_and_reserves_materials`
- `OrderToCashChainTest::test_wo_completion_creates_pending_outgoing_inspection`
- `OrderToCashChainTest::test_outgoing_pass_creates_delivery_draft_with_coc`
- `OrderToCashChainTest::test_delivery_confirmed_creates_draft_invoice`
- Each test asserts: row created, `is_auto_generated=true`, audit log written, notification dispatched (use `Notification::fake()` and `Event::fake()` for partial assertions).

---

## 2. C2 — Procure-to-Pay Auto-Chain

### 2.1 New events

| Event | File |
|---|---|
| `PurchaseRequestApproved` | `api/app/Modules/Purchasing/Events/PurchaseRequestApproved.php` |
| `PurchaseOrderApproved` | `api/app/Modules/Purchasing/Events/PurchaseOrderApproved.php` |
| `GoodsReceiptNoteCreated` | `api/app/Modules/Inventory/Events/GoodsReceiptNoteCreated.php` |

Fire `PurchaseRequestApproved` from `ApprovalService` when a PR's final approval level completes. Same for `PurchaseOrderApproved`. `GoodsReceiptNoteCreated` fires from `GrnService::create()`.

### 2.2 New listeners

| Listener | Subscribes to | Behavior |
|---|---|---|
| `ConsolidatePurchaseOrders` | `PurchaseRequestApproved` | Groups all newly-approved PRs by vendor; calls existing `AutoPurchaseOrderService::consolidate($vendor, $prItems)`; if total < ₱50K, auto-marks PO as approved (skip approval workflow) and fires `PurchaseOrderApproved`; otherwise leaves PO pending VP approval. |
| `SendPOToSupplier` | `PurchaseOrderApproved` | Renders PDF via [`PurchaseOrderPdfService`](../api/app/Modules/Purchasing/Services/PurchaseOrderPdfService.php:1), emails to vendor via new `PurchaseOrderToSupplierMail` notification. Marks `sent_to_supplier_at`. |
| `TriggerIncomingQC` | `GoodsReceiptNoteCreated` | Creates pending incoming `Inspection` (stage: `incoming`). Notifies QC team. |
| `AcceptGRNAndDraftBill` | `InspectionPassed` (filter: `stage === incoming`) | Calls `GrnService::accept($grn)` (updates stock + weighted-avg cost). Calls `ThreeWayMatchService::match($po, $grn, null)` to validate. Creates draft `Bill` via new `BillService::createDraftFromGrn(GoodsReceiptNote $grn): Bill`. Notifies Finance. |
| `RejectGRNOnQcFail` | `InspectionFailed` (filter: `stage === incoming`) | Calls `GrnService::reject($grn, $reason)`; creates NCR via existing `NcrService` (already wired). |

### 2.3 Threshold constant

Add `'auto_approve_po_threshold' => 50000` to `api/config/purchasing.php` (new file). Reference via `config()` to allow per-env override.

### 2.4 Tests (`api/tests/Feature/Chain/ProcureToPayChainTest.php`)

- `test_pr_approval_consolidates_pos_per_vendor`
- `test_po_under_threshold_auto_approves_and_emails_supplier`
- `test_po_over_threshold_waits_for_vp_approval`
- `test_grn_creation_triggers_incoming_inspection`
- `test_incoming_qc_pass_accepts_grn_and_drafts_bill`
- `test_incoming_qc_fail_rejects_grn_and_creates_ncr`

---

## 3. C3 — Hire-to-Retire Auto-Chain

Most pieces already exist:

- Onboarding: [`OnboardingService`](../api/app/Modules/HR/Services/OnboardingService.php:1), [`SendOnboardingReminders`](../api/app/Console/Commands/SendOnboardingReminders.php:1) command (already scheduled).
- Payroll auto-period: [`CreateAutoPayrollPeriod`](../api/app/Console/Commands/CreateAutoPayrollPeriod.php:1) (already scheduled).
- Final pay: [`FinalPayService`](../api/app/Modules/HR/Services/FinalPayService.php:1), [`SeparationService`](../api/app/Modules/HR/Services/SeparationService.php:1).

### 3.1 Gaps to fill

| Missing piece | File | Behavior |
|---|---|---|
| `EmployeeCreated` event | `api/app/Modules/HR/Events/EmployeeCreated.php` | Fired by `EmployeeService::create()` after the row is committed. |
| `InitializeLeaveBalances` listener | `api/app/Modules/Leave/Listeners/InitializeLeaveBalances.php` | Subscribes to `EmployeeCreated`; iterates `leave_types`; pro-rates against `date_hired` vs current calendar year; inserts `employee_leave_balances` rows. Idempotent via unique key (`employee_id`,`leave_type_id`,`year`). |
| `PayrollPeriodFinalized` event | `api/app/Modules/Payroll/Events/PayrollPeriodFinalized.php` | Fired by `PayrollPeriodService::finalize()`. |
| `GeneratePayslipsAndNotify` listener | `api/app/Modules/Payroll/Listeners/GeneratePayslipsAndNotify.php` | Generates PDF payslips (queue per employee) + bank file CSV + GL post + per-employee notification. |
| `SeparationInitiated` event | `api/app/Modules/HR/Events/SeparationInitiated.php` | Fired by `SeparationService::initiate()`. |
| `OpenClearanceItems` listener | `api/app/Modules/HR/Listeners/OpenClearanceItems.php` | Creates `Clearance` rows for all department heads listed in seed (`docs/SEEDS.md`). Notifies each. |
| `ClearanceFullySigned` event | `api/app/Modules/HR/Events/ClearanceFullySigned.php` | Fired by `ClearanceService::sign()` once all rows complete. |
| `ComputeFinalPayAndDeactivate` listener | `api/app/Modules/HR/Listeners/ComputeFinalPayAndDeactivate.php` | Calls `FinalPayService::compute($employee)`, generates BIR 2316 PDF, calls `UserProvisioningService::deactivateForEmployee($employee)`. |
| Year-rollover scheduled command | `api/app/Console/Commands/ResetLeaveBalancesForYear.php` | Runs Jan 1 00:01 via `app/Console/Kernel.php`. Resets balances per `is_carried_over_to_next_year` rule on each leave_type. |

### 3.2 Tests (`api/tests/Feature/Chain/HireToRetireChainTest.php`)

- `test_creating_employee_initializes_pro_rated_leave_balances`
- `test_finalizing_payroll_generates_payslips_and_bank_file`
- `test_initiating_separation_opens_all_clearance_items`
- `test_full_clearance_signoff_computes_final_pay_and_deactivates_account`
- `test_year_rollover_command_resets_balances_per_carryover_rule`

---

## 4. C4 — Real-Time Chain Progress Tracker

### 4.1 Backend — single canonical event

Create `api/app/Common/Events/ChainStepAdvanced.php`:

```
class ChainStepAdvanced implements ShouldBroadcast {
    public function __construct(
        public string $entityType,    // 'sales_order'|'purchase_order'|'work_order'|'delivery'|'grn'
        public string $entityHashId,  // never raw id
        public string $newStatus,
        public string $activeStep,
        public array  $completedSteps,
        public ?string $actorName = null
    ) {}
    public function broadcastOn(): Channel {
        return new Channel("chain.{$this->entityType}.{$this->entityHashId}");
    }
}
```

### 4.2 Centralized broadcast helper

Create `api/app/Common/Services/ChainBroadcaster.php` with a method `broadcastFor(Model $entity, string $newStatus, ?User $actor = null)`. It maps the entity class to:
- `entityType` slug (`SalesOrder` → `sales_order`),
- `activeStep` and `completedSteps` derived from the chain definitions in [`spa/src/lib/chains/index.ts`](../spa/src/lib/chains/index.ts:1) — mirrored on the backend in a new `app/Common/Support/ChainDefinitions.php` (single source of truth, exported to TS via Tasks E2/X build artifact later — out of scope here).

Every status-mutation in `SalesOrderService`, `PurchaseOrderService`, `WorkOrderService`, `DeliveryService`, `GrnService`, `InspectionService` calls `ChainBroadcaster::broadcastFor(...)` after committing.

### 4.3 Frontend hook

Create [`spa/src/hooks/useChainProgress.ts`](../spa/src/hooks/useChainProgress.ts:1):

```
export function useChainProgress(entityType: string, entityId: string) {
  const queryClient = useQueryClient();
  useEffect(() => {
    const channel = window.Echo.channel(`chain.${entityType}.${entityId}`);
    channel.listen('.ChainStepAdvanced', (data: ChainStepEvent) => {
      queryClient.invalidateQueries({ queryKey: [entityType, entityId] });
      toast(`${data.activeStep} updated${data.actorName ? ' by ' + data.actorName : ''}`, { icon: '🔁' });
    });
    return () => { window.Echo.leave(`chain.${entityType}.${entityId}`); };
  }, [entityType, entityId, queryClient]);
}
```

Add a `ChainStepEvent` type to [`spa/src/types/chain.ts`](../spa/src/types/chain.ts:1).

### 4.4 Wire into existing detail pages

Add `useChainProgress(<type>, id)` near the top of:

- [`spa/src/pages/crm/sales-orders/detail.tsx`](../spa/src/pages/crm/sales-orders/detail.tsx:1) (verify exact path; create if missing)
- [`spa/src/pages/purchasing/purchase-orders/detail.tsx`](../spa/src/pages/purchasing/purchase-orders/detail.tsx:1)
- [`spa/src/pages/production/work-orders/detail.tsx`](../spa/src/pages/production/work-orders/detail.tsx:1)
- [`spa/src/pages/supply-chain/deliveries/detail.tsx`](../spa/src/pages/supply-chain/deliveries/detail.tsx:1)

### 4.5 Echo / Reverb setup

Verify [`spa/src/lib/echo.ts`](../spa/src/lib/echo.ts:1) is initialized; use existing public-channel pattern (`Channel`, not `PrivateChannel`) since chain progress is non-sensitive (only IDs and status, no PII). Document this decision in the listener docblock so a future security review (skill: [`security-review.md`](../.roo/skills/kwatog/security-review.md)) can revisit.

### 4.6 Tests

- Backend feature test: `tests/Feature/Chain/ChainBroadcastingTest.php` using `Event::fake([ChainStepAdvanced::class])` — confirm one broadcast per status change, correct payload shape.
- Frontend Vitest: `spa/src/hooks/useChainProgress.test.ts` — mock `window.Echo`, assert the hook subscribes and invalidates the query on event.

---

## 5. C5 — Chain Bottleneck Detection

### 5.1 Service

Create [`api/app/Common/Services/ChainBottleneckService.php`](../api/app/Common/Services/ChainBottleneckService.php:1) with one method per chain step + a single aggregator `detectAll(): array`. Thresholds in `api/config/chain.php`:

```
'bottlenecks' => [
    'so_at_mrp_planned'         => ['hours' => 48,  'audience' => 'ppc_head'],
    'wo_confirmed_unmaterialized' => ['hours' => 24, 'audience' => 'warehouse'],
    'inspection_outgoing_pending' => ['hours' => 4,  'audience' => 'qc_head'],
    'delivery_scheduled'        => ['hours' => 24, 'audience' => 'impex'],
    'invoice_draft'             => ['hours' => 24, 'audience' => 'finance_officer'],
    'pr_pending'                => ['hours' => 48, 'audience' => 'next_approver'],
    'bill_unpaid'               => ['hours' => 720,'audience' => 'finance_officer'],
],
```

Each detector returns rows: `['entity_type', 'hash_id', 'doc_number', 'stuck_since', 'hours_stuck', 'audience']`.

### 5.2 Scheduled command

Create `api/app/Console/Commands/RunChainBottleneckCheck.php` scheduled hourly. Persists results into the existing `alerts` table (migration [`0111_create_alerts_table.php`](../api/database/migrations/0111_create_alerts_table.php:1)) with `category='chain_bottleneck'`. Idempotent — won't double-create alert for same `(entity_type, entity_id, category)` open alert.

### 5.3 API endpoint

`GET /api/v1/chain/bottlenecks` — returns groups by step.
- Controller: `api/app/Common/Http/Controllers/ChainBottleneckController.php`.
- Permission: `dashboard.view_bottlenecks` (seed in `RolePermissionSeeder`).
- Route: register under `api/routes/api.php` with `['auth:sanctum', 'permission:dashboard.view_bottlenecks']`.

### 5.4 Frontend dashboard widget

Create `spa/src/components/dashboard/ChainBottleneckWidget.tsx` following Panel pattern from [`docs/DESIGN-SYSTEM.md`](../docs/DESIGN-SYSTEM.md:534). Uses TanStack Query (`['chain-bottlenecks']`, 60s `staleTime`). Renders rows with:

- Step label (text-sm primary)
- Count chip (variant: warning if < 5, danger if ≥ 5)
- Click row → navigates to filtered list of those entities

Include the widget in:

- Plant Manager dashboard
- PPC Head dashboard
- Finance Officer dashboard

Each widget instance pre-filters the rows that match its audience's relevant steps (so Finance only sees `invoice_draft` and `bill_unpaid`).

### 5.5 Tests

- `tests/Unit/ChainBottleneckServiceTest.php` — feed fixtures, assert correct rows returned for each detector.
- `tests/Feature/RunChainBottleneckCheckTest.php` — runs the scheduled command, asserts alerts created and idempotent on second run.
- `spa/src/components/dashboard/ChainBottleneckWidget.test.tsx` — renders all 5 page states (loading/error/empty/data/stale).

---

## 6. Cross-cutting concerns

### 6.1 Mandatory rules to verify per file

Per [`CLAUDE.md`](../CLAUDE.md:507) and [`docs/PATTERNS.md`](../docs/PATTERNS.md:1716):

- Every new model gets `HasHashId`. (None expected here — we are not adding tables.)
- Every API Resource returns `hash_id`, never raw `id`. The `ChainStepAdvanced` payload uses `entityHashId`.
- Every listener wraps its writes in `DB::transaction()` even if the underlying service already does — listener-level transaction protects multi-service orchestrations.
- Auto-generated rows tagged `is_auto_generated=true`, `auto_generated_reason='chain_automation'`. Confirm columns exist (already added in migrations [`0114`](../api/database/migrations/0114_add_is_auto_generated_to_ncr.php:1) and [`0116`](../api/database/migrations/0116_add_is_auto_generated_to_purchase_orders.php:1)). Add similar columns where missing via a single new migration `0122_add_is_auto_generated_to_chain_entities.php`.
- Every list/detail page touched honors the 5 mandatory states.
- All numbers in widget tables use `font-mono tabular-nums`; status uses `<Chip>` with semantic variant.
- No color on canvas — bottleneck widget rows are gray; only chip + count are colored.

### 6.2 Permissions to seed

Add to `RolePermissionSeeder`:

- `dashboard.view_bottlenecks` → roles: plant_manager, ppc_head, finance_officer, system_admin.

### 6.3 Performance — N+1 prevention

In `ChainBottleneckService` detectors, eager load the relationships the API Resource accesses. See [`eloquent-performance.md`](../.roo/skills/kwatog/eloquent-performance.md). Add DB indexes on `(status, updated_at)` for the seven tables queried (one migration: `0123_add_indexes_for_chain_bottleneck_queries.php`).

### 6.4 Security review checkpoints

Per [`security-review.md`](../.roo/skills/kwatog/security-review.md):

- Public broadcast channel for chain progress is acceptable because payload contains no PII or money — only doc numbers, statuses, and an actor display name. Add a docblock to `ChainStepAdvanced` stating this and forbidding payload expansion without re-review.
- Bottleneck endpoint enforces `permission:dashboard.view_bottlenecks` server-side. Frontend `<CanDo>` is UX only.
- Auto-emailed POs go to `vendors.email` — validate `email` field on Vendor model is a real email, sanitize before render. If missing, listener queues a "supplier email missing" notification instead of crashing.

### 6.5 Quality gate (mandatory before marking done)

Per [`code-quality-gate.md`](../.roo/skills/kwatog/code-quality-gate.md):

```
cd api && composer install && php artisan migrate:fresh --seed && php artisan test
cd spa && npm ci && npm run lint && npm run typecheck && npm run test
```

All must pass. Report results in PR body.

---

## 7. File-by-file create/modify list

### 7.1 New backend files

```
api/app/Common/Events/ChainStepAdvanced.php
api/app/Common/Services/ChainBroadcaster.php
api/app/Common/Services/ChainBottleneckService.php
api/app/Common/Support/ChainDefinitions.php
api/app/Common/Http/Controllers/ChainBottleneckController.php
api/app/Console/Commands/RunChainBottleneckCheck.php
api/app/Console/Commands/ResetLeaveBalancesForYear.php
api/config/chain.php
api/config/purchasing.php
api/database/migrations/0122_add_is_auto_generated_to_chain_entities.php
api/database/migrations/0123_add_indexes_for_chain_bottleneck_queries.php

# Events
api/app/Modules/Production/Events/WorkOrderCompleted.php
api/app/Modules/Quality/Events/InspectionPassed.php
api/app/Modules/Quality/Events/InspectionFailed.php
api/app/Modules/Purchasing/Events/PurchaseRequestApproved.php
api/app/Modules/Purchasing/Events/PurchaseOrderApproved.php
api/app/Modules/Inventory/Events/GoodsReceiptNoteCreated.php
api/app/Modules/HR/Events/EmployeeCreated.php
api/app/Modules/HR/Events/SeparationInitiated.php
api/app/Modules/HR/Events/ClearanceFullySigned.php
api/app/Modules/Payroll/Events/PayrollPeriodFinalized.php

# Listeners (C1)
api/app/Modules/CRM/Listeners/InitiateOrderToCashChain.php
api/app/Modules/Production/Listeners/TriggerOutgoingQC.php
api/app/Modules/Quality/Listeners/CreateDeliveryDraftOnQcPass.php

# Listeners (C2)
api/app/Modules/Purchasing/Listeners/ConsolidatePurchaseOrders.php
api/app/Modules/Purchasing/Listeners/SendPOToSupplier.php
api/app/Modules/Quality/Listeners/TriggerIncomingQC.php
api/app/Modules/Quality/Listeners/AcceptGRNAndDraftBill.php
api/app/Modules/Quality/Listeners/RejectGRNOnQcFail.php

# Listeners (C3)
api/app/Modules/Leave/Listeners/InitializeLeaveBalances.php
api/app/Modules/Payroll/Listeners/GeneratePayslipsAndNotify.php
api/app/Modules/HR/Listeners/OpenClearanceItems.php
api/app/Modules/HR/Listeners/ComputeFinalPayAndDeactivate.php

# Mailables / Notifications
api/app/Modules/Purchasing/Notifications/PurchaseOrderToSupplierMail.php

# Tests
api/tests/Feature/Chain/OrderToCashChainTest.php
api/tests/Feature/Chain/ProcureToPayChainTest.php
api/tests/Feature/Chain/HireToRetireChainTest.php
api/tests/Feature/Chain/ChainBroadcastingTest.php
api/tests/Feature/RunChainBottleneckCheckTest.php
api/tests/Unit/ChainBottleneckServiceTest.php
```

### 7.2 New frontend files

```
spa/src/hooks/useChainProgress.ts
spa/src/api/chain.ts                    # GET /chain/bottlenecks wrapper
spa/src/components/dashboard/ChainBottleneckWidget.tsx
spa/src/components/dashboard/ChainBottleneckWidget.test.tsx
spa/src/hooks/useChainProgress.test.ts
spa/src/types/chain.ts                  # extend with ChainStepEvent
```

### 7.3 Backend files to modify

```
api/app/Modules/CRM/Services/SalesOrderService.php          # call ChainBroadcaster on every transition
api/app/Modules/Production/Services/WorkOrderService.php    # add complete() + fire WorkOrderCompleted; broadcast
api/app/Modules/Production/Services/WorkOrderOutputService.php  # broadcast on auto-complete
api/app/Modules/Quality/Services/InspectionService.php      # createPending(); fire Passed/Failed; broadcast
api/app/Modules/Inventory/Services/GrnService.php           # fire GoodsReceiptNoteCreated; broadcast
api/app/Modules/SupplyChain/Services/DeliveryService.php    # broadcast on every transition
api/app/Modules/Accounting/Services/InvoiceService.php      # createDraftFromDelivery(); broadcast
api/app/Modules/Accounting/Services/BillService.php         # createDraftFromGrn()
api/app/Modules/Purchasing/Services/PurchaseOrderService.php # auto-approve under threshold; fire PurchaseOrderApproved
api/app/Modules/Purchasing/Services/AutoPurchaseOrderService.php # accept multi-PR consolidation
api/app/Modules/HR/Services/EmployeeService.php             # fire EmployeeCreated
api/app/Modules/HR/Services/SeparationService.php           # fire SeparationInitiated
api/app/Modules/HR/Services/ClearanceService.php            # fire ClearanceFullySigned
api/app/Modules/Payroll/Services/PayrollPeriodService.php   # fire PayrollPeriodFinalized
api/app/Common/Services/ApprovalService.php                 # fire PurchaseRequestApproved/PurchaseOrderApproved on final approval
api/app/Providers/EventServiceProvider.php                  # bind all event→listener pairs
api/app/Console/Kernel.php                                  # schedule RunChainBottleneckCheck hourly + ResetLeaveBalancesForYear yearly
api/database/seeders/RolePermissionSeeder.php               # add dashboard.view_bottlenecks
api/routes/api.php                                          # register /chain/bottlenecks
```

### 7.4 Frontend files to modify

```
spa/src/pages/crm/sales-orders/detail.tsx           # useChainProgress
spa/src/pages/purchasing/purchase-orders/detail.tsx # useChainProgress
spa/src/pages/production/work-orders/detail.tsx     # useChainProgress
spa/src/pages/supply-chain/deliveries/detail.tsx    # useChainProgress
spa/src/pages/dashboard/index.tsx                   # mount ChainBottleneckWidget per role
```

---

## 8. Execution order (recommended for code mode)

Execute in five PRs, in this order, each gated independently:

1. **PR-C1-events-and-O2C** — events `WorkOrderCompleted`/`InspectionPassed`/`InspectionFailed`, three O2C listeners, service additions, tests. Quality gate must pass.
2. **PR-C2-procure-to-pay** — events + five listeners + threshold config + supplier email. Tests + gate.
3. **PR-C3-hire-to-retire** — remaining HR/Payroll events, listeners, year-rollover command. Tests + gate.
4. **PR-C4-realtime-tracker** — `ChainStepAdvanced`, `ChainBroadcaster`, hook into 4 detail pages, broadcast hooks in services. Tests + gate.
5. **PR-C5-bottlenecks** — service, scheduled command, endpoint, dashboard widget. Tests + gate.

Each PR follows the [`commit-and-pr.md`](../.roo/skills/kwatog/commit-and-pr.md) skill: conventional commits, target `kwat0g/kwatog`, PR body must include the gate output.

---

## 9. Risks and watch-outs

- **Event loops.** `InspectionPassed` is consumed by both the C1 (outgoing) and C2 (incoming) listeners; they must filter on `stage` first. Add a unit test that fires both stages and asserts only the correct listener acts.
- **Idempotency.** Re-running `InitiateOrderToCashChain` on the same SO must not create duplicate WOs. Solution: check for existing WOs with `(sales_order_id, sales_order_item_id)` before creating; use a unique partial index.
- **Queue ordering.** Chain listeners use `ShouldQueue` + same queue (`chain`) to preserve order per entity. Configure a single worker for that queue, or use `WithoutOverlapping` middleware keyed by `entity:id`.
- **Migration safety.** `0122` and `0123` only ADD columns/indexes — safe online.
- **Test data complexity.** Hire-to-retire chain test needs a fully seeded employee with leave types, payroll period, departments. Build a `ChainTestSeeder` to keep tests readable.
- **Reverb channel limits.** Per the spec the chain channel is public; if Reverb config caps concurrent channels, add a single shared `chain.{type}` channel with payload-level filtering as a fallback. Document in the listener.

---

## 10. Definition of done

- [ ] All five PRs merged to main, each with green CI.
- [ ] [`docs/PATTERNS.md`](../docs/PATTERNS.md:1) checklist passes for every changed file.
- [ ] Quality gate (`api`: composer + migrate + test; `spa`: lint + typecheck + test) green on each PR.
- [ ] Manual smoke: confirm an SO end-to-end and observe in real time the WO/inspection/delivery/invoice cascade in another browser tab without refresh.
- [ ] Bottleneck dashboard widget shows non-empty data when an SO is intentionally left at `mrp_planned` for > 48h (use freezable `Carbon::setTestNow()` in a one-off test scenario).
- [ ] No new `console.log`, no Bearer-token usage, no localStorage auth, no raw integer IDs in any payload.
