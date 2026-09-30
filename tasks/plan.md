# Implementation Plan: Budgeting Allocation Hardening (+ Panel Virement Option)

## Overview
Harden the existing allocation path (FY + department + monthly leaf-account lines, `draft→submitted→active→closed`, overview/vs-actual/enforcement) so the roles that do the work can actually reach it, SoD holds Finance-maker vs VP-checker, and lifecycle holes (reject/delete, FY guard, ceiling inflation, annual-only enforcement, blind spenders, missing board/search/sidebar) are closed. Panel-requested re-allocation (virement `line A→B`) is scoped as a Phase 3 option, reusing `TransferOrderService` lock-guard + `AssetTransferService` SoD + `InspectionSpec` versioning, not built until Phases 1–2 are approved.

## Architecture Decisions
- SoD over convenience: strip `budgeting.approve` from `finance_officer`; maker = Finance (`manage`), checker = VP (`approve`). Mirrors payroll compute-vs-approve and PR Finance→VP money chain. One-line seeder change + drift-test update, not a new workflow engine.
- No broad `budgeting.view` grant to spenders: `Budget::index` has no row scope today, so granting view leaks all departments. Instead surface `available` inside PR/PO UI + keep enforcement message actionable. Scoped read is a separate task, not a permission dump.
- Annual enforcement stays authoritative in Phase 1–2; monthly cap is researched in Phase 2 and only enforced in Phase 3 if panel insists (monthly spread affects `BudgetConsumptionService::hydrate` pro-rata logic — high regression surface).
- Budget stays OUT of generic `ApprovalService`/`ApprovalTypeRegistry` in Phase 1–2 (custom `submit/approve` with user-id SoD). Board integration is a Phase 3 adapter task, not a rewrite. Document manual queue (`/budgeting?status=submitted`) meanwhile.
- Virement, if approved, is same-FY / same-type / leaf-to-leaf, `active`-only, amount ≤ source `available`, SoD, single `budget_transfers` table. No generic JSON `budget_revisions` (close + supplemental covers it; prior revision code applied nothing — `0456`).

## Task List

### Phase 1: Access + SoD (fail fast, highest panel visibility)
- [ ] Task 1: Sidebar Budgeting entry + discoverability (XS/S) — DONE 2026-09-30, issue #191, commit 9f1c9f35
- [ ] Task 2: Finance-vs-VP SoD enforcement (S) — DONE 2026-09-30 as same-role guard in `approve()` (not grant strip; Finance keeps PR/PO acknowledgment), issue #192, commit 7b5220f8
- [ ] Task 3: Reject + draft-delete lifecycle (S/M) — DONE 2026-09-30, migration 0567 + service/controller/routes/resource + SPA ReasonDialog/Delete, issue #193

### Checkpoint: Access + SoD
- [ ] Focused tests pass (`BudgetConsumptionAndLifecycleTest`, `BudgetEnforcementBoundaryTest`, `ApprovalChainRolePermissionDriftTest`, Sidebar permission tests)
- [ ] Finance can create/submit but NOT approve own; VP can approve but NOT create; sidebar shows Budgeting to `budgeting.view` holders only
- [ ] Submitted budget can be returned to draft or deleted as draft; no strand
- [ ] Review with human before Phase 2

### Phase 2: Correctness guards (no schema redesign)
- [ ] Task 4: FY-active guard + duplicate/supplemental policy (S) — DONE 2026-09-30, service guards + tests, issue #194
- [ ] Task 5: Spender visibility without leak (M) — DONE 2026-09-30 as PR-detail budget_context (PO deferred: resource is supplier-portal-shared), issue #195
- [ ] Task 6: Stale refs + enforcement-mode docs (XS) — DONE 2026-09-30, issue #196
- [ ] Task 7: `budget_transfers` virement slice — request→approve→apply (M/L) — DONE 2026-09-30 backend (migration 0568 + service + routes + 4 tests) + frontend (list/request pages), issue #197
- [ ] Task 8: Board/search/badge integration (M) — DONE 2026-09-30 search groups + badges + sidebar count; board cards deferred (approval_records-driven), issue #198

### Checkpoint: Correctness
- [ ] Cannot allocate to draft/closed FY; duplicate policy documented + tested (unique or explicit supplemental flow)
- [ ] PR/PO creator sees actionable `available` without opening all budgets; no cross-department leak
- [ ] `transfers` wording gone; `warn` vs `block` behavior demoed
- [ ] Review with human before Phase 3

### Phase 3 (OPTIONAL, panel-gated): Virement + board/search
- [x] Task 7 done (see Phase 2 entry above)
- [x] Task 8 done (see Phase 2 entry above)

### Checkpoint: Complete
- [ ] Transfer honors ≤ source `available`, SoD, audit, `lockForUpdate`, HashIDs, money-strings
- [ ] Submitted transfers visible in approval queue + global search + sidebar badge
- [ ] Full suite green on dedicated DB; ready for panel rehearsal

Tasks tracked in GitHub Issues in `kwat0g/kwatog` (one issue per task above + checkpoint issues) — published 2026-09-30:

- #191 — Task 1: Sidebar entry (no blockers, frontier)
- #192 — Task 2: Finance/VP SoD (no blockers, frontier)
- #193 — Task 3: Reject + draft-delete (blocked by #192)
- #194 — Task 4: FY guard + duplicates (blocked by #193)
- #195 — Task 5: Spender visibility (blocked by #191, #194)
- #196 — Task 6: Stale/docs (no blockers, frontier)
- #197 — Task 7: Virement, panel-gated (blocked by #192, #193, #194)
- #198 — Task 8: Board/search/badge (blocked by #197)

Frontier (startable now): #191, #192, #196. Plan document stays here at `tasks/plan.md`.

## Risks and Mitigations
| Risk | Impact | Mitigation |
|------|--------|------------|
| Finance-approve strip breaks existing approved fixtures/demos | High | Task 2 migrates seed/demo approvers to VP + updates drift test; verify `GoldenPathDemoSeeder` + ADV9 rehearsal |
| Broad view grant leaks salaries-by-department | High | Task 5 does NOT grant raw view; scoped read or in-context `available` only, with visibility-matrix test |
| Monthly enforcement regresses WAC/consumption math | High | Phase 2 research-only; enforce annual until Phase 3 decision; run consumption + enforcement suites on dedicated DB |
| Board adapter reintroduces dead-code pattern (`0456/0459`) | Med | Task 8 is adapter over live `submit/approve`, not a parallel chain; registry + scope tests required |
| Two agents sharing `ogami_test` tear schema | Med | Each verification on dedicated DB (`ogami_test_budget_*`); never `ogami_test` concurrently |
| Virement scope creep (cross-FY, cross-type, partial-month) | Med | Constrain to same-FY/same-type/leaf-to-leaf/≤available; everything else = close + supplemental |

## Open Questions
- Checker final: VP-only approve, or Finance-lead + VP-override ≥ ₱ threshold (PR-style ₱50k)? Default in plan: VP-only.
- Duplicate budgets: hard unique `(fiscal_year, department, budget_type)` or allow explicit supplemental with reason? Default: document + guard, decide in Task 4.
- Monthly cap: display-only burn-down first, or hard block in Phase 3? Default: display-only until panel confirms.
- Spender view: in-PR/PO `available` snippet sufficient, or scoped `/budgeting` read for `department_head` own dept? Default: snippet first.

## Dependency Graph
```
FiscalYear status + Budget TRANSITIONS
  ├── Sidebar/nav + permissions (Task 1–2)
  │     └── Reject/delete (Task 3)
  ├── FY guard + duplicate policy (Task 4)
  │     └── Spender visibility (Task 5, needs Task 4 policy)
  ├── Stale/docs (Task 6, independent)
  └── Virement table/service/routes/UI (Task 7, needs 1–4)
        └── Board/search/badge (Task 8, needs 7)
```

## Parallelization
- Safe parallel: Task 1 (SPA nav) + Task 6 (docs/stale) after plan approval.
- Sequential: 2→3→4→5 (permissions → lifecycle → guards → visibility); 7→8.
- Needs contract first: Task 7 API shape before Task 8 board/search work.
