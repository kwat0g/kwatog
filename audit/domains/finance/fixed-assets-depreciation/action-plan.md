# M031 — Fixed Assets & Depreciation action plan

Audit date: 2026-08-27
Status: `📋 Plan Ready`  
Overall recommendation: `separate-recommended`

The existing F01–F05, F13, and F14 implementation is verified by the current
backend suite. F07–F10 are source-aligned but lack asset-specific browser
acceptance. Dependencies were read for context only; F16 is explicitly owned
by the shared Accounting decision and is not implemented here.

## Ordered work

| Order | Finding | Classification / priority | Scope | Session recommendation | Acceptance evidence |
|---:|---|---|---|---|---|
| 1 | M031-F15 disposal-month GL reconciliation | Broken / P0 | large | separate-recommended | Choose and document one disposal-month policy; both cron-before-disposal and disposal-before-cron leave the contra account at zero and report the same loss. |
| 2 | M031-F18 salvage bound | Broken / P1 | medium | separate-recommended | Create/update rejects salvage greater than acquisition cost; zero, equal, and post-history cases are tested. |
| 3 | M031-F16 journal maker attribution | Incomplete / P1 | medium | separate-recommended | Accounting decision #12 records actor semantics and automated asset JEs preserve auditable maker/source metadata; run cross-module regression. |
| 4 | M031-F17 restore binding | Broken / P1 | small | same-session-ok | `PATCH /assets/{hash}/restore` binds a trashed asset and restores it; HTTP regression covers hashed and unauthorized calls. |
| 5 | M031-F06 transfer surface | Missing / P1 | large | separate-recommended | Record retire-vs-restore decision; only then remove dead code or restore guarded API/SPA routes, role checks, and browser coverage. |
| 6 | M031-F11 acquisition/maintenance linkage | Missing / P2 | large | separate-recommended | Record register-only boundary or implement one auditable asset/source/custodian/maintenance ownership timeline. |
| 7 | M031-F07/F08/F09/F10 browser acceptance | Incomplete / Broken / Polish | medium | separate-recommended | Add role-matrix and browser checks for permission gates, field contract, detail QR/download, January default, and actionable API errors. |
| 8 | M031-F19 QR label-sheet workflow | Missing / P2 | medium | separate-recommended | Reconcile the user manual with the product or implement permission-gated multi-row selection and printable QR labels. |

## Gate decision

Only F17 is `same-session-ok`; the remaining work is financial, cross-module,
product-scope, or browser-acceptance work, and the total scope is not small.
No production fix is applied in this session. The next implementation session
should start with the F15 accounting policy and F18 validation, then take the
shared F16 decision before any transfer/maintenance surface work.

## Already verified — no action in this plan

- F01 Money/centavo arithmetic: exact per-asset-to-JE reconciliation and
  full-life exhaustion measured on PostgreSQL.
- F02 period ordering/idempotency and F03 lifecycle locks: module race and
  backfill tests pass.
- F04/F13 disposal contract and zero-proceeds journals: disposal tests pass.
- F05 transfer service guards and F14 HashID history filter: focused tests pass.
