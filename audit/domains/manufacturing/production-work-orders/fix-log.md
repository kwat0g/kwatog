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
