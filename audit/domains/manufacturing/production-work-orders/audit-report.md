# M051 — Production Work Orders Audit Report

**Audit date:** 2026-08-25  
**Auditor:** Codex  
**Claim:** `manufacturing/production-work-orders`  
**Final status:** 📋 Plan Ready  
**Scope:** API module, persistence constraints, permissions/resources, SPA work-order flows, and the module's existing production tests.

## Session summary

This audit re-checked the module after the registry identified M051 as the only unlocked `Needs Re-audit` candidate. The implementation has several useful safeguards—transactional lifecycle mutations, row locks around the primary work order, durable output idempotency, server-side output totals, and hashed IDs in most resources—but the remaining issues cross lifecycle, inventory traceability, routing execution, and UI/API contract boundaries.

The issues are not a safe same-session patch set. Several require a product/policy decision (especially machine ownership, exception authorization, and reservation/lot semantics), and the fixes span services, migrations, resources, controllers, and the SPA. The module is therefore left **Plan Ready** with no production-code fixes in this session.

## Verification

- `docker compose run --rm api vendor/bin/phpunit tests/Feature/Production` — **PASS**, 35 tests / 131 assertions.
- `npm run typecheck` in `spa/` — **PASS**.
- `npm run test:run -- src/pages/responsive-detail-tables.test.ts` — **BLOCKED before collection** by `EACCES` opening `spa/node_modules/.vite-temp/vitest.config.ts.timestamp-…mjs`; the dependency directory is owned by `root:root` and was not modified.
- No production source or test files were changed during this audit.

## What is working well

- Work-order lifecycle mutations and output recording use database transactions and lock the primary work-order row (`api/app/Modules/Production/Services/WorkOrderService.php:149-202`, `WorkOrderOutputService.php:144-185`).
- Output idempotency is backed by a unique `(work_order_id, idempotency_key)` constraint (`api/database/migrations/0466_add_work_order_output_idempotency.php:17-23`).
- Standard work orders cannot start without a material plan, while explicitly classified non-standard work orders require a reason (`WorkOrderService.php:631-644`, `StoreWorkOrderRequest.php:33-46`).
- Most API resources use HashID values and decimal strings for quantities and money (`api/app/Modules/Production/Resources/WorkOrderResource.php:18-26`, `WorkOrderOutputResource.php:16-46`).

## Findings

### Broken

#### M051-B01 — Lifecycle transition policy is coupled to an HTTP exception

`WorkOrderService::assertTransition()` throws `IllegalLifecycleTransitionException`, which extends `HttpResponseException` (`api/app/Modules/Production/Services/WorkOrderService.php:623-628`, `api/app/Modules/Production/Exceptions/IllegalLifecycleTransitionException.php:10-17`). A domain/service mutation therefore owns response formatting and status code behavior. This makes non-HTTP callers, jobs, and future integrations depend on controller transport semantics.

**Impact:** lifecycle rules cannot be reused cleanly and exception handling can diverge between request paths.  
**Classification:** Broken.

#### M051-B02 — Starting a work order attributes material issue activity to the creator

`WorkOrderService::start()` passes the work-order creator (or `created_by`) to `issueReservedMaterials()` instead of the authenticated actor starting the order (`api/app/Modules/Production/Services/WorkOrderService.php:312-379`, especially `355-358`). The service does not receive an actor identifier from `WorkOrderController::start()` (`api/app/Modules/Production/Controllers/WorkOrderController.php:186-196`).

**Impact:** stock movement/audit attribution can be wrong, weakening traceability and accountability.  
**Classification:** Broken.

#### M051-B03 — Machine ownership is not enforced across pause, resume, and complete

`start()` accepts an already-running machine and overwrites its `current_work_order_id` (`WorkOrderService.php:327-343`). `pause()`, `resume()`, and `complete()` then update the locked machine without consistently verifying that the machine is currently assigned to this work order (`WorkOrderService.php:381-515`). `cancel()` contains such a check, showing the missing guard is inconsistent (`WorkOrderService.php:539-581`).

**Impact:** one work order can clear or replace another order's active machine assignment, producing incorrect execution state and downtime history.  
**Classification:** Broken.

#### M051-B04 — Manual receipt fallback returns stale output state

When receipt handoff fails, `recordOutput()` marks the persisted output as manual-required through a separately loaded row, then returns/events the original `$output` instance without refreshing it (`api/app/Modules/Production/Services/WorkOrderOutputService.php:247-284`, `markProductionReceiptManual()` at `362-376`).

**Impact:** the API response and SPA success path can report `not_started` even though the durable row is `manual_required`, hiding an operator action.  
**Classification:** Broken.

#### M051-B05 — Material-lot capture is not tied to the reserved/issued stock

`captureMaterialLotReferences()` selects the latest GRN item with a lot number for each material, regardless of reservation, location, or the actual issue (`api/app/Modules/Production/Services/WorkOrderService.php:283-310`). It records the BOM quantity as `quantity_used`. The inventory input contract has no lot field (`api/app/Modules/Inventory/Models/StockMovementInput.php:14-28`), while the issue service supports optional lot stamping (`api/app/Modules/Inventory/Services/MaterialIssueService.php:125-131`).

**Impact:** the work order can claim a lot and quantity that were never consumed, breaking the supplier-lot → issue → batch trace chain.  
**Classification:** Broken.

#### M051-B06 — Reserved-material issue silently becomes a no-op when reservations are absent

`issueReservedMaterials()` only loads rows in `ReservationStatus::Reserved`; when none exist, it creates no `MaterialIssue` stock movement and still allows the work order start path to continue (`api/app/Modules/Production/Services/WorkOrderService.php:914-960`). The historical system audit already identified this best-effort behavior as a production traceability risk (`docs/SYSTEM-AUDIT-FINDINGS-2026-08-13.md:355-370`).

**Impact:** production can start without a durable material issue even though the standard work-order path represents material consumption.  
**Classification:** Broken.

#### M051-B07 — Operation output can exceed the planned operation quantity

`WoOperationService::recordOutput()` accumulates completed and scrapped quantities with no check against `qty_planned` (`api/app/Modules/Production/Services/WoOperationService.php:197-224`). The controller only validates positive quantity and `scrap <= qty` (`api/app/Modules/Production/Controllers/WoOperationController.php:154-170`).

**Impact:** operation-level progress can exceed the work-order routing plan and feed invalid completion reporting.  
**Classification:** Broken.

#### M051-B08 — Defect rows are accepted when reject quantity is zero

`RecordOutputRequest` requires defects only when `reject_qty > 0` (`api/app/Modules/Production/Requests/RecordOutputRequest.php:25-55`), and the service checks the defect sum only inside the same condition (`WorkOrderOutputService.php:86-115`). Positive defect rows can therefore be persisted with zero rejected quantity.

**Impact:** defect totals and output totals can disagree, weakening QC and yield reporting.  
**Classification:** Broken.

#### M051-B09 — Skipping an operation bypasses the promised operation transition log

`WoOperationService` documents that every transition is logged, but `skipOperation()` updates status and notes without calling `log()` (`api/app/Modules/Production/Services/WoOperationService.php:20-25,266-274`).

**Impact:** a material routing decision has no corresponding operation history or actor/reason record.  
**Classification:** Broken.

### Missing / incomplete

#### M051-M01 — Routing operations are not generated in the work-order creation flow

`WoOperationService::generateFromRouting()` exists and creates operations from an active routing (`api/app/Modules/Production/Services/WoOperationService.php:42-73`), but source inspection found no application caller from `WorkOrderService::createDraft()` or its controller. A work order can therefore be created without the operations that the execution UI and operation API expect.

**Classification:** Missing.

#### M051-I01 — Exception authorization is presence-checked, not independently authorized

The request accepts `exception_authorized_by` as input and the migration only requires a non-null value for non-standard classes (`api/app/Modules/Production/Requests/StoreWorkOrderRequest.php:33-46`, `api/database/migrations/2026_08_13_210000_add_production_material_plan_contract.php:9-22`). There is no rule that the named user has the required authority, nor a server-side actor/approval workflow.

**Classification:** Incomplete; requires a policy decision for approver role and separation of duties.

#### M051-I02 — API resources expose raw internal IDs in traceability fields

`WorkOrderResource` exposes raw `exception_authorized_by` and child `product_id`, while `WorkOrderOutputResource` returns `material_lineage` without transforming its embedded IDs (`api/app/Modules/Production/Resources/WorkOrderResource.php:18-57`, `WorkOrderOutputResource.php:16-46`). This conflicts with the project HashID convention (`docs/PATTERNS.md:442-458`).

**Classification:** Incomplete.

#### M051-I03 — Restore bypasses the work-order service and explicit audit path

`WorkOrderController::restore()` directly calls `$workOrder->restore()` (`api/app/Modules/Production/Controllers/WorkOrderController.php:137-140`) rather than using a transactional service operation with authorization and a lifecycle/audit event.

**Classification:** Incomplete.

#### M051-I04 — Work-order class and exception data are not represented in the SPA create contract

The API request supports `standard`, `service`, `non_stock`, and `prototype`, but `spa/src/pages/production/work-orders/create.tsx:32-43,87-98` only submits the base fields. The page's own copy describes manual/sample/R&D work without exposing the server-side material-plan classification or reason.

**Classification:** Incomplete.

#### M051-I05 — Output idempotency is not stable across manual retries

The output page creates the idempotency key inside the mutation function using the current timestamp and random suffix (`spa/src/pages/production/work-orders/record-output.tsx:90-101`). A retry of the same operator submission is consequently a new business event rather than a replay of the original request.

**Classification:** Incomplete.

#### M051-I06 — Operation commands do not enforce the parent work-order lifecycle

Setup, start, pause/resume, record-output, complete, and skip methods validate operation-local state but do not require the parent work order to be `in_progress` (`api/app/Modules/Production/Services/WoOperationService.php:80-274`). The operation routes also expose a direct `{operation}` resource without a nested parent context (`api/app/Modules/Production/routes.php:83-95`).

**Classification:** Incomplete.

#### M051-I07 — Operation execution is not exposed from the work-order detail page

The detail page only fetches and renders a read-only operations table (`spa/src/pages/production/work-orders/detail.tsx:482-539`), despite the routing API exposing mutation methods (`spa/src/api/production/routings.ts:24-44`). There are no setup/start/pause/record/complete/skip controls or contextual mutation feedback.

**Classification:** Incomplete.

#### M051-I08 — Production permissions and UI capabilities are not aligned

The registry lists `system_admin`, `production_manager`, and `ppc_head` for M051. `ppc_head` receives create/confirm and view permissions but not lifecycle or output-record permissions (`api/database/seeders/RolePermissionSeeder.php:530-583`). The expected planner-versus-executor boundary should be documented and covered by authorization tests, rather than inferred from the page behavior.

**Classification:** Incomplete; confirm intended role policy before changing grants.

### Polish

#### M051-P01 — Operation table needs responsive and state-aware presentation

The operation table is a direct wide table without an overflow wrapper or explicit loading/empty/error treatment (`spa/src/pages/production/work-orders/detail.tsx:501-538`). The page already uses opaque semantic warning styling elsewhere (`:268-270`), while the design system requires opaque surfaces (`docs/DESIGN-SYSTEM.md:15,100-102`).

**Classification:** Polish.

## Standards and policy checks

- API identifier convention: several child/lineage fields still use raw integer IDs; normalize them through the existing HashID resource pattern.
- Domain boundary: replace HTTP-specific lifecycle exceptions with a domain/application exception mapped at the HTTP boundary.
- Traceability: material issue, lot, and actor must be one durable chain; inferred “latest GRN lot” references are not sufficient evidence.
- Authorization: the exception approver must be independently authorized and the actor performing lifecycle/material mutations must be explicit.
- UI contract: create and execution pages need to represent the API's material-plan and operation state, including stable idempotency and mutation feedback.

## Session disposition

No production-code fixes were made. The module is **Plan Ready** because the majority of findings require coordinated API/domain/inventory/UI changes or an explicit policy decision. The action plan records dependencies, scope, and a re-audit gate.

---

# Re-audit — 2026-09-01 (M051, session 3)

**Module:** `manufacturing/production-work-orders` — CLAIMED cleanly (`audit/scripts/claim-module.sh` → `CLAIMED`).

## 0. Environment verification (done BEFORE any probe)

Four prior sessions across two modules wrote verification claims from runs that
never executed, because every container in the compose project had been stopped
and `SQLSTATE[08006] host "db" could not be resolved` looked like a broken test
bootstrap. This session verified the environment first:

```
$ docker compose ps
NAME          IMAGE                COMMAND                  SERVICE   STATUS
ogami-db      postgres:16-alpine   "docker-entrypoint.s…"   db        Up 40 minutes (healthy)
ogami-redis   redis:7-alpine       "docker-entrypoint.s…"   redis     Up 40 minutes

$ docker compose exec -T db psql -U ogami -d postgres -c "select 1;"
 ?column?
----------
        1
(1 row)
```

`db` and `redis` were both already running; neither was started, stopped or
restarted by this session (other sessions are sharing them).

### Real numeric baseline (before any change)

Own database, never the shared `ogami_test`:

```
$ docker compose exec -T db psql -U ogami -d postgres \
    -c "CREATE DATABASE ogami_test_wo2 OWNER ogami;"
CREATE DATABASE

$ docker compose run --rm -e DB_DATABASE=ogami_test_wo2 api \
    php artisan test tests/Feature/Production --no-coverage
  Tests:    67 passed (232 assertions)
  Duration: 43.05s
[exited with code 0]
```

**Baseline = 67 tests / 232 assertions / 43.05s / exit 0.** Assertions are
non-zero, so this is a real run and not the ZERO-assertion signature of a
poisoned environment. Note the prior session recorded 39 tests / 143 assertions;
the suite has grown since.

## 1. Disposition of the two leads this module was prioritised for

### Lead A — "no listener exists for `MoldShotLimitNearing` / `MoldShotLimitReached`"

Recorded second-hand from the 2026-08-30 aborted session and flagged as an
unverified hypothesis.

**Measured verdict: the grep claim is TRUE but the inference drawn from it is
FALSE. The 80% mold alert IS delivered. This is NOT a dead subsystem, and not
the 8D-SLA-ledger shape.**

Evidence:

```
$ grep -rn 'MoldShotLimit' api/app api/tests api/database
api/app/Common/Enums/AlertType.php:43:          case MoldShotLimit = 'mold_shot_limit';
api/app/Common/Enums/AlertType.php:77:          self::MoldShotLimit => 'Mold approaching limit',
api/app/Common/Services/OutboxEventCodec.php:125: MoldShotLimitNearing::class,
api/app/Common/Services/OutboxEventCodec.php:126: MoldShotLimitReached::class,
api/app/Common/Services/AlertEngineService.php:415: AlertType::MoldShotLimit,
api/app/Common/Services/AlertEngineService.php:713: AlertType::MoldShotLimit,
api/app/Modules/MRP/Events/MoldShotLimitReached.php:19
api/app/Modules/MRP/Events/MoldShotLimitNearing.php:20
api/app/Modules/MRP/Services/MoldService.php:135,138
```

There is indeed **no `Event::listen()` registration** for either class in
`AppServiceProvider::boot()`. But neither event was ever meant to have one:

1. Both `implement ShouldBroadcast`
   (`api/app/Modules/MRP/Events/MoldShotLimitNearing.php:20`,
   `.../MoldShotLimitReached.php:19`) and are recorded through
   `OutboxService::record()` at
   `api/app/Modules/MRP/Services/MoldService.php:135,138`. Their delivery
   channel is the WebSocket broadcast for the live production dashboard, and
   they are registered in the outbox codec allow-list at
   `api/app/Common/Services/OutboxEventCodec.php:125-126` — an unregistered
   event class would have been rejected by the codec, so this is a positive
   signal that the path is wired, not silent-by-omission.
2. The **operator-facing 80% alert is raised by a different mechanism**: the
   scheduled `AlertEngineService` production check at
   `api/app/Common/Services/AlertEngineService.php:395-424`, which polls
   `molds` and raises `AlertType::MoldShotLimit` (Warning) at
   `alerts.mold.warning_ratio` and `AlertType::MoldShotCritical` at
   `alerts.mold.critical_ratio`. That check has a missing-condition resolver
   (`:700-730`) that explicitly enumerates `MoldShotLimit` /
   `MoldShotCritical`, i.e. the engine clears alerts that no longer apply —
   more machinery than a dead path would have.

Both driving settings exist and are non-null **in the live database**, so
`SettingsService::requiredFloat()` inside the shot-increment transaction cannot
throw and roll back an output recording:

```
$ docker compose exec -T db psql -U ogami -d ogami \
    -c "select key, value from settings where key like 'alerts.mold%';"
            key             | value
----------------------------+-------
 alerts.mold.warning_ratio  | 0.8
 alerts.mold.critical_ratio | 0.95
```

Seeded in two places (`api/database/seeders/SettingsSeeder.php:149-150` and
migration `api/database/migrations/0290_seed_alert_approval_and_quality_policy_settings.php:17-18`).

**Conclusion: close the lead as NOT A DEFECT.** Recorded here so the next
reader does not re-derive it. The residual, genuinely open question is a
*design* one, not a dead-code one — see M051-R-Q1 below (crossing-edge
detection vs. polling produce different alert semantics after a shot reset).

**NOT YET MEASURED (incomplete):** I did not get to executing a runtime probe
that increments a real mold across the 80% edge and asserts an `alerts` row or
notification actually materialises. The finding above is established from code
plus live settings/DB reads, not from an end-to-end delivery observation. The
next session should still run that probe; the classification is very unlikely to
change, but "delivered" is currently an inference from the poll query, not an
observation.

### Lead B — IC-16, handed over from the quality session: "the in-process QC gate does not exist"

**Measured verdict: the grep reproduces exactly, but the conclusion is WRONG.
The WO-level in-process QC gate EXISTS and is wired. A *different*, narrower
in-process gate — the per-operation one CLAUDE.md actually describes — is the
real gap.**

The grep reproduces:

```
$ grep -rn "InProcess\|in_process" api/app/Modules/Production/
(no output; exit 1)
```

But the gate is not supposed to live in Production. In this modular monolith
Production *dispatches* and Quality *listens* — the dependency points the right
way, so a grep scoped to Production can never see it:

```
$ grep -rln "InProcess" api/app
api/app/Modules/Dashboard/Services/QualityDashboardService.php
api/app/Modules/Quality/Controllers/InspectionController.php
api/app/Modules/Quality/Enums/InspectionStage.php
api/app/Modules/Quality/Listeners/TriggerInProcessQC.php     ← the gate
api/app/Providers/AppServiceProvider.php                     ← the registration
```

- `App\Modules\Quality\Listeners\TriggerInProcessQC` is registered explicitly at
  `api/app/Providers/AppServiceProvider.php:330`:
  `Event::listen(WorkOrderStatusChanged::class, [TriggerInProcessQC::class, 'handle']);`
- It is dispatched from this module: `WorkOrderService::recordStatusChange()`
  stages `WorkOrderStatusChanged` through `OutboxService::recordForChain()` at
  `api/app/Modules/Production/Services/WorkOrderService.php:732-738`, inside the
  owning lifecycle transaction, so a rolled-back start cannot emit it.
- On `in_progress` the listener creates an `in_process` `Inspection` bound to
  the WO via `InspectionService::create()`, re-reading and locking the
  authoritative WO row first, idempotent on
  `(stage, entity_type, entity_id)`, and refuses rather than inventing data when
  `product_id`, `quantity_target` or the creator is missing. It notifies via the
  single entry point `NotificationService::send($recipients, 'chain.in_process_qc_required', …)`
  scoped by the `quality.in_process_qc.notification_roles` setting.
- Note its failure path is *correctly* not swallowed: the outer
  `catch (\Throwable)` logs **and rethrows** for queue retry, unlike the pattern
  that made the 8D SLA ledger invisible.

**Correct classification: `Incomplete`, in two specific and separable ways.**

**(a) The WO-level in-process inspection is CREATED but GATES NOTHING.** Incoming
QC gates GRN acceptance and outgoing QC gates delivery, but
`WorkOrderService::complete()`
(`api/app/Modules/Production/Services/WorkOrderService.php:467-516`) reads no
inspection state whatsoever. A work order can be completed — and therefore
release finished goods downstream — while its auto-created in-process inspection
is still `pending`, or has **failed**. So the touchpoint exists as a record and
not as a gate. Whether it *should* block completion is a policy question, not
something to guess: see M051-R-Q2.

**(b) The per-operation gate CLAUDE.md describes — "periodic sampling between
operations" — is a `Log::info` stub, with the TODO still in the docblock.**
`WoOperationService::completeOperation()` at
`api/app/Modules/Production/Services/WoOperationService.php:243-266`:

```php
// QC trigger — routing operation may require quality check after completion.
if ($locked->routingOperation && $locked->routingOperation->qc_required) {
    Log::info('WoOperation QC trigger: operation requires quality check', [...]);
}
```

and its own docblock states: *"If the routing operation has qc_required = true, a
QC trigger is logged (actual event integration comes in a later task)."*
So `routing_operations.qc_required` is a configurable flag whose entire effect is
a log line: no inspection is created, nothing is notified, nothing is gated, and
the operation completes identically whether the flag is true or false. **This —
not the WO-level listener — is the genuine IC-16 gap.** Classification:
**Missing** (never built; explicitly deferred in-code).

Per the brief, whether to *build* a sampling regime is a scope question for a
human. Reported precisely; nothing designed or built here. See M051-R-Q2.

## 2. Findings

Classifications follow the audit vocabulary. Every claim below is labelled
**[measured]** (executed against the live PostgreSQL / a real command) or
**[code-read]** (established by reading source at the cited line, not yet
executed). This session was cut short before the probe suite ran, so the
`[code-read]` items are genuine findings with exact citations but *unexecuted*;
they are the next session's first job.

### Broken

#### M051-R-B01 — `POST /production/operations/{operation}/output` accepts `numeric`, and large or high-precision quantities 500

`api/app/Modules/Production/Controllers/WoOperationController.php:151-156`:

```php
'qty'   => ['required', 'numeric', 'min:0.0001'],
'scrap' => ['nullable', 'numeric', 'min:0', 'lte:qty'],
```

This is the exact sibling-module defect class the brief flagged (`numeric` on a
quantity; `1e3` reaching `bcadd`/`Money`; `1e17`/`1e20` reaching the column).
Three distinct failures follow, all reachable from one endpoint:

1. **Silent precision loss.** `wo_operations.qty_completed` is
   `numeric(15,4)` **[measured]**:
   ```
   $ docker compose exec -T db psql -U ogami -d ogami -c "select column_name,data_type,numeric_precision,numeric_scale from information_schema.columns where table_name='wo_operations';"
    qty_planned   | numeric | 15 | 4
    qty_completed | numeric | 15 | 4
    qty_scrapped  | numeric | 15 | 4
   ```
   `numeric` accepts `1.99995`; the service accumulates with
   `bcadd((string) $locked->qty_completed, (string) $qty, 4)`
   (`WoOperationService.php:214-217`) and the column stores 4 dp, so a recorded
   production quantity of `1.99995` becomes `2.0000`. Same shape as the quality
   session's `10.00005` → `10.0001`. **[code-read]**
2. **`ValueError` → 500 on scientific notation.** `numeric` passes `is_numeric('1e15')`,
   the controller casts `(float)`, and the service interpolates it back to a
   string for `bcadd`. PHP's default `precision=14` renders floats ≥ 1e15 in
   exponential form (`(string)1.0E+15 === '1.0E+15'`), and bcmath rejects
   exponential input — `bcadd(): Argument #2 ($num2) is not well-formed`. The
   controller catches only `BusinessRuleException`
   (`WoOperationController.php:158-161`), so this is an uncaught 500.
   **[code-read — the 500 was NOT executed; this is the highest-value unrun probe]**
3. **`22003` → 500 on column overflow.** `qty` of `1e12` is well-formed for
   bcmath but exceeds `numeric(15,4)` (max ≈ 1e11), producing
   `SQLSTATE[22003] numeric field overflow` from a `QueryException` that the
   controller does not catch. **[code-read]**

Contrast `RecordOutputRequest` (`api/app/Modules/Production/Requests/RecordOutputRequest.php:28-34`),
which correctly uses `integer|min:0` for `good_count`/`reject_count` — so the
*primary* output path is not exposed to this class of defect. The operation-level
path is the outlier. Piece counts should be `integer`, and the accumulator should
reject anything that would overflow the column.

#### M051-R-B02 — `WoOperationService::recordOutput()` is a second, unreconciled output path that bypasses `WorkOrderOutputService::record()`

`api/app/Modules/Production/Services/WoOperationService.php:202-231`. The brief
states plainly that **any other output path must go through**
`WorkOrderOutputService::record()`. This one does not. Verified by grep
**[measured]**:

```
$ grep -n "recordOutput\|WorkOrderOutputService\|good_count\|reject_count\|incrementShots" \
    api/app/Modules/Production/Services/WoOperationService.php
202:    public function recordOutput(WoOperation $op, float $qty, float $scrap = 0, ?string $scrapReason = null): void
```

That is the *only* hit in the whole file: no reference to
`WorkOrderOutputService`, no `incrementShots`, no `good_count`/`reject_count`.
Consequences, each a contract the canonical path guarantees and this one drops
**[code-read]**:

| guarantee in `WorkOrderOutputService::record()` | operation path |
|---|---|
| `work_order_outputs` row created (`:196-218`) | none — writes only `wo_operations.qty_completed/qty_scrapped` |
| mold shot count incremented (`:244-249`) | **never incremented** |
| WO `quantity_produced/good/rejected` + `scrap_rate` updated (`:230-238`) | not touched |
| FG stock receipt / `ProductionReceipt` movement (`:251-273`) | none |
| `WorkOrderOutputRecorded` outbox event (`:276-278`) | none |
| defect rows required to reconcile with reject count (`:104-113`) | `scrap` needs no defect rows at all |
| idempotency key + DB unique constraint | none |

So `wo_operations.qty_completed` and `work_orders.quantity_produced` are two
independent counters that can disagree without limit, and scrap recorded per
operation is invisible to the WO scrap rate, to OEE quality, and to Pareto
defect analysis. Shots taken on operation-recorded production never age the
mold, which directly undercuts the 80%-of-max alert that Lead A confirmed works.

Related, same method: there is **no ceiling against `qty_planned`**, so an
operation can complete an unbounded multiple of the quantity it was planned for.
The prior session recorded this as B07 and deferred it pending a production
policy on overrun tolerance; that deferral still stands and is reasonable — the
*reconciliation* defect above is separable from the *tolerance* policy.

#### M051-R-B03 — `skipOperation()` can skip an already-completed operation, destroying its completion record

`api/app/Modules/Production/Services/WoOperationService.php:277-291`. Every
other operation command guards its source state with `assertStatus()` — verified
by enumerating the calls **[measured]**:

```
$ grep -n "assertStatus\|assertPreviousCompleted" api/app/Modules/Production/Services/WoOperationService.php
 82,88   startSetup      → [Pending]
108,112  endSetup        → [Setup]
133,138  startOperation  → [Pending, Setup]  (+ assertPreviousCompleted 134,140)
159,163  pauseOperation  → [InProgress]
181,185  resumeOperation → [Paused]
204,211  recordOutput    → [InProgress]
243,247  completeOperation → [InProgress]
315      private function assertStatus(...)
```

`skipOperation` is the single command absent from that list. Its docblock says
"Can be called from any status," so *some* permissiveness is intended (skipping
a pending or setup operation is legitimate), but "any" currently includes
`Completed`: the row is overwritten to `WoOperationStatus::Skipped` and its
`notes` replaced with the skip reason, while `actual_end`, `qty_completed` and
`qty_scrapped` survive as orphans describing an operation the system now claims
was never run. It also swallows a `Skipped → Skipped` no-op into a second
`ProductionLog` row. The parent-state guard (`assertParentInProgress`) is
present, so the blast radius is limited to an in-progress WO. **[code-read]**

### Missing

#### M051-R-M01 — Per-operation in-process QC (`routing_operations.qc_required`) is a log statement

See §1 Lead B(b). `api/app/Modules/Production/Services/WoOperationService.php:243-266`.
A configurable IATF quality flag whose only effect is `Log::info`. Classification
**Missing**; build/no-build is a human scope call (M051-R-Q2).

#### M051-R-M02 — `document_sequences` has no `work_order` or `production_batch` row, exposing WO numbering to the known `DocumentSequenceService` create race

**[measured]** — the live table contains neither document type:

```
$ docker compose exec -T db psql -U ogami -d ogami -c "select * from document_sequences limit 20;"
 id |  document_type  | prefix | year | month | last_number
  1 | maintenance_wo  | MWO    | 2026 |     8 |           4
  2 | clearance       | CLR    | 2026 |     8 |           3
  3 | credit_note     | CN     | 2026 |     8 |           4
  4 | journal_entry   | JE     | 2026 |     8 |          12
  5 | stock_count     | SC     | 2026 |     8 |           1
  6 | invoice         | INV    | 2026 |     8 |           1
  7 | job_posting     | JP     | 2026 |     8 |           1
  8 | job_application | JA     | 2026 |     8 |           4
  9 | contact_inquiry | INQ    | 2026 |     8 |           1
(9 rows)
```

**[code-read]** `DocumentSequenceService::generate()` at
`api/app/Common/Services/DocumentSequenceService.php:60-80` still carries the
unguarded lock-or-create the brief describes (it raced 4 of 8 concurrent callers
into a `23505` 500 when no sequence row existed): the `lockForUpdate()->first()`
locks nothing when the row is absent, so two concurrent transactions both fall
through to `insert()` and one violates
`document_sequences_document_type_year_month_unique`.

This bites work orders specifically because `wo_number` is generated inside
`WorkOrderService::createDraft()`'s transaction
(`api/app/Modules/Production/Services/WorkOrderService.php:148`) and
`production_batch` inside `start()` (`:347`) — so the **first work order of every
calendar month** and the **first start of every calendar month** are each exposed.

**Per the brief this is shared-service scope: reported, NOT fixed.** The
containable Production-side mitigation would be seeding the two rows, but that
only narrows the window rather than closing it, and the fix belongs in `Common`.

#### M051-R-M03 — No database-level immutability for recorded production output

**[measured]** — `work_order_outputs` has **no `deleted_at`** (so no
`SoftDeletes`) and there are **zero triggers** on any `work_order*` table:

```
$ docker compose exec -T db psql -U ogami -d ogami -c "select event_object_table, trigger_name from information_schema.triggers where event_object_table like 'work_order%';"
 event_object_table | trigger_name
--------------------+--------------
(0 rows)
```

This is the same shape the quality session found for completed inspections. It
is **materially less severe here**, and the reason is worth recording so it is
not over-escalated: there is **no API surface that mutates or deletes an
output**. The complete route list for outputs is
`api/app/Modules/Production/routes.php:63-65` — `GET .../outputs`,
`POST .../outputs`, `POST .../outputs/{output}/retry-receipt`. No `PUT`, no
`PATCH`, no `DELETE`. So output is immutable *by absence of an endpoint*, not by
an enforced invariant. **[measured — route table read directly]**

The gap is that the invariant is unenforced at the model and storage layers:
`good_count`, `reject_count` and `batch_code` are all mass-assignable
(`api/app/Modules/Production/Models/WorkOrderOutput.php:23-29`), so any future
service, job, console command or seeder can rewrite a recorded production
quantity with no `P0001` trigger to stop it — the precedent being
`journal-ledger`'s observer + PostgreSQL trigger. Classification **Missing**
(defence-in-depth absent), not Broken (not currently reachable).

One positive to record: DB-level idempotency for output **is** enforced
**[measured]** — `work_order_outputs_work_order_idempotency_unique UNIQUE (work_order_id, idempotency_key)`,
so the replay guard is not application-only.

### Incomplete

#### M051-R-I01 — `complete()` accepts a work order with zero output

`api/app/Modules/Production/Services/WorkOrderService.php:467-516`. The only
precondition is the state-machine edge `in_progress → completed`; there is no
check on `quantity_produced`. A WO that produced nothing can be declared
Completed, which fires `WorkOrderCompleted` onto the `o2c` chain
(`:504-510`) and — via `AppServiceProvider.php:331` — triggers outgoing QC for a
batch that does not exist. The same absence means completion short of
`quantity_target` is permitted, which is likely *correct* (partial completion is
real manufacturing) and should not be conflated with the zero case.
**[code-read]** See M051-R-Q3.

`complete()` handles its own divide-by-zero correctly:
`$scrap = $produced > 0 ? round(($rejected / $produced) * 100, 2) : 0.0` (`:482`).

#### M051-R-I02 — `complete()` does not verify that consumed material was ever issued

Same method. `start()` issues reserved material best-effort by design —
`issueReservedMaterials()` (`:938-985`) iterates only `Reserved` reservations and
the docblock at `:355-357` states that a WO with no reservation "still starts —
material_issue rows just won't be created." Nothing later reconciles it:
`complete()` never compares `work_order_materials.actual_quantity_issued`
against `bom_quantity` for the produced quantity, so a WO can be completed
having produced goods from material that was never issued, leaving inventory
overstated with no variance visible at the gate. `variance` and `cost_variance`
columns are maintained per-issue (`:977-982`) but never asserted on.
**[code-read]** Cross-module contract with `material-issues-reservations`;
per the brief, reported and not fixed. The prior session's B05/B06 deferral
covers the reservation→issue→lot lineage half of this.

#### M051-R-I03 — `cancel()` does not account for material already issued

`api/app/Modules/Production/Services/WorkOrderService.php:539-582` calls
`releaseReservedMaterials()`, which by construction touches only reservations
still in `Reserved` (`:992-1007`). The state machine permits
`paused → cancelled` (`api/app/Modules/Production/Support/WorkOrderStateMachine.php:19`),
and a paused WO has necessarily passed through `start()` and therefore had its
material issued. Cancelling it produces no scrap movement, no return-to-store
movement, and no variance record — the material is simply consumed by a work
order that officially produced nothing. **[code-read]** Cross-module; reported
only.

#### M051-R-I04 — OEE availability derives from an assumed constant, not from recorded shift data

`api/app/Modules/Production/Services/OeeService.php:121-133`:

```php
private function scheduledMinutes(Machine $machine, Carbon $from, Carbon $to): int
{
    ... if (! $cursor->isWeekend()) { $workingDays++; } ...
    return (int) round((float) $machine->available_hours_per_day * $workingDays * 60);
}
```

The brief asks specifically whether availability/performance/quality each derive
from recorded data. **Performance and quality do; availability does not.**
Scheduled time is a static machine attribute times a naive weekday count,
ignoring the actual shift assignments and the holidays calendar this system
maintains (`/hr/attendance/shifts`, `/hr/attendance/holidays`). Two consequences:
a genuine Saturday or Sunday run yields `scheduledMinutes = 0` →
`availableTime = 0` → availability `null` → **OEE null despite real recorded
output**; and a public holiday counts as a full working day, depressing
availability. **[code-read]**

Divide-by-zero on an idle machine is **correctly handled** — every metric is
null-guarded rather than divided (`:86-94`): `availability` requires
`availableTime > 0`, `performance` requires `runTime > 0 && total > 0 && idealCycle > 0`,
`quality` requires `total > 0`, and `oee` requires all three non-null. This is
the opposite of the AR `credit_limit = 0.00` `DivisionByZeroError`. The
aggregation paths are equally careful: `report()`'s `$overallAvg` returns null
rather than 0 when nothing is measured (`:203-209`), with a comment saying
exactly why. **[code-read]** Worth crediting; the unrun probe would confirm no
exception is raised.

#### M051-R-I05 — Downtime is attributed wholly to its start day, so a shift-spanning stoppage is misattributed (and can clamp availability to 0)

`OeeService::calculate()` filters downtime with
`whereBetween('start_time', [$from, $to])` and sums the **full**
`duration_minutes` (`:47-64`). A downtime is therefore charged entirely to the
window containing its start: one beginning 23:00 and lasting 300 minutes puts
all 300 minutes on the first day, and none on the second. In `report()`'s daily
trend loop (`:229-243`), which calls `calculate()` once per day, this
systematically over-charges the starting day and under-charges the next.

It is **not double-counted** — each row is attributed to exactly one window by
`start_time`, so the brief's "downtime spanning two shifts is double-counted"
does not reproduce; the defect is misattribution, not duplication. The
consequential harm is the clamp at `:66-67`:
`$runTime = max(0, $availableTime - $unplanned)`. Once over-charged unplanned
downtime exceeds available time, `runTime` becomes 0 and availability is
reported as a hard **0.0** — a data-attribution artifact presented as a genuine
0% availability, rather than as absent evidence. **[code-read]**

#### M051-R-I06 — A raw integer primary key leaks in a 422 error body

`api/app/Modules/Production/Services/WorkOrderService.php:880-883`:

```php
throw new BusinessRuleException(
    "Insufficient stock for item {$material->item_id} (work order {$wo->wo_number}): "
    . "needed {$needed}."
);
```

`$material->item_id` is the raw `items` primary key. The path to the client is
direct and unfiltered: `confirm()` → `reserveMaterialsFor()` → throw →
`WorkOrderController::confirm()`'s
`catch (BusinessRuleException $e) { return response()->json(['message' => $e->getMessage()], 422); }`
(`api/app/Modules/Production/Controllers/WorkOrderController.php:172-174`).

This is the enumeration-oracle class the brief flags (five modules shipped
`{"id":<pk>,…}`). It is also **the most contained fix in this report** — the
message should carry `$material->item->code` (already loaded via the `materials.item`
eager load in `show()`) or the item's `hash_id`, changing no behaviour and no
recorded value. **[code-read]**

#### M051-R-I07 — `status` and the recorded quantity columns remain mass-assignable on `WorkOrder`

`api/app/Modules/Production/Models/WorkOrder.php:33-42` keeps `status`,
`quantity_produced`, `quantity_good`, `quantity_rejected` and `scrap_rate` in
`$fillable`. The repo's stated hardening convention removed `status` from
`$fillable` on Loan, LeaveRequest, PR, PO, PayrollPeriod, NCR and others
precisely so the state machine cannot be bypassed by mass assignment;
`WorkOrder` was not converted, and `WorkOrderService` still writes status via
`$lockedWo->update(['status' => …])` throughout.

**[measured]** that this is live behaviour, not theory: the existing test
`api/tests/Feature/Production/WorkOrderOutputIdempotencyKeyTest.php:59-71`
constructs a WO with `WorkOrder::create([... 'status' => WorkOrderStatus::InProgress->value ...])`
and the baseline suite passes, so mass assignment of `status` demonstrably
succeeds today.

No current API path reaches it — `StoreWorkOrderRequest`
(`api/app/Modules/Production/Requests/StoreWorkOrderRequest.php:35-47`) does not
accept `status` or any quantity, and `createDraft()` forces
`WorkOrderStatus::Planned`. So this is a hardening gap, not an exploitable bug.
Converting it is a behaviour-preserving change on the service side but **would
break existing fixtures** that mass-assign `status` (at minimum the file above),
which is the judgement call the next session must make explicitly rather than
discover.

### Polish

#### M051-R-P01 — `report()`'s daily trend is O(days × machines) service calls

`api/app/Modules/Production/Services/OeeService.php:229-243` calls
`calculate()` once per machine per day, and each call issues two downtime
aggregates plus an outputs query with `with('workOrder.mold')`. Over the 92-day
cap (`:221`) with N machines that is ~4 × 92 × N queries for one page load. The
cap bounds it, so this is Polish, not Broken. **[code-read]**

## 3. Invariants — execution status

The brief asks for a table of work-order invariants **actually executed**. This
session was cut short before its probe suite ran, so honesty requires separating
the two groups. **Nothing below is claimed as executed unless marked so.**

### Executed this session (measured)

| # | Invariant / probe | Probe | Result |
|---|---|---|---|
| 1 | Environment is real, not a phantom | `docker compose ps`; `psql -c "select 1;"` | db + redis Up (healthy); 1 row |
| 2 | Baseline suite is a real run | `php artisan test tests/Feature/Production` on `ogami_test_wo2` | **67 passed / 232 assertions / 43.05s / exit 0** — non-zero assertions |
| 3 | `MoldShotLimit*` listener registration | `grep -rn 'MoldShotLimit' api/app api/tests api/database`; read `AppServiceProvider::boot()` | No `Event::listen`; both events are `ShouldBroadcast` + outbox-codec-registered; alert raised by `AlertEngineService` poll. **Lead closed as not-a-defect** |
| 4 | 80% threshold settings exist (so the shot-increment transaction cannot throw) | `select key,value from settings where key like 'alerts.mold%'` | `warning_ratio=0.8`, `critical_ratio=0.95` — present, non-null |
| 5 | IC-16 grep reproduces | `grep -rn "InProcess\|in_process" api/app/Modules/Production/` | exit 1, no output — reproduces exactly |
| 6 | …but the gate exists elsewhere | `grep -rln "InProcess" api/app`; read `AppServiceProvider.php:330` | `Quality/Listeners/TriggerInProcessQC` registered on `WorkOrderStatusChanged`. **Quality session's conclusion refuted** |
| 7 | Output-mutation API surface | Full read of `api/app/Modules/Production/routes.php` | Only GET / POST / POST-retry on outputs. **No update or delete endpoint exists** |
| 8 | DB-level output idempotency | `\d work_order_outputs` | `UNIQUE (work_order_id, idempotency_key)` present |
| 9 | DB-level output immutability | `information_schema.triggers where event_object_table like 'work_order%'` | **0 rows**; no `deleted_at` column → no SoftDeletes |
| 10 | Restore route binds `withTrashed()` | Read `routes.php:53` + `WorkOrderService::restore()` | Route has `->withTrashed()`; service uses `WorkOrder::withTrashed()->lockForUpdate()->findOrFail()` and refuses a non-trashed target. **Holds** — not the 404-for-every-target defect three modules shipped |
| 11 | Quantity column precision | `information_schema.columns` on `work_orders`, `wo_operations` | `work_orders.quantity_*` `numeric(10,0)`, `scrap_rate` `numeric(5,2)`; `wo_operations.qty_*` `numeric(15,4)` |
| 12 | `work_order` sequence row exists | `select * from document_sequences` | **Absent** (also `production_batch`) → first-of-month create race is live |
| 13 | Every operation command guards source state | `grep -n "assertStatus" WoOperationService.php` | 7 of 8 commands guard; **`skipOperation` does not** |
| 14 | Operation output path reuses the canonical service | `grep -n "WorkOrderOutputService\|incrementShots\|good_count" WoOperationService.php` | Single hit (its own signature). **Confirmed bypass** |
| 15 | `status` is mass-assignable on `WorkOrder` | Baseline suite passes with `WorkOrder::create(['status'=>…])` at `WorkOrderOutputIdempotencyKeyTest.php:59-71` | Mass assignment succeeds |

### NOT executed — next session's first job

Each is specified below with the probe to run, so no rediscovery is needed. The
`[code-read]` analysis above predicts the outcome; none of it is verified.

| Invariant | Probe to run | Predicted (unverified) |
|---|---|---|
| Output beyond ordered quantity | `record()` with `good+reject > quantity_target - quantity_produced` | Refused — `WorkOrderOutputService.php:188-190` |
| Negative output | service-level `good_count: -5, reject_count: 10`, bypassing the FormRequest | **Suspected hole**: `record()` checks only `total <= 0` (`:82`), so `-5 + 10 = 5` passes and `quantity_good` is *decremented*. HTTP path is protected by `integer|min:0` (`RecordOutputRequest.php:28`) |
| Zero output | `good_count: 0, reject_count: 0` | Refused at `:82` and at `RecordOutputRequest.php:44-46` |
| Output on cancelled / draft / completed WO | `record()` on each status | Refused — `:184-186` re-reads the locked authoritative WO, not the route-bound model |
| Duplicate-output idempotency (shots / scrap / events not doubled) | `record()` twice with one `X-Idempotency-Key`; assert `molds.current_shot_count`, `work_orders.scrap_rate` and outbox event count unchanged | Holds — durable key checked under the WO row lock at `:167-181`, before the status guard; DB unique constraint confirmed (row 8 above). **Existing baseline tests cover the row-count half but NOT the shot/scrap/event half** |
| good + reject exceeding what was started | as above vs `quantity_target` | Refused at `:188` |
| Reject without defect rows | `reject_count: 5, defects: []` | Refused twice — `:104-106` and `RecordOutputRequest.php:48-52` |
| Mold shot increments exactly once per output | `record()` once with good=3, reject=2; assert `current_shot_count` +5 | Once, by `good+reject`, under `Mold::lockForUpdate()` inside the WO transaction (`:244-249`) |
| 80% alert actually delivered | increment a real mold across the edge; assert an `alerts` row / notification materialises | Delivered via `AlertEngineService` poll — see §1 Lead A. **Inferred, not observed** |
| Behaviour at and past 100% of mold life | increment past `max_shots_before_maintenance`, then `record()` again | `MoldService::incrementShots()` flips status to `Maintenance` + writes `MoldHistory`. **Open question:** `start()` accepts only `Available`/`InUse` molds (`WorkOrderService.php:335-337`), but a mold flipped to `Maintenance` *mid-run* is never re-checked, so an already-started WO appears able to keep recording output on an over-life mold. Probe this — it is the substantive mold question, and it is NOT per-shot depreciation (correctly out of scope) |
| Material reconciled against BOM | complete a WO whose `actual_quantity_issued` is 0 | Permitted — M051-R-I02 |
| WO cannot complete with unissued material | as above | Not enforced — M051-R-I02 |
| Illegal status transitions, each named | for each of the 7 statuses, attempt every non-listed target | `WorkOrderStateMachine::TRANSITIONS` (`WorkOrderStateMachine.php:15-23`) permits only planned→{confirmed,cancelled}, confirmed→{in_progress,cancelled}, in_progress→{paused,completed}, paused→{in_progress,cancelled}, completed→{closed}; closed and cancelled are terminal. So **cancel-a-completed-WO, re-open-a-closed-WO, complete-twice and cancel-an-in-progress-WO are all refused with 409** (`WorkOrderController.php:243-247`). Both service entry and post-lock re-check call `assertTransition` (e.g. `:469`, `:477`), so a stale model cannot slip through |
| Complete with zero output | `complete()` on an in-progress WO with `quantity_produced = 0` | **Permitted** — M051-R-I01 |
| Closed-period refusal | `record()` with the FG receipt hitting a closed accounting period | Surfaces as 422 — `WorkOrderController.php:307` catches `ClosedPeriodException` explicitly, with a comment on why. Existing baseline test `WorkOrderOutputFgReceiptTest::a_closed_period_in_the_receipt_handoff_is_not_absorbed_by_the_degra…` **passes**, so this one is effectively covered |
| OEE divide-by-zero on an idle machine | `calculate()` on a machine with no downtime and no output | Nulls, no exception — `OeeService.php:86-94` |
| Downtime spanning two shifts not double-counted | one downtime crossing midnight; compare per-day trend sums | Not doubled, but **misattributed** — M051-R-I05 |
| Activity feed present and permission-scoped | locate the WO activity-feed endpoint; call it as a role lacking WO view | `ActivityFeedService::record()` is called on restore (`WorkOrderService.php:611-619`); **the read endpoint and its gate were not located this session** — delegated sweep did not return before cutoff |
| Concurrent output on one WO | two PDO connections recording simultaneously | Serialised — both take `WorkOrder::lockForUpdate()` at `:160` before any write. Use two real connections, not `RefreshDatabase` (which hides uncommitted rows and reports a phantom "no lock") |
| `WO-YYYYMM-NNNN` uniqueness under concurrency | two concurrent `createDraft()` in a month with no sequence row | **Predicted 23505 → 500** for one caller — M051-R-M02. Note the brief's warning: this probe can deadlock on an uncommitted unique insert; say so rather than inventing a result |
| Quantity FormRequest vs `1.999` / `1e3` / `1e17` / `10.00005` | POST both output endpoints with each value | `/work-orders/{wo}/outputs` **safe** (`integer`); `/operations/{op}/output` **unsafe** (`numeric`) — M051-R-B01 |
| Completed output immutable (update / soft-delete / force-delete) | attempt each | No endpoint exists (row 7); no trigger or SoftDeletes (row 9) — M051-R-M03 |
| Permission gate per endpoint, incl. list/options | call all 27 routes as a role holding none | Every route carries `permission:` middleware — read directly from `routes.php:19-98`, all 27 gated. **Runtime 403s not executed**; the seeder matrix sweep did not return before cutoff |
| Every registry role can complete its part | cross the seeder matrix against the route gates | **Not established.** Prior session's I08 flagged `ppc_head` as holding create/confirm + view but not lifecycle or record (`RolePermissionSeeder.php:530-583`) — unverified this session |
| No role can rubber-stamp its own output | check whether one role holds both `production.wo.record` and `production.work_orders.lifecycle` | **Not established** — same delegated sweep |
| Raw-id-free error bodies | trigger the insufficient-stock 422 and inspect the body | **Leak predicted and located** — M051-R-I06. Note `assertStringNotContainsString` on a path is unsound (`json_encode` escapes `/`), but this is a bare integer so a numeric assertion is fine |

No test was written this session, so there is nothing to label as a
pass-either-way regression lock, and no claim that any test was confirmed to
fail against unmodified source.

## 4. Questions requiring a human decision

- **M051-R-Q1 — Mold alert semantics: crossing-edge event vs. polled alert.**
  `MoldService::incrementShots()` fires `MoldShotLimitNearing` only on the
  *transition* across 80% (`$beforePct < $warningRatio && $row->shot_percentage >= $warningRatio`,
  `api/app/Modules/MRP/Services/MoldService.php:133-136`), whereas
  `AlertEngineService` re-raises on *every* poll while the mold sits above the
  ratio. After `resetShotCount()` sets the count back to 0 the edge can be
  crossed again, but a mold that is *already* above 80% when the setting is
  lowered never crosses. Is the polled alert intended as the authoritative
  operator notification and the broadcast purely for live dashboard animation?
  If so this is fine and should be documented; if the event is meant to be
  authoritative, the edge condition is too narrow. MRP-owned either way.
- **M051-R-Q2 — Should in-process QC *gate* anything?** Two sub-decisions:
  (a) should `WorkOrderService::complete()` refuse while the auto-created
  in-process inspection is pending or failed; (b) should
  `routing_operations.qc_required` create a real inspection and block
  `completeOperation()` until it passes. CLAUDE.md names in-process QC as one of
  four IATF touchpoints and describes "periodic sampling between operations", so
  (b) is in scope by the letter of the spec — but designing a sampling regime is
  explicitly not mine to invent. Needs a decision before either is built.
- **M051-R-Q3 — Is completing a work order with zero output ever legitimate?**
  If a run is abandoned the correct terminal state looks like `cancelled`, not
  `completed` — but `in_progress → cancelled` is *not* a legal edge
  (`WorkOrderStateMachine.php:18`), so an operator with a failed run must pause
  first and then cancel. Is that the intended path? If yes, `complete()` should
  refuse zero output. If no, the state machine needs the direct edge.
- **M051-R-Q4 — Material consumed by a cancelled work order.** What should
  happen to material already issued when a paused WO is cancelled — scrap
  movement, return-to-store, or accept the loss with a variance record?
  Cross-module with Inventory; blocks M051-R-I03.
- **M051-R-Q5 — Operation overrun tolerance.** Still open from the prior
  session's B07. How much may `qty_completed` exceed `qty_planned` before it is
  refused? Blocks the ceiling half of M051-R-B02.

## 5. Session disposition

Interrupted by the parent process exiting (not an error in this session, and not
quota). The module lock was still held and **zero commits had landed**, so this
write-up was produced first, before any further probing, specifically so the
measurements above survive — three earlier agents in this pipeline lost
everything by deferring their write-up.

**No production code was changed. No test was written or run beyond the
baseline.** The single most contained fix identified is M051-R-I06 (the raw
`item_id` in a 422 body); M051-R-B01 (the `numeric` quantity 500s) is next and
is also well contained, but both were left unapplied because the report and its
measurements had to be committed first.

Status: **🔁 Needs Re-audit** — discovery is substantially complete and recorded
with citations, but the probe suite that would convert the `[code-read]` findings
into measured ones has not run.

---

# Re-audit — 2026-09-01, part 2: PROBES EXECUTED

The §3 "NOT executed" table above is now superseded for every row listed here.
Probe file: `api/tests/Feature/Production/ZzM051AuditProbeTest.php` (scratch,
deleted after recording — it asserts nothing, it prints measurements).

Run on this session's own database, never the shared `ogami_test`:
`docker compose run --rm -e DB_DATABASE=ogami_test_wo2 api php artisan test tests/Feature/Production/ZzM051AuditProbeTest.php --no-coverage`
→ **10 passed, 1 failed (12 assertions)**; the single failure was my own wrong
table name (`outbox_events`; the real table is `event_outbox`), fixed and re-run
→ **1 passed**. No probe failure was a product defect.

**Environment note:** the `db` and `redis` containers were **restarted by
something outside this session** partway through (a `psql` call returned
`FATAL: the database system is starting up`). Re-verified before continuing —
`docker compose ps` showed both `Up (healthy)` and `select 1;` returned a row —
and `ogami_test_wo2` survived. Recorded because a stopped container was the root
cause of four earlier sessions' phantom verification, and this is exactly the
moment a session would start measuring nothing.

## Measured results, and what changed versus the code-read predictions

### PROMOTED TO BROKEN — negative output corrupts a recorded production quantity

Predicted as a "suspected hole"; **confirmed, and worse than predicted.**

```
[PROBE negative-output] threw=NULL produced=5 good=-5 rejected=10 scrap=200.00
```

`WorkOrderOutputService::record()` with `good_count: -5, reject_count: 10`
**succeeded silently**. The work order now carries `quantity_good = -5` and
`scrap_rate = 200.00`. The only quantity guard is `total <= 0`
(`api/app/Modules/Production/Services/WorkOrderOutputService.php:82`), and
`-5 + 10 = 5` clears it. Nothing downstream rejects a negative good count, and
`scrap_rate = 200.00` fits `numeric(5,2)` so the database raises nothing either.

`scrap_rate` above 100% is by itself proof the value is nonsense, and it will
flow into OEE quality, the Pareto analysis and the production dashboard.

**Reachability:** the HTTP path is protected — `RecordOutputRequest.php:28-29`
is `integer|min:0`, and the probe confirmed `qty: -5` → **422** on the operation
endpoint too. So this is not remotely exploitable today. But `record()` is the
module's **published shared contract** — the brief states any other output path
must go through it — so every future job, console command, importer or Edge
ingest path inherits an unguarded signature. Classified **Broken** (a recorded
production quantity can be corrupted) with the mitigation that it is currently
service-internal.

### NEW BROKEN — `POST /work-orders/{id}/resume` on a *confirmed* work order is a backdoor start

Found by the exhaustive transition matrix; **not predicted at all.**

```
"confirmed": { ..., "resume": "ALLOWED(in_progress)", ... }
```

`resume()` validates only the state-machine edge, and `confirmed → in_progress`
**is** a legal edge (`WorkOrderStateMachine.php:17`) because it is the edge
`start()` uses. So `resume()` moves a Confirmed work order straight to
`in_progress` (`api/app/Modules/Production/Services/WorkOrderService.php:423-465`)
while skipping everything `start()` does at `:312-379`:

| `start()` does | `resume()` on a confirmed WO |
|---|---|
| `assertMaterialPlan()` (`:324`) — a standard WO needs an effective BOM | **skipped** |
| `assertProductionDependenciesReady()` (`:325`) — subassembly children must have produced their target | **skipped** |
| machine must be Idle/Running, mold Available/InUse (`:332-337`) | **skipped** |
| `issueReservedMaterials()` (`:358`) — the MaterialIssue movements | **skipped** — material never issued |
| `batch_number` generation (`:347`) — the IATF production batch | **skipped** — stays null |
| `actual_start` stamp (`:351`) | **skipped** — stays null, so OEE and cycle time have no start |
| `captureMaterialLotReferences()` (`:364`) — IATF backward traceability | **skipped** |
| promote parent SO to `in_production` (`:368-371`) | **skipped** |

Both endpoints carry the same `production.work_orders.lifecycle` permission
(`api/app/Modules/Production/routes.php:57` vs `:55`), so this is not privilege
escalation — it is a guard bypass. It defeats at least one invariant that has a
dedicated passing test: `WorkOrderMachineConflictTest::parent_work_order_cannot_start_before_subassembly_child_is_ready`
holds for `start()` and is simply not consulted by `resume()`. It also defeats
`standard_no_bom_work_order_cannot_start`. Output can then be recorded on the
resulting `in_progress` WO, producing finished goods from material that was never
issued and a batch with no batch number.

The fix is one guard — `resume()` must require the source state to be `Paused`
specifically, not merely "any state with a legal edge to in_progress". Note this
is *narrowing* a transition, which by the containment rule is a change to when
production may proceed, so it is **not** an in-session fix despite the tiny diff.

Lower-severity mirror of the same root cause, also measured: `paused → start` is
`business-rule` rather than `illegal-transition`, i.e. the state machine permits
it and only a missing machine stopped the probe. With a real machine, `start()`
would re-run on a Paused WO. That case is largely benign — `issueReservedMaterials()`
finds no `Reserved` rows (they are already `Issued`), `actual_start ?? now()` and
`batch_number ?:` both preserve the originals — but it is a second uncontrolled
entry into `in_progress`.

### CONFIRMED — the full transition matrix, all 49 cells

Every one of the 7 statuses × 7 lifecycle actions was attempted.

```
planned:     confirm=business-rule  start=illegal  pause=illegal  resume=illegal  complete=illegal  close=illegal  cancel=ALLOWED(cancelled)
confirmed:   confirm=illegal        start=business-rule  pause=illegal  resume=ALLOWED(in_progress)  complete=illegal  close=illegal  cancel=ALLOWED(cancelled)
in_progress: confirm=illegal        start=illegal  pause=ALLOWED(paused)  resume=illegal  complete=ALLOWED(completed)  close=illegal  cancel=illegal
paused:      confirm=illegal        start=business-rule  pause=illegal  resume=ALLOWED(in_progress)  complete=illegal  close=illegal  cancel=ALLOWED(cancelled)
completed:   confirm=illegal        start=illegal  pause=illegal  resume=illegal  complete=illegal  close=ALLOWED(closed)  cancel=illegal
closed:      all 7 illegal
cancelled:   all 7 illegal
```

The named illegal transitions the brief asked about **all hold**:

- **cancel a completed WO** → `illegal-transition` (409) ✓
- **re-open a closed WO** → every action `illegal-transition`; `closed` is fully terminal ✓
- **complete twice** → `refused: IllegalLifecycleTransitionException` ✓
- **record output on a cancelled WO** → refused ✓ (see next section)
- **cancel an in-progress WO** → `illegal-transition`; the operator must pause first (see Q3)

`cancelled` is likewise fully terminal, so there is no resurrection path.

### CONFIRMED — output guards all hold except the negative case

```
zero output ............. refused: "At least one of Good count or Reject count must be greater than zero."
beyond ordered qty ...... refused: "Recording this output would exceed the work order target quantity (10)."
status planned .......... refused
status confirmed ........ refused
status completed ........ refused
status closed ........... refused
status cancelled ........ refused
status paused ........... refused
reject without defects .. refused: "The total sum of defects (0) must exactly equal the Reject count (3)."
defect sum mismatch ..... refused
```

Note output is refused on **paused** as well as the terminal states — only
`in_progress` may record, and the guard re-reads the locked authoritative row
(`WorkOrderOutputService.php:184-186`) rather than trusting the route-bound
model, so a stale model cannot slip through.

### CONFIRMED — duplicate-output idempotency, including the half the baseline suite does not cover

```
[PROBE idempotency] first ={"shots":5,"lifetime":5,"produced":5,"scrap_rate":"40.00","outputs":1,"outbox":4}
[PROBE idempotency] second={"shots":5,"lifetime":5,"produced":5,"scrap_rate":"40.00","outputs":1,"outbox":4}
```

Identical payload replayed under one `X-Idempotency-Key`: **mold shots, lifetime
shots, WO produced quantity, scrap rate, output row count and `event_outbox` row
count are all unchanged.** The baseline suite asserts the output-row half; this
measures the shot / scrap / event half the brief specifically asked for. Nothing
is doubled.

Mold shots incremented **exactly once per output, by `good + reject`** (3 + 2 = 5).

Same payload with **no** key creates a genuine second output (shots 10, produced
10, 2 output rows). That is correct — two real shifts may legitimately record
identical numbers — and is worth stating so it is not mistaken for a leak.

### CONFIRMED — a mold past 100% of its rated life keeps running

```
[PROBE mold-past-100pct] after-crossing status=maintenance shots=101/100 pct=101
[PROBE mold-past-100pct] further-output threw=NULL shots_now=106 status_now=maintenance
```

Crossing 100% correctly flips the mold to `Maintenance` and writes a
`MoldHistory` row (`api/app/Modules/MRP/Services/MoldService.php:120-128`). But
the already-running work order **kept recording output on it**: a further 5
parts were accepted and the shot count rose to 106/100 while the mold sat in
`Maintenance`. `start()` checks mold status (`WorkOrderService.php:335-337`) and
nothing re-checks it afterwards, so the auto-flip to `Maintenance` is advisory
for the run that caused it.

This is the substantive mold finding, and it is **not** per-shot mold
depreciation (correctly out of scope per CLAUDE.md's NOT-BUILDING list). Whether
an in-flight run should be stopped or allowed to finish its batch is a
manufacturing policy call — see Q6. Classified **Incomplete**.

### CONFIRMED — `complete()` accepts zero output; short-of-target is also accepted

```
[PROBE complete-zero-output]     ALLOWED status=completed produced=0
[PROBE complete-twice]           refused: IllegalLifecycleTransitionException
[PROBE complete-short-of-target] ALLOWED status=completed
```

I01 confirmed. The short-of-target case is very likely correct by design
(partial completion is real) and should not be conflated with the zero case,
which claims completion of a run that produced nothing and then fires
`WorkOrderCompleted` onto the `o2c` chain — triggering outgoing QC
(`AppServiceProvider.php:331`) for a batch that does not exist.

### CONFIRMED — `skipOperation()` destroys a completed operation's record

```
[PROBE skip-completed-operation] threw=NULL status_now=skipped qty_completed=42.0000
                                actual_end='2026-09-01 04:51:09' notes='probe skip of a completed op'
```

B03 confirmed exactly as read. The operation is now `skipped` while still
carrying `qty_completed = 42` and an `actual_end` — a row asserting both that 42
parts were completed and that the operation never ran.

### CONFIRMED — all three `numeric` quantity failures on `/operations/{op}/output`

Service level:

```
"1.999"    → ACCEPTED qty_completed=1.9990
"1.99995"  → ACCEPTED qty_completed=1.9999      ← silent rounding
"10.00005" → ACCEPTED qty_completed=10.0000     ← silent truncation
"1e3"      → ACCEPTED qty_completed=1000.0000
"1e12"     → THREW QueryException: SQLSTATE[22003] Numeric value out of range: numeric field overflow
"1e15"     → THREW ValueError: bcadd(): Argument #2 ($num2) is not well-formed
"1e17"     → THREW ValueError: bcadd(): Argument #2 ($num2) is not well-formed
"1e20"     → THREW ValueError: bcadd(): Argument #2 ($num2) is not well-formed
```

And over HTTP, which is what a client actually sees:

```
[PROBE operation-http-status] {"1.999":200,"1.99995":200,"1e3":200,"1e12":500,"1e15":500,"1e20":500,"-5":422}
```

**Three 500s from one endpoint**, confirming B01 in full. `10.00005 → 10.0000`
is the quality session's exact defect (`10.00005` silently stored as `10.0001`)
reproduced in a second module. `-5` correctly 422s, so the sign guard is the one
part of this validation that works.

### CONFIRMED — the operation output path bypasses everything, measured

```
[PROBE operation-qty] bypass_check:
  wo.quantity_produced=0  wo.quantity_rejected=0  wo.scrap_rate=0.00
  work_order_outputs=0    mold_shots=0
  op.qty_completed=25.0000  op.qty_scrapped=5.0000
```

25 good parts and 5 scrap recorded through the operation endpoint left the work
order reading **zero produced, zero rejected, 0.00% scrap, no output rows and no
mold shots**. B02 is now measured, not inferred. The two counters are fully
disjoint, and 5 scrapped parts are invisible to the WO scrap rate, to OEE
quality, and to Pareto defect analysis.

Overrun also confirmed: `qty: 10000` against `qty_planned: 10` → **ACCEPTED**,
`qty_completed = 10000.0000`.

### CONFIRMED — raw integer primary key in a 422 body

```
[PROBE raw-pk-leak] status=422 item_pk=1 item_code=ITM-73BL
body={"message":"Insufficient stock for item 1 (work order WO-P-81057): needed 5.000."}
contains raw pk "item 1": true
```

I06 confirmed end-to-end over HTTP. The body carries the raw `items` primary key
`1` while the human-meaningful `ITM-73BL` was available on the already-loaded
relation. Enumeration oracle, and the most contained fix in this report.

### OEE — divide-by-zero is safe, but two semantic defects confirmed and one NEW one found

```
[PROBE oee-idle]         a=1.0  p=NULL q=NULL oee=NULL
                         diag={"scheduled_minutes":960,...,"available_time":960,"run_time":960,
                               "good_count":0,"reject_count":0,"ideal_cycle_seconds":0}
[PROBE oee-weekend-run]  a=NULL q=0.9804 oee=NULL scheduled_minutes=0 good=500
[PROBE downtime-split]   MON unplanned=300 available=960 run=660 availability=0.6875
                         TUE unplanned=0   available=960 run=960 availability=1.0
```

1. **Divide-by-zero: safe.** An idle machine with no downtime and no output
   returned cleanly with `performance`, `quality` and `oee` all `null` and **no
   exception**. This is the opposite of the AR `credit_limit = 0.00`
   `DivisionByZeroError` that 500'd an entire customer list. Confirmed good.

2. **NEW (Incomplete) — an idle machine reports `availability = 1.0`.** Not
   predicted. A machine that produced nothing, ran nothing and was never
   scheduled anything reports **100% availability**, because availability is
   `run_time / available_time` and both are derived from the
   `available_hours_per_day` constant rather than from anything recorded. So
   "perfect uptime" and "no work at all" are indistinguishable on the metric
   that is supposed to detect stoppages. `performance`/`quality`/`oee` correctly
   go `null`; availability alone asserts a measured result it does not have.

3. **I04 confirmed — a real weekend run yields no OEE at all.** 500 good and 10
   reject parts genuinely recorded on Saturday 2026-06-06 gave
   `scheduled_minutes = 0` → `availability = null` → **`oee = null`**, despite
   `quality` computing fine at 0.9804 from the same rows. Availability is the
   only one of the three not derived from recorded data, and it takes OEE down
   with it.

4. **I05 confirmed — misattributed, NOT double-counted.** A 300-minute breakdown
   starting 23:00 Monday was charged **300 minutes to Monday and 0 to Tuesday**,
   though only 60 of those minutes fell on Monday. Monday's availability reads
   0.6875 against a true ≈0.9375; Tuesday reads a clean 1.0 while it actually
   lost 240 minutes. The brief's "double-counted" hypothesis does **not**
   reproduce — each row is attributed to exactly one window by `start_time`.

## Revised finding classifications after measurement

| ID | Before probes | After probes | Why it moved |
|---|---|---|---|
| — | (not found) | **Broken** — `resume()` backdoor-starts a confirmed WO | found only by the exhaustive matrix |
| — | "suspected hole" | **Broken** — negative output persists `quantity_good = -5`, `scrap_rate = 200.00` | measured |
| B01 | Broken [code-read] | **Broken [measured]** — three 500s over HTTP | measured |
| B02 | Broken [code-read] | **Broken [measured]** — WO totals and mold shots provably untouched | measured |
| B03 | Broken [code-read] | **Broken [measured]** | measured |
| I01 | Incomplete [code-read] | **Incomplete [measured]** | measured |
| I04 | Incomplete [code-read] | **Incomplete [measured]** + idle machine reports `availability=1.0` | measured, plus a new sub-finding |
| I05 | Incomplete [code-read] | **Incomplete [measured]**, "double-counted" refuted | measured |
| I06 | Incomplete [code-read] | **Incomplete [measured]** | measured |
| — | — | **New Incomplete** — mold past 100% keeps taking output | measured |
| idempotency | predicted to hold | **holds [measured]**, incl. shots/scrap/events | measured |
| transitions | predicted to hold | **hold [measured]**, all 49 cells | measured |
| OEE div-by-zero | predicted safe | **safe [measured]** | measured |

## Still NOT verified after part 2 — stated plainly

- **Permissions and row scope.** The delegated seeder/route sweep did not return
  before this session ended. So: which of the 13 roles holds each of the 8
  `production.*` permissions, whether every registry role can complete its part,
  and whether any single role can both record output and complete the work order
  it recorded (self-certification), are all **unverified**. What *is* measured is
  that all 27 Production routes carry `permission:` middleware (read directly
  from `routes.php:19-98`) — but not that the grants behind them are coherent.
  The prior session's I08 lead (`ppc_head` holds create/confirm + view but not
  lifecycle or record, `RolePermissionSeeder.php:530-583`) remains unconfirmed.
- **Concurrency.** Neither the two-connection concurrent-output probe nor the
  `WO-YYYYMM-NNNN` uniqueness race was run. The output case is predicted safe
  (both callers take `WorkOrder::lockForUpdate()` at
  `WorkOrderOutputService.php:160` before any write); the sequence case is
  predicted to 500 for one caller (M02, no `work_order` row exists — measured).
  Per the brief, a two-connection probe on an uncommitted unique insert can
  deadlock; if the next session's does, it should say so rather than invent a
  result.
- **Activity feed read surface.** `ActivityFeedService::record()` is called on
  restore (`WorkOrderService.php:611-619`), so the module does write to the feed
  — but the read endpoint, its permission gate, and whether it leaks fields the
  caller cannot otherwise read were not located.
- **80% mold alert end-to-end delivery.** Still inferred from the
  `AlertEngineService` poll query plus live settings, not observed as a
  materialised `alerts` row. Classification is very unlikely to change.
- **Dead surfaces** (route with no client, page with no route, `docs/USER-MANUAL.md`
  vs implementation) — same delegated sweep, did not return.

## Additional question

- **M051-R-Q6 — should an in-flight run stop when its mold crosses 100% of rated
  life?** Measured: it does not — output kept being accepted onto a mold sitting
  in `Maintenance` at 106/100 shots. Options are refuse further output, allow the
  current batch to finish and refuse the next start (the status flip already
  achieves this), or warn only. This changes a mold-life threshold's *effect*, so
  it is explicitly not the auditor's call.

---

# Re-audit — 2026-09-01, part 3: permissions and dead surfaces (MEASURED)

Supersedes the "Still NOT verified" entries for permissions and dead surfaces.

## Permission matrix — measured against a freshly seeded database

`RefreshDatabase` alone does **not** seed RBAC (a first attempt returned an empty
`permissions` table and zero roles — worth knowing, since a matrix dumped without
seeding looks exactly like "no role holds anything"). Re-run with
`$this->seed(\Database\Seeders\RolePermissionSeeder::class)`:

```
[PERM] slugs referenced by routes but NOT seeded: []

  department_head        (none)
  driver                 (none)
  employee               (none)
  finance_officer        (none)
  hr_officer             (none)
  impex_officer          (none)
  maintenance_tech       (none)
  ppc_head               create confirm view r.view r.manage
  production_manager     create confirm record view lifecycle dash r.view
  purchasing_officer     (none)
  qc_inspector           (none)
  system_admin           create confirm record view lifecycle dash r.view r.manage
  warehouse_staff        (none)

[PERM] can record AND complete/close its own output: ["production_manager","system_admin"]
[PERM] can record but cannot view work orders: []
[PERM] holds lifecycle but not confirm: []
```

### Results against the questions the brief asked

- **No route is gated on an un-grantable permission.** All 8 permission slugs used
  by `api/app/Modules/Production/routes.php` exist as seeded rows
  (`RolePermissionSeeder.php:267-281`). This module does **not** have the defect
  three sibling modules shipped.
- **No chain step is impossible.** `production_manager` holds create + confirm +
  lifecycle + record, so one role can carry a work order from creation to close
  end-to-end. Nothing stalls.
- **No role can record output without being able to view work orders**, and **no
  role holds `lifecycle` without `confirm`.** Both clean.
- **The prior session's I08 lead is CONFIRMED as measured and looks intentional.**
  `ppc_head` holds `create`, `confirm`, `view`, `routings.view`, `routings.manage`
  but **not** `lifecycle`, **not** `record` and **not** `dashboard.view`. That is a
  coherent planner-vs-executor boundary — PPC plans and authors routings,
  production_manager executes — and it is deliberate: `production_manager` is
  explicitly denied `routings.manage` with a comment explaining why
  (`RolePermissionSeeder.php:562-570`), which is the mirror image of the same
  boundary. Reclassify I08 from "not aligned" to **intentional, worth documenting**.

### NEW Incomplete — `qc_inspector` is notified of in-process QC with a link it gets 403 on

This is the one concrete permission defect, and it falls out of combining two
measurements.

`TriggerInProcessQC` notifies the roles named in
`quality.in_process_qc.notification_roles`, with a deep link into this module:

```php
'link_to' => "/production/work-orders/{$wo->hash_id}",
```

and the setting's live value is **measured** as:

```
$ docker compose exec -T db psql -U ogami -d ogami -c "select key, value from settings where key like 'quality.in_process%';"
 quality.in_process_qc.notification_roles | ["qc_inspector","production_manager"]
```
(seeded at `api/database/migrations/0374_seed_remaining_notification_roles.php:15`)

But `qc_inspector` holds **zero** production permissions, and
`GET /production/work-orders/{workOrder}` is gated on
`production.work_orders.view` (`routes.php:49`). So **half the recipients of every
in-process QC notification receive a link that 403s** — both at the API and behind
the SPA's `PermissionGuard`.

The QC inspector can still do the inspection itself (those endpoints are gated on
`quality.*`), so this is not a blocked chain — it is a notification pointing at a
door the recipient cannot open, on the touchpoint that is this thesis's
differentiator.

**Not fixed.** The remedy is granting `production.work_orders.view` to
`qc_inspector`, and changing who may see what is an authorisation change —
explicitly on the not-contained list regardless of diff size. See Q7.

### Self-certification — real, but with a caveat that changes the answer

`production_manager` holds **both** `production.wo.record` and
`production.work_orders.lifecycle`, so one role records production output and then
completes/closes the very work order that output belongs to. Taken alone that
reads as a separation-of-duties failure.

It probably is not, for a reason the matrix makes visible: **`production_manager`
is the only non-admin role holding either permission.** Requiring separation would
leave nobody to perform the other half, so the current grants are not a leak so
much as an absence of a second production role. And the real independent check on
production output is not the lifecycle transition — it is outgoing QC and the
Certificate of Conformance, which sit with `qc_inspector` under `quality.*`.

Recorded as a **question** (Q8) rather than a finding, because deciding whether
production needs a maker/checker split is an authorisation policy call.

## Dead surfaces — measured

**Frontend: clean.** Every page under `spa/src/pages/production/` is registered in
`spa/src/routes/productionRoutes.tsx` (`dashboard.tsx`, `oee.tsx`, `schedule.tsx`,
`routings/`, and all four of `work-orders/{index,create,detail,record-output}.tsx`).
No orphan pages.

**Backend: one dead route.**
`GET /production/operations/schedule` (`api/app/Modules/Production/routes.php:89`,
→ `WoOperationController::schedule()` → `WoOperationService::getScheduleByMachine()`)
has **zero callers** — no SPA client function, no page, no test:

```
$ grep -rn "operations/schedule" spa/ api/tests    # (excluding node_modules)
(no output)
```

The production schedule page does not use it: `spa/src/pages/production/schedule.tsx:4-5,13`
reads `GET /mrp/scheduler/snapshot` via `schedulerApi` instead. So the
operation-level machine schedule endpoint, its service method and its response
transform are unreachable. Classification **Polish** (dead code, no correctness
impact) — but note it is gated on `production.dashboard.view`, so it is not
inert-and-unreachable, merely unused.

`docs/USER-MANUAL.md` was **not** cross-checked — out of budget. Still open.

## Additional questions

- **M051-R-Q7 — should `qc_inspector` hold `production.work_orders.view`?** It is
  notified of in-process QC with a work-order deep link and currently 403s on it.
  Granting read on work orders is the obvious remedy; the alternative is changing
  the notification's `link_to` to a Quality-side inspection URL. Authorisation
  change either way.
- **M051-R-Q8 — does recording production output need a maker/checker split?**
  `production_manager` can record output and then complete the work order it
  belongs to, and is the only non-admin role able to do either — so enforcing
  separation requires inventing a second production role, not just moving a grant.
  The independent quality check already lives with `qc_inspector` via outgoing QC
  and the CoC.
