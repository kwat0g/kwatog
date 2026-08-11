# Process Hardening Audit — Phase 1 findings

**Anchor commit:** feaa9621 (`main`, pushed to origin)
**Date:** 2026-08-11
**Status:** in progress
**Spec:** docs/superpowers/specs/2026-08-11-process-hardening-audit-design.md

Phase 1 is findings-only. No fix is proposed or applied here.

Every claim cites `file:line` as of the anchor commit. A process that has not
been traced end to end appears in the untraced list, never in the clean list.

Severe findings (data-corrupting, non-idempotent, race) are labeled PROVEN
where an executable probe demonstrated the bad outcome, ARGUED where the
finding rests on code reading alone.

## 1. Scope and method

Scope is the Ogami ERP API (`api/`) plus SPA submit and retry paths (`spa/`),
followed where the client is the guard preventing a duplicate effect. A
duplicate that only a disabled button prevents is a server-side finding.

### 1.1 Surface counts

Measured at the anchor commit. Each was re-derived from `feaa9621` itself (via
`git ls-tree`/`git show`), not only from the working tree, so the numbers below
describe the code the citations point at.

| Surface | Count | How measured |
|---|---|---|
| Services | 215 | `find api/app -name '*Service.php'` |
| Controllers | 168 | `find api/app -name '*Controller.php'` |
| Domain event classes | 53 | `find api/app -path '*Events*' -name '*.php'` |
| Listener files | 50 | `find api/app -path '*Listeners*' -name '*.php'` |
| Jobs | 9 | `find api/app -path '*Jobs*' -name '*.php'` |
| `Event::listen` registrations | 58 | `grep -c 'Event::listen' api/app/Providers/AppServiceProvider.php` |
| `::class` tokens in codec file | 57 | `grep -cE '::class' api/app/Common/Services/OutboxEventCodec.php` |
| **Outbox codec allowlist entries** | **51** | `::class` entries inside `SUPPORTED_EVENTS`, `OutboxEventCodec.php:75-127` |

The first six match the design spec
(`docs/superpowers/specs/2026-08-11-process-hardening-audit-design.md:30`,
`:37`, `:40`). The seventh does **not**, and the discrepancy is instructive.

The spec and plan both cite 57 codec allowlist entries. 57 is the output of
`grep -cE '::class'` over the whole file, but the allowlist —
`SUPPORTED_EVENTS` at `api/app/Common/Services/OutboxEventCodec.php:75-127` —
holds exactly **51** unique entries. The grep additionally matches six lines
that are not allowlist members: `$event::class` (`:132`), `$value::class`
(`:208`, `:217`, `:225`), `Model::class` (`:268`), and `BackedEnum::class`
(`:289`).

The count of durable edges is therefore 51. This is a proxy-versus-reality
gap of exactly the kind this audit exists to find, discovered in the audit's
own instrument before a single process was traced.

Module count is 22 (`api/app/Modules/`), matching the spec's stated surface.

### 1.2 Anchor and drift

Findings are anchored to `feaa9621`. `HEAD` at the time of writing is
`d3a1b9e4`, six commits later. The spec anticipated only docs-only commits
after the anchor; that expectation is **not** met — two of the six commits
touch `api/` and `spa/`:

- `da3d8f56` — `feat(dashboard): richer department_head default layout`
- `b1fe60d1` — `feat(dashboard): widgets for maintenance, assets, returns, CRM, budget, loans`

Combined drift across `api/` and `spa/` is 7 files, +210/-4 lines, confined to
the Dashboard read-model surface (`DashboardWidgetDataService.php`, widget and
role-layout seeders, migration `0442`, a settings request, a dashboard test,
and the SPA widget registry). No service in another module, no listener
registration, no outbox codec entry, and no job changed. This is why all seven
surface counts are identical at `feaa9621` and at `HEAD`.

Per the spec (`:26`), findings remain anchored to `feaa9621` and the drift is
noted here rather than re-baselined. Line citations in this document are valid
against `feaa9621`; readers checking against a later `HEAD` should expect
offsets only in the seven files listed above.

The working tree is otherwise clean: `git status --porcelain`, excluding
untracked `.codex/` and `.impeccable/` scratch directories, printed `0`.

### 1.3 The six edge sources

The inventory is enumerated from six sources rather than assembled from
memory, so coverage is checkable
(`docs/superpowers/specs/2026-08-11-process-hardening-audit-design.md:108`):

| Source | Yields |
|---|---|
| module `routes.php` + `api/routes/api.php` | HTTP entry points |
| `api/app/Providers/AppServiceProvider.php` `Event::listen` ×58 | async edges |
| `api/app/Common/Services/OutboxEventCodec.php` ×51 allowlist | durable edges |
| `api/routes/console.php` | scheduled entries |
| `Jobs/` ×9 | queued entries |
| cross-module import graph ×708 | direct-call edges, filtered to write-reaching |

The 708 figure originates in the design spec (`:67`). Unlike the seven surface
counts above, it was not measured by this task; it was re-measured
independently at `HEAD` before Task 3 relied on it, and confirmed at 708
(sum over all 22 modules of `use App\Modules\*\(Services|Models)\` imports,
excluding same-module imports). Task 2 re-derives it a third time as its own
gate.

The last row is the reason for the classification rule below. Direct
synchronous calls outnumber event edges by roughly an order of magnitude (708
cross-module `Services`/`Models` imports versus 53 event classes and 58
listener registrations), so an event-map-only audit would cover the minority of
coupling.

Every inventory row carries a disposition — traced, clean, or
parked-with-reason. No row disappears silently. The untraced list in section 5
is *generated* from rows lacking a disposition.

### 1.4 Classification rule — write reach, not import reach

Quoted verbatim from the implementation plan
(`docs/superpowers/plans/2026-08-11-process-hardening-audit.md:47-49`; the
design spec states the same rule at `:92-94` with an additional sentence):

> **Classification is by write reach, not import reach.** A process is
> cross-module only if it *writes* across module namespaces, or triggers
> something that does.

Applied: Production importing `Employee` to read a name is not a cross-module
process; Production calling `InventoryService` to deduct stock is. Import-only
reads are recorded on the edge list and dismissed with that stated reason, so
every dismissal is auditable rather than invisible.

Chain versus single-module splits on step count within one namespace: 2+
sequential state-changing steps makes it a chain. Depth is allocated forensic
on cross-module and chain processes; single-module processes are inventoried
and explicitly parked in the untraced list.

### 1.5 Evidence standard — PROVEN / ARGUED

Every claim carries `file:line`. Quoted verbatim from the implementation plan
(`docs/superpowers/plans/2026-08-11-process-hardening-audit.md:56-58`; the
design spec states the same standard at `:164-172` in different wording):

> Severe findings (data-corrupting, non-idempotent, race) carry an executable
> probe and are labeled PROVEN. A finding whose probe proves too expensive
> stays in the report labeled ARGUED — never silently upgraded.

A probe is a throwaway test driving the actual bad outcome, or the existing
`make chain-smoke` / `make worker-recovery-smoke` harnesses. Probes are deleted
after use; none are committed. The label is what makes the severity list
reviewable rather than assertive, and it is the specific failure mode of the
two prior documents (section 6).

### 1.6 Prior audits are hypotheses, not evidence

`docs/PROCESS-AUDIT-2026-08-10.md` (499 lines) and
`docs/PROCESS-FAILURE-MATRIX-2026-08-11.md` (137 lines) already claim this
scope and mark most boundaries closed. Three properties disqualify them as
evidence: both are self-authored, both remain unreviewed, and neither was ever
committed — they are untracked files, so no reviewer has ever seen a diff of
them. Every "Closed" claim is treated as an unverified hypothesis and
re-derived from current code with fresh citations. Contradictions are recorded
in section 6.

A correction belongs here, because it is the same error this section warns
against. An earlier draft of this document, following the design spec (`:57`,
`:209`), stated that both prior documents cite `Edge`, a module deleted in
`c3156301`. That is false. The string `Edge` appears **zero** times in either
document, case-sensitively or otherwise; the only `edge` substrings are inside
"acknowledgement" and "ledger". `docs/PROCESS-FLOWS.md` does contain `Edge`
three times — not nine — and all three are the heading phrase "Edge Cases",
unrelated to the deleted module. The deletion itself is real: `c3156301`
removed `api/app/Modules/Edge` across 9 paths. The claim entered the spec
un-verified and was inherited here un-verified, which is precisely the failure
mode described above. It is withdrawn; the conclusion of this section rests on
the three properties named in the paragraph above.

### 1.7 Severity classes

Findings group into five classes, on evidence only: data-corrupting, silent
failure, bypassable, non-idempotent, missing compensation. These are the
section 3 subheadings.

## 2. Edge inventory

### 2.1 How the 642 mechanical edges collapse to 81 processes

Task 2 produced a mechanical edge list, not a process list. Its five sections
count 642 edges: 72 direct write-reaching cross-module calls, 58 `Event::listen`
registrations, 41 scheduled entries, 9 queued jobs, and 462 HTTP write routes.
Most of those are steps, not processes. `WorkOrderService -> MaterialReservation`
and `WorkOrderService -> StockMovementService` are two edges of one business
action; `POST /work-orders`, `POST /work-orders/{wo}/confirm` and
`POST /work-orders/{wo}/start` are three route rows on the same state machine.

Collapsing on the rule "one process = one business action a user or a clock can
start, plus everything it triggers" yields **81 processes**. Three inputs were
folded in beyond the edge list:

- **Raw `DB::table()` writes** (section 2.2). Import-graph scanning cannot see
  them, so they were absent from the Task 2 edge file and recovered here by
  table-ownership sweep.
- **Async edges re-attributed by write target, not declaration site.** Task 2
  attributed a listener to the module that declares it. For process collapse the
  write target is what matters: `CreateDeliveryDraftOnQcPass` is declared in
  Quality but writes `Delivery`/`DeliveryItem`
  (`api/app/Modules/Quality/Listeners/CreateDeliveryDraftOnQcPass.php:135`,
  `:148`), so it is a Quality→SupplyChain process edge. The four listeners with
  cross-module write reach are `AcceptGrnOnIncomingQcPass` (→ Inventory),
  `RejectGRNOnQcFail` (→ Inventory), `CreateDeliveryDraftOnQcPass` (→
  SupplyChain), and `HandleMachineBreakdown` (→ MRP).
- **Per-edge detail, not per-pair.** The 72 write-reaching edges collapse to only
  39 module pairs. Pairs are too coarse: Quality→Inventory covers both the QC-pass
  accept path and the QC-fail reject path, which are separate processes with
  separate listeners. Rows below are cut at edge granularity.

`Class` is `cross-module` (writes across module namespaces, or triggers something
that does), `chain` (2+ sequential state-changing steps inside one namespace), or
`single-module`. `Blast` ranks descending: **money** (GL, AP, AR, payroll) >
**stock** > **employee-state** (payroll-adjacent) > **other**. IDs are assigned in
blast-radius order, so `P01`…`P81` *is* the trace order for Tasks 4–9 — the
ranking is inspectable rather than implicit. Within a blast band, cross-module
sorts before chain before single-module.

`Disposition` is empty for every row. Nothing is traced in this task. Task 11
generates the untraced list from rows still empty, so an empty cell is a
measurement, not an oversight.

Single-module rows are present deliberately. They are parked, not omitted, and
parking is only auditable if the row exists.

### 2.2 Raw `DB::table()` write sweep (recovered blind spot)

Import scanning is blind to `DB::table('x')->insert(...)`: there is no `use`
statement to match, so none of these appear in the Task 2 edge file. Sweeping
`api/app/` for `DB::table()` followed by a write verb
(`insert|insertGetId|insertOrIgnore|update|updateOrInsert|upsert|delete|increment|decrement`)
and resolving each target table to its owning module finds **11 cross-module raw
writes**, distributed HR 4, Admin 3, Payroll 2, Inventory 2.

Ownership was resolved by locating the Eloquent model that maps to the table, and
falling back to the creating migration where no model exists.

| # | Write site | Target table | Owning module | Folded into |
|---|---|---|---|---|
| 1 | `api/app/Modules/HR/Services/EmployeeService.php:205` | `employee_shift_assignments` | Attendance (`api/app/Modules/Attendance/Models/EmployeeShiftAssignment.php`) | P44 |
| 2 | `api/app/Modules/HR/Services/EmployeeService.php:224` | `employee_leave_balances` | Leave (`api/app/Modules/Leave/Models/EmployeeLeaveBalance.php`) | P44 |
| 3 | `api/app/Modules/HR/Listeners/InitializeLeaveBalances.php:57` | `employee_leave_balances` | Leave | P44 |
| 4 | `api/app/Modules/HR/Services/UserProvisioningService.php:100` | `sessions` | *unresolved — see below* | P54 |
| 5 | `api/app/Modules/Admin/Controllers/SessionController.php:44` | `sessions` | *unresolved* | P73 |
| 6 | `api/app/Modules/Admin/Services/UserAdminService.php:135` | `sessions` | *unresolved* | P73 |
| 7 | `api/app/Modules/Admin/Controllers/SettingsController.php:48` | `settings` | Common (`api/app/Common/Services/SettingsService.php:15`) | P75 |
| 8 | `api/app/Modules/Payroll/Services/PayrollGlPostingService.php:313` | `journal_entries` | Accounting (`api/app/Modules/Accounting/Models/JournalEntry.php`) | P02 |
| 9 | `api/app/Modules/Payroll/Services/PayrollGlPostingService.php:328` | `journal_entry_lines` | Accounting (`api/app/Modules/Accounting/Models/JournalEntryLine.php`) | P02 |
| 10 | `api/app/Modules/Inventory/Services/GrnGlPostingService.php:223` | `journal_entries` | Accounting | P15 |
| 11 | `api/app/Modules/Inventory/Services/MovementGlPostingService.php:201` | `journal_entries` | Accounting | P16 |

Two of these are the confirmed examples the sweep was calibrated against
(`EmployeeService.php:205`, `PayrollGlPostingService.php:313`/`:328`); the other
nine were recovered by the sweep itself.

**Ownership that could not be established.** Rows 4–6 write the `sessions` table,
which has **no owning module**: no Eloquent model maps to it
(`find api/app -name 'Session.php' -path '*Models*'` returns nothing) and it is
created by `api/database/migrations/0006_create_sessions_table.php` as the
Laravel `database` session-driver table
(`api/config/session.php:12`, `'table' => env('SESSION_TABLE', 'sessions')`).
It is framework infrastructure that three module-namespaced call sites write
directly. Rather than guess an owner, they are recorded as writes to an unowned
framework table and folded into the access-control processes that perform them.
The same caveat applies weakly to `settings` (row 7): no model, but a clear
Common-module service owner, so it is attributed to Common.

**Two further cross-module raw writes exist but add no new process.**
`api/app/Modules/Quality/Services/InspectionService.php:174` and `:365` both
`update` the Inventory-owned `goods_receipt_notes` table. They are not a blind
spot: the same file already imports `GoodsReceiptNote`, so Task 2's import scan
caught the Quality→Inventory edge (`InspectionService.php:14`, evidenced at
`:250`). They are additional evidence for an already-visible edge, folded into
P28/P29, and are excluded from the count of 11 for that reason.

Read-only `DB::table()` queries are out of scope. Of 353 `DB::table()`
occurrences in `api/app/`, the great majority are reads
(`ChainBottleneckService`, `GlobalSearchService`, `CalendarAggregatorService`,
the statutory report builders); write reach is the classification rule, so they
are dismissed here rather than enumerated.

### 2.3 Process inventory

| ID | Process | Class | Domains | Entry point | Trigger | Blast | Disposition |
|---|---|---|---|---|---|---|---|
| P01 | Payroll finalize → bank file + payslip email + employee notify | cross-module | Payroll, Accounting | `api/app/Modules/Payroll/Controllers/PayrollPeriodController.php:174` | HTTP + event | money | |
| P02 | Payroll GL posting handoff + retry (raw `journal_entries` insert) | cross-module | Payroll, Accounting | `api/app/Modules/Payroll/Services/PayrollGlPostingService.php:313` | event + job + HTTP | money | |
| P03 | Payroll compute → loan deduction + loan balance write | cross-module | Payroll, Loans | `api/app/Modules/Payroll/Controllers/PayrollPeriodController.php:143` | HTTP + event + job | money | |
| P04 | Payroll period void → GL reversal + cycle-claim release | cross-module | Payroll, Accounting | `api/app/Modules/Payroll/Controllers/PayrollPeriodController.php:275` | HTTP | money | |
| P05 | Final pay compute → GL | cross-module | HR, Accounting | `api/app/Modules/HR/Controllers/SeparationController.php:80` | HTTP | money | |
| P06 | Delivery confirm → SO marked delivered + draft invoice | cross-module | SupplyChain, CRM, Accounting | `api/app/Modules/SupplyChain/Controllers/DeliveryController.php:79` | HTTP + event | money | |
| P07 | GRN accepted → auto-create bill (AP) | cross-module | Inventory, Accounting | `api/app/Modules/Accounting/Listeners/AutoCreateBillOnGrnAccepted.php:34` | event | money | |
| P08 | Budget enforcement acknowledge on PR / PO | cross-module | Purchasing, Accounting | `api/app/Modules/Purchasing/Services/PurchaseRequestService.php:342` | HTTP | money | |
| P09 | Supplier portal invoice submit → draft bill | cross-module | B2B, Accounting | `api/app/Modules/B2B/Controllers/SupplierPortalController.php:169` | HTTP | money | |
| P10 | Asset acquisition + disposal → GL | cross-module | Assets, Accounting | `api/app/Modules/Assets/Controllers/AssetController.php:57` | HTTP | money | |
| P11 | Monthly depreciation → GL | cross-module | Assets, Accounting | `api/app/Modules/Assets/Listeners/RunMonthlyDepreciationOnRequested.php:35` | scheduled + event + job | money | |
| P12 | Payroll period create + cycle claim (manual, auto, reconcile) | chain | Payroll | `api/app/Modules/Payroll/Controllers/PayrollPeriodController.php:120` | HTTP + scheduled | money | |
| P13 | 13th month compute → finalize | chain | Payroll | `api/app/Modules/Payroll/Controllers/PayrollPeriodController.php:329` | HTTP | money | |
| P14 | Payroll disbursement proof → mark disbursed → force-unlock / stale reap | chain | Payroll | `api/app/Modules/Payroll/Controllers/PayrollPeriodController.php:230` | HTTP + scheduled | money | |
| P15 | GRN GL posting | chain | Inventory, Accounting | `api/app/Modules/Inventory/Services/GrnGlPostingService.php:203` | event | money | |
| P16 | Stock movement GL posting + retry | chain | Inventory, Accounting | `api/app/Modules/Inventory/Listeners/PostStockMovementToGlOnRequested.php:27` | event + HTTP | money | |
| P17 | AR: invoice create → finalize → collection → credit note | chain | Accounting | `api/app/Modules/Accounting/Controllers/InvoiceController.php:62` | HTTP | money | |
| P18 | AP: bill create → post → payment | chain | Accounting | `api/app/Modules/Accounting/Controllers/BillController.php:43` | HTTP | money | |
| P19 | Payroll adjustment request → approve / reject | single-module | Payroll | `api/app/Modules/Payroll/Controllers/PayrollAdjustmentController.php:48` | HTTP | money | |
| P20 | Manual journal entry post / reverse + accounting period lock-relock | single-module | Accounting | `api/app/Modules/Accounting/Controllers/JournalEntryController.php:44` | HTTP + scheduled | money | |
| P21 | Budget lifecycle (create → submit → approve → close) + actuals sync | single-module | Accounting | `api/app/Modules/Accounting/Controllers/BudgetController.php:135` | HTTP + scheduled + event + job | money | |
| P22 | AR dunning run | single-module | Accounting | `api/app/Console/Commands/RunArDunning.php:16` | scheduled | money | |
| P23 | Shipment landed-cost allocation | single-module | SupplyChain | `api/app/Modules/SupplyChain/Controllers/ShipmentController.php:167` | HTTP | money | |
| P24 | PO sent → draft GRN | cross-module | Purchasing, Inventory | `api/app/Modules/Inventory/Listeners/CreateDraftGrnOnPoSent.php:32` | event | stock | |
| P25 | GRN receive → incoming QC trigger | cross-module | Inventory, Quality | `api/app/Modules/Inventory/Controllers/GoodsReceiptNoteController.php:58` | HTTP + event | stock | |
| P26 | Incoming QC pass → GRN accept → stock + weighted-avg cost | cross-module | Quality, Inventory | `api/app/Modules/Quality/Listeners/AcceptGrnOnIncomingQcPass.php:42` | event | stock | |
| P27 | Incoming QC fail → GRN reject → NCR | cross-module | Quality, Inventory | `api/app/Modules/Quality/Listeners/RejectGRNOnQcFail.php:42` | event | stock | |
| P28 | Reorder-point breach → auto purchase request | cross-module | Inventory, Purchasing | `api/app/Modules/Inventory/Services/AutoReplenishmentService.php:34` | event | stock | |
| P29 | MRP run → material plan → PR + WO draft | cross-module | MRP, Purchasing, Production, CRM | `api/app/Modules/MRP/Services/MrpEngineService.php:68` | HTTP + scheduled | stock | |
| P30 | MRP II capacity schedule → confirm → reassign / reorder | cross-module | MRP, Production | `api/app/Modules/MRP/Controllers/SchedulerController.php:26` | HTTP | stock | |
| P31 | WO confirm → material reservation + issue → stock | cross-module | Production, Inventory | `api/app/Modules/Production/Controllers/WorkOrderController.php:143` | HTTP | stock | |
| P32 | WO output → production receipt → stock + mold shot count | cross-module | Production, Inventory, MRP | `api/app/Modules/Production/Services/WorkOrderOutputService.php:67` | HTTP + event | stock | |
| P33 | WO operation lifecycle → operator binding | cross-module | Production, HR | `api/app/Modules/Production/Controllers/WoOperationController.php:87` | HTTP | stock | |
| P34 | Spare part usage → stock issue | cross-module | Maintenance, Inventory | `api/app/Modules/Maintenance/Services/SparePartUsageService.php:31` | HTTP | stock | |
| P35 | RMA receive → inspect → dispose → replacement PO | cross-module | ReturnManagement, Quality, Inventory, Purchasing | `api/app/Modules/ReturnManagement/Controllers/ReturnRequestController.php:170` | HTTP + event | stock | |
| P36 | Material issue slip → stock issue | single-module | Inventory | `api/app/Modules/Inventory/Controllers/MaterialIssueSlipController.php:30` | HTTP | stock | |
| P37 | Stock adjustment → approve → stock | single-module | Inventory | `api/app/Modules/Inventory/Controllers/StockAdjustmentController.php:74` | HTTP | stock | |
| P38 | Stock count → variance approve → stock | single-module | Inventory | `api/app/Modules/Inventory/Controllers/StockCountController.php:65` | HTTP | stock | |
| P39 | Transfer order → execute → stock | single-module | Inventory | `api/app/Modules/Inventory/Controllers/TransferOrderController.php:74` | HTTP | stock | |
| P40 | MRB quarantine → release | single-module | Inventory | `api/app/Modules/Inventory/Controllers/MrbController.php:47` | HTTP | stock | |
| P41 | Safety-stock + ABC parameter recompute | single-module | Inventory | `api/app/Console/Commands/RecomputeSafetyStock.php:16` | scheduled + HTTP | stock | |
| P42 | Employee hire → user provision + shift assign + leave balances | cross-module | HR, Auth, Attendance, Leave | `api/app/Modules/HR/Services/EmployeeService.php:205` | HTTP + event | employee-state | |
| P43 | Separation initiate → clearance sign → finalize → account deactivate | cross-module | HR, Auth | `api/app/Modules/HR/Controllers/SeparationController.php:51` | HTTP + event | employee-state | |
| P44 | Leave request → dept / HR approve → attendance mark | cross-module | Leave, Attendance | `api/app/Modules/Leave/Controllers/LeaveRequestController.php:42` | HTTP + event | employee-state | |
| P45 | Leave year-end: processing → payroll adjustment → balance reset | cross-module | Leave, Payroll, HR | `api/app/Modules/Leave/Jobs/ProcessYearEndLeave.php:68` | scheduled + event + job + HTTP | employee-state | |
| P46 | Overtime request → approve / reject | cross-module | HR, Attendance | `api/app/Modules/HR/Controllers/SelfServiceController.php:233` | HTTP + event | employee-state | |
| P47 | Loan / cash advance request → approve → amortization | cross-module | HR, Loans | `api/app/Modules/HR/Controllers/SelfServiceController.php:131` | HTTP + event | employee-state | |
| P48 | Employee account provision / deactivate / reset password | cross-module | HR, Auth | `api/app/Modules/HR/Controllers/EmployeeAccountController.php:31` | HTTP | employee-state | |
| P49 | Attendance biometric CSV import → DTR | single-module | Attendance | `api/app/Modules/Attendance/Controllers/AttendanceController.php:74` | HTTP | employee-state | |
| P50 | Salary adjustment request → act | single-module | HR | `api/app/Modules/HR/Controllers/SalaryAdjustmentController.php:59` | HTTP | employee-state | |
| P51 | Profile update request → HR review + finance review | single-module | HR | `api/app/Modules/HR/Controllers/ProfileUpdateReviewController.php:43` | HTTP | employee-state | |
| P52 | HR talent lifecycle: recruitment, training / skills, onboarding | single-module | HR | `api/app/Modules/HR/Controllers/RecruitmentPostingController.php:64` | HTTP + scheduled | employee-state | |
| P53 | Customer complaint → NCR → 8D → close | cross-module | CRM, Quality | `api/app/Modules/CRM/Controllers/ComplaintController.php:56` | HTTP + event + scheduled | other | |
| P54 | Auth session + credential lifecycle (login, logout, password, layout clone) | cross-module | Auth, Admin, Dashboard, HR | `api/app/Modules/Auth/Controllers/LoginController.php:34` | HTTP | other | |
| P55 | WO status / complete → in-process + outgoing QC trigger | cross-module | Production, Quality | `api/app/Modules/Quality/Listeners/TriggerInProcessQC.php:41` | event | other | |
| P56 | Outgoing QC pass → delivery draft + shipment-lot traceability / CoC | cross-module | Quality, SupplyChain | `api/app/Modules/Quality/Listeners/CreateDeliveryDraftOnQcPass.php:47` | event + HTTP | other | |
| P57 | Inspection create → measurements → complete (writes GRN link) | cross-module | Quality, Inventory | `api/app/Modules/Quality/Controllers/InspectionController.php:133` | HTTP + event | other | |
| P58 | Approved-supplier registry | cross-module | Purchasing, Accounting, Inventory | `api/app/Modules/Purchasing/Services/ApprovedSupplierService.php:36` | HTTP | other | |
| P59 | B2B portal self-service writes (supplier PO ack / shipment, customer complaint / schedule) | cross-module | B2B, Purchasing, CRM | `api/app/Modules/B2B/Controllers/SupplierPortalController.php:246` | HTTP | other | |
| P60 | Machine status transition → breakdown handling | cross-module | MRP, Production | `api/app/Modules/MRP/Controllers/MachineController.php:67` | HTTP + event | other | |
| P61 | Maintenance WO → machine / mold state + mold history + hours recompute | cross-module | Maintenance, MRP | `api/app/Modules/Maintenance/Controllers/MaintenanceWorkOrderController.php:72` | HTTP + scheduled | other | |
| P62 | Demand forecast recompute + MRP inclusion flag | cross-module | Forecasting, CRM | `api/app/Modules/Forecasting/Controllers/ForecastMrpController.php:34` | HTTP + scheduled | other | |
| P63 | Access control administration: roles, users, permission overrides | cross-module | Admin, Auth | `api/app/Modules/Admin/Controllers/RoleController.php:55` | HTTP + scheduled | other | |
| P64 | System settings update (raw `settings` write) | cross-module | Admin, Common | `api/app/Modules/Admin/Controllers/SettingsController.php:48` | HTTP | other | |
| P65 | Bulk import dry-run → commit → rollback | cross-module | Common + target modules | `api/app/Common/Controllers/ImportController.php:45` | HTTP | other | |
| P66 | Sales order create → confirm → cancel | chain | CRM | `api/app/Modules/CRM/Controllers/SalesOrderController.php:47` | HTTP + event | other | |
| P67 | NCR lifecycle: create → action → disposition → close (+ escalation, effectiveness) | chain | Quality | `api/app/Modules/Quality/Controllers/NcrController.php:60` | HTTP + scheduled | other | |
| P68 | PR lifecycle: create → submit → approve → convert to PO | chain | Purchasing | `api/app/Modules/Purchasing/Controllers/PurchaseRequestController.php:72` | HTTP + event | other | |
| P69 | PO lifecycle: create → submit → approve → send → close / cancel | chain | Purchasing | `api/app/Modules/Purchasing/Controllers/PurchaseOrderController.php:51` | HTTP + event | other | |
| P70 | Supplier dispatch prepare / close / recover + performance recompute | chain | Purchasing | `api/app/Modules/Purchasing/Listeners/PrepareSupplierDispatch.php:23` | event + scheduled + HTTP | other | |
| P71 | Delivery lifecycle: create → status → proof / receipt (incl. driver PWA) | chain | SupplyChain | `api/app/Modules/SupplyChain/Controllers/DeliveryController.php:53` | HTTP | other | |
| P72 | Shipment (ImpEx) lifecycle + documents + containers | chain | SupplyChain | `api/app/Modules/SupplyChain/Controllers/ShipmentController.php:73` | HTTP | other | |
| P73 | Preventive maintenance generation | chain | Maintenance | `api/app/Modules/Maintenance/Listeners/GeneratePreventiveMaintenanceOnRequested.php:35` | scheduled + event + job | other | |
| P74 | Outbox dispatch + chain listener run tracking + replay | chain | Common | `api/app/Common/Services/OutboxService.php:29` | scheduled + job + HTTP | other | |
| P75 | PPAP submission → review → approve + calibration records | single-module | Quality | `api/app/Modules/Quality/Controllers/PpapController.php:34` | HTTP + scheduled | other | |
| P76 | Approval delegation + escalation run | single-module | Admin | `api/app/Modules/Admin/Controllers/ApprovalDelegationController.php:34` | HTTP + scheduled | other | |
| P77 | Alert engine run + chain bottleneck detection | single-module | Common | `api/app/Common/Services/AlertEngineService.php:114` | scheduled + HTTP | other | |
| P78 | Platform services: notifications, digests, scheduled exports, documents, print, retention prune, DB backup | single-module | Common, Auth, Admin | `api/app/Common/Services/NotificationService.php:113` | event + scheduled + HTTP | other | |
| P79 | Reporting: dashboard KPI compute, layout save / reset, production summaries | single-module | Dashboard, Production | `api/app/Modules/Dashboard/Controllers/KpiController.php:31` | HTTP + scheduled | other | |
| P80 | Landing public writes: contact inquiry + newsletter | single-module | Landing | `api/app/Modules/Landing/Controllers/ContactInquiryController.php:16` | HTTP | other | |
| P81 | Master data maintenance (operations, commercial, people / payroll reference) | single-module | Inventory, MRP, Production, Quality, SupplyChain, CRM, Accounting, HR, Attendance, Leave, Payroll | `api/app/Modules/Inventory/Controllers/ItemController.php:60` | HTTP | other | |

**Counts.** 81 processes: 43 cross-module, 16 chain, 22 single-module. By blast
radius: 23 money, 18 stock, 11 employee-state, 29 other.

P81 is deliberately one row covering the pure-CRUD reference tables (items,
categories, UOM, warehouses / zones / locations, BOM, machines, molds, routings,
inspection specs, NCR templates, item quality plans, vehicles, containers,
customers, vendors, products, price agreements, COA accounts, departments,
positions, skills, trainings, shifts, holidays, leave types, government
contribution tables, de-minimis benefits). These have no multi-step state
machine and no cross-module write reach; splitting them into 27 rows would
inflate the inventory without adding a traceable process. Their entry points are
enumerated in the Task 2 HTTP section.

### 2.4 Durable events with no consumer (finding candidates, not traced here)

Ten events are allowlisted in `OutboxEventCodec::SUPPORTED_EVENTS`
(`api/app/Common/Services/OutboxEventCodec.php:75-127`) — meaning they can be
durably stored and replayed — yet have **no `Event::listen` registration** in
`api/app/Providers/AppServiceProvider.php`. Nothing consumes them:

`BadgesChanged`, `ChainStepAdvanced`, `MaintenanceWorkOrderCreated`,
`MoldShotLimitNearing`, `MoldShotLimitReached`, `MrpPlanGenerated`,
`PayrollPeriodDisbursed`, `PayrollPeriodVoided`, `PermissionOverrideChanged`,
`WorkOrderOutputRecorded`.

These are recorded as candidates because several sit on processes ranked here —
`PayrollPeriodVoided` on P04, `PayrollPeriodDisbursed` on P14,
`WorkOrderOutputRecorded` on P32, `MoldShotLimitNearing`/`MoldShotLimitReached`
on P32 (the mold-shot alert at 80% of max named in `CLAUDE.md`),
`MaintenanceWorkOrderCreated` on P61, `MrpPlanGenerated` on P29,
`PermissionOverrideChanged` on P63. Whether a missing consumer is a defect or an
intentionally unwired seam is a question for the tracing tasks, not this one. No
claim is made here.

The converse set is clean: all 8 `Event::listen` registrations lacking a codec
entry are Laravel framework events (`Illuminate\Console\Events\*`,
`Illuminate\Queue\Events\*`) consumed by observability trackers. Zero domain
events lack codec coverage.

## 3. Findings by severity

### 3.1 Data-corrupting

### 3.2 Silent failure

### 3.3 Bypassable

### 3.4 Non-idempotent

### 3.5 Missing compensation

## 4. Clean list

## 5. Untraced list

## 6. Prior-claim delta
