# M055 — Inspection Specifications Action Plan

Status after re-audit: `📋 Plan Ready`  
Audit report: [audit-report.md](audit-report.md)

The re-audit found that the previous plan's core archive/revision/SPC work has
partially landed in the working tree, but the current seed/bootstrap path and
role-facing revision history are not yet compatible with it. Items below are
ordered by data trust and ability to exercise the quality flow.

## Ordered fixes

### 1. Repair quality demo/bootstrap fixtures and rerun safety

- **Findings:** F-01, F-02, F-06.
- **Scope:** large.
- **Session recommendation:** `separate-recommended`.
- Include `inspection_spec_revisions` in reset/truncate handling without leaving
  orphaned rows; create specs and revision-linked items through the same domain
  contract as production or explicitly reproduce it; pin seeded inspections to
  their spec revision; and create inspections before measurements only after the
  spec linkage exists.
- Rewrite seeded dimensional data so bounds match the evaluator's absolute-bound
  contract, or change the contract once and update the API/UI/tests consistently.
  Do not leave the seeder using tolerance offsets while the request treats bounds
  as absolute values.
- Add a repeatable seeder/integration check proving a clean run and a second run
  leave valid revisions, linked inspections, valid item geometry, and usable SPC
  evidence.

### 2. Make revision history reconstructable through the module surface

- **Findings:** F-03, F-05, F-11-style coverage gap.
- **Scope:** large.
- **Session recommendation:** `separate-recommended`.
- Add a read-only revision-history endpoint/resource that returns the revision
  actor, timestamp, notes, and complete historical item definitions; add the
  corresponding editor history/detail state for both `system_admin` and
  `qc_inspector` without exposing authoring controls to view-only users.
- Show which revision governs an inspection and provide a supported path from an
  inspection/spec detail to the exact historical definition used for that evidence.
- Test that a new revision leaves the old item/tolerance set readable and that
  completed inspection evidence remains explainable after archive/revision.

### 3. Decide and enforce the SPC population policy

- **Findings:** F-04, F-07, F-11-style coverage gap.
- **Scope:** medium/large.
- **Session recommendation:** `separate-recommended`.
- Decide whether the current-spec panel uses only the current revision or combines
  completed readings across superseded revisions. Align the controller/service
  documentation, response metadata, editor copy, and capability-study behavior to
  that decision.
- Define the zero-variance result explicitly. If it is not computable, return
  `null`/an explicit insufficient-variation state instead of flooring sigma; if
  infinite capability is intentional, document and represent it safely rather than
  rounding an artificial value.
- Add database-backed tests for passed/failed inclusion, draft/in-progress/
  cancelled exclusion, old-vs-current revision scope, insufficient samples, and
  identical readings.

### 4. Close database lineage and spec-item invariants

- **Findings:** F-05, F-06.
- **Scope:** large.
- **Session recommendation:** `separate-recommended`.
- Choose composite foreign keys or a transaction/domain validator so an item and
  inspection cannot pair one spec root with another root's revision. Decide whether
  new rows can remain nullable for legacy backfill; make the post-migration state
  explicit.
- Add database checks for the parameter enum, tolerance order, nominal geometry,
  and any type-conditional numeric rules that must hold even for seeders, jobs, or
  direct model writers. Add a migration/backfill failure report for existing bad
  rows rather than silently rewriting quality evidence.
- Add regression coverage for invalid direct writes and mismatched lineage pairs.

### 5. Handle products that are soft-deleted or unavailable in the spec list

- **Findings:** current audit follow-up from the product relation boundary.
- **Scope:** small.
- **Session recommendation:** `same-session-ok`.
- Either eager-load the product with its trashed state and render the row as
  unavailable, or navigate every row by the spec ID and make the detail view show
  the archived product context. Never build `/quality/inspection-specs/undefined`
  from a missing product relation.
- Add a list/detail regression test for an active spec whose product is soft-deleted.

### 6. Complete the small frontend failure/loading states

- **Findings:** F-08, F-09.
- **Scope:** small.
- **Session recommendation:** `same-session-ok`.
- Render an SPC loading state and a retryable error state distinct from the
  insufficient-sample message. Change the list skeleton to six columns so its
  loading layout matches the actual table.
- Execute the editor test once the Vite temp/config permission issue is resolved.

## Release gate

Do not mark M055 `✅ Verified` until:

1. the comprehensive and Golden Path seeders can be run twice without orphaned or
   revision-less quality rows;
2. the supported API/UI can reconstruct a historical spec revision and the
   governing revision for completed inspections;
3. the SPC population and zero-variance policy is explicit and covered by a live
   database test;
4. database/domain invariants prevent cross-spec revision pairs and invalid spec
   items; and
5. the focused API suite passes against the project database and the editor test
   executes successfully.

The module should then receive a narrow re-audit of these findings before being
released as `✅ Verified`.
