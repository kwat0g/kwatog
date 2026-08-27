# M031 — Fixed Assets & Depreciation action plan

Audit date: 2026-08-24  
Status: `📋 Plan Ready`  
Overall recommendation: `separate-recommended`

## Ordered work

| Priority | Finding | Scope | Session recommendation | Deliverable / acceptance evidence |
|---|---|---|---|---|
| P0 | M031-F01 Money/centavo arithmetic | large | separate-recommended | Asset calculations use integer centavos or `Money`; per-asset rounded rows reconcile exactly to the consolidated JE; boundary and large-value tests pass. |
| P0 | M031-F02 depreciation period policy/order | large | separate-recommended | Future/out-of-order/invalid backfills follow an explicit policy; acquisition/disposal eligibility is correct; period-level journal identity and retry behavior are proven. |
| P1 | M031-F03 lifecycle locking and financial immutability | large | separate-recommended | Delete, dispose, depreciation, and schedule edits serialize on the asset row; posted history cannot be hidden or silently recalculated; two-connection races pass. |
| P1 | M031-F04 disposal date/reason/audit contract | medium | separate-recommended | Date bounds, closed-period behavior, proceeds, reason, journal reference, and audit record are validated and tested. |
| P1 | M031-F05 transfer custody race and status guards | medium | separate-recommended | Approval locks/rechecks the asset, rejects stale source/disposed assets, prevents overlapping pending transfers, and passes concurrent tests. |
| P1 | M031-F06 transfer scope decision and live surface | large | separate-recommended | Product decision is recorded: either remove dead transfer code/permissions or restore guarded API, SPA routes, role checks, and browser coverage. |
| P2 | M031-F07 permission gate alignment | small | separate-recommended | Edit and depreciation actions use the exact server permission; view-only/custom-role matrix tests prove 403/visible behavior. |
| P2 | M031-F08 API/UI field contract | medium | separate-recommended | Mutable fields are explicit; insurance/depreciation fields, department preservation, journal identifiers, and TypeScript fixtures agree end-to-end. |
| P2 | M031-F11 acquisition/maintenance linkage decision | large | separate-recommended | If in scope, one auditable asset/source/custodian/maintenance timeline is implemented; otherwise the boundary and dead fields are removed/documented. |
| P2 | M031-F09 QR rendering | small | same-session-ok after semantic fixes | The detail page renders a real QR and downloads a valid image; browser test passes. |
| P3 | M031-F10 January/recovery UI polish | small | same-session-ok | Previous-month boundary works in January; validation/closed-period errors are actionable; browser/API cases pass. |

## Suggested implementation sequence

1. Decide the accounting policy for centavos, acquisition-month/disposal-month
   treatment, backfills, declining balance, and period closure.
2. Implement F01 and F02 together, because rounding and period ordering define
   the authoritative depreciation rows and consolidated journal.
3. Harden F03/F04 with locked asset lifecycle transitions and PostgreSQL
   two-connection tests.
4. Decide F06/F11 before touching transfer or maintenance integration; then
   implement F05 and the chosen cross-module surface.
5. Align F07/F08 and repair F09/F10, followed by role-matrix, browser, migration,
   and GL reconciliation checks.

## Post-execution addendum — 2026-08-27

The 2026-08-24/25 sessions wrote F01–F05 and F07–F10 but never ran them
(`migrate:fresh` was broken repo-wide). This session executed the module for the
first time. Full detail and evidence in `fix-log.md`.

- **Closed by execution:** F01, F02, F03, F05 verified; F04 verified and extended.
- **New, fixed:** F13 — every zero-proceeds disposal (scrapping) was impossible,
  because `dispose()` emitted zero-amount journal lines. F14 — the depreciation
  history filter cast a HashID to `int`, answering an empty page.
- **Still source-only:** F07, F09, F10 and the SPA half of F08 — no SPA tooling can
  run while `spa/node_modules` is root-owned.
- **Open decisions, in priority order for the next session:**
  1. `D-M031-1` (P0, money) — a mid-month disposal leaves the accumulated
     depreciation contra-account non-zero for a removed asset, and the reported
     loss differs by one month's depreciation depending on whether the operator
     disposes before or after the monthly cron. Three costed options; measured
     numbers in `fix-log.md`.
  2. `D-M031-2` — depreciation and disposal journals lose `created_by`. This is
     decision #12 in `audit/OVERNIGHT-2026-08-27.md`; do not fix it here in
     isolation.
  3. F06, F11 — unchanged product-scope decisions.

## Audit-session decision — 2026-08-24

No implementation fix is applied in this session. The majority of findings are
financial, state-machine/lifecycle, permission, or cross-module changes. A
small UI-only fix would leave the ledger and custody invariants unresolved and
could create a second API contract, so M031 is released as `📋 Plan Ready`.
