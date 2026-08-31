# Module Audit — Quality / NCR + CAPA (M057)

Audit session: 2026-08-25  
Status: 📋 Plan Ready  
Scope: Laravel NCR/CAPA backend, scheduled escalation/effectiveness jobs, Quality SPA list/create/detail flows, notifications, and NCR permissions. Dependencies were read for contract checks only; no dependency source was changed.

## Outcome

The module has a coherent NCR root lifecycle and uses transactions plus row locks around action, disposition, close, and cancel mutations. The main delivery risk is the boundary between that lifecycle and the newer escalation/CAPA workflows: escalation can stop after containment, CAPA verification has no domain transition guard, and the SPA does not expose the CAPA endpoints.

No application source fixes were made in this session. The findings are mostly cross-cutting state, notification, RBAC, database, or frontend work, so the module is released with a plan rather than a partial fix.

## Discovery

- Backend surface: `NcrController`, `NcrService`, `NcrEscalationService`, `NcrRecurrenceDetector`, `EffectivenessService`, `EffectivenessController`, models, resources, migrations, settings, routes, scheduler commands, and Quality feature tests.
- Frontend surface: `spa/src/api/quality/ncrs.ts`, NCR list/create/detail pages, route registration, Quality types, notification rendering, and notification metadata.
- Role surface: `RolePermissionSeeder` and Quality routing settings were inspected read-only.
- Design system: existing NCR pages use `PageHeader`, `Panel`, `DataTable`, `Chip`, semantic token classes, labelled form controls, and keyboard focus helpers. No standalone visual-polish defect was found; the CAPA gap is functional completeness.
- Money/ULID checks: NCR tracks integer quantities only; there is no monetary calculation in this module. Repository-standard integer IDs plus HashIDs are used. No centavo or ULID issue applies.

## Findings

### F-001 — Escalation silently stops after a non-corrective action

Classification: **Broken** · Priority: P1  
Evidence: `api/app/Modules/Quality/Services/NcrEscalationService.php:34-39` selects only `status = open`; `api/app/Modules/Quality/Services/NcrService.php:201-215` changes an Open NCR to `in_progress` after *any* action, including containment. `api/tests/Feature/Quality/NcrEscalationTest.php:70-109` covers only Open NCRs.

Impact: the normal containment step removes an NCR from the escalation candidate set even when no Corrective action exists. The SLA can therefore stop without a tier notification. The same exclusion also applies after setting a disposition, which moves the NCR to `in_progress`.

Recommendation: define the eligible state policy explicitly and include every intended non-terminal state, or make escalation a separate state-machine transition. Add an in-progress containment test.

### F-002 — Escalation advances state before delivery and has no claim/lock

Classification: **Incomplete** · Priority: P1  
Evidence: `api/app/Modules/Quality/Services/NcrEscalationService.php:34-39` loads candidates without a lock; `:69-72` persists `escalation_level` and `last_escalated_at` before resolving recipients and calling notifications at `:74-84`.

Impact: a notification failure after the save permanently consumes the tier; an empty audience also consumes it. Concurrent scheduler/manual runs can select the same row and race the tier update. The scheduler's `withoutOverlapping` only covers the configured scheduler invocation, not every caller.

Recommendation: use a row-claim/transaction or durable outbox with an idempotency key, define empty-audience behavior, and only mark a tier delivered after the delivery record is durable. Add failure and concurrency tests.

### F-003 — Auto-NCR idempotency is not database-safe

Classification: **Broken** · Priority: P1  
Evidence: `api/app/Modules/Quality/Services/NcrService.php:129-156` performs a read-then-create check. `api/database/migrations/0091_create_non_conformance_reports_table.php:33-34,56-63` adds only a non-unique `inspection_id` index. `api/tests/Feature/Quality/InspectionNcrTest.php:498-515` proves only sequential idempotency.

Impact: two concurrent inspection-completion paths can both observe no NCR and create duplicates, violating the method's idempotency contract and duplicating downstream recurrence/notification work.

Recommendation: add a nullable unique constraint for the inspection link and handle the uniqueness race by returning the winner, or use a locked/upsert path. Add a concurrent test.

### F-004 — Auto-generated descriptions defeat recurrence detection

Classification: **Broken** · Priority: P1  
Evidence: `api/app/Modules/Quality/Services/NcrRecurrenceDetector.php:19-24,39-54,90-95` compares the first 80 normalized description characters. `api/app/Modules/Quality/Services/NcrService.php:145-155` starts an automatic description with the unique inspection number and defect count/stage.

Impact: two otherwise equivalent inspection failures have different signatures before the defect detail is reached, so automatic NCRs will generally not link as recurrences. `api/tests/Feature/Quality/NcrRecurrenceTest.php:33-80` exercises only identical manually authored descriptions.

Recommendation: persist a canonical defect/parameter signature (or derive one from structured inspection data) and use it for both manual and automatic NCRs. Add an auto-generated recurrence case.

### F-005 — Recurrence failures are swallowed without replay

Classification: **Incomplete** · Priority: P1  
Evidence: `api/app/Modules/Quality/Services/NcrRecurrenceDetector.php:60-80` writes the recurrence/outbox record and sends alerts; `:82-87` catches every Throwable, logs, and returns. `NcrService::create` invokes the scan as part of NCR creation.

Impact: a transient database, settings, outbox, or notification failure can leave a persisted NCR unlinked or unannounced with no retry/repair signal beyond a log entry. This makes recurrence analytics and systemic-action alerts best effort without an explicit operational contract.

Recommendation: make linking a durable after-commit job/outbox workflow with retry and failure visibility; separate notification retry from the recurrence-link transaction.

### F-006 — CAPA verification accepts invalid action and status transitions

Classification: **Broken** · Priority: P1  
Evidence: `api/app/Modules/Quality/Controllers/EffectivenessController.php:26-40` checks only that the action belongs to the NCR and that the submitted status is an enum value. `api/app/Modules/Quality/Services/EffectivenessService.php:64-88` can verify containment actions, verify an open/cancelled NCR, and overwrite an existing verdict without a transition guard.

Impact: temporary containment can be marked effective/ineffective even though scheduling excludes it; repeated or out-of-order requests can rewrite the audit history. The CAPA workflow has no explicit transition table/state-machine guard.

Recommendation: lock the action and NCR, require a closed NCR and corrective/preventive action type, define allowed transitions from Pending/Ineffective, and reject repeated terminal verification unless an explicit re-open/recheck transition exists.

### F-007 — CAPA due notifications are not idempotent and have no action link

Classification: **Broken** · Priority: P1  
Evidence: `api/app/Modules/Quality/Services/EffectivenessService.php:135-177` selects every pending action whose date is due and sends on every run without recording a notification, advancing the date, or deduplicating. Neither payload at `:148-153` or `:168-172` includes `link_to`. The command claims “Idempotent” in `api/app/Console/Commands/CheckCapaEffectivenessCommand.php:10-22`, while the schedule runs daily at `api/routes/console.php:301-306`.

Impact: owners and production managers receive the same alert every day, and the notification cannot navigate to the NCR/action detail. This is both alert fatigue and a broken operational handoff.

Recommendation: add a durable notification/dedup key and notification timestamps/backoff, decide whether reminders are daily or one-shot, and include a canonical NCR/action link.

### F-008 — CAPA effectiveness is not usable from the SPA

Classification: **Missing** · Priority: P1  
Evidence: backend routes exist at `api/app/Modules/Quality/routes.php:106-118`, but `spa/src/api/quality/ncrs.ts:23-45` has no due-list or verify method; `spa/src/routes/qualityRoutes.tsx:42-47` has no effectiveness page; `spa/src/pages/quality/ncrs/detail.tsx:76-123,300-330` has no verification controls.

Impact: the backend can schedule and receive CAPA verdicts, but a QC user has no normal UI path to discover due checks or record a verdict. The loop can remain permanently pending unless someone calls the API manually.

Recommendation: add a due/overdue queue and detail-page verification panel with owner, due date, notes, status, and permission-aware actions. Include loading, empty, error, and stale-conflict states.

### F-009 — Return-to-supplier notifications do not match the notification contract

Classification: **Broken** · Priority: P1  
Evidence: `api/app/Modules/Quality/Services/NcrService.php:373-395` selects role recipients without `is_active`, builds `subject`/`body`/`ncr_id`/`severity`, and uses the legacy `notify()` wrapper. `api/app/Common/Services/NotificationService.php:173-194` delegates to Laravel's notification object rather than the standard typed payload. The SPA renders `data.title`, `data.message`, and `data.link_to` at `spa/src/pages/notifications/index.tsx:128-132,235-240`; the metadata entry is `spa/src/lib/notificationMeta.ts:108-115`.

Impact: Purchasing can receive an inactive-recipient notification with no actionable title/message/link. The anonymous database notification also does not reliably carry the configured `ncr.return_to_supplier` type used by the SPA metadata. This path runs during NCR close at `NcrService.php:269-335`, so notification semantics are part of a state transition.

Recommendation: migrate this path to `NotificationService::send()` with the standard type/title/message/link/entity fields, filter active recipients, and deliver after the close transaction commits.

### F-010 — Bulk close is backend-only

Classification: **Missing** · Priority: P2  
Evidence: the backend route/controller are implemented at `api/app/Modules/Quality/routes.php:102-104` and `api/app/Modules/Quality/Controllers/NcrController.php:116-193`, but `spa/src/api/quality/ncrs.ts:23-45` has no `bulkClose` method and `spa/src/pages/quality/ncrs/index.tsx:208-218` renders no selection or bulk action.

Impact: the documented “SPA can render a per-id summary” response has no user-facing caller. Operators must close NCRs one at a time or use an undocumented API client.

Recommendation: add permission-aware row selection, a confirmation summary, per-row results, and cache invalidation. Keep partial-success semantics visible.

### F-011 — NCR list query parameters are not validated

Classification: **Incomplete** · Priority: P2  
Evidence: `api/app/Modules/Quality/Controllers/NcrController.php:53-56` passes the raw query to `NcrService::list`; `api/app/Modules/Quality/Services/NcrService.php:57-70` accepts arbitrary enum/filter values and caps `per_page` only from above, with no lower bound or typed ID validation.

Impact: malformed status/source/disposition values silently produce misleading results, while zero/negative page sizes and untrusted identifier values reach the query builder. The endpoint lacks a stable validation contract for the SPA.

Recommendation: add an index request with enum, integer/hash-ID, search length, page, and `per_page:1..100` rules. Return field-level 422 errors.

### F-012 — Frontend/backend NCR contracts have drifted

Classification: **Incomplete** · Priority: P2  
Evidence: backend `api/app/Modules/Quality/Enums/NcrSource.php:10-18` permits only `inspection_fail` and `customer_complaint`, while `spa/src/types/quality.ts:108-112` also permits `production` and `audit`. Backend resources emit CAPA fields at `api/app/Modules/Quality/Resources/NcrResource.php:68-74` and `NcrActionResource.php:25-36`, but `spa/src/types/quality.ts:114-149` omits those fields.

Impact: callers can construct values the backend rejects, and TypeScript consumers cannot safely render fields the API already returns. This will make the CAPA UI and future integrations depend on casts and hidden assumptions.

Recommendation: generate or centrally reconcile the enum/resource contracts, remove stale source values, and add an API contract/type test.

### F-013 — Production-manager permissions need an explicit NCR policy decision

Classification: **Incomplete** · Priority: P2  
Evidence: escalation targets include `production_manager` in `api/database/migrations/0366_seed_ncr_escalation_roles.php:11-16`, and CAPA overdue alerts target that role in `api/database/migrations/0378_seed_effectiveness_notification_roles.php:11-18`. The role receives only `quality.view`, `quality.inspections.view`, and `quality.ncr.view` at `api/database/seeders/RolePermissionSeeder.php:530-541`; `qc_inspector` receives the full Quality module at `:632-645`.

Impact: if managers are expected to act on escalated NCRs or verify CAPA, the alert links to a user who cannot perform the action. If they are intentionally observers, the current permission is correct but should be documented and tested.

Recommendation: confirm the role policy with the product owner. Then either grant the minimum manage/verify permission or keep view-only and route escalations to an actionable role.

### F-014 — CAPA ownership and due dates cannot be assigned through the NCR API/UI

Classification: **Incomplete** · Priority: P2  
Evidence: the model/schema support `owner_id` and `due_date` at `api/app/Modules/Quality/Models/NcrAction.php:20-37` and `api/database/migrations/0188_add_ncr_followthrough_columns.php:13-20`. The controller validates and forwards only action type, description, and performed date at `api/app/Modules/Quality/Controllers/NcrController.php:68-76`; the detail form also collects only type/description at `spa/src/pages/quality/ncrs/detail.tsx:76-83,300-328`.

Impact: every action defaults to the actor and a settings-derived date. Quality cannot assign a responsible owner or negotiate a due date, weakening CAPA accountability and making the overdue queue less meaningful.

Recommendation: add validated owner/due-date inputs, authorization for assigning users, and visible owner/due fields in the detail and due-list workflows.

### F-015 — Tests do not cover the CAPA loop or the critical races

Classification: **Incomplete** · Priority: P2  
Evidence: the Quality feature-test inventory contains NCR escalation/recurrence/close/inspection tests but no effectiveness test file. Existing escalation cases at `api/tests/Feature/Quality/NcrEscalationTest.php:55-121` omit in-progress containment, delivery failure, empty audience, and concurrency; recurrence tests at `api/tests/Feature/Quality/NcrRecurrenceTest.php:33-80` use manual descriptions; auto-idempotency is sequential at `api/tests/Feature/Quality/InspectionNcrTest.php:498-515`.

Impact: the highest-risk behavior is not protected by executable regression coverage. The static implementation may look healthy while scheduled side effects and concurrent paths fail in production.

Recommendation: add database-backed tests for CAPA scheduling/verification/rollup, notification deduplication, valid transition rejection, auto recurrence, unique-race handling, in-progress escalation, and delivery failure.

### F-016 — Work-order dependency failures can be hidden during NCR close

Classification: **Broken** · Priority: P2  
Evidence: `api/app/Modules/Quality/Services/NcrService.php:283-325` closes scrap/rework NCRs and attempts to create a work order; `:363-370` catches every container-resolution Throwable and returns `null`, after which close proceeds without a replacement/rework link.

Impact: a missing or misconfigured Production service can leave a closed NCR with the required downstream work order absent and no user-visible failure. This creates a quality-to-production traceability gap.

Recommendation: make the dependency explicit for production-enabled deployments, fail/retry the close transaction when the work order is required, or create a durable post-commit command with a visible pending/error state. Add failure-path tests.

## Verification

- PHP syntax: passed for the NCR controllers, services, recurrence detector, routes, and effectiveness controller.
- Route registration: passed; NCR list/create/show/action/verify/disposition/close/cancel, bulk-close, and CAPA due routes are registered.
- SPA TypeScript: `npm run typecheck` passed.
- SPA lint: targeted ESLint for NCR API/pages/routes/types passed.
- Focused backend tests: 31 tests failed before assertions because PostgreSQL host `db` could not resolve (`SQLSTATE[08006]`, `ogami_test`, 0 assertions). This is an environment blocker, not evidence that the tests passed or that a source assertion failed.

## Fix disposition

No source fix was applied. The recommended work is predominantly separate-session work because it changes state-machine policy, database uniqueness, notification delivery, RBAC, cross-module work-order behavior, and introduces a new CAPA UI. Small same-session candidates (query validation and type reconciliation) should be bundled only after the state/contract decisions are settled.

---

# Re-audit — 2026-09-01 (session 4) — IN PROGRESS

Status: 🔁 in progress. Scope: verify every fix claimed in the 2026-08-25 plan-execution
session by runtime probe against real PostgreSQL rows (that session self-flagged all
feature tests as blocked at `SQLSTATE[08006]` with 0 assertions, so nothing in it was
ever executed). Then walk the closing arc of the IATF loop: inspection fail -> NCR ->
disposition consequences -> replacement WO -> Pareto, plus CAPA SLA/effectiveness,
the full NCR transition matrix, immutability after closure, numbering/concurrency,
validation family, permissions, HashID leakage, and dead surfaces.

Findings are appended below as they are measured.

## Baseline (real, measured 2026-09-01)

Own database `ogami_test_ncr`; container-relative paths.

| run | tests | assertions | exit |
|---|---|---|---|
| `NcrEscalationTest NcrCapaEffectivenessTest NcrRecurrenceTest Unit/NcrEffectivenessStateMachineTest` | 21 passed | 29 | 0 |
| `InspectionNcrTest NcrAutoReworkWoTest NcrCloseCancelRaceTest NcrCloseRequiresActionsTest NcrDoubleCloseRaceTest NcrSetDispositionRaceTest QualityAnalyticsBoundaryTest` | 31 passed | 92 | 0 |
| **total** | **52 passed** | **121** | **0** |

## Prior-session assessment

The 2026-08-25 plan-execution session **did real work and its log is honest**: it wrote
`0478_harden_ncr_capa_contracts`, the escalation delivery ledger, the CAPA state machine,
the recurrence signature/job, and the SPA CAPA surfaces — and it stated plainly that every
feature test stopped at `SQLSTATE[08006]` with 0 assertions. On first real execution
**all 52 tests pass.** This is the `supplier-performance` outcome, not the
`separation-final-pay` one.

Of the 16 prior findings F-001…F-016, **15 no longer reproduce** (verified by probe, not by
reading the log — see the invariant table). **F-013 still reproduces** and is now measured
precisely rather than described (see N-002).

## Findings — re-audit 2026-09-01

### N-001 — `ncr:escalate` cannot tell "nothing to do" from "everything threw"

Classification: **Broken** · Priority: P1 · Scope: small · **same-session-ok**

`NcrEscalationService::run()`
(`api/app/Modules/Quality/Services/NcrEscalationService.php:39-58`) counts only NCRs whose
tier was *advanced*. `advanceOne()` returns `false` for three different things —
not-yet-due, already-delivered, and **`catch (Throwable)` at :179-230** — and
`RunNcrEscalations::handle()` (`api/app/Console/Commands/RunNcrEscalations.php:19-24`)
prints that one number and always returns `self::SUCCESS`.

Measured: three overdue critical NCRs, notification transport bound to throw.
`run()` returned `0`; the command printed exactly `NCR escalation completed: 0 advanced.`
and exited **0** — byte-identical to an idle run. Every 15 minutes, forever.
This is the shape CLAUDE.md documents for the 8D SLA ledger.

Mitigating (and materially better than 8D): the durable ledger **did** record the failure —
`ncr_escalation_deliveries` held three rows `status=pending, attempts=1,
last_error="probe: notification transport is down"`. The data is there; the operator-facing
signal is not. Also unlike 8D, the failure-recorder's own `catch (Throwable) → Log::error`
at `:215-221` was never exercised because the table exists (`0478:66-84`) and the model's
inferred table name `ncr_escalation_deliveries` matches it — the 8D `42P01` failure mode
does **not** reproduce here.

### N-002 — escalation tier 2 targets a role that cannot clear the escalation (F-013, still open)

Classification: **Incomplete** · Priority: P1 · Scope: small but **IATF/RBAC decision** ·
**separate-recommended**

`0366_seed_ncr_escalation_roles.php:13` sets the tiers to
`['qc_inspector', 'production_manager', 'system_admin']`. The escalation clears only when a
**Corrective** action exists (`NcrEscalationService.php:44-48,246-248`), and adding one
requires `quality.ncr.manage` (`api/app/Modules/Quality/routes.php:128-129`).
`RolePermissionSeeder.php:577` grants `production_manager` only
`quality.view, quality.inspections.view, quality.ncr.view`.

So tier 2 of 3 notifies a role that can read the NCR and can do nothing about it. Tier 1
(`qc_inspector`) and tier 3 (`system_admin`) hold `quality.ncr.manage` and are actionable.
Either widen `production_manager` or retarget tier 2 — a human decision, not mine.

### N-003 — a closed NCR is fully mutable and hard-deletable, with zero triggers

Classification: **Missing** · Priority: P1 · Scope: medium · **separate-recommended**

`NonConformanceReport` (`api/app/Modules/Quality/Models/NonConformanceReport.php:25-48`)
has **no `SoftDeletes`** and there is **no observer and no PostgreSQL trigger** on either
`non_conformance_reports` or `ncr_actions`. Measured against a closed NCR:

- `forceFill(['status' => 'open', 'defect_description' => 'rewritten'])->save()` → **mutated**
- raw `update … set status='open', ncr_number='HACKED'` → **mutated**
- `->delete()` → **row gone**
- `select tgname from pg_trigger …` → `[]` for both tables

Not reachable over HTTP: there is no `PATCH /quality/ncrs/{ncr}` and no `DELETE` route at
all (12 registered `quality/ncrs` routes enumerated, none `DELETE`). So this is a missing
record-integrity control on an IATF record, not a live exploit. Precedent: `journal-ledger`
pairs an observer with a `P0001` trigger; a prior quality session found completed
inspections in the same state.

**Hazard for whoever implements it — do not naively key a trigger on `OLD.status`.**
`NcrService::close()` writes the row **twice**: `:312-316` flips status to `closed`, then
`:334` / `:355` writes `replacement_work_order_id` / `rework_work_order_id` in a second
`save()` where `OLD.status` is *already* `closed`. `EffectivenessService` also legitimately
writes `effectiveness_status` / `effectiveness_closed_at` to a closed NCR
(`EffectivenessService.php:167-172`) and `effectiveness_*` / `verified_*` /
`next_effectiveness_check_at` to its actions (`:112-120`). Any freeze needs a per-column
allow-list, or it breaks the CAPA loop that currently works.

### N-004 — an NCR disposition has no material consequence

Classification: **Missing** · Priority: P1 · Scope: large · **separate-recommended**
(cross-module + IATF-auditable)

`NcrService::close()` (`:304-369`) produces exactly two effects: a work order (scrap/rework,
outgoing stage only) and a role notification (return-to-supplier). Measured on a `scrap`
close of a 40-piece outgoing NCR: `stock_movements` 0 → 0, `material_review_records` 0 → 0,
`replacement_work_order_id` set. **Scrap removes no stock**, so the question of reversibility
does not arise — nothing happened to reverse.

The stock mechanism exists, in Inventory, and it already speaks this module's enum:
`QuarantineService::release()` (`api/app/Modules/Inventory/Services/QuarantineService.php:289-390`)
switches on `NcrDisposition::{Rework,UseAsIs,Scrap,ReturnToSupplier}` and emits
`Transfer` / `Scrap` / `ReturnToVendor` stock movements. `material_review_records.ncr_id`
is **nullable** (`QuarantineService.php:172,228`). Nothing reconciles the two dispositions,
so an MRB can be released `use_as_is` while its linked NCR says `scrap`, and an NCR
disposition set with no MRB moves nothing at all.

**Question for a human:** is the NCR disposition meant to drive the MRB release, or are the
two registers deliberately independent with the MRB as the sole stock authority? Reported,
not touched — Inventory belongs to another session and changing a disposition's consequences
is explicitly an IATF-auditable decision.

### N-005 — `use_as_is` records no concession and no grantor; `return_to_supplier` names no vendor

Classification: **Missing** · Priority: P1 · Scope: medium · **separate-recommended**

`non_conformance_reports` has 28 columns and **none** matching `concession|approv|grant`
and **none** matching `vendor|supplier` (enumerated from `information_schema.columns`).
Measured: an NCR closes on `use_as_is` with no additional approval step and no record of who
granted the concession — an IATF-auditable act with no auditable trace.

`return_to_supplier` (`NcrService.php:360-361,430-453`) sends a role-targeted notification
whose body names the NCR and quantity but **not the supplier**, because the NCR cannot
reference one. Nothing reaches supplier performance. For an incoming-QC NCR the vendor is
derivable (inspection → GRN → PO → vendor) and no code derives it.

### N-006 — a CAPA loop closes as "Effective" when every verdict was "Not Applicable"

Classification: **Incomplete** · Priority: P2 · Scope: small but **IATF decision** ·
**separate-recommended**

`NcrEffectivenessStateMachine::TRANSITIONS`
(`api/app/Modules/Quality/Support/NcrEffectivenessStateMachine.php:20-25`) allows
`pending_verification → not_applicable`, and `EffectivenessService::updateNcrEffectiveness()`
(`:143-172`) counts `NotApplicable` as verified and rolls up to `Effective` unless something
is `Ineffective`. Measured: both CAPA actions verified `not_applicable` with the note `n/a`
→ NCR `effectiveness_status = effective`, `effectiveness_closed_at` set.

The only evidence gate is a non-empty `effectiveness_notes` string
(`EffectivenessService.php:83-86`); `ncr_actions` has **no attachment or evidence-reference
column** (17 columns enumerated). Same class as the CoC that could be issued with zero
measurement rows. What counts as effectiveness evidence is an IATF-auditable decision — flagged,
not changed.

### N-007 — the causer can disposition, close, and verify its own NCR

Classification: **Incomplete** · Priority: P2 · Scope: small but **IATF decision** ·
**separate-recommended**

Measured with one `qc_inspector`: the same user created the NCR, set its disposition,
recorded both the Corrective and Preventive action, closed it (`created_by == closed_by`),
and then recorded the CAPA effectiveness verdict on its own corrective action
(`performed_by == verified_by`). No separation-of-duty check exists in
`NcrService::close()` (`:282-370`) or `EffectivenessService::verifyAction()` (`:77-126`).
`qc_inspector` holds the whole Quality module (`RolePermissionSeeder.php:682-696`,
`$this->module('quality')`), and tier 1 of the SLA escalation escalates *to the same role*.
Self-absolution is the IATF-relevant form of self-approval. Who may close an NCR is
explicitly a human decision.

### N-008 — `PATCH /quality/ncr-templates/{id}/restore` lacks `->withTrashed()`

Classification: **Broken** · Priority: P2 · Scope: small · **same-session-ok**

`api/app/Modules/Quality/routes.php:106-107` registers the restore route with no
`->withTrashed()`, while `NcrTemplate` **does** use `SoftDeletes`
(`api/app/Modules/Quality/Models/NcrTemplate.php:14,25`). Compare the correct
inspection-spec restore two blocks up at `routes.php:55-57`, which has it. Route-model
binding therefore cannot resolve a trashed template — the fourth-plus instance of the
pattern four other modules shipped. (Runtime confirmation deferred to the second probe pass;
first attempt failed on my own fixture, not on the route — `ncr_templates.source` is NOT NULL.)

### N-009 — the Pareto drill-down row-mapping branch had zero coverage (but works)

Classification: **Polish** · Priority: P3 · Scope: small · **same-session-ok**

`DefectParetoService::inspectionsWithDefect()` has exactly one test caller,
`api/tests/Feature/Quality/QualityAnalyticsBoundaryTest.php:53`, which asserts
`assertSame([], $drill)` — the **empty case only**. Lines `:172-185` (the hashid encoding
and the `product` sub-array) had never executed in any test, while the SPA calls the
endpoint from `spa/src/pages/quality/dashboard.tsx:46`. Exactly the calibration-analytics
precedent.

**Refuted as a defect, kept as a coverage gap.** Probed with real rows, the branch is
correct: 3 defects → `Burr` 2 / 66.67%, `Short shot` 1 / 33.33%, cumulative 100; the
drill-down returned one row with HashID ids for both the inspection and the product and no
raw integers. No `42803`.

## Refuted / verified-good (do not inherit these as findings)

- **No `SQLSTATE[42803]` anywhere.** Every aggregate over `NonConformanceReport::actions()`
  already calls `->reorder()` inside the closure: `NcrService.php:292`,
  `NcrEscalationService.php:46`, `EffectivenessService.php:51,135`. Pareto aggregates over
  `inspection_measurements` via the query builder and never touches the relation.
- **Empty-period divide-by-zero is honest.** `inspectionSummary` returns `pass_rate: null`
  (`DefectParetoService.php:53`, comment says so out loud) and `run()` returns
  `total_defects: 0, rows: []`.
- **Archived-row divergence does not apply.** `NonConformanceReport`, `NcrAction`,
  `Inspection` and `InspectionMeasurement` all lack `SoftDeletes`, so there is no trashed
  row for an aggregate to disagree about. `products` *is* soft-deletable and neither
  `run()` nor `inspectionsWithDefect()` filters it — **consistently**, in both aggregates,
  which is the correct outcome (a defect that happened still happened).
- **The validation family does not reproduce.** `affected_quantity` is
  `integer|min:0|max:1000000` (`CreateNcrRequest.php:42`). Probed `1.999`, `1e3`, `1e17`,
  `1e20`, `10.00005`, `-1` → six clean 422s, zero 500s. There is no money column in this
  module. `analytics/defect-pareto?from=0000-01-01` → clean 422; `from=9999-12-31` → 200
  with empty rows. `per_page=0`, `status=bogus`, `product_id=999999` → one 422 naming all
  three fields.
- **No raw-integer-id oracle.** 13 error/edge bodies probed (422 validation, 422
  `BusinessRuleException`, 207 partial bulk-close, 200 analytics); the regex
  `"(id|ncr_id|product_id|inspection_id|user_id)":\d+` matched **none**. `bulk-close` echoes
  a rejected raw `"30"` back as the caller's own input string with `"Invalid ID."`, which is
  not a leak.
- **Numbering is correct.** `document_sequences` starts with **no** `ncr` row and creates it
  lazily on first `generate('ncr')`; two sequential calls produced
  `NCR-202609-0001` / `NCR-202609-0002` against a single row keyed
  `(document_type=ncr, year=2026, month=9)`. The work-orders session's "no row at all"
  observation is the pre-generation state, not a defect. The `23505` race lives in
  `DocumentSequenceService` — **`Common` scope, not fixed here.**
- **The 8D `42P01` dead-ledger failure mode does not reproduce.** All three new models omit
  `$table` and their inferred names match the migration exactly:
  `NcrEscalationDelivery`→`ncr_escalation_deliveries`,
  `NcrEffectivenessNotification`→`ncr_effectiveness_notifications`,
  `NcrRecurrenceScan`→`ncr_recurrence_scans` (`0478:48,66,87`).
- **`ncr:check-effectiveness` *does* surface failures.** With the notification transport
  bound to throw, `notifyOverdueChecks()` let the `RuntimeException` propagate, so the
  command exits non-zero. Only `ncr:escalate` has the N-001 defect.
- **The loop closes end to end.** Failed outgoing inspection → `openFromInspectionFailure`
  → NCR `source=inspection_fail`, `severity=high`, `defect_signature` populated →
  `rework` disposition + close → `rework_work_order_id` created → both defects visible in
  Pareto. Measured in one transaction chain, no swallowed link.
- **The full transition matrix is sound.** 20 cells (4 statuses × 5 operations) walked; all
  four operations refuse from `closed` and `cancelled`, and `verifyAction` refuses from
  `open`, `in_progress`, `cancelled`, and from a `closed` NCR whose action was never
  scheduled ("Unscheduled"). No `resume()`-style backdoor found — there is no second
  entry point to any transition.
