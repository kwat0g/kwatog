# M007 — Dashboards & KPIs action plan

Plan status: Plan Ready  
Audit date: 2026-08-24  
Decision: no production fixes in the audit session; execute as a separate hardening slice

The module has more medium/large and security/correctness findings than small polish findings. Keep the audit claim released as Plan Ready until the P0/P1 items below have evidence.

## Ordered remediation plan

| Order | Finding(s) | Action | Scope | Session recommendation | Acceptance evidence |
| --- | --- | --- | --- | --- | --- |
| 1 | M007-01 | Rework computeInventoryTurnover against the inventory ledger contract. Use movement_type and the intended issue movement set, value stock with weighted_avg_cost, and document whether the denominator is current, opening/closing, or period-average inventory. | Large | Separate recommended | Seeded monthly compute runs without SQL errors; fixture with receipts/issues produces a reviewed turnover value; no-data case remains null; command is green. |
| 2 | M007-02 | Replace balance_due with the authoritative invoice balance and define the eligible AR statuses/date snapshot policy. | Medium | Separate recommended | AR aging fixture with finalized, partial, paid, cancelled, and draft invoices returns the expected percentage; seeded monthly compute is green. |
| 3 | M007-03 | Move every sensitive query into the closure guarded by its exact permission. At minimum refactor FinanceDashboardService, HR, PPC/accounting, Purchasing, Warehouse, Quality, and Admin KPI precomputation. | Large | Separate recommended | Query-log or mocked repository tests prove unauthorized invoice/bill/journal/payroll/employee queries are not executed; authorized payload tests remain green; cache keys still distinguish gate answers. |
| 4 | M007-04 | Replace quality: prefix authorization with source-aware parsing: quality:inspection requires quality.inspections.view or quality.view; quality:ncr requires quality.ncr.view or quality.view. Add tests for both directions and stale/guessed keys. | Medium | Separate recommended | NCR-only user receives 403 for an inspection key; inspection-only user receives 403 for an NCR key; broad quality.view can operate both. |
| 5 | M007-05 | Align warehouse KPIs to the inventory enums and workflow tables: choose the intended GRN pending states, use material_issue for posted stock movements, and count transfer_orders.status=pending for pending transfers. | Medium | Separate recommended | Fixtures for pending_qc GRNs, material issues, and pending transfer orders produce non-zero expected counts; completed/cancelled rows are excluded; dedicated and generic widget semantics agree. |
| 6 | M007-06 | Establish metric contracts for dashboard headline values: exclude draft/cancelled invoices from plant revenue, calculate pass rate from completed outcomes, and count CoCs from delivery_proofs or an authoritative passed-outgoing-inspection source. | Medium | Separate recommended | Fixture tests assert each label’s denominator/source; metric definitions are documented beside code; values match the equivalent widget/API contract. |
| 7 | M007-07 | Replace the dead CoC link with a real permitted worklist route, or add the missing certificate route if a certificate list is required. Make the route decision part of the CoC metric fix. | Small | Same-session possible after M007-06 | Authenticated click path resolves to a registered route and the route permission matches the metric’s source. |
| 8 | M007-08 | Select the latest supplier snapshot period and count distinct vendor_id below the review threshold. Reuse the same latest-period boundary as the supplier panel. | Medium | Separate recommended | Two historical low snapshots for one vendor count as one current review; latest-period fixtures cover null scores and threshold equality. |
| 9 | M007-09 | Replace the hard-coded six-month display with the configured probation period value used by the query. | Small | Same-session possible | Changing hr.probation.period_months changes both membership and displayed probation_end. |
| 10 | M007-10 | Extend the process test to seed KpiDefinitionSeeder (and required dashboard settings) and run computeAll for a representative month. Keep the synthetic failure test as a separate isolation test. | Medium | Separate recommended | The test fails on either current schema defect; after fixes it asserts every seeded definition is computed/no_data and the command exit code is 0. |
| 11 | M007-11 | Make first-login layout cloning concurrency-safe with a transaction lock or a database uniqueness strategy for user/widget placement, then reconcile any duplicate rows before enforcing a constraint. | Medium | Separate recommended | Concurrent clone regression test leaves one row per user/widget; sequential idempotency and user save/reset tests remain green. |
| 12 | M007-12 | Batch KPI trend data or return trend points with the scorecard response; retain permission filtering and the 24-month server clamp. | Medium | Separate recommended | Browser/network test shows one scorecard request (or bounded batch) rather than one trend request per card; cards still render empty/no-data states. |

## Verification gate after remediation

1. Run the full dashboard feature suite and the related inventory, accounting, quality, HR, and supply-chain fixture suites.
2. Run the seeded monthly KPI command for a month with data and a no-data month; capture computed/no_data/failed output and exit status.
3. Run PHP syntax/lint and SPA typecheck, unit tests, build, and route/link audits.
4. Run authenticated browser checks for admin, production, PPC, HR, finance, purchasing, warehouse, QC, and default dashboards at desktop and mobile widths.
5. Review query logs or mocked data repositories for denied-panel non-execution.
6. Only then regenerate the registry and move M007 to Verified; otherwise leave Plan Ready with the remaining finding IDs.

## Risk notes

- M007-01 and M007-02 are not safe one-line renames without confirming KPI definitions and accounting/inventory semantics.
- M007-03 must preserve response-level cache isolation while changing query timing.
- M007-04 changes an authorization boundary and needs negative tests before deployment.
- M007-11 may require a migration and duplicate-data cleanup; do not add a uniqueness constraint blindly.
