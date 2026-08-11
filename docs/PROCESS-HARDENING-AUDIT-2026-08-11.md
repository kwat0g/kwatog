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

Findings are anchored to `feaa9621`. The spec anticipated only docs-only commits
after the anchor; that expectation is **not** met, and the gap is now large.
Feature work on the Dashboard surface has continued *concurrently with this
audit* — as of Task 4 the anchor is 19 commits behind `HEAD`, with `api/` and
`spa/` drift of 23 files, +1525/-5 lines.

Drift is confined to two module namespaces plus their tests, migrations, and
seeders:

| Namespace | Status |
|---|---|
| `api/app/Modules/Dashboard` | drifted — widget analytics, `WidgetScope` |
| `api/app/Modules/Admin` | drifted |
| `api/database/{migrations,seeders}` | drifted — dashboard widget seeds |
| `api/tests/Feature/Dashboard` | drifted — 5 new/changed test files |
| `spa/src/components/dashboard/registry.tsx` | drifted |
| **every other module** | **untouched since the anchor** |

No service outside Dashboard/Admin, no listener registration, no outbox codec
entry, and no job has changed. This is why all seven surface counts in §1.1 are
still identical at `feaa9621` and at `HEAD`, and why the 83-row inventory does
not need re-deriving.

Per the spec (`:26`), findings remain anchored to `feaa9621` rather than
re-baselined. Citations are valid at both commits for every module except
Dashboard and Admin. **A task tracing a Dashboard or Admin process (P63, P64,
and any Dashboard row) must re-verify its citations against `HEAD` rather than
trusting this section.**

The working tree is **not** clean and its contents change during the audit — at
Task 4 it held a modified `DashboardWidgetSeeder.php` and an untracked dashboard
test. Any task asserting a clean tree must run `git status --porcelain api/ spa/`
itself; an assertion inherited from a brief is stale by the time it is read.
Nothing under `api/` or `spa/` is modified *by* this audit — Phase 1 is
findings-only, and every audit commit touches this document alone.

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

### 1.8 Trace protocol (finalized on P01, Task 4)

Eight fields per traced process, each carrying `file:line`. The protocol was
dry-run on P01 before being spent across the remaining traces; the amendments
below are what that dry run changed.

| # | Field | What it must answer |
|---|---|---|
| 1 | Steps | Every step in order, with the owning class and the line that performs it. Steps that only *record intent* (an outbox row) are marked as such — they are not the effect. |
| 2 | Transaction boundary | Which steps the boundary encloses and which fall outside. State the enclosing method, not just "wrapped". |
| 3 | N succeeds, N+1 fails | Per boundary crossing: rollback, compensation, or orphan. "Orphan" must name the row left behind. |
| 4 | Handoff | Sync or async per edge; behavior on failure, retry, and out-of-order delivery. Name the retry budget (`$tries`/`$backoff`) or its absence. |
| 5 | Idempotency under replay | What a second delivery of the same event, or a second call of the same method, does. Name the dedupe mechanism and the column or index enforcing it. |
| 6 | Guard reachability | **Mechanical enumeration**, see below. |
| 7 | Audit attribution | Which steps write an `audit_logs` row and which are silent; who is recorded as actor. |
| 8 | Verdict | Findings with severity class and PROVEN/ARGUED, or clean with the citation that makes it clean. |

**Field 6 is enumerated, never intuited.** Four greps, because three
constructs are invisible to any one of them:

```bash
cd /home/kwat0g/Desktop/kwatog/api/app
grep -rn "<StatusEnum>::" --include=*.php . | grep -vE '/tests/'   # 1. enum writers
grep -rn "DB::table('<table>')" --include=*.php .                  # 2. raw writes (no import)
grep -rn "<Model>::query()" --include=*.php . | grep -E "update\(|delete\("  # 3. mass ops
grep -rnE "app\(|resolve\(|App::make\(|make\(" <traced files>      # 4. FQN resolution
```

Grep 1 must NOT be narrowed to `'status' =>` mass-assignment form: this repo
removes `status` from `$fillable` and writes it via `forceFill`, `->status =`,
and conditional `->update([...])`. Filtering to the mass-assignment shape alone
would have missed `PayrollPeriodService.php:928` (`->status =`) and
`:788` (conditional `update()`). Greps 2 and 4 are the blind spots recovered in
sections 2.2 and 2.3a; they are part of the protocol, not a one-time sweep.

Every writer found is then checked individually for the guard. The output of
field 6 is a table of *all* writers with a guard column, so a reviewer can see
the enumeration rather than trust it.

**Two amendments the P01 dry run forced.**

1. *Guarded-vs-locked is a separate column.* The first pass recorded field 6 as
   "does this writer check the status?" — every P01 writer answered yes, and the
   field read clean. The real distinction is *which row* the check reads: a
   guard on a route-bound model that was fetched before the request is a guard
   on stale data. Splitting the column into `guard` and `reads locked row`
   turned a uniformly-clean field into the P01-01 finding. Field 6 now records
   both.
2. *A field may not be answered in prose alone.* Fields 5 and 6 must each carry
   a table. Prose let "the outbox dedupes it" stand as an answer in the first
   pass; the table forced the dedupe *key* to be written down, which is what
   exposed P01-02. A field with no table and no `file:line` is not an answer.

The protocol is falsifiable in the intended sense: every P01 claim below can be
checked by opening one cited line, and the two severe findings were each driven
to their bad outcome by a probe rather than argued.

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

`Domains` lists the namespaces a process **writes** — the same write-reach test
that sets `Class`, not every namespace it touches. A module that is only read
from does not appear. This is why P77 can read eight foreign modules
(`api/app/Common/Services/AlertEngineService.php:11-18`) and still list only
`Common`: its only writes are `$alert->update()` at `:93`, `:105`, `:465`. Tasks
4–9 use this column to decide which namespaces to open, so a missing domain
means an untraced write.

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
| 1 | `api/app/Modules/HR/Services/EmployeeService.php:205` | `employee_shift_assignments` | Attendance (`api/app/Modules/Attendance/Models/EmployeeShiftAssignment.php`) | P42 |
| 2 | `api/app/Modules/HR/Services/EmployeeService.php:224` | `employee_leave_balances` | Leave (`api/app/Modules/Leave/Models/EmployeeLeaveBalance.php`) | P42 |
| 3 | `api/app/Modules/HR/Listeners/InitializeLeaveBalances.php:57` | `employee_leave_balances` | Leave | P42 |
| 4 | `api/app/Modules/HR/Services/UserProvisioningService.php:100` | `sessions` | *unresolved — see below* | P54 |
| 5 | `api/app/Modules/Admin/Controllers/SessionController.php:44` | `sessions` | *unresolved* | P63 |
| 6 | `api/app/Modules/Admin/Services/UserAdminService.php:135` | `sessions` | *unresolved* | P63 |
| 7 | `api/app/Modules/Admin/Controllers/SettingsController.php:48` | `settings` | Common (`api/app/Common/Services/SettingsService.php:15`) | P64 |
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
P57, and are excluded from the count of 11 for that reason.

**Correction (Task 3 review).** Six of the eleven `Folded into` pointers above,
and the exclusion pointer in the preceding paragraph, were wrong on first
writing — they were numbered against a draft inventory and not re-synced. Rows
1–3 pointed at P44 (*leave request approval*) instead of P42, whose cited entry
point is `EmployeeService.php` itself; rows 5–6 pointed at P73 (*preventive
maintenance*) instead of P63; row 7 pointed at P75 (*PPAP*) instead of P64,
whose cited entry point is `SettingsController.php:48` itself; the exclusion
note pointed at P28/P29 (*reorder-point breach, MRP run*) instead of P57, whose
name already reads "writes GRN link". All are corrected above. This column is
the only map from recovered blind spot to covering process, so a wrong pointer
would have let a raw write go untraced while both rows read clean.

Read-only `DB::table()` queries are out of scope. Of 353 `DB::table()`
occurrences in `api/app/`, the great majority are reads
(`ChainBottleneckService`, `GlobalSearchService`, `CalendarAggregatorService`,
the statutory report builders); write reach is the classification rule, so they
are dismissed here rather than enumerated.

### 2.3 Process inventory

| ID | Process | Class | Domains | Entry point | Trigger | Blast | Disposition |
|---|---|---|---|---|---|---|---|
| P01 | Payroll finalize → bank file + payslip email + employee notify | cross-module | Payroll, Accounting | `api/app/Modules/Payroll/Controllers/PayrollPeriodController.php:174` | HTTP + event | money | **traced** (§3.0) — 3 findings: P01-01 PROVEN, P01-02 PROVEN, P01-03 ARGUED |
| P02 | Payroll GL posting handoff + retry (raw `journal_entries` insert) | cross-module | Payroll, Accounting | `api/app/Modules/Payroll/Services/PayrollGlPostingService.php:313` | event + job + HTTP | money | **traced** (§3.0) — 2 findings: P02-01 PROVEN, P02-02 PROVEN |
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
| P17 | AR: invoice create → finalize → collection → credit note | cross-module | Accounting, CRM | `api/app/Modules/Accounting/Controllers/InvoiceController.php:62` | HTTP | money | |
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
| P31 | WO confirm → material reservation + issue → stock | cross-module | Production, Inventory, CRM | `api/app/Modules/Production/Controllers/WorkOrderController.php:143` | HTTP | stock | |
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
| P67 | NCR lifecycle: create → action → disposition → close (+ escalation, effectiveness) | cross-module | Quality, Production | `api/app/Modules/Quality/Controllers/NcrController.php:60` | HTTP + scheduled | other | |
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
| P81 | Master data maintenance (operations, commercial, people — 25 pure-CRUD reference tables) | single-module | Inventory, MRP, Production, Quality, SupplyChain, CRM, Accounting, HR, Attendance, Leave | `api/app/Modules/Inventory/Controllers/ItemController.php:60` | HTTP | other | |
| P82 | Government contribution table maintenance (update, activate/deactivate, import, restore) | chain | Payroll | `api/app/Modules/Payroll/Controllers/GovernmentTableController.php:43` | HTTP | money | |
| P83 | De-minimis benefit table maintenance (update, activate/deactivate) | chain | Payroll | `api/app/Modules/Payroll/routes.php:121` | HTTP | money | |

**Counts.** 83 processes: 45 cross-module, 16 chain, 22 single-module. By blast
radius: 25 money, 18 stock, 11 employee-state, 29 other.

P81 is deliberately one row covering the pure-CRUD reference tables (items,
categories, UOM, warehouses / zones / locations, BOM, machines, molds, routings,
inspection specs, NCR templates, item quality plans, vehicles, containers,
customers, vendors, products, price agreements, COA accounts, departments,
positions, skills, trainings, shifts, holidays, leave types). These have no
multi-step state machine and no cross-module write reach; splitting them into 25
rows would inflate the inventory without adding a traceable process. Their entry
points are enumerated in the Task 2 HTTP section.

**Correction (Task 3 review) — payroll reference tables split out as P82/P83.**
The government contribution and de-minimis tables were originally inside P81 and
therefore ranked `other`, `single-module`, and last in the trace order. That was
wrong on both stated criteria. They *do* have a state machine —
`activate`/`deactivate` at `api/app/Modules/Payroll/routes.php:29,31` and
`:123,:124`, alongside `update` (`GovernmentTableController.php:43`), `destroy`
(`:58`), `restore` (`:65`), and `import` (`:74`). And they carry money blast:
`CLAUDE.md` records these tables as effective-dated and selected by
`payroll_date`, worth roughly ₱100/employee between the 2024 and 2025 schedules,
so editing one bracket changes computed deductions for every employee in a
cutoff. They are now P82 and P83 with `money` blast, which moves them out of the
parked pile and into the traced set.

Note that P82/P83 break the otherwise strict rule that ID order equals blast
order: they are money-blast rows carrying IDs above the `other` band. Renumbering
83 rows to preserve the invariant would invalidate every ID already cited in this
document and in the task ledger. Tasks 4–9 trace P82/P83 with the money band
(after P23), not at the end.

### 2.3a FQN container resolution — third blind spot (recovered in review)

Import scanning misses a second construct beyond raw `DB::table()`. A service can
reach a foreign module with no `use` statement at all, by resolving the class
through the container from its fully-qualified name:

```php
app(\App\Modules\CRM\Services\SalesOrderService::class)->markInvoiced(...)
```

There is no import to match, so the edge is absent from *both* Task 2 edge files
— not mis-dismissed as read-only, simply never seen in any form. Sweeping
`api/app/` for every container syntax (`app(`, `resolve(`, `App::make(`, `make(`)
followed by an `App\Modules\` FQN, excluding tests, finds **11 call sites, of
which 5 cross module namespaces**:

| Call site | Resolves | Writes? | Status |
|---|---|---|---|
| `api/app/Modules/Accounting/Services/InvoiceService.php:250` | CRM `SalesOrderService` | **yes** — `transitionTo()` at `api/app/Modules/CRM/Services/SalesOrderService.php:589` locks and writes the SO row | **missed** → P17 reclassified |
| `api/app/Modules/Quality/Services/NcrService.php:338` | Production `WorkOrderService` | **yes** — `createDraft()` writes `work_orders` at `api/app/Modules/Production/Services/WorkOrderService.php:148` | **missed** → P67 reclassified |
| `api/app/Modules/Production/Services/WorkOrderService.php:354` | CRM `SalesOrderService` | **yes** — `markInProduction()` | edge present, domain omitted → P31 corrected |
| `api/app/Modules/Inventory/Services/AutoReplenishmentService.php:53` | Purchasing `AutoPurchaseOrderService` | yes — `PurchaseOrder::create` at `:83` | already covered: file imports Purchasing (4 `use` lines), edge at P28 |
| `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:363` | Quality `PpapService` | **no** — `vendorHasActivePpap()` is `exists()`-only, returns bool (`api/app/Modules/Quality/Services/PpapService.php:198`) | correctly excluded under write reach |

The remaining six resolve same-module services and are not edges.

Both `InvoiceService.php` and `NcrService.php` have **zero** foreign-module
imports for the module they write (`grep -c 'use App\Modules\CRM'` and
`'use App\Modules\Production'` both return `0`), which is precisely why the
import graph could not see them. P17 was classed `chain | Accounting` while
writing CRM on a money path; P67 was classed `chain | Quality` while creating
Production work orders — the NCR corrective-action loop `CLAUDE.md` names as the
thesis differentiator. Both are now `cross-module`.

The sweep above is complete for these four syntaxes. Resolution split across an
intermediate variable, or via a string class name, would still evade it; that
residual gap is recorded in section 5 rather than claimed as closed.

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

### 3.0 Traces

Full eight-field traces. Findings raised here are filed into 3.1–3.5 below with
their severity class; the trace is the evidence, the finding is the claim.

#### P01 — Payroll finalize → bank file + payslip email + employee notify

Entry point `api/app/Modules/Payroll/Controllers/PayrollPeriodController.php:174`
→ `PayrollPeriodService::finalize()` at
`api/app/Modules/Payroll/Services/PayrollPeriodService.php:1049`.

**Field 1 — steps and owning class**

| # | Step | Owner | Line |
|---|---|---|---|
| 1 | Re-read + `lockForUpdate` the period | `PayrollPeriodService` | `:1061` |
| 2 | Guard: status must be `Approved` | `PayrollPeriodService` | `:1065` |
| 3 | Guard: zero unresolved `PayrollAnomalyFlag` | `PayrollPeriodService` | `:1070-1076` |
| 4 | Write `status=Finalized`, `finalized_by/at`, `bank_file_status=Pending`, `gl_handoff_status=Pending` | `PayrollPeriodService` | `:1080-1090` |
| 5 | 13th-month: flip accruals `is_paid=true` | `ThirteenthMonthService::markAccrualsPaidOnFinalize` | `:1100` → `ThirteenthMonthService.php:259` |
| 6 | Audit row `payroll.period.finalize` | `AuditLog` | `:1102-1112` |
| 7 | **Record intent** — outbox row for `PayrollPeriodFinalized` | `OutboxService::recordForChain` | `:1115-1121` |
| 8 | **Record intent** — outbox row for `PayrollGlPostingRequested`, dedupe key `payroll-gl-finalize:{id}` | `OutboxService::recordForChain` | `:1122-1129` |

Steps 7–8 are intent, not effect. The effects run later, off the outbox:

| # | Effect | Owner | Line |
|---|---|---|---|
| 9 | Bank file CSV + `BankFileRecord` | `GenerateBankFileOnPayrollFinalized` → `BankFileService::generate` | `Listeners/GenerateBankFileOnPayrollFinalized.php:43` → `Services/BankFileService.php:92` |
| 10 | Payslip email child jobs, one per employee | `EmailPayslipPdfOnPayrollFinalized` → `SendPayslipEmailJob` | `Listeners/EmailPayslipPdfOnPayrollFinalized.php:26`, dispatch `:59` |
| 11 | In-app + email notification to every paid employee | `NotifyEmployeesOnPayrollFinalized` → `NotificationService::send` | `Listeners/NotifyEmployeesOnPayrollFinalized.php:18`, `:34` |
| 12 | GL journal entry (raw `journal_entries` insert — P02) | `PostPayrollToGlOnRequested` → `PayrollGlPostingService` | `Listeners/PostPayrollToGlOnRequested.php:28`, raw insert at `Services/PayrollGlPostingService.php:313` |

Registrations: `api/app/Providers/AppServiceProvider.php:298-300` (three
`PayrollPeriodFinalized` listeners) and `:302` (`PayrollGlPostingRequested`).

**Field 2 — transaction boundary**

One `DB::transaction` at `:1056` encloses steps 1–8 only. It covers the status
write, the 13th-month accrual flip, the audit row, and both outbox rows — the
transactional-outbox pattern, correctly applied: `OutboxService::record` detects
the open transaction and joins it rather than opening its own
(`api/app/Common/Services/OutboxService.php:59-61`), and defers the queue push
to `DB::afterCommit` (`:65-74`). Steps 9–12 each run in their own later
transaction (`GenerateBankFileOnPayrollFinalized.php:45`,
`BankFileService.php:102`, `EmailPayslipPdfOnPayrollFinalized.php:83`). No
enclosing boundary spans finalize and its effects, by design.

**Field 3 — step N succeeds, N+1 fails**

| Crossing | Behavior | Evidence |
|---|---|---|
| 4 → 5 | Rollback. Accrual flip is inside the boundary and synchronous, deliberately not a queued listener | `:1092-1100` |
| 8 → 9 (bank file) | No rollback, and none wanted: the period stays Finalized and `bank_file_status` is driven to `manual_required` with an operator note on every failure branch | `GenerateBankFileOnPayrollFinalized.php:79`, `:107`, `:119` |
| 8 → 12 (GL) | No rollback. Period stays Finalized with `gl_handoff_status` recoverable; operator retry at `PayrollPeriodController.php:188` | `PayrollPeriodService.php:1138`, `PostPayrollToGlOnRequested.php:67` |
| Partial payslip batch | Per-row claim state, not all-or-nothing; a failed dispatch returns the row to `EMAIL_FAILED` before rethrowing | `EmailPayslipPdfOnPayrollFinalized.php:62`, `:116-134` |

Compensation is genuinely present on this process rather than assumed: every
cross-boundary failure lands on a persisted recovery state plus a scheduled
reconciler (`api/routes/console.php:91`, `:99`, `:163`).

**Field 4 — sync vs async handoff**

| Edge | Kind | Retry budget | Out-of-order |
|---|---|---|---|
| finalize → accrual flip | **sync**, in-transaction | n/a — rolls back | n/a |
| finalize → all four listeners | async via outbox, `DispatchOutboxMessage` | outbox `$tries=3`, `backoff [10,60,300]` (`Common/Jobs/DispatchOutboxMessage.php:19-22`); scheduled recovery every minute (`routes/console.php:163`) | Lease-fenced: `claim()` refuses a PUBLISHED or freshly-PROCESSING row (`OutboxDispatcher.php:119-139`) |
| bank-file listener | queued | `$tries=3`, `backoff [60,300]` (`GenerateBankFileOnPayrollFinalized.php:36-39`) | Re-entrant safe (field 5) |
| GL listener | queued | `$tries=3`, `backoff [60,300,900]` (`PostPayrollToGlOnRequested.php:21-24`) | Guards on current status + `journal_entry_id` (`:39`, `:51`) |
| payslip listener | queued, fans out to child jobs | no `$tries` declared → queue default | 15-min stale-claim reclaim (`EmailPayslipPdfOnPayrollFinalized.php:20`, `:42-47`) |

**Field 5 — idempotency under replay**

| Effect | Dedupe mechanism | Enforced by | Verdict |
|---|---|---|---|
| Status write | `status === Approved` guard on the locked row | `:1065` | idempotent — second call 422s |
| 13th-month accrual flip | `where('is_paid', false)` | `ThirteenthMonthService.php:270` | idempotent |
| Bank file | `bank_file_status === Generated \|\| bankFileRecords()->exists()` | `GenerateBankFileOnPayrollFinalized.php:53-54` | idempotent — replay is a read |
| Payslip email | per-row `payslip_emailed_at` + status claim under `lockForUpdate` | `:89-110` | idempotent per employee |
| GL posting | `journal_entry_id !== null` early return | `PostPayrollToGlOnRequested.php:51` | idempotent |
| Employee notification | **none** — no dedupe key, no sent-marker column | `NotifyEmployeesOnPayrollFinalized.php:34` | **not idempotent**, but bounded by outbox lease (see P01-03) |
| Outbox row itself | `dedupe_key` + `insertOrIgnore` | `OutboxService.php:32`, `:36` | idempotent — **and this is the defect in P01-02** |

**Field 6 — guard reachability (mechanical enumeration)**

Grep 1, `PayrollPeriodStatus::` writers outside `/tests/`, yields nine writers.
Grep 2 (`DB::table('payroll_periods')`) returns four sites, all reads
(`Common/Services/ChainBottleneckService.php:491`,
`Common/Services/CalendarAggregatorService.php:271`,
`Modules/Dashboard/Services/HrDashboardService.php:97`,
`Modules/Dashboard/Services/DashboardWidgetDataService.php:343`) — no raw
write to this table exists. Grep 3 returns zero `PayrollPeriod::query()`
mass `update`/`delete`. Grep 4 output is in field 8's note.

| # | Writer | Target status | Guard | Reads locked row |
|---|---|---|---|---|
| 1 | `PayrollPeriodService::create` `:256` | Draft | new row | n/a |
| 2 | `PayrollPeriodService::claimForCompute` `:788` | Processing | conditional `UPDATE … WHERE status IN (draft,computed) OR stale` `:774-786` | **yes** — the WHERE *is* the lock |
| 3 | `PayrollPeriodService::approve` `:889` | Approved | `status === Computed` `:858` | yes `:849` |
| 4 | `PayrollPeriodService::finalize` `:1081` | Finalized | `status === Approved` `:1065` | yes `:1061` |
| 5 | `PayrollPeriodService::markDisbursed` `:928` | Disbursed | in `markDisbursed` `:914-928` | yes `:915` |
| 6 | `PayrollPeriodService::void` `:1254` | Voided | `status === Finalized` `:1233` | yes `:1229` |
| 7 | `PayrollPeriodService::releaseClaim` `:693` | Computed **or Draft** | **none in the method** | **no** |
| 8 | `AutoPayrollPeriodService` `:111` | Draft | new row | n/a |
| 9 | `ThirteenthMonthService` `:163`, `:244` | Draft, Computed | own-run scope | no, but confined to a 13th-month period it just created |

Writer 7 is the bypass. `releaseClaim` writes `status` with no guard of its own
and has four callers, of which three carry a guard and one does not:

| Caller | Guard before calling | Line |
|---|---|---|
| `ProcessPayrollJob` finally block | job bailed earlier unless `status === Processing` | `Jobs/ProcessPayrollJob.php:80`, call at `:162` |
| `ProcessPayrollJob::failed` | `status === Processing` | `:191`, call at `:192` |
| `ReapStalePayrollRuns` | `where('status', Processing)` in the query | `Console/Commands/ReapStalePayrollRuns.php:53`, call at `:73` |
| `PayrollPeriodService::forceUnlock` | `$period->status !== Processing` — **on the unlocked, route-bound model** | `:1180`, call at `:1190` |

**Field 7 — audit attribution**

| Step | Audit row | Actor |
|---|---|---|
| finalize | `payroll.period.finalize`, in-transaction | `finalized_by = $actor->id` `:1082`, `:1102-1112` |
| approve | `payroll.period.approve` | `approved_by` `:894` |
| void | `payroll.period.void`, records reversal JE id | `voided_by` `:1281-1291` |
| force-unlock | `payroll.period.force_unlock` | `force_unlocked_by` `:1190`, `:1193-1203` |
| bank file generated | `BankFileRecord.generated_by` — a *resolved system actor*, not the finalizer | `GenerateBankFileOnPayrollFinalized.php:71-75`, `BankFileService.php:183` |
| payslip email | `saveQuietly()` — no audit row, by design (per-row delivery state) | `EmailPayslipPdfOnPayrollFinalized.php:110` |
| employee notification | none | — |

One attribution gap worth naming without inflating it: the bank file is
attributed to whichever active user holds an automation role, ordered by `id`
(`GenerateBankFileOnPayrollFinalized.php:71-75`), because the listener has no
access to the finalizer. The period *does* carry `finalized_by` (`:1082`), so
the information exists but is not threaded through. Filed as advisory, not a
severity finding.

**Field 8 — verdict**

Three findings. The GL leg (step 12) belongs to P02 and is not verdicted here.
Grep 4 (FQN container resolution) over the ten traced files found no unseen
cross-module edge: `app(JournalEntryService::class)` at `:1247` is Accounting,
but that file already imports it at `:12`, so the import graph saw it; all other
`app()` calls resolve `Common` or same-module services. P01's declared domains
(Payroll, Accounting) are correct.

- **P01-01** — `forceUnlock` can demote a Finalized period. **PROVEN.**
  Data-corrupting → 3.1.
- **P01-02** — re-finalize after void records no new GL request. **PROVEN.**
  Silent failure → 3.2.
- **P01-03** — employee payslip notification has no dedupe. **ARGUED.**
  Non-idempotent → 3.4.

#### P02 — Payroll GL posting handoff + retry (raw `journal_entries` insert)

Entry point `api/app/Modules/Payroll/Services/PayrollGlPostingService.php:313`
(the raw insert recovered in §2.2). The process has three doors into one
private method: `post()` `:40`, `retry()` `:55`, both delegating to
`postLocked()` `:97`; and `markManual()` `:77` for the failure state.

`post()` has **no production caller**. Every live path enters through
`retry()`: the listener at `Listeners/PostPayrollToGlOnRequested.php:60` and the
legacy job adapter at `Jobs/PostPayrollToGlJob.php:42`. `post()` is reached only
from tests. Both doors share `postLocked`, so behaviour is identical; the fact
is recorded for accuracy, not as a finding.

**Field 1 — steps and owning class**

| # | Step | Owner | Line |
|---|---|---|---|
| 1 | Re-read + `lockForUpdate` the period | `PayrollGlPostingService` | `:43-45` (`post`), `:59-61` (`retry`) |
| 2 | Guard: status ∈ {Finalized, Disbursed} | `postLocked` | `:99` |
| 3 | Guard: `journal_entry_id` already set → return existing id, repair handoff state | `postLocked` | `:102-109` |
| 4 | Branch: accounting module disabled → `markGlNotRequired` | `postLocked` | `:114-120` |
| 5 | Branch: accounting tables absent → `markGlManualRequired` | `postLocked` | `:122-128` |
| 6 | Guard: accounting period not closed for `payroll_date` | `AccountingPeriodService::assertPostingAllowed` | `:133` |
| 7 | Branch: no valid payroll rows → `markGlNotRequired` | `postLocked` | `:139-142` |
| 8 | Aggregate 18 sums over `payrolls` | `postLocked` | `:144-167` |
| 9 | Resolve 13 configured account codes | `SettingsService::requiredString` | `:172-182` |
| 10 | Build balanced debit/credit lines | `postLocked` | `:190-303` |
| 11 | Guard: debits === credits, else throw | `postLocked` | `:305-310` |
| 12 | Generate `entry_number` from sequence | `DocumentSequenceService::generate` | `:312` |
| 13 | **Raw insert** `journal_entries` | `postLocked` | `:313-325` |
| 14 | **Raw insert** `journal_entry_lines`, one per line | `postLocked` | `:327-331` |
| 15 | Link `journal_entry_id` + `gl_handoff_status=Posted` on the period | `postLocked` | `:333-338` |

Steps 13–15 are the effect. There is no outbox row and no event here — P02 is
the *consumer* end of P01's step 8.

**Field 2 — transaction boundary**

One `DB::transaction` per door, enclosing steps 1–15 in full: `:42` for `post`,
`:58` for `retry`. The lock at step 1 is taken inside that boundary, so steps
2–3 read the authoritative row — the pattern P01-01 found missing in
`forceUnlock`. `markManual` `:79` opens its **own** transaction and is invoked
only from the catch at `:70-73`, i.e. after the enclosing transaction has
already rolled back. That ordering is correct: the failure state survives the
rollback that erased the attempt.

**Field 3 — step N succeeds, N+1 fails**

| Crossing | Behavior | Evidence |
|---|---|---|
| 13 → 14 (JE inserted, lines fail) | Rollback. Both inserts are inside one boundary, so no headerless JE can persist | `:42`/`:58` enclose `:313`, `:327` |
| 14 → 15 (lines inserted, link fails) | Rollback. No orphan JE with an unlinked period | same boundary, `:333` |
| 11 (unbalanced) | Throws before any insert; `BusinessRuleException` → `markManual` in its own transaction | `:306`, `:71`, `:79` |
| 6 (closed period) | `ClosedPeriodException` → `markManual`, period stays Finalized and recoverable | `:133`, `AccountingPeriodService` `assertPostingAllowed` |
| listener → `retry` throws non-BusinessRule | Rethrown, job retries under `$tries=3` | `PostPayrollToGlOnRequested.php:74-80` |

Compensation is present: every terminal failure lands on a persisted
`gl_handoff_status` the operator can act on, and `markManual` is called twice on
the BusinessRule path (once by `retry`'s catch `:71`, once by the listener's
`:67`) — redundant but idempotent, both writing the same state.

**Field 4 — sync vs async handoff**

| Edge | Kind | Retry budget | Out-of-order |
|---|---|---|---|
| finalize → `PayrollGlPostingRequested` | async via outbox | outbox `$tries=3`, `backoff [10,60,300]`; scheduled recovery every minute (`routes/console.php:163`) | Lease-fenced (`OutboxDispatcher.php:119-139`) |
| event → `PostPayrollToGlOnRequested` | queued listener | `$tries=3`, `backoff [60,300,900]` | `:21-24`; guards on live status `:39` and `journal_entry_id` `:51` |
| operator retry → outbox | async, key `payroll-gl-retry:{id}:{uuid}` | fresh row per click | `PayrollPeriodService.php:1162` |
| legacy `PostPayrollToGlJob` | queued, `ShouldBeUnique`, `uniqueFor=600` | delegates to `retryGlPosting` | `Jobs/PostPayrollToGlJob.php:24-42` |

Boundary-vs-dispatch (plan step 2): **no event is dispatched inside a
transaction anywhere on this process.** `PayrollGlPostingService` dispatches
nothing (grep for `dispatch(`/`event(` over the file returns zero). The only
producer is `finalize`, which records through `OutboxService`, and that joins
the caller's open transaction (`OutboxService.php:59-61`) and defers the queue
push to `DB::afterCommit` (`:65-74`). Neither the naked-dispatch-in-transaction
defect nor a non-`afterCommit` push exists here.

**Field 5 — idempotency under replay**

| Effect | Dedupe mechanism | Enforced by | Verdict |
|---|---|---|---|
| Journal entry insert | `journal_entry_id !== null` early return, read on the **locked** row | `:102` after lock at `:43`/`:59` | idempotent — replay returns the existing id |
| Concurrent double post | row lock on `payroll_periods`, not on `journal_entries` | `:43-45`, `:59-61` | serialized; second caller sees the link and returns |
| `entry_number` collision | `SELECT … FOR UPDATE` on `document_sequences` per (type, year, month) | `DocumentSequenceService.php:62-88` | unique index `journal_entries.entry_number` (`0039:…unique()`) never reached |
| Duplicate JE for one period | **none at the database level** — `(reference_type, reference_id)` carries an `index`, not a `unique` | `0039_create_journal_entries_table.php` index line | relies wholly on the period-row lock + `journal_entry_id`; sound while that link is honest, see P02-02 |
| Operator retry rows | `Str::uuid()` suffix → every click stages a new row | `PayrollPeriodService.php:1162` | **deliberately not deduped**; effect still single (below) |
| `markManual` | writes the same state; `$changed` only gates the timestamp | `PayrollPeriod.php:213-225` | idempotent |

On the brief's question about the retry path's UUID: it does create unbounded
*requests* — 5 clicks produced 5 outbox rows in probe 6 — but not unbounded
*effects*. `retryGlPosting` refuses to stage anything once `journal_entry_id` is
set (`PayrollPeriodService.php:1150-1152`), so rows accumulate only while the
handoff is genuinely unposted, which is precisely when a retry is warranted.
Deliveries that lose the race skip at `PostPayrollToGlOnRequested.php:51` and
again under lock at `:102`. The UUID discriminator is the correct choice here
and is **not** a finding; the missing discriminator on the *finalize* key
(P01-02) remains one.

**Field 6 — guard reachability (mechanical enumeration)**

Grep 1 anchored on `JournalEntryStatus::` yields 19 hits, of which only four are
writes: `JournalEntryService.php:122` (Draft), `:214` (Posted), `:289`
(reversal Posted), `:308` (original → Reversed).

**Grep 1 is incomplete here, and this is the inherited protocol gap firing.**
Three further writers set the JE status as a plain string and are invisible to
an enum-anchored grep. Grep 2 (`DB::table('journal_entries')`) is what recovers
them:

| # | Writer | Status written | Form | Guard | Reads locked row |
|---|---|---|---|---|---|
| 1 | `JournalEntryService::create` `:114-124` | `Draft` | enum | new row | n/a |
| 2 | `JournalEntryService::post` `:213-219` | `Posted` | enum | `status !== Draft` throw `:182`; balance re-check `:198`; maker-checker `:211` | **no** — guard at `:182` reads the route-bound model *before* the transaction at `:186` |
| 3 | `JournalEntryService::reverse` `:281-293` | `Posted` (mirror) | enum | `status !== Posted` `:270`, `reversed_by_entry_id !== null` `:273` | **no** — both guards precede the transaction at `:277` |
| 4 | `JournalEntryService::reverse` `:307-310` | `Reversed` | enum | same as 3 | **no** |
| 5 | **`PayrollGlPostingService:313-325`** | **`'posted'` (string)** | **raw insert** | period status `:99` + `journal_entry_id` `:102` | **yes** — locked at `:43`/`:59` |
| 6 | `GrnGlPostingService:223-225` | `'posted'` (string) | raw update | (P15, not traced here) | — |
| 7 | `MovementGlPostingService:201-203` | `'posted'` (string) | raw update | (P16, not traced here) | — |

Writers 2–4 guard on an unlocked, pre-transaction read — the same shape as
P01-01. They are Accounting-owned and belong to P17/P18/P20, so they are
recorded here as enumerated-and-flagged rather than verdicted; the note is
carried to §5 so those traces inherit it instead of re-deriving it.

Grep 3 (`JournalEntry::query()` mass `update`/`delete`) returns zero. Grep 4
(FQN container resolution over the traced files) returns only
`ChainListenerRunService` and same-module services — no unseen cross-module
edge. P02's declared domains (Payroll, Accounting) are correct.

**Field 7 — audit attribution**

| Step | Audit row | Actor |
|---|---|---|
| JE insert (step 13) | **none** | **none — `created_by` and `posted_by` are absent from the insert array** `:314-324` |
| JE lines (step 14) | none | n/a |
| period link (step 15) | none (`forceFill`+`save` on a model without `HasAuditLog`) | n/a |
| `markManual` / `markGlNotRequired` | none | n/a |
| listener outcome | `chain_step_runs` row via `ChainListenerRunService::recordOutcome` | system |
| operator retry | no audit row; only the outbox + chain-step row | `PayrollPeriodService.php:1156-1163` |

This is P02-01. `JournalEntry` carries `HasAuditLog`
(`Modules/Accounting/Models/JournalEntry.php:20`), which registers Eloquent
model-event hooks (`Common/Traits/HasAuditLog.php:20-22`). A query-builder
insert fires no model events, so the trait never runs.

**Field 8 — verdict**

Two findings.

- **P02-01** — the payroll journal entry is written with no actor and no audit
  row. **PROVEN.** Bypassable → 3.3.
- **P02-02** — a voided period keeps `journal_entry_id`, so every re-post path
  permanently no-ops while reporting success. **PROVEN.** Silent failure → 3.2.

Sound on this process, recorded so the clean parts are visible: the lock-then-check
ordering (`:43`/`:99`/`:102`), full-boundary atomicity of the three writes
(`:313`, `:327`, `:333`), the balance assertion before any insert (`:305`), the
closed-period guard on `payroll_date` (`:133`), sequence-generator concurrency
(`DocumentSequenceService.php:62-88`), and the retry UUID discriminator
(`PayrollPeriodService.php:1162`).

### 3.1 Data-corrupting

#### P01-01 — `forceUnlock` demotes a Finalized period to Computed (PROVEN)

`PayrollPeriodService::forceUnlock`
(`api/app/Modules/Payroll/Services/PayrollPeriodService.php:1178`) reads its
guard from the **route-bound model**, before the transaction and before any
lock:

```php
if ($period->status !== PayrollPeriodStatus::Processing) {   // :1180 — unlocked read
    throw new BusinessRuleException('Only periods stuck at Processing can be force-unlocked.');
}
return DB::transaction(function () use ($period, ...) {       // :1184 — lock starts here
    $this->releaseClaim($period, ['force_unlocked_by' => $actor->id]);  // :1190
```

Every sibling lifecycle method re-reads under `lockForUpdate` inside the
transaction and checks the locked row: `approve` `:849`/`:858`, `finalize`
`:1061`/`:1065`, `void` `:1229`/`:1233`, `markDisbursed` `:915`. `forceUnlock`
is the only one that does not, and `releaseClaim` `:691` carries no guard of its
own (field 6, writer 7) — it writes `Computed` unconditionally when payroll rows
exist.

Consequence: a force-unlock request whose model was loaded while the period was
Processing will demote the period even if it has since been approved and
finalized. The docblock at `:1175-1176` claims the opposite — "cannot demote
Approved/Finalized/Disbursed". A demoted period is computable again
(`PayrollPeriodStatus::isComputable()` accepts `Computed`,
`Enums/PayrollPeriodStatus.php:53-56`), so a recompute can overwrite payroll
rows that a bank file has already paid, while `finalized_by`/`finalized_at`
remain stamped on the row from the finalize that was undone.

**Probe (PROVEN).** Throwaway PHPUnit test, deleted after the run. Loaded a
stale `PayrollPeriod` instance at Processing, updated the row to Finalized to
simulate the concurrent finalize, then called
`forceUnlock($staleModel, $admin, 'probe')`. Result: the finalized period
transitioned to `computed`. Probe output:

```
[PROBE1] status after forceUnlock on finalized row: computed
```

The window is real rather than theoretical: `forceUnlock` exists precisely for
periods that have been sitting at Processing, so an operator opening the period
list, then finalizing in another tab (or another operator finalizing after the
reaper released the claim at `Console/Commands/ReapStalePayrollRuns.php:73`),
reproduces it without contrivance.

### 3.2 Silent failure

#### P01-02 — re-finalize after void records no new GL request, and reports success (PROVEN)

`finalize` records the GL handoff request with a dedupe key derived from the
period id alone:

```php
app(OutboxService::class)->recordForChain(
    new PayrollGlPostingRequested($fresh, 'payroll_finalized'),
    ..., 'payroll-gl-finalize:'.$fresh->id,    // :1128 — no run discriminator
);
```

`OutboxService::record` inserts with `insertOrIgnore` keyed on `dedupe_key`
(`api/app/Common/Services/OutboxService.php:36-46`) and then *re-reads the
existing row* (`:48-50`). On a second finalize of the same period the insert is
ignored, the already-PUBLISHED row from the first finalize is returned, and
`DB::afterCommit` re-enqueues it (`:65-74`). `OutboxDispatcher::claim` refuses
any PUBLISHED row (`OutboxDispatcher.php:119-121`), so the second finalize's GL
request is never delivered. Nothing throws: `finalize` returns 200 with
`gl_handoff_status = pending` written at `:1087`, so the period reports a GL
handoff in flight that no longer exists.

Contrast the operator retry path, which appends a UUID to the same key family
precisely to avoid this — `'payroll-gl-retry:'.$fresh->id.':'.Str::uuid()`
(`:1162`). The finalize path has no such discriminator.

**Probe (PROVEN).** Seeded a PUBLISHED `event_outbox` row with dedupe key
`payroll-gl-finalize:{id}`, then called `recordForChain` with the same key as
finalize does. Result: row count stayed at 1 and the returned row was still
`published` — the second request was swallowed. Probe output:

```
[PROBE2] rows before=1 after=1 status=published
```

**Reachability is narrower than the mechanism, and this is the honest bound.**
Reaching a second finalize requires returning a period to `Approved`, and the
sanctioned correction path does not allow it: `void` moves the period to
`Voided` (`:1254`), and `claimForCompute` explicitly refuses a Voided period
with "Create a replacement period instead." (`:746`). So on the sanctioned path
this is latent. It becomes live by composition with **P01-01**: force-unlock a
finalized period to `Computed`, recompute, approve, finalize again — the second
finalize is now reachable and its GL request is silently dropped. Payroll is
finalized, the bank file regenerates (that listener is guarded by
`bankFileRecords()->exists()` and would skip, `GenerateBankFileOnPayrollFinalized.php:54`),
and the ledger never receives the entry. That is money-blast cross-module
inconsistency, which is why it is filed rather than dismissed as latent.

A related documentation defect sits on the same path, and it is not confined to
a comment. The `void` docblock at `:1218` says the period "can be
recomputed/re-finalized (see allowedToRecompute)". No `allowedToRecompute`
exists anywhere in `api/app`, and the code does the opposite — Voided is refused
at `:746`. The same wrong instruction reaches the operator: the void endpoint
responds "you can recompute or create a replacement period"
(`PayrollPeriodController.php:285`), and the first half of that sentence is
false. Only the "replacement period" half is achievable.

#### P02-02 — a voided period keeps `journal_entry_id`, so every re-post path silently no-ops (PROVEN)

`void` reverses the journal entry via a balanced mirror
(`PayrollPeriodService.php:1247`) and marks the original `Reversed`
(`JournalEntryService.php:307-310`), but it **never clears
`journal_entry_id` on the period**. The write at `:1253-1258` sets `status`,
`voided_at`, `voided_by`, `void_reason` and nothing else; `:1259` only touches
`gl_handoff_status` when the id is already null. So a voided period still points
at a reversed JE, and `gl_handoff_status` stays `posted`.

Every re-post path treats a non-null `journal_entry_id` as proof that a live
entry exists:

| Path | Guard | Line |
|---|---|---|
| `retryGlPosting` | `journal_entry_id !== null` → return without staging | `PayrollPeriodService.php:1150-1152` |
| `PostPayrollToGlOnRequested` | `journal_entry_id !== null` → record `skipped` | `Listeners/PostPayrollToGlOnRequested.php:51-57` |
| `postLocked` | `journal_entry_id` → return the existing id as success | `PayrollGlPostingService.php:102-109` |
| `markGlNotRequired` | early return when the id is set | `Models/PayrollPeriod.php:236-239` |

The stale link therefore disables the GL handoff for that period permanently.
Nothing throws and nothing warns: `retryGlPosting` returns the period, the
controller answers `202 GL handoff retry queued.`
(`PayrollPeriodController.php:198`) for a retry that was never staged, and
`postLocked` returns the id of the *reversed* entry as though it had just posted
— then calls `markGlPosted()` at `:106` if the handoff state had drifted,
actively re-asserting a posting that no longer exists.

On the sanctioned path a voided period is terminal, so this is latent. It goes
live by the same composition as P01-02: force-unlock the voided-then-resurrected
period (P01-01), recompute, approve, finalize. The period is then Finalized,
carrying real payroll and a `journal_entry_id` pointing at a reversed entry whose
net ledger effect is zero — and every path that would post the replacement entry
refuses, reporting success. Finalized payroll, employees paid from the bank file,
no ledger entry.

**Probe (PROVEN).** Throwaway PHPUnit test, deleted after the run. Posted a real
period to the GL, voided it, then drove both re-post doors:

```
[PROBE5a] after void: period status=voided journal_entry_id=2 gl_handoff_status='posted' | original JE status=reversed reversed_by=3
[PROBE5b] retryGlPosting staged outbox rows: before=4 after=4 (delta=0) | post() returned=2 (original=2) | payroll_period JEs=1
```

The void left `journal_entry_id=2` and `gl_handoff_status='posted'` on a period
whose JE is `reversed`. `retryGlPosting` staged **zero** outbox rows while
returning normally, and `post()` returned the reversed entry's own id, `2`,
rather than creating a replacement — one `payroll_period` JE total, and that one
reversed to nil.

**Probe 6, same run — the retry UUID is bounded.** Five consecutive
`retryGlPosting` calls on an unposted period staged five outbox rows
(`[PROBE6] … before=3 after=8 (delta=5)`), confirming the brief's suspicion of
unbounded *requests*. The effect stays single: each delivery re-checks
`journal_entry_id` under lock (`PayrollGlPostingService.php:102`), so the first
to win posts and the rest skip. Requests are cheap rows in `event_outbox`; the
alternative (a stable key) is what P01-02 punishes. Not filed as a finding.

### 3.3 Bypassable

#### P02-01 — payroll's journal entry bypasses actor attribution and the audit log (PROVEN)

`PayrollGlPostingService::postLocked` writes the ledger with the query builder:

```php
$entryId = DB::table('journal_entries')->insertGetId([
    'entry_number' => $entryNumber,          // :314
    ...
    'status'       => 'posted',              // :321 — plain string, not the enum
    'posted_at'    => now(),                 // :322
    // no 'posted_by', no 'created_by'
]);
```

Three controls that apply to every other posted journal entry do not apply to
this one.

1. **No actor.** `posted_by` and `created_by` are nullable FKs to `users`
   (`0039_create_journal_entries_table.php`) and both are omitted from the insert
   array (`:313-325`), so the largest recurring entry in the ledger records
   nobody. Contrast `JournalEntryService::post`, which stamps
   `'posted_by' => $by->id` (`Modules/Accounting/Services/JournalEntryService.php:215`),
   and `reverse`, which stamps both (`:291-292`). The information exists on the
   period — `finalized_by` (`PayrollPeriodService.php:1082`) — and is not
   threaded through, the same shape as P01's bank-file attribution gap but on a
   financial row rather than a file.
2. **No audit row.** `JournalEntry` uses `HasAuditLog`
   (`Modules/Accounting/Models/JournalEntry.php:20`), which hooks
   `static::created` / `updated` / `deleted`
   (`Common/Traits/HasAuditLog.php:20-22`). Query-builder inserts fire no
   Eloquent model events, so no `audit_logs` row is written for the JE or its
   lines. Nothing else on the path compensates: step 15's period write is a
   `forceFill`+`save` on `PayrollPeriod`, and the only audit row anywhere near
   this process is `payroll.period.finalize`, written by a different method in a
   different transaction (`PayrollPeriodService.php:1102-1112`).
3. **No maker-checker.** `JournalEntryService::post` enforces segregation of
   duties at `:211` → `assertNotSelfPosting` `:235-262`. The raw insert never
   calls it. This one is *by design* and correctly so — `assertNotSelfPosting`
   itself exempts any entry carrying a `reference_type` (`:247-249`), and payroll
   sets `reference_type = 'payroll_period'` (`:317`). SoD is enforced upstream at
   payroll approve. Recorded to show the exemption is deliberate, not incidental.

Items 1 and 2 are the finding. A ledger entry for a full payroll cycle is
unattributable: no `posted_by`, no `created_by`, and no `audit_logs` row naming
who or what created it. The `status` write is also the plain-string form
(`'posted'` at `:321`) rather than `JournalEntryStatus::Posted`, which is what
made this writer invisible to the protocol's enum-anchored grep 1 (field 6).

**Probe (PROVEN).** Throwaway PHPUnit test, deleted after the run. Enabled the
accounting module, computed and finalized a real period, then called
`PayrollGlPostingService::post`. Inspected the inserted row and counted
`audit_logs` rows for `model_type = JournalEntry` before and after:

```
[PROBE4] je status=posted posted_by=NULL created_by=NULL total_debit=12560.00 | JE audit rows before=0 after=0
```

A balanced ₱12,560.00 entry posted itself into the general ledger with no actor
and no audit trail.

### 3.4 Non-idempotent

#### P01-03 — payslip-ready notification has no dedupe or sent-marker (ARGUED)

`NotifyEmployeesOnPayrollFinalized::handle`
(`api/app/Modules/Payroll/Listeners/NotifyEmployeesOnPayrollFinalized.php:18`)
selects every user with a payroll row in the period (`:23-27`) and calls
`NotificationService::send` (`:34`). There is no sent-marker column, no dedupe
key, and no per-employee claim — compare the payslip-email listener, which
claims each row under `lockForUpdate` and checks `payslip_emailed_at`
(`EmailPayslipPdfOnPayrollFinalized.php:89-110`). `NotificationService::send`
inserts inbox rows directly (`api/app/Common/Services/NotificationService.php:113`)
and dedupes only *within one call*, by keying recipients on user id
(`:168-175`); it has no cross-call idempotency. A second delivery therefore
inserts a second "Your payslip for … is ready" row per employee and queues a
second email (`:135`, `queueEmail` at `:255`).

Severity is bounded, and I am labeling this ARGUED rather than PROVEN because
the bound is what matters and I did not drive the duplicate. The outbox lease
prevents replay of a PUBLISHED message (`OutboxDispatcher.php:119-121`), so the
ordinary paths cannot double-deliver. Reaching a duplicate requires the
listener to fail *after* `NotificationService::send` commits but before the job
completes, so the outbox row returns to PENDING (`:191-219`) and redelivers all
three finalize listeners. The listener rethrows after logging (`:41-44`), and
the notification insert is not deferred — inbox rows commit inside `send`
(`:112-114`), while only the broadcast and email are `afterCommit` (`:118`). So
the window exists; a probe would have to inject a failure between the insert and
job completion, which is a harness change rather than a state setup, and I chose
not to spend it. Blast is employee-visible duplicate notifications and duplicate
payslip emails, not money — no financial row is touched.

### 3.5 Missing compensation

## 4. Clean list

## 5. Untraced list

Generated in Task 11 from inventory rows with an empty disposition. As of Task 4
that is P02–P83; P01 is traced (§3.0).

Residual gaps recorded rather than claimed closed:

- Container resolution split across an intermediate variable, or via a string
  class name, still evades the section 2.3a sweep and protocol grep 4.
- P01 field 4 notes `EmailPayslipPdfOnPayrollFinalized` declares no `$tries`, so
  it inherits the queue default. Whether that default is appropriate for a
  fan-out listener is a queue-configuration question, not traced here.
- P01's GL leg (effect 12, `PayrollGlPostingService.php:313`) is P02's subject
  and is verdicted there, not in §3.0.
- P01-03's duplicate-notification window was not driven by a probe (see its
  ARGUED label). It needs a failure injected between the notification insert and
  job completion; if Phase 2 builds that harness, the label should be revisited
  rather than assumed.

## 6. Prior-claim delta

**P01 vs `docs/PROCESS-FAILURE-MATRIX-2026-08-11.md`.** Task 4 does not yet
re-derive that document's P01-adjacent claims line by line; the two PROVEN
findings above are recorded here as the first concrete contradictions to its
"nearly every boundary closed" posture, pending the full delta in Task 11.

**Stale docblock found while tracing P01.** `PayrollPeriodService::void`'s
docblock references `allowedToRecompute`
(`api/app/Modules/Payroll/Services/PayrollPeriodService.php:1218`), a method that
does not exist in `api/app`, and describes a Voided period as re-computable when
`claimForCompute` refuses exactly that (`:746`). `forceUnlock`'s docblock claims
it "cannot demote Approved/Finalized/Disbursed" (`:1175-1176`), which P01-01
disproves. Both are recorded as changes for Phase 2, not findings.
