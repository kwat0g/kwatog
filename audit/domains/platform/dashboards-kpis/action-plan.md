# M007 — Dashboards & KPIs action plan

Plan status: 📋 Plan Ready
Audit date: 2026-08-27
Claim: `platform / dashboards-kpis` (M007)
Decision: no production fixes in this session; the gate requires a separate hardening slice.

The current branch fixes and verifies M007-01 through M007-12, subject to the noted
formula and browser/SPA verification follow-ups. The actions below address the
remaining findings and the residual formula decision.

## Ordered remediation plan

| Order | Finding(s) | Action | Scope | Session recommendation | Acceptance evidence |
| --- | --- | --- | --- | --- | --- |
| 1 | M007-15 | Apply the active-definition boundary to individual/batch trend reads, rich KPI analytics, and scalar KPI widgets. Add inactive-definition access/render regressions. | medium | separate-recommended | Inactive definitions are absent from scorecard, trends, rich layouts, and scalar data; authorized active definitions still work. |
| 2 | M007-16 | Validate KPI year/month at the HTTP and command boundaries; reject unsupported periods before Carbon normalization or snapshot persistence. | medium | separate-recommended | Invalid months/years return a validation error; no period outside the supported range can be persisted; valid year-boundary compute remains green. |
| 3 | M007-13 | Have Production/PPC choose due-cohort versus completion-throughput semantics, then implement the formula and name/definition together. | medium | separate-recommended | Fixture covers late, early, and same-month completion; result cannot exceed 100% unless the approved contract explicitly permits it. |
| 4 | M007-14 | Decide the soft-delete policy for work-order KPIs and apply it consistently to denominator/numerator queries. | medium | separate-recommended | Soft-deleted work-order fixture proves the approved inclusion rule; related calculator policy is documented. |
| 5 | M007-19 | Replace dashboard monetary float casts with decimal-string/Money/BCMath handling across API aggregates, KPI inputs, and SPA formatting. | large | separate-recommended | High-precision decimal fixtures match accounting service output byte-for-byte to two cents; API and SPA preserve strings. |
| 6 | M007-17 | Include `draft` and `in_progress` consistently in the dedicated QC KPI/queue, or document a deliberate narrower contract and align generic widget/badge links. | small | same-session-ok | Draft and in-progress fixtures appear consistently in KPI, queue, badge, and drill-down behavior. |
| 7 | M007-21 | Restrict generic upcoming payables to `BillStatus::Unpaid` and `BillStatus::Partial`, matching the finance panel. | small | same-session-ok | Draft, paid, cancelled, unpaid, and partial fixtures produce the same payable population in both surfaces. |
| 8 | M007-18 | Scope the HR queue to the current approver/actor where supported, or rename it and apply an HR-wide permission contract. | medium | separate-recommended | UI label, query population, permission, and target worklists all agree; a non-owner cannot see an incorrectly labelled personal queue. |
| 9 | M007-20 | Define snapshot immutability versus recomputation; clear or mark a same-period snapshot stale when a rerun returns no data if recomputation is approved. | medium | separate-recommended | A data-present run followed by a no-data rerun cannot silently present the old value as current. |
| 10 | M007-01 residual | Obtain inventory-owner sign-off on current-ending versus average inventory denominator and retain the approved formula beside the calculator. | medium | separate-recommended | Reviewed fixture and documented formula agree with the inventory KPI definition. |
| 11 | M007-22 | Derive scorecard loading placeholders from the configured KPI catalog instead of hard-coding eight. | small | same-session-ok | Loading state matches the 11-definition catalog and adapts when definitions change. |

## Verification gate

1. Run `tests/Feature/Dashboard` plus `tests/Feature/Admin/DashboardLayoutTest.php`
   with `DB_DATABASE=ogami_test_m007_roll_a` or another uniquely assigned database.
2. Add and run focused regression tests for M007-13 through M007-21; never use
   `ogami_test`.
3. Run the seeded monthly KPI command for a populated and a no-data month, capturing
   computed/no_data/failed counts and exit status.
4. Run PHP syntax, `git diff --check`, SPA typecheck, SPA KPI unit tests, build, and
   route/link audits.
5. Run authenticated browser checks for admin, production, PPC, HR, finance,
   purchasing, warehouse, QC, and default dashboards at desktop and mobile widths.
6. Review denied-panel query execution and inactive-KPI responses with query logs or
   focused mocks.
7. Only after all open findings and environment-blocked checks are closed should the
   coordinator regenerate the registry and promote M007 to `✅ Verified`.

## Gate decision for this claim

Only three actions are small and `same-session-ok`; the majority are medium/large and
`separate-recommended`, including authorization, metric-contract, and money work.
Therefore no production action is eligible under the same-session gate. Release as
`📋 Plan Ready`.
