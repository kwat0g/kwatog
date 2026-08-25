# M042 — Material Issues & Reservations Fix Log

## 2026-08-25 re-audit

The prior report/plan were stale relative to later changes in the shared
worktree, so discovery, hardening, and polish were re-run. No production-code
fix was applied in this re-audit. The first ordered item requires a
process-owner decision between a final-at-create issue and an explicit
draft/pick/issue lifecycle; work-order issue ownership is also unresolved.

Refreshed artifacts:

- `audit-report.md` — current three-pass findings with file:line evidence.
- `action-plan.md` — ordered remediation plan with scope and session
  recommendations.

Pending before implementation:

- M042-F01 lifecycle decision.
- M042-F02–F04 reservation matching/order and work-order actual ownership.
- M042-F05–F15 hardening, API, permission, lot-policy, and SPA work.

## 2026-08-25 resumed plan session

The existing report and action plan were read in full after claiming M042.
Module source mtimes and the fix log show no M042 code changes after those
artifacts were written, so discovery was not repeated. No production-code fix
was applied: ordered item M042-F01 still requires the process owner to choose
between final-at-create issue semantics and an explicit draft/pick/issue
lifecycle. M042-F02–F15 remain pending because their implementation depends on
that lifecycle choice and the related work-order issue-ownership decision.

The module is released as `🔁 Needs Re-audit` so the next session can continue
after those decisions are supplied.

## 2026-08-25

No production-code fixes were applied. The module was claimed, audited, and
released as `📋 Plan Ready` because the majority of findings are inventory
state-machine, reservation, financial, idempotency, permission, or
cross-module changes that require a separate hardening session.

Audit artifacts written:

- `audit-report.md` — discovery, hardening, polish findings, and evidence.
- `action-plan.md` — ordered remediation items with scope and session
  recommendations.

Verification recorded in the audit report:

- Focused PHP suite: 30 tests reached setup but failed before assertions because
  PostgreSQL host `db` could not resolve.
- SPA `npm run typecheck`: passed.
- SPA `npm run audit:tokens`: passed (`769 files checked`).
- SPA `npm run audit:api-routes`: blocked because the API service was not
  running.

This is a plan handoff, not a claim that any finding is fixed. The next session
must implement the P0 reservation/lifecycle/idempotency controls, run the
focused tests against PostgreSQL, and then perform a fresh M042 re-audit.
