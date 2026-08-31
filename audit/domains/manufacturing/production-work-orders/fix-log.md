# M051 — Fix Log

**Audit date:** 2026-08-25  
**Initial audit status:** No implementation fixes made.

The findings were intentionally left for planned sessions because the dominant issues cross the Production and Inventory boundaries or require policy decisions about machine ownership, material reservation/lot semantics, exception approval, and planner-versus-executor permissions.

## Verification recorded

- Backend production feature suite: **PASS** — 35 tests, 131 assertions.
- SPA typecheck: **PASS**.
- Targeted responsive-detail Vitest: **BLOCKED before collection** by filesystem permission denied while Vite attempted to write `spa/node_modules/.vite-temp`; no dependency files were changed.

## Required follow-up

Implement the ordered action plan, rerun the full relevant backend and SPA suites, then repeat the M051 audit against the re-audit gate before marking the module complete.

## 2026-08-25 — resumed Plan Ready implementation session

The existing action plan was executed in order where the required policy and cross-module decisions were not blockers. The action plan and audit report were not rewritten.

### Fixed findings

| Finding | File:line | Before | After |
|---|---|---|---|
| B01 | `api/app/Modules/Production/Support/WorkOrderStateMachine.php:12-34`; `api/app/Modules/Production/Exceptions/IllegalLifecycleTransitionException.php:9-18`; `api/app/Modules/Production/Controllers/WorkOrderController.php:162-275` | Transition validation threw an HTTP response exception from the domain service. | Transitions live in a reusable `TRANSITIONS` state machine; the exception is a `BusinessRuleException` with a stable code, and controllers map it to HTTP 409. |
| B02 | `api/app/Modules/Production/Services/WorkOrderService.php:312-358`; `api/app/Modules/Production/Controllers/WorkOrderController.php:190-202` | Starting issued reserved material under the work-order creator. | The authenticated starter is passed to the issue path and becomes `StockMovement.created_by`; covered by `WorkOrderSplitReservationTest`. |
| B04 | `api/app/Modules/Production/Services/WorkOrderOutputService.php:254-270` | Manual receipt fallback returned an output instance with stale handoff status. | The persisted output is refreshed after marking `manual_required`, while preserving the newly-created response status; receipt tests assert the durable state in the response. |
| B08 | `api/app/Modules/Production/Requests/RecordOutputRequest.php:35-58`; `api/app/Modules/Production/Services/WorkOrderOutputService.php:103-110` | Non-empty defect rows could accompany zero rejects. | Request and service validation reject that combination; `WorkOrderOutputFgReceiptTest` covers it. |
| B09 | `api/app/Modules/Production/Services/WoOperationService.php:273-285,367-385`; `api/app/Modules/Production/Enums/ProductionLogEvent.php:14-38`; `api/app/Modules/Production/Controllers/WoOperationController.php:196-215` | Skipping changed status without a transition log, actor, or reason. | Skip requires an employee operator and writes a `skip` production log with operator and notes; covered by `WoOperationOutputRaceTest`. |
| M01 | `api/app/Modules/Production/Services/WorkOrderService.php:196-201`; `api/app/Modules/Production/Services/WoOperationService.php:42-72` | Work-order creation did not snapshot active routing operations. | Creation calls routing generation after the material snapshot; `firstOrCreate` makes sequential retries idempotent and the feature test verifies no duplicate rows. |
| I02 | `api/app/Modules/Production/Resources/WorkOrderResource.php:20-57,165-199`; `api/app/Modules/Production/Resources/WorkOrderOutputResource.php:31-101` | Exception approver, child product, and embedded lineage IDs could be raw integers. | Resource transformations encode those IDs through the HashID service; SPA types now describe the hashed lineage shape. |
| I03 | `api/app/Modules/Production/Services/WorkOrderService.php:598-624`; `api/app/Modules/Production/Controllers/WorkOrderController.php:131-142`; `api/app/Modules/Production/routes.php:44` | Restore directly called the model and emitted no explicit activity event. | Restore is a row-locked transaction with an idempotent activity event, actor attribution, resource response, and `withTrashed` route binding. |
| I05 | `spa/src/pages/production/work-orders/record-output.tsx:45,91-138` | Each mutation invocation generated a new timestamp/random idempotency key. | A key is generated once per form-value fingerprint and retained across failed retries; success clears it. |
| I06 (partial) | `api/app/Modules/Production/Services/WoOperationService.php:80-285,346-364` | Operation commands ignored parent work-order lifecycle state. | Every operation command locks and requires its parent to be `in_progress`; ownership/context and nested-route policy remain open. |
| I07 | `spa/src/pages/production/work-orders/detail.tsx:153-206,564-637,741-796`; `spa/src/api/production/routings.ts:24-44` | Detail page rendered operations read-only. | Detail now exposes state-aware setup/start/pause/resume/output/complete/skip actions, permission gates, employee/operator feedback, mutation invalidation, and error/loading states. |
| P01 | `spa/src/pages/production/work-orders/detail.tsx:565-566`; `spa/src/pages/responsive-detail-tables.test.ts:10` | Operations table had no responsive content wrapper and used the warning surface with alpha styling. | Operations use an overflow wrapper and explicit minimum width; the warning surface is opaque and the responsive contract test now covers the added wrapper. |

### Deferred findings

- B03 — machine ownership checks across start/pause/resume/complete need the agreed machine-reuse policy; no lifecycle transition was guessed.
- B05/B06 — reservation → issue → lot lineage and required issue behavior cross into Inventory and need one shared contract; this module does not invent that contract.
- B07 — operation overrun/tolerance behavior needs a production policy before enforcing a quantity ceiling.
- I01 — exception approver authority and separation of duties need a role/policy decision.
- I04 — SPA class/reason controls depend on the I01 authorization contract.
- I08 — the exact planner/executor role matrix and permission grants remain policy work; new operation controls are gated by the existing lifecycle/output permissions.
- I06 — parent-state enforcement is complete, but ownership and nested parent-context routing are still pending.

## Verification

- `docker compose run --rm api vendor/bin/phpunit tests/Feature/Production --testdox` — **PASS**, 39 tests / 143 assertions.
- Focused modified production tests — **PASS**, 18 tests / 83 assertions.
- `docker compose run --rm spa npm run test:run -- src/pages/responsive-detail-tables.test.ts` — **PASS**, 1 test.
- `npm run typecheck` — the audited production files typecheck; the command remains **BLOCKED by pre-existing** `src/pages/assets/detail.tsx` errors because the `qrcode` module/types are absent (`TS2307`, `TS7006`).
- PHP lint for changed Production source/tests and `git diff --check` — **PASS**.

## Session aborted 2026-08-30 — API quota exhausted mid-discovery

The 2026-08-30 session was killed by `403 pre-consume quota failed` partway
through the hardening pass. It wrote **no audit-report, action-plan or fix-log
entry**, applied **no production change**, and left the working tree clean for
this module. Nothing here was verified by the coordinator.

**One lead worth keeping, recorded second-hand from the session's last output
and NOT independently confirmed — treat it as a hypothesis to re-measure, not a
finding:**

> "Confirmed: no listener exists for `MoldShotLimitNearing` / `MoldShotLimitReached`."

If that holds, it matters: CLAUDE.md states mold shot count auto-increments with
an **alert at 80% of max**, so an event dispatched with no registered listener
means the alert has never fired for any mold. Note the wiring convention —
`Event::listen($EventClass, [$ListenerClass, 'handle'])` is explicit in
`AppServiceProvider::boot()` with **no auto-discovery** — so a missing
registration is silent by construction. This is the same shape as the 8D SLA
escalation ledger that was dead for its entire life.

First move for the next session: `grep -rn 'MoldShotLimit' api/app` and check
`AppServiceProvider::boot()` for a registration, then dispatch the event and
observe whether any notification is delivered.

## 2026-09-01 — re-audit session 3: discovery recorded, no production change

Interrupted by the parent Claude Code process exiting mid-probe-authoring (not
quota, not an error in-session). The lock was still held and zero commits had
landed, so the write-up was committed before any further work.

**No production code changed. No test written. Baseline run only.**

### Environment + baseline (both verified, quoted here because four earlier sessions faked this)

- `docker compose ps` → `ogami-db` and `ogami-redis` both **Up (healthy)**. Neither
  started, stopped nor restarted by this session.
- `docker compose exec -T db psql -U ogami -d postgres -c "select 1;"` → 1 row.
- Baseline on an **own** database (`ogami_test_wo2`, created for this session):
  `docker compose run --rm -e DB_DATABASE=ogami_test_wo2 api php artisan test tests/Feature/Production --no-coverage`
  → **67 passed / 232 assertions / 43.05s / exit 0**. Non-zero assertions, so not
  the poisoned-run signature. (Prior session recorded 39/143; the suite grew.)

### The two prioritised leads — both resolved, both against the incoming hypothesis

**Lead A — "no listener for `MoldShotLimitNearing`/`MoldShotLimitReached`": grep TRUE, conclusion FALSE. CLOSE AS NOT-A-DEFECT.**
There is no `Event::listen()` for either class, and there was never meant to be.
Both `implement ShouldBroadcast` and are recorded through `OutboxService` at
`api/app/Modules/MRP/Services/MoldService.php:135,138`; they are registered in
the outbox codec allow-list at `api/app/Common/Services/OutboxEventCodec.php:125-126`
(an unregistered class would be rejected — positive evidence the path is live).
The operator-facing 80% alert comes from a *different* mechanism: the scheduled
`AlertEngineService` production check at
`api/app/Common/Services/AlertEngineService.php:395-424`, which polls `molds` and
raises `AlertType::MoldShotLimit`, with a missing-condition resolver at `:700-730`
that clears stale alerts. Both driving settings are present and non-null in the
live DB (`alerts.mold.warning_ratio=0.8`, `alerts.mold.critical_ratio=0.95`), so
`requiredFloat()` inside the shot-increment transaction cannot throw and roll back
an output recording. **Caveat: "delivered" is inferred from the poll query, not
observed end-to-end — the runtime probe did not run.**

**Lead B — IC-16 "in-process QC gate does not exist": grep reproduces exactly, conclusion FALSE.**
`grep -rn "InProcess\|in_process" api/app/Modules/Production/` → exit 1, no output.
But the gate is not supposed to live in Production — Production dispatches and
Quality listens, which is the correct dependency direction, so a Production-scoped
grep can never see it. `App\Modules\Quality\Listeners\TriggerInProcessQC` is
registered at `api/app/Providers/AppServiceProvider.php:330` on
`WorkOrderStatusChanged`, which this module stages inside the owning lifecycle
transaction at `api/app/Modules/Production/Services/WorkOrderService.php:732-738`.
It creates an `in_process` inspection on start, is idempotent, refuses rather than
inventing data, notifies through `NotificationService`, and — unlike the 8D SLA
ledger — logs **and rethrows** for queue retry.

Correct classification is **Incomplete, in two separable ways**:
- (a) the WO-level inspection is **created but gates nothing** —
  `WorkOrderService::complete()` (`:467-516`) reads no inspection state, so a WO
  completes with its in-process inspection pending or failed;
- (b) the per-operation gate CLAUDE.md actually describes ("periodic sampling
  between operations") is a **`Log::info` stub** at
  `api/app/Modules/Production/Services/WoOperationService.php:243-266`, whose own
  docblock says "actual event integration comes in a later task". So
  `routing_operations.qc_required` is a configurable IATF flag whose entire effect
  is a log line. **That is the genuine IC-16 gap → Missing.**
Whether to build a sampling regime is a human scope call (Q2 in the report).

### New findings recorded (full evidence + file:line in audit-report.md §2)

Broken: **B01** `/operations/{op}/output` validates `qty` as `numeric` → `1.99995`
silently stored as `2.0000`, `1e15` → `bcadd` `ValueError` 500, `1e12` → `22003`
500 (controller catches only `BusinessRuleException`). **B02**
`WoOperationService::recordOutput()` is a second output path that bypasses
`WorkOrderOutputService::record()` entirely — no `work_order_outputs` row, **no
mold shot increment**, no WO totals/scrap rate, no FG receipt, no outbox event, no
defect reconciliation, no idempotency. **B03** `skipOperation()` is the only
operation command with no `assertStatus()`, so a **Completed** operation can be
flipped to Skipped.

Missing: **M01** per-operation QC is a log statement. **M02** `document_sequences`
has **no `work_order` and no `production_batch` row** (measured), so the known
unguarded lock-or-create in `DocumentSequenceService::generate()` exposes the
first WO create and first start of **every calendar month** to a `23505` 500 —
shared-service scope, reported not fixed. **M03** zero triggers on any
`work_order*` table and no `deleted_at` on `work_order_outputs` (both measured);
mitigated in practice because **no update or delete endpoint for outputs exists**,
so this is defence-in-depth absent rather than reachable.

Incomplete: **I01** `complete()` accepts zero output (and then fires outgoing QC
for a nonexistent batch). **I02** `complete()` never reconciles issued material
against BOM. **I03** `cancel()` ignores already-issued material on the legal
`paused → cancelled` edge. **I04** OEE availability derives from
`available_hours_per_day × naive weekday count`, ignoring shifts and holidays — a
real weekend run yields OEE `null`. **I05** downtime is charged wholly to its
start day; not double-counted but misattributed, and the `max(0, …)` clamp turns
the artifact into a reported hard 0% availability. **I06** raw integer `items` PK
leaks into a 422 body at `WorkOrderService.php:880-883` via `confirm()`. **I07**
`status` + recorded quantity columns still mass-assignable on `WorkOrder`
(measured: an existing test mass-assigns `status` and passes).

Polish: **P01** OEE `report()` trend is O(days × machines) service calls.

Verified as **holding** (credit where due): restore binds `withTrashed()` at both
route and service and refuses a non-trashed target — not the 404-for-every-target
defect three modules shipped; `work_order_outputs` has a real DB
`UNIQUE (work_order_id, idempotency_key)`; all three OEE metrics are null-guarded
rather than divided, so no `DivisionByZeroError`; all 27 Production routes carry
`permission:` middleware; the state machine re-checks `assertTransition` after
taking the row lock, so a stale model cannot slip an illegal transition through.

### Required follow-up (ordered, next session's first job)

1. Run the probe suite in audit-report.md §3 "NOT executed" — it names each probe
   and the predicted outcome, so no rediscovery is needed. Highest value: the B01
   `1e15` 500, the duplicate-output shot/scrap/event non-doubling (baseline covers
   the row-count half only), and whether a mold flipped to `Maintenance`
   **mid-run** can still take output (`start()` checks mold status but nothing
   re-checks it after).
2. Apply I06 (most contained: swap the raw PK for `item->code`), then B01.
3. Resolve Q1–Q5 with a human before touching B02's ceiling, I01, I03 or either
   half of the in-process QC gating.

### 2026-09-01 — fixes applied (same session, contained subset only)

The action plan is majority `separate-recommended`, so this module does **not**
clear the "majority same-session-ok → fix now" gate. Per the brief, only the
genuinely contained items were landed; the split is stated in
`action-plan.md` and justified per item.

**Baseline before:** 67 passed / 232 assertions (`ogami_test_wo2`).
**After:** **78 passed / 247 assertions, 0 failures** — 67 pre-existing (all still
green) + 11 probes, plus 15 extra assertions from the strengthened stock test.
**Zero pre-existing regressions.**

| Finding | File:line | Before (measured) | After (measured) |
|---|---|---|---|
| **NEW Broken** — `resume()` backdoor-starts a confirmed WO | `api/app/Modules/Production/Services/WorkOrderService.php:423-450`, new `assertResumable()` at `:668-685` | transition matrix cell `confirmed → resume` = `ALLOWED(in_progress)`, skipping the material-plan assertion, subassembly readiness, machine/mold availability, material issue, `batch_number`, `actual_start`, lot capture and SO promotion | `confirmed → resume` = `illegal-transition` (409). Only the `paused` row still shows `ALLOWED(in_progress)` |
| **NEW Broken** — negative output persists a corrupt quantity | `api/app/Modules/Production/Services/WorkOrderOutputService.php:79-96` | `good=-5, reject=10` → `threw=NULL produced=5 good=-5 rejected=10 scrap=200.00` | `threw='BusinessRuleException: Good count and Reject count cannot be negative.' produced=0 good=0 rejected=0 scrap=0.00` |
| **B01** — `numeric` quantity → three 500s + silent rounding | `api/app/Modules/Production/Controllers/WoOperationController.php:148-168` | HTTP `{"1.999":200,"1.99995":200,"1e3":200,"1e12":500,"1e15":500,"1e20":500,"-5":422}` | HTTP `{"1.999":200,"1.99995":422,"1e3":422,"1e12":422,"1e15":422,"1e20":422,"-5":422}` |
| **B03** — `skipOperation()` destroys a completed operation | `api/app/Modules/Production/Services/WoOperationService.php:277-315` | `threw=NULL status_now=skipped qty_completed=42.0000 actual_end='…' notes='probe skip…'` | `threw='BusinessRuleException: Cannot skip an operation that is already ''completed''.' status_now=completed qty_completed=42.0000 notes=NULL` |
| **I06** — raw `items` PK in a 422 body | `api/app/Modules/Production/Services/WorkOrderService.php:892-895, 906-919` | `{"message":"Insufficient stock for item 1 (work order WO-P-81057): needed 5.000."}` — contains raw pk: **true** | `{"message":"Insufficient stock for ITM-55YS (work order WO-P-c3aa2): needed 5.000."}` — contains raw pk: **false** |

#### One pre-existing test was made red by a fix, and updated — deliberately

`api/tests/Feature/Production/WorkOrderSplitReservationTest.php:250-283`
(`test_confirm_fails_when_pooled_stock_is_insufficient`) asserted
`expectExceptionMessage('Insufficient stock for item')`, i.e. it **locked in the
raw-PK message format that I06 exists to remove**. This is the quality-session
shape rather than the payroll shape: the red assertion encodes exactly the
falsified state the fix removes, and it is **this module's own test**, so
updating it is not editing another module's test to make a change pass. It now
asserts the item **code** is present and the primary key is **absent**, so it
locks the fix instead of the defect. That is a strictly stronger assertion and
the reason the assertion count rose.

#### Behaviour narrowings worth flagging explicitly

- `decimal:0,4` also rejects **scientific notation** on the operation quantity, so
  `qty: 1e3` went from `200` (stored as 1000) to `422`. No UI sends exponential
  notation for a piece count, and the alternative was leaving the 1e15 `ValueError`
  500 in place, but this is a real input-format narrowing rather than pure
  hardening — noted so it is not a surprise.
- The `max:99999999999` bound is derived from the column: `numeric(15,4)` holds at
  most `99999999999.9999` (11 integer digits). Anything the column can store is
  still accepted.
- `reserveMaterialsFor()` now eager-loads `materials.item` rather than `materials`,
  because `Model::preventLazyLoading` is active outside production and the message
  reads the item's code.

#### Static analysis

- `php -l` — clean on all 4 changed source files.
- `phpstan analyse <4 changed files> --memory-limit=1G` → **`[OK] No errors`**.
- `pint --test` — 4 of the 5 changed files fail, and **inheritance was proved, not
  assumed**: each file's HEAD extract was run through Pint in the *same
  invocation* as the working copy so column truncation matched, and the rule lists
  came back identical (`WoOperationController` 6/6, `WoOperationService` 6/6,
  `WorkOrderService` 14/14, `WorkOrderSplitReservationTest` 5/5, with `NEW rules:
  NONE`). For `WorkOrderService` the comparison was repeated with the HEAD extract
  at an **identical path length** (`WorkOrderServic0.php`) to rule out a
  truncation artifact — both truncated at the same character. `WorkOrderOutputService`
  passes Pint outright. **Zero new violations; nothing was reformatted.**

#### Not fixed, and why (see action-plan.md for the full ordering)

`B02` (the operation output path bypassing `WorkOrderOutputService::record()` —
measured as leaving `wo.quantity_produced=0` and `mold_shots=0` for 25 good and 5
scrap parts) is the single most valuable remaining item, and is `large`: the two
counters have been diverging in any existing installation, so reconciling the
write paths raises a migration question about rows already recorded. `M01`
(per-operation QC is a `Log::info`), `I01` (complete with zero output), `I03`
(material consumed by a cancelled WO), the mold-past-100% behaviour, `I04`/`I05`
(OEE availability and downtime attribution) and `I07` (`status` in `$fillable`)
are all gated on a human decision or on moving numbers a human may already be
reporting. `M02` is `Common` scope and was deliberately left alone.

#### Housekeeping

Scratch probe `api/tests/Feature/Production/ZzM051AuditProbeTest.php` was deleted
after its measurements were recorded (it asserts nothing — it prints). The
session database `ogami_test_wo2` was dropped. `db` and `redis` were left running
and untouched for the other sessions.
