# M022 Action Plan — Payslip & Statutory Disbursement

Plan date: 2026-08-27
Status: 📋 Plan Ready
Gate decision: do not fix in this session

The plan has one same-session-ok candidate and nine separate-recommended actions. Its total scope includes several large policy/data-contract changes, so it does not satisfy the required majority-same-session-ok plus small-total-scope gate. F01/F02 ownership/publication fixes and the no-store/void-check portions of F06/F07 are already verified and are not repeated below.

## Ordered actions

1. M022-F10 — Establish the statutory filing-date basis.

   Classification: Broken / P1
   Scope: large
   Session recommendation: separate-recommended
   Action: decide whether statutory and certificate periods use payroll_date or another documented basis; centralize selection and add cross-month/year tests. Owner coordination is required because the repository treats payroll_date as load-bearing.
   Done when: all statutory and self-service aggregates use the same documented selector and a delayed/cross-year fixture reconciles.

2. M022-F04 — Reconcile taxable base, thirteenth-month handling, and Money precision.

   Classification: Broken / P1
   Scope: large
   Session recommendation: separate-recommended
   Action: define deductible categories and thirteenth-month treatment once; expose a shared centavo-exact calculation result to alphalist, BIR exports, and self-service summaries.
   Done when: cross-export fixtures reconcile exactly and no exporter converts the authoritative calculation to float before formatting.

3. M022-F03 — Define the official statutory artifact contract.

   Classification: Missing / P1
   Scope: large
   Session recommendation: separate-recommended
   Action: version the supported filing formats and control totals; explicitly gate unsupported DAT/XML or other official formats behind a staging/manual workflow.
   Done when: each download is clearly staging or filing-ready, references a format version, and validates its field/order/control-total contract.

4. M022-F05 — Add statutory completeness preflight.

   Classification: Incomplete / P1
   Scope: medium
   Session recommendation: separate-recommended
   Action: identify missing member IDs, deleted/inactive employees, unsupported records, contribution anomalies, and the EC-share policy before generating a file.
   Done when: the API returns actionable exceptions and requires a reviewed override for permitted exceptions; focused tests cover every statutory exporter.

5. M022-F06 — Add an immutable statutory export run and artifact record.

   Classification: Incomplete / P1
   Scope: large
   Session recommendation: separate-recommended
   Action: persist actor, scope/date basis, selected source rows or snapshot, calculation/format versions, preflight result, checksum, retention, and download/approval events for direct CSV and spreadsheet exports.
   Done when: a later audit can reproduce or identify every downloaded artifact.

6. M022-F07 — Make provider-accepted payslip email delivery retry-safe.

   Classification: Incomplete / P1
   Scope: medium
   Session recommendation: separate-recommended
   Action: add provider/message-id idempotency or a durable outbox/accepted state, then test provider acceptance followed by worker/database failure and retry.
   Done when: a retry cannot submit a duplicate for the same payroll publication unless an explicit recovery action authorizes it.

7. M022-F08 — Version and deduplicate payslip artifacts.

   Classification: Incomplete / P2
   Scope: medium
   Session recommendation: separate-recommended
   Action: separate generation from download, key an artifact to the payroll/publication revision, define concurrency and retention, and make repeated downloads read-only.
   Done when: repeated and concurrent downloads reuse the canonical version and do not create extra rows/blobs.

8. M022-F12 — Repair the operator failure query contract.

   Classification: Broken / P2
   Scope: medium
   Session recommendation: separate-recommended
   Action: keep employee-facing lists publishable-only but give the permissioned failure tab its own error-inclusive query and tests.
   Done when: the failure tab returns errored payroll rows without exposing them to employee-facing payslip/download routes.

9. M022-F09 — Connect statutory preflight/review evidence to the SPA.

   Classification: Incomplete / P2
   Scope: medium
   Session recommendation: same-session-ok
   Action: show coverage, exceptions, control totals, format/version, and reviewed/download state from the export-run contract.
   Done when: an operator can inspect and acknowledge readiness from the page and the acknowledgment is represented by the backend run record.

10. M022-F11 — Decide the audit and retention contract for personal certificates.

    Classification: Missing / P2
    Scope: medium
    Session recommendation: separate-recommended
    Action: document the intentional direct-render boundary or move BIR/contribution certificates to versioned, access-audited artifacts.
    Done when: policy, retention, attribution, and reproducibility behavior are explicit and tested.

11. Dependency P02-01 — Resolve payroll journal-entry actor attribution.

    Classification: dependency blocker
    Scope: large
    Session recommendation: separate-recommended
    Action: finance/journal-ledger owner must decide how reference-originated payroll journal entries record created_by while preserving auditability; M022 must not edit that dependency.
    Done when: the focused regression records non-null created_by/posted_by and the audit row without breaking journal-ledger policy.

## Gate and handoff

No production fixes are authorized by this plan. The next implementation session should start with the filing-date and taxable-base policy decisions, then build the shared export preflight/run contract before the UI review work.
