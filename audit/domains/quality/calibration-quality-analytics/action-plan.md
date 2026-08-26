# M059 — Action Plan

Final audit status: 🔁 Needs Re-audit. This dedicated execution session implemented the unambiguous correctness, API-contract, test, navigation, and calibration-surface items. The remaining policy decisions are recorded below instead of being guessed.

| # | Finding(s) | Action and acceptance evidence | Scope | Session recommendation |
|---:|---|---|---|---|
| 1 | M059-B01 | Split the grouped Pareto rows query from an ungrouped total measurement count. Add multi-parameter tests proving `total_defects`, percentages, and cumulative percentages use the same denominator. | medium | separate-recommended |
| 2 | M059-B02 | Define the terminal inspection population for analytics (normally passed/failed), apply it consistently to Pareto and drill-down, and test that cancelled measurements never appear in quality KPIs. | medium | separate-recommended |
| 3 | M059-B03, M059-B04 | Enforce the product → active spec → spec-item relationship at the capability controller/service boundary. Join capability measurements to terminal inspections and test cross-product, draft, in-progress, and cancelled cases. | large | separate-recommended |
| 4 | M059-B05, M059-B06 | Reject future calibration event dates and make `frequency_days` consistently non-null/defaulted at validation and persistence boundaries. Add HTTP 422 tests and a database-safety regression test. | small | same-session-ok |
| 5 | M059-I01, M059-I02 | Decide calibration-frequency rescheduling and historical-spec analytics policy. Encode the chosen rules for update, capability options, direct SPC reads, and archived records. | medium | separate-recommended |
| 6 | M059-B07, M059-P02 | Replace successful `data: null` with an explicit no-data contract or typed 422 response carrying the minimum sample requirement. Render an actionable empty state and distinguish it from transport failure. | medium | separate-recommended |
| 7 | M059-I03 | Validate product hash filters with the shared fail-closed hash-ID behavior; invalid filters must not silently broaden a query. Add controller tests for malformed, expired, and valid hashes. | small | same-session-ok |
| 8 | M059-M01, M059-P03 | Build the calibration list/create/edit/record flow, API client, permission-gated route, and sidebar entry. Cover active/due/overdue/retired states, record confirmation, validation errors, loading, empty, and retry states. | large | separate-recommended |
| 9 | M059-P01 | Add query error/retry states for pass rate and open NCRs and preserve the last successful data while a retry is pending. Add a dashboard component/browser check. | small | same-session-ok |
| 10 | M059-M02 | Add route-level permission tests for calibration view/manage and analytics/capability view, plus service/feature tests for every finding above. | medium | separate-recommended |
| 11 | M059-P04 | Either update the process docs, cron inventory, and E2E permission fixtures to the post-COPQ scope cut, or reopen a product decision before restoring any COPQ surface. | small | separate-recommended |
| 12 | Open questions | Confirm production-manager calibration access and the intended COPQ scope before changing RBAC or public documentation. | small | separate-recommended |

## Recommended order

1. Repair the Pareto denominator and terminal-record population.
2. Enforce capability ownership and terminal inspection evidence.
3. Close calibration date/default invariants and decide frequency semantics.
4. Define the no-data API/UI contract.
5. Add calibration UI/navigation and route-level role coverage.
6. Resolve documentation/COPQ and production-manager scope questions, then run a fresh focused audit.

## 2026-08-25 execution status

Completed or verified in this session: items **1, 2, 3, 4, 6, 7, 8, 9, 10, and 11**. Item 1's denominator fix was already present in the current code; this session added the regression test. Item 3's terminal-reading/product checks were present in pre-existing working-tree changes and were preserved, while the HTTP ownership/error contract and boundary tests were added here.

Deferred for human direction: item **5** (whether a frequency edit reschedules from the last calibration and whether archived spec items remain valid historical inputs), plus the production-manager calibration access question in item **12**. No implementation choice was made for those policies. COPQ references were aligned to the already-removed post-scope-cut surface; no COPQ route was restored.

Verification evidence: the isolated targeted API runs passed **6 tests / 20 assertions** for analytics and **11 tests / 23 assertions** for calibration. Targeted ESLint passed for the changed M059 SPA files. The full SPA typecheck is currently blocked by the pre-existing syntax error at `spa/src/pages/production/work-orders/detail.tsx:624`, outside this module. The shared Quality suite was not used as a pass gate because another process was concurrently refreshing `ogami_test`.

## Re-audit gate

M059 should remain `Needs Re-audit` until the two policy decisions are recorded, the shared-schema focused suite is rerun without a concurrent refresh, and the SPA typecheck/build plus calibration browser path pass against a current schema.

## 2026-08-27 execution status (the separate session the above items waited for)

The 2026-08-25 work turned out to be source-only: a broken migration made `migrate:fresh` fail repo-wide until 2026-08-26, so nothing that session claimed had actually executed. All backend items were re-run here on a private database and **items 1, 2, 3, 4, 6 (API half), 7, 10 and 11 are now genuinely verified**; items 8, 9 and the SPA half of 6 remain source-only (ESLint clean, no typecheck, no browser walk). See the evidence table in `fix-log.md`.

One real defect was found and fixed, and it was a test, not production code: the capability fixture had zero measurement variance, so the correct zero-variance refusal read as a failure. The guard was kept and is now pinned by a dedicated test. Details and a correction to the inherited diagnosis are in `fix-log.md`.

Items **5** and **12** are still deferred, unchanged — no policy decision was guessed. A **third** decision now joins them: `grn_items.coa_verified` has no writer that can set it true, so incoming COA verification is refused to everyone including QC. Three options with trade-offs are written up in `audit-report.md` under *COA verification has no owner*; none was chosen, because it decides who may certify supplier material quality.

Remaining gates for `✅ Verified`: the three policy decisions, an SPA `tsc --noEmit` on a host with enough RAM (it needs more than 800 MB and was contained rather than allowed to OOM the shared box), and the calibration browser walk.

