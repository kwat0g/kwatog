# M031 — Fixed Assets & Depreciation action plan

Audit date: 2026-08-27 · Re-audited 2026-08-30
Status: `🔁 Needs Re-audit`
Overall recommendation: `separate-recommended` for everything remaining

F01–F05, F13, F14, F17, F18 and F20 are implemented and verified by the backend
suite (33 tests / 145 assertions) plus the SPA suite (47 files / 297 tests).
F07–F10 are source-aligned but have no browser acceptance. Dependencies were
read for context only; F16 is owned by the shared Accounting decision #12 and is
not implemented here.

## Ordered work

| Order | Finding | Classification / priority | Scope | Session recommendation | Acceptance evidence |
|---:|---|---|---|---|---|
| 1 | M031-F15 disposal-month GL reconciliation | Broken / P0 | large | separate-recommended | **Blocked on a policy decision.** Pick and document one disposal-month convention; today both cron-before-disposal and disposal-before-cron leave the contra account non-zero or report a different loss. Then assert the contra account nets to zero for a disposed asset in both operation orders. |
| 2 | M031-F16 journal maker attribution | Incomplete / P1 | medium | separate-recommended | Accounting decision #12 records actor semantics and automated asset JEs preserve auditable maker/source metadata; run cross-module regression. |
| 3 | M031-F06 transfer surface | Missing / P1 | large | separate-recommended | Record retire-vs-restore decision; only then remove dead code or restore guarded API/SPA routes, role checks, and browser coverage. |
| 4 | M031-F11 acquisition/maintenance linkage | Missing / P2 | large | separate-recommended | Record register-only boundary or implement one auditable asset/source/custodian/maintenance ownership timeline. |
| 5 | M031-F07/F08/F09/F10 browser acceptance | Incomplete / Broken / Polish | medium | separate-recommended | A Playwright spec for `/assets*` covering the role matrix, permission gates, field contract, detail QR/download, January default, and actionable API errors. Chromium required — these are layout and rendering assertions. |
| 6 | M031-F19 QR label-sheet workflow | Missing / P2 | medium | separate-recommended | Reconcile `docs/USER-MANUAL.md:251-254` with the product, or implement permission-gated multi-row selection and printable QR labels. |

## Gate decision (2026-08-30)

Everything remaining is `separate-recommended`, and two of the six items are
blocked on decisions that are not an auditor's to take. The two contained items
on the list were fixed this session instead (F18, F20 — see `fix-log.md`), so the
module advances without touching a gated item. Status stays `🔁 Needs Re-audit`.

The next session on M031 should not open code first. It should get answers to the
two questions at the end of `audit-report.md` — the F15 disposal-month policy
above all — because F15 is the only P0 left and every candidate fix moves a
reported peso figure.

## Already verified — no action in this plan

- F01 Money/centavo arithmetic: exact per-asset-to-JE reconciliation and
  full-life exhaustion measured on PostgreSQL.
- F02 period ordering/idempotency and F03 lifecycle locks: module race and
  backfill tests pass.
- F04/F13 disposal contract and zero-proceeds journals: disposal tests pass.
- F05 transfer service guards and F14 HashID history filter: focused tests pass.
- F17 restore binding: `routes.php:21-23` opts into trashed binding;
  `AssetRestoreRouteTest` covers the hashed and unauthorized calls.
- F18 salvage bound: rejected at both FormRequests, the service, and both SPA
  forms; `AssetSalvageValueBoundTest` + `salvageBound.test.ts`.
- F20 detail eager load: one journal query regardless of history length;
  `AssetDetailEagerLoadTest` pins the count.
