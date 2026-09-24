# Full Re-Audit Register - 2026-09-19

This is a fresh audit of the current repository. The 2026-09-18 reports, including
`FINISHED`, `PARTIAL`, and `MISSING-TESTS` files, were treated as historical evidence only.

## Artifact update

Every Markdown artifact in this directory now has a current re-audit section at its top. This
file remains the canonical cross-module register; the per-file sections are intentionally concise
and point back here for complete current evidence.

## Method

- Deterministic source, migrations, routes, seeders, current tests, and current artifact paths
  were checked module by module.
- Every tracker row was reassessed. No module received an exemption because its prior report
  said `FINISHED`.
- Current tests were inspected; the re-audit agents did not claim a test passed unless it was
  already documented as current evidence. A full suite was not rerun during this re-audit.

Evidence priority: current code and executable regression tests outrank historical report labels;
deployed settings, queues, schedulers, migrations, providers, and
business-policy intent remain untestable without the relevant runtime or owner decision.

## Artifact Re-Audit

| Artifact | Current verdict |
|---|---|
| `FINISHED-SALES-ORDER-CHAIN-TRACE-2026-09-18.md` | **FINISHED (2026-09-23).** All current-risk items closed or dispositioned: cancelled SO retires linked draft/pending MRP auto-PRs; MRP scope is undelivered quantity so invoiced partial SOs replan; confirm reports `planning_status=failed` with the run error instead of `queued`; Delivered chain tile derives from coverage; invoice finalize refuses a second invoice consuming an already-invoiced delivery; CoC durable recovery and no-spec outgoing QC fail-closed verified current. §7 items are dispositioned in the report (7 fixed/stale, 6 by-design or tracked in their owning module). |
| `PURCHASE-REQUEST-CHAIN-TRACE-2026-09-18-FINISHED.md` | FINISHED (remediated). Cancelled PO/draft GRN purge, partial quantity conversion, posted partial-bill continuation, fractional/archived incoming-QC handling, manual PO coverage, PO submission ownership, and accepted-GRN GL retry are fixed and focused-tested. Production migration preflight and a GRN-GL retry UI affordance remain operational/polish follow-up. |
| `HIRE-TO-RETIRE-PAYROLL-TRACE-2026-09-18-FINISHED.md` | Payroll, Attendance, Leave, and Loans remediation is current-tested. SSS R-3 now rejects non-finalized periods; input freezing, eligibility, anomaly serialization, effective-table, 13th-month date, statutory, Finance-controlled loan write-off, disbursement, and repayment mappings are present. Manual-OT/break/maternity policy, statutory certification, and live provider proof remain. |
| `ACCOUNTING-CORE-AUDIT-2026-09-18-FINISHED.md` | **FINISHED (current worktree).** Credit-note voiding, account-policy collision rejection, fiscal-year lifecycle/overlap controls, effective-date numbering, canonical five-bucket statement aging, update authorization, audit hooks, sync identifier hardening, budget-scope serialization, dead-path removal, and stale budget reference cleanup are implemented. Year-end retained earnings, multi-currency translation, company-wide/cross-module budget semantics, service-bill SOD, and historical account archiving remain explicit policy/scope dispositions. Focused test execution is blocked by an unrelated duplicate import in Maintenance; modified Accounting files pass PHP syntax checks. |
| `INVENTORY-RETURNS-AUDIT-2026-09-18-FINISHED.md` | **FINISHED (2026-09-23).** Inventory location/count/decimal/QC/lot/idempotency controls and Return Management source, approval, VAT, NCR, bill, and MRB traceability boundaries are remediated or explicitly dispositioned. Focused Inventory/RMA/Quality regressions passed (239 tests, 888 assertions). Full-suite attempts were blocked by PostgreSQL migration/schema deadlocks and duplicate/missing-table errors; one run also failed an existing `CarbonDiffSignConventionTest` in `WoOperationService`, so the full suite is not claimed green. Single-screen fractional receiving and fully-paid supplier-credit settlement remain documented policy workflows. |
| `QUALITY-AUDIT-2026-09-18-FINISHED.md` | FINISHED for engineering scope. AQL/unit counting, declared samples, no-spec fail-closed behavior, calibration linkage, full-sampling bounds, and high-risk incoming/outgoing maker-checker review are fixed and focused-tested. Stored-but-unused legacy fields remain cleanup follow-up. |
| `PRODUCTION-AUDIT-2026-09-18-FINISHED.md` | Remediated (2026-09-23). Breakdown MWO, summary SQL, operation windows, machine/mold guards, schedule constraints, resume-after-breakdown, machine ownership, material-lot truth, operation ledger limits, downtime interval math, dashboard invalidation, authorization, notification filtering, and audit coverage are implemented and tested. Only dead/broadcast-only paths and Pareto duplication remain. |
| `MRP-AUDIT-2026-09-18-FINISHED.md` | Remediated for current engineering scope. BOM version/numeric validation, PO in-transit UOM, manual/daily overlap, plan history, missing-BOM status/alerting, MOQ precision, and direct-service validation are fixed and focused-tested. Redis/two-worker behavior and production migration preflight remain unproven. |
| `MRP-AUDIT-2026-09-18-MISSING-TESTS.md` | Focused MRP boundaries, read-only migration preflight, and Redis two-worker overlap smoke are verified. Full-suite triage, production-database migration execution, and deployed worker recovery remain release evidence. |
| `SUPPLYCHAIN-AUDIT-2026-09-18-FINISHED.md` | Remediated. Landed-cost capitalization, shipment-to-GRN, customs evidence, immutability, document deduplication, delivery stock decrement, deletion guards, driver booking, CoC recovery, lot provenance, manual idempotency, and Asset-to-fleet association are verified. Secondary container/restore UI and B2B shipment cross-reference remain non-blocking. |
| `CRM-AUDIT-2026-09-18.md` | Non-SO CRM engineering findings remediated. Product/price ownership, revenue-account HashID reachability, inquiry feature gating/audit/terminal status, price fallback, case-insensitive product identity, and archive semantics are fixed and focused-tested. Product-form account selection, Product-to-Item identity, unused legacy schema, and CRM SPA coverage remain. |
| `HR-AUDIT-2026-09-18-FINISHED.md` | **FINISHED (2026-09-23).** Direct bank edits, review-action scope, profile validation/redaction, salary basis/history, archive restore, recruitment conversion, document retention, training chronology, skill metadata, boolean filters, position direction, salary approval attribution, and dead HR surfaces are remediated or explicitly dispositioned. |
| `ATTENDANCE-LEAVE-LOANS-AUDIT-2026-09-18-FINISHED.md` | Current remediation covers raw-punch routing, payroll recovery, OT ceilings/overnight and stale recalculation, shift/holiday recomputation, cross-year leave allocations, private documents, negative balances, holiday semantics, loan replay/visibility/workflow migration, Finance-controlled write-off, disbursement/manual-repayment GL, and payroll mutation guards. Manual OT duplicate prevention, break-interval/rest policy, maternity entitlement policy, and live provider proof remain. Loan evidence uses a controlled Finance reference string by policy. |
| `FORECASTING-ASSETS-AUDIT-2026-09-18-FINISHED.md` | **FINISHED (2026-09-23).** Forecast generation is scheduled; invalid HashIDs fail closed; total/customer accuracy scopes are authoritative; reconciliation is transactionally rerunnable; decimal projection paths and BOM failure visibility are hardened. Assets have authorized machine/mold/vehicle association, truthful maintenance status, retired transfer routes/permissions, corrected QR contract, deployed disposal-workflow migration, HashID depreciation responses, aligned scheduler mutex, and durable failure outcomes. Forecast-driven MRP remains advisory by explicit product-policy disposition. |
| `B2B-MAINTENANCE-AUDIT-2026-09-18-FINISHED.md` | **FINISHED (2026-09-23).** Portal password expiry, parent-state and invitation audit, customer token revoke, reset pruning, supplier RFQ document download, authenticated write throttles, customer response/RMA SPA, timed-expiry routing, and customer-order idempotency are implemented. Maintenance predictive de-duplication/freshness, ledger-derived downtime, runtime interval accounting, due-widget visibility, breakdown notification routing, polymorphic target integrity, and reading audit are implemented or explicitly scope-dispositioned. See the finished report for evidence. |
| `PLATFORM-CROSS-CUTTING-AUDIT-2026-09-18-FINISHED.md` | **FINISHED (2026-09-23).** Approval escalation health, bottleneck alert deduplication, recovery audit subjects, approval-board pagination, business-policy authorization, document sequence first-use, outbox version handling, vault checksum verification, notification idempotency, dashboard badge scopes/failure signalling, supplier password expiry, auth timing/current-password/history controls, landing feature/PII controls, and Edge reference cleanup are remediated. Nginx PDF-preview framing, annual budget KPI semantics, the `/auth/login` compatibility alias, delegated-approval API expansion, and 2FA remain explicit policy/compatibility dispositions. |
| `FINDINGS-REGISTER-2026-09-18.md` | Historical overlay is stale in multiple locations: it says P0-07 has zero MWO references although current code creates one, and P0-08 still describes row-count AQL although current code counts sample units. Use this re-audit register for current status. |
| `MODULE-AUDIT-TRACKER-2026-09-18.md` | The tracker now points Accounting at its `-FINISHED` artifact; historical status vocabulary and any remaining legacy links should not override the current per-artifact evidence. |
## Current Module Matrix

| # | Module/feature | Re-audit result | Highest current residual risk |
|---:|---|---|---|
| 1 | CRM Sales Order | **FINISHED** (2026-09-23) — all SO-chain gaps closed or dispositioned | none tracked here; see `FINISHED-SALES-ORDER-CHAIN-TRACE-2026-09-18.md` §7 table |
| 2 | Purchasing PR/P2P | FINISHED (remediated) | migration preflight; GRN-GL retry UI; operational proof |
| 3 | Payroll/H2R | Remediated for current engineering scope | Finance/statutory reporting policy and live filing/provider proof |
| 4 | Accounting core | FINISHED for current engineering scope | credit-note void, policy collisions, fiscal-year overlap/lifecycle, effective-date numbering, canonical statement aging, audit hooks, and wired budget serialization are closed; explicit owner-policy dispositions remain |
| 5 | Budgeting | FINISHED for current engineering scope | company-wide/cross-module spend policy and owner decisions |
| 6 | Inventory | **FINISHED** (2026-09-23) | single-screen fractional receiving remains line-level-QC only; cleanup debt is non-blocking |
| 7 | Quality | FINISHED (remediated) | stored-but-unused legacy code |
| 8 | Production | Remediated (2026-09-23) | dead/broadcast-only paths; three Pareto implementations |
| 9 | MRP/BOM | Remediated for current engineering scope | deployed worker recovery and production migration execution |
| 10 | SupplyChain | Remediated | secondary SPA container/restore UI; B2B shipment cross-reference |
| 11 | CRM remaining | Remediated for current engineering scope | product-form revenue account selector; Product-to-Item identity; SPA coverage |
| 12 | HR | **FINISHED (2026-09-23)** | policy dispositions are recorded in `HR-AUDIT-2026-09-18-FINISHED.md` |
| 13 | Attendance | Remediated for current engineering scope | manual OT duplicates; break-interval/rest policy; live biometric-provider proof |
| 14 | Leave | Remediated for current engineering scope | maternity entitlement policy and live SPA/browser breadth |
| 15 | Loans | Remediated for current engineering scope | live accounting/provider proof |
| 16 | Return Management | **FINISHED** (2026-09-23) | fully-paid supplier-credit settlement is an explicit Finance refund/offset workflow; broad row visibility is policy |
| 17 | Forecasting | **FINISHED** (2026-09-23) | advisory-only MRP integration is an explicit policy disposition; remaining engineering findings are remediated and focused-tested |
| 18 | Assets | **FINISHED** (2026-09-23) | operational links, maintenance lifecycle, deployed workflow migration, HashIDs, transfer cleanup, and failure visibility are remediated and focused-tested |
| 19 | B2B Portal | **FINISHED** (2026-09-23) | no open finding in this report; customer-order and supplier-RFQ evidence is recorded in the finished artifact |
| 20 | Maintenance | **FINISHED** (2026-09-23) | no open finding in this report; condition-reading routes remain intentionally hidden pending an IoT source |
| 21 | Dashboard | FINISHED for current engineering scope | annual KPI semantics and deployed browser/runtime proof |
| 22 | Admin | FINISHED for current engineering scope | delegated-approval API expansion and deployed runtime proof |
| 23 | Auth/security | FINISHED for current engineering scope | 2FA product/security decision and deployed runtime proof |
| 24 | Edge/shop-floor device integration | Removed by design | no live Edge module; factory/driver PWAs are separate live features |
| 25 | Landing/public pages | FINISHED for current engineering scope | public policy decisions and deployed runtime/provider proof |
| 26 | Common infrastructure | FINISHED for current engineering scope | deployed scheduler/queue/provider proof |

## Highest-priority residual findings

1. **~~Purchasing G1/G2/G4/G5:~~** closed — cancelled PO/draft GRN resurrection, partial RFQ conversion, posted partial-bill continuation, and incoming-QC fractional/archived truncation are remediated with regression tests (see `PURCHASE-REQUEST-CHAIN-TRACE-2026-09-18-FINISHED.md` §9).
2. **Payroll:** source-level input-freeze, eligibility, anomaly, effective-table, 13th-month-date, SSS-R3, and tax-correction gaps are closed; statutory policy certification and live filing/provider proof remain.
3. **Accounting:** engineering residuals are closed in the current worktree; year-end close, multi-currency, company-wide/cross-module budget semantics, service-bill SOD, and historical account archiving are explicit owner-policy dispositions.
4. **~~HR N1/N2/N3/N4/N5:~~** closed 2026-09-23; direct bank edits, unscoped review actions, unvalidated profile values, salary-basis mismatch, and mutable hire/employment dates are fixed or dispositioned in `HR-AUDIT-2026-09-18-FINISHED.md`.
5. **~~Inventory H-08 and location/count/lot gaps:~~** closed or dispositioned 2026-09-23 — fractional QC is fail-closed or ceiling-counted by path, location changes are guarded, count verification/completion is maker-checker controlled, and return lot/source provenance is enforced (see inventory report §7).
6. **~~Production N-01/N-02/N-04/N-05:~~** closed 2026-09-23 — resume during machine failure, wrong assignment clearing, false lot lineage, independent operation output ledger, and downtime interval math are remediated with regression tests (see `PRODUCTION-AUDIT-2026-09-18-FINISHED.md` §10).
7. **Quality cleanup:** stored-but-unused legacy code.
8. **Operational proof:** Redis/two-worker MRP behavior, production migration preflight, deployed scheduler/queue/provider behavior, and live browser coverage remain release evidence gaps.

## Test-gap summary

Across the re-audit, existing tests are strongest for happy paths, ordinary idempotency, and
selected permission boundaries. Missing or insufficient regression coverage repeatedly clusters
around:

- same-day source mutation after approval/computation;
- two-connection concurrency rather than sequential “race” test names;
- malformed HashIDs/raw numeric IDs in response payloads;
- archived/inactive source records;
- partial completion followed by later continuation;
- failed queue/listener/notification persistence paths;
- actual scheduler/outbox/Redis/Nginx/SMTP runtime behavior;
- SPA route/permission parity and decimal serialization;
- database-level invariants versus service-only guards.

## Re-audit conclusion

The current `FINISHED` and `PARTIAL` labels are not reliable enough to drive release decisions without the per-artifact current-status evidence.
The source has materially improved, but every module still has either residual correctness,
security, financial, quality, operational, documentation, or test-coverage gaps. The current
release gate should use this register plus focused tests, not historical filenames.
