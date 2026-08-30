# M056 — Inspections & Certificates action plan

**Release status:** 📋 Plan Ready  
**Audit decision:** defer production implementation; the remaining work includes product decisions, cross-module quantity/provenance contracts, lifecycle recovery, persistence hardening, and SPA workflow verification.

The session recommendations below are planning tags. A future session that claims this plan should execute the ordered work and only defer for a real in-the-moment constraint or human decision.

## Ordered remediation

### 1. Establish decimal quantity and AQL verdict contracts — IC-01, IC-02

Scope: **large**  
Session recommendation: separate-recommended  
Priority: P0  
Owner area: Quality plus Inventory/product policy

- Decide whether fractional received quantities are valid inspection units and how the unit-of-measure conversion affects batch, sample, accepted, and rejected quantities.
- Remove the incoming-QC integer truncation/skip behavior or implement the agreed conversion explicitly; preserve the source quantity needed for audit.
- Decide whether Ac/Re defects are failed sampled units or failed parameter rows.
- Implement the aggregate at the selected level and keep `defect_count`, NCR creation, outbox events, and CoC output consistent.
- Add boundary tests for values below one, fractional lots, one sample with several failed parameters, and Ac/Re edges.

Acceptance: no valid received quantity is silently skipped or truncated, and the AQL verdict is stable and documented at the same level as Ac/Re.

### 2. Replace the outgoing fallback with an explicit recoverable path — IC-03

Scope: **large**  
Session recommendation: separate-recommended  
Priority: P1  
Owner area: Quality plus Production chain operations

- Narrow fallback eligibility to a typed “no active scaffoldable spec” condition; do not treat every `BusinessRuleException` as a missing spec.
- Choose one recovery contract: fail/manual-required without a misleading inspection, or create a placeholder that has an explicit repair/reseed operation and lifecycle status.
- Ensure replay after the underlying spec is corrected can seed measurement rows without being blocked by the existing output guard.
- Add tests for missing spec, empty spec, missing revision, output mismatch, AQL/settings failure, recovery, and idempotent replay.

Acceptance: every persisted outgoing inspection is either scaffoldable/completable or explicitly marked for manual recovery, and non-spec failures remain visible.

### 3. Close the stage/entity provenance contract — IC-04

Scope: **large**  
Session recommendation: separate-recommended  
Priority: P1  
Owner area: Quality, Inventory, Production, Supply Chain, and Returns owners

- Define and document the valid stage/entity matrix and whether standalone inspections are allowed.
- Decode every supported HashID type, or narrow the API/options to the actually supported values.
- Validate source existence, product/line ownership, stage compatibility, and the downstream gate/back-link required for each pair.
- Add the required source selectors or source-specific entry points to the SPA; do not show stages that cannot be completed from the form.
- Cover direct API, automatic listener, return hand-off, and permission-aware resource cases.

Acceptance: every non-standalone inspection has a valid source and product relationship, and backend enums, API docs, options, SPA types, and forms advertise the same contract.

### 4. Enforce cross-row evidence invariants at the terminal boundary — IC-05

Scope: **large**  
Session recommendation: separate-recommended  
Priority: P1  
Owner area: Quality persistence/domain

- Recompute tolerance-based verdicts during completion, not only during the mutation request.
- Reject numeric rows with missing readings or contradictory stored verdicts before terminal status changes.
- Add safe database checks for accepted quantity bounds, measurement sample-index bounds, and other driver-supported invariants; keep source and aggregate policy in the service.
- Add integrity tests for direct/imported malformed rows and verify failed writes roll back status, NCR, and outbox changes.

Acceptance: a malformed or stale measurement row cannot produce a passed inspection through any terminal path.

### 5. Set an operational limit for full-sample generation — IC-06

Scope: **large**  
Session recommendation: separate-recommended  
Priority: P1  
Owner area: Quality/platform

- Measure the largest supported sample-by-parameter workload on the production database engine.
- Add a domain-approved synchronous ceiling before allocation, or implement bounded asynchronous generation with progress, retry, and manual recovery states.
- Keep insert batches bounded and add load tests at the accepted maximum.

Acceptance: a valid request cannot hold an unbounded transaction or create an undocumented multi-million-row workload.

### 6. Make completion safe with local SPA edits — IC-07

Scope: **medium**  
Session recommendation: same-session-ok  
Priority: P1  
Owner area: Quality frontend

- Disable Complete while `dirtyCount > 0`, or have Complete save and await the save response before opening/finalizing.
- Reset drafts and dirty flags from the successful save response; preserve server errors without losing local edits.
- Add browser tests for numeric edits, manual verdict changes, notes-only edits, and double-click/refresh behavior.

Acceptance: finalization always reflects the operator’s visible edits, and a successful save no longer appears dirty.

### 7. Make the QC queue’s draft policy explicit — IC-08

Scope: **small**  
Session recommendation: same-session-ok  
Priority: P2  
Owner area: Quality product/frontend

- Decide whether the default queue should include `draft` and `in_progress` together.
- If draft work belongs to another queue, add a visible link/notification path and explain the state in the page.
- Add a role-level browser test for the initial queue and filter links.

Acceptance: a QC inspector can reliably find newly staged work from the primary Quality entry point.

### 8. Make manual product/output lookup searchable and paginated — IC-09

Scope: **medium**  
Session recommendation: separate-recommended  
Priority: P2  
Owner area: Quality frontend/API integration

- Add search and pagination/infinite loading to the product selector using the existing product search contract.
- Add a bounded search or pagination contract for work-order outputs instead of silently limiting the latest 100.
- Add loading, empty, and error states and tests with more than 100 records.

Acceptance: authorized operators can reach any valid product and output batch without relying on an undocumented first-page limit.

### 9. Improve measurement input accessibility — IC-10

Scope: **small**  
Session recommendation: same-session-ok  
Priority: P3  
Owner area: Quality frontend

- Include parameter name and sample number in numeric input accessible labels.
- Verify keyboard navigation and screen-reader naming in the detail table.

Acceptance: each measurement control has a unique, meaningful accessible name.

### 10. Restore executable lifecycle evidence — IC-11

Scope: **medium**  
Session recommendation: separate-recommended  
Priority: P2  
Owner area: Quality QA/platform

- Restore the `ogami_test` database/service or document the supported test bootstrap so the focused backend suite can run.
- Add API tests for the current contracts and browser tests for create/detail/list/roles/measurements/completion.
- Run the static token/RBAC/typecheck gates and record unrelated baseline failures separately.

Acceptance: the module has runnable regression evidence for every P0/P1 item and the browser’s role/state behavior.

## Verification gate before closing the module

- Focused backend tests for decimal quantities, AQL aggregation, outgoing fallback recovery, provenance, cross-row invariants, scale limits, and terminal concurrency.
- Existing Quality regression tests for incoming/in-process/outgoing triggers, NCR/outbox behavior, delivery drafting, rework, revision lineage, and return hand-off.
- SPA browser tests for queue defaults, output/product selectors, dirty completion, manual/numeric rows, notes, accessibility, and role guards.
- `npm run audit:tokens`, `npm run audit:rbac`, SPA typecheck, PHP syntax checks, and migration checks on a fresh database plus representative existing data.
- Regenerate the registry and release only after all P0/P1 items and their evidence gaps are closed.

---

# Action plan — 2026-08-30 re-audit

**Release status:** ✅ Verified for the certificate/evidence surface; the
remainder is gated and re-queued.

The four items marked ✅ below were implemented and committed in this session
(`8d126a1f`). Everything else is ordered for a follow-up. Items 5–7 are gated on
a **human or cross-module decision**, not on effort — per the audit protocol, a
sampling plan, an acceptance number, a tolerance, or the meaning of a quality
record is an IATF-auditable decision and is not an audit session's to make.

## Done this session

### ✅ 1. Bind the Certificate of Conformance to its evidence — IC-12
Scope **small** · `same-session-ok` · P0
`CoCService::assertEvidenceSupportsCertificate()`. Four typed refusals: no
evidence, unresolved rows, evidence contradicting the verdict, fewer sampled
units than declared. Locked by `CoCEvidenceIntegrityTest` (6 of 7 red at HEAD).

### ✅ 2. Make certificate re-issue idempotent — IC-13
Scope **small** · `same-session-ok` · P1
One certificate number now maps to one vault document, and the `GET /coc`
endpoint no longer writes on every call.

### ✅ 3. Bound `measured_value` validation — IC-14
Scope **small** · `same-session-ok` · P2
`decimal:0,4` + `between`. Ends silent rounding of the operator's reading and
three 500-class overflows.

### ✅ 4. Make completion safe with local SPA edits, and label the inputs — IC-07, IC-10
Scope **small** · `same-session-ok` · P1 / P3
Complete is gated on `dirtyCount` with an explanatory `title`; drafts clear on
save success; every measurement input has a unique accessible name.

## Gated on a human decision — do not implement unilaterally

### 5. Define what an AQL defect counts — IC-02
Scope **medium** (implementation) · `separate-recommended` · P1
**Question, not a bug.** The sampling table, the arrow rule, the lot clamp and
both Ac/Re boundaries are now measured correct. What is undefined is whether a
defect is a failed *sampled unit* or a failed *parameter row*; today it is the
latter, so one unit failing two parameters counts twice against an Ac defined
per unit. Needs a quality-engineering ruling first, then implement the aggregate
at the same level as Ac and keep `defect_count`, NCR severity and CoC
consistent with it.

### 6. Decide whether issuing a CoC is a read — IC-20
Scope **small** (implementation) · `separate-recommended` · P1
`quality.inspections.view` currently lets `production_manager` — the role
producing the goods — issue the conformance certificate for its own output.
Either that is intended customer-facing convenience, or the route moves to a
dedicated `quality.coc.issue` slug. Separation-of-duties call for a human.

### 7. Decide what the lightweight incoming path claims — IC-17
Scope **small** · `separate-recommended` · P2
`createIncomingForItem()` writes `sample_size=32, aql_code=G` and scaffolds one
verdict row. Either stop asserting an AQL sample on that path, or scaffold it.
Both change what the record means.

## Ordered engineering work

### 8. Make a completed quality record immutable — IC-15
Scope **large** · `separate-recommended` · P1
The highest-value item left. Follow the `journal-ledger` precedent: an observer
**plus** a PostgreSQL trigger raising `P0001`, covering update, soft-delete,
force-delete and measurement-row mutation once an inspection is terminal. Also
remove `'status'` from `Inspection::$fillable` (the three service `create()`
calls move to `forceFill`), and add soft deletes with a `->withTrashed()`
restore route if retention requires one. Migration must be
`2026_MM_DD_HHMMSS_*`, not `0NNN_`: it depends on
`2026_08_25_200000_add_inspection_integrity_checks`, and every `0NNN_` runs
before every `2026_*`.

### 9. Gate the work order on its in-process inspection — IC-16
Scope **medium** · `separate-recommended` · P1 · **owner: Production**
No gate exists at all. Report only; the fix is not this module's file.

### 10. Replace the outgoing no-spec fallback with a recoverable path — IC-03
Scope **large** · `separate-recommended` · P1
Prior plan item 2, now confirmed by measurement: the fallback row is
uncompletable *and* unrepairable. Unchanged otherwise.

### 11. Resolve the decimal received-quantity contract — IC-01
Scope **large** · `separate-recommended` · P1 · needs Inventory
Prior plan item 1, still open, still decision-dependent.

### 12. Finish the decimal-as-string contract — IC-18
Scope **medium** · `separate-recommended` · P2
`InspectionMeasurementResource` returns decimals as JSON floats and
`evaluate()` compares as floats. **Latent, not live** — no wrong verdict is
reachable at `decimal(12,4)`. Fix all three sides together (resource, SPA type,
tolerance bar) or none; an `AUDIT NOTE` in `detail.tsx` marks the half-migration
I deliberately backed out.

### 13. Remaining prior items, unchanged
IC-04 (stage/entity provenance, large), IC-05 (cross-row DB checks — partly
subsumed by item 8), IC-06 (full-sample workload ceiling, large), IC-09
(paginated selectors, medium), IC-11 (browser coverage, medium). IC-04, IC-06
and IC-09 were **not re-measured** this session.

### 14. Polish
IC-08 draft-queue default (product decision); drop the unused
`quality.inspections.create` / `.edit` permission slugs; give the
`app.debug=false` 404 a message.

## Shared-service items — report, do not fix here
`HashIdFilter::decode` accepting raw integers in production; the
`document_sequences` create race; the `CocAutoAttachOnConfirmTest` fixture
(SupplyChain owner).
