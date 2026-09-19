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
- Jev was used only as read-only corroboration after deterministic evidence collection. The live
  verifier processed 19 scenarios sequentially with model `jev-1.13.0`: `0 covered`, `0 gaps`,
  `19 review`, `0 not_applicable`, `0 service_errors`. Jev did not close or downgrade any finding.
- Scenario manifest: `RE-AUDIT-JEV-SCENARIOS-2026-09-19.json`.
- Jev raw result was captured outside the repository at `/tmp/opencode/re-audit-jev-2026-09-19.json`.

Evidence priority: current code and executable regression tests outrank historical report labels;
Jev is corroboration only; deployed settings, queues, schedulers, migrations, providers, and
business-policy intent remain untestable without the relevant runtime or owner decision.

## Artifact Re-Audit

| Artifact | Current verdict |
|---|---|
| `FINISHED-SALES-ORDER-CHAIN-TRACE-2026-09-18.md` | Historical label stale. Current SO chain has new open gaps: cancelled SO draft auto-PR survives; invoiced partial SOs are excluded from MRP replan; chain/paid timestamps and MRP queued-vs-failed response are incomplete; CoC failure has no durable recovery. No-spec outgoing QC now fails closed without creating a dead-end inspection. |
| `PURCHASE-REQUEST-CHAIN-TRACE-2026-09-18-FINISHED.md` | Historical fixes are partly correct. Current P2P has critical cancelled-PO/draft-GRN resurrection, RFQ partial-quantity conversion, duplicate manual PO coverage, posted partial-bill continuation, fractional/archived incoming-QC gaps, PO submission ownership drift, and missing GRN GL recovery. |
| `HIRE-TO-RETIRE-PAYROLL-TRACE-2026-09-18-FINISHED.md` | Most historical payroll fixes are present, but SSS R-3 still returns an empty workbook for unfinalized periods and new input-freeze, eligibility-completeness, anomaly-race, government-table, 13th-month-period, tax-correction, and statutory-basis gaps remain. |
| `ACCOUNTING-CORE-AUDIT-2026-09-18-PARTIAL.md` | Partial status is accurate. New invoice-source, credit-note-source, bill-payment-idempotency, budget-posting, account-policy, fiscal-year, raw-ID, and service-bill SOD risks remain. |
| `INVENTORY-RETURNS-AUDIT-2026-09-18.md` | Search imports and stock-card direction are fixed. WAC read arithmetic, stock-count completion/self-approval, fractional QC, zone reclassification, item restore, idempotency, lot authority, source integrity, and RMA boundaries remain. |
| `QUALITY-AUDIT-2026-09-18.md` | AQL unit counting, 8D command status, declared sample enforcement, no-spec fail-closed behavior, multi-product return inspections, PPAP lifecycle, NCR API linkage, supplier-return closure, revision immutability, and traceability gap signaling are fixed in the current worktree. Full-sampling bounds, seeded role config, decimal serialization, approval policy, and calibration-to-inspection integration remain. |
| `PRODUCTION-AUDIT-2026-09-18-FINISHED.md` | Breakdown MWO, summary SQL, operation windows, machine/mold guards, schedule constraints, and mold PM creation were improved. Resume-after-breakdown, machine ownership on pause/complete, material-lot truth, operation ledger limits, downtime interval math, dashboard invalidation, dead links, and audit coverage remain. |
| `MRP-AUDIT-2026-09-18-FINISHED.md` | Most historical BOM/MRP fixes are current. Same-second routing replan dedupe, BOM soft-deleted version collision, UOM-normalized in-transit supply, manual/daily MRP overlap, plan-history reconstruction, missing-BOM status/alerting, MOQ precision, and direct-service numeric validation remain. |
| `MRP-AUDIT-2026-09-18-MISSING-TESTS.md` | Still valid as a test-gap inventory, but it is not a current pass/fail result. Redis overlap, two-worker races, migration preflight, full-suite triage, and several BOM/MRP boundary tests remain unproven. |
| `SUPPLYCHAIN-AUDIT-2026-09-18.md` | Landed-cost visibility was fixed; capitalization remains absent. Customs evidence, shipment immutability/status, duplicate documents, shipment lots, CoC recovery, delivery inventory decrement, active delivery deletion, driver double-booking, decimal contracts, and manual delivery idempotency remain. |
| `CRM-AUDIT-2026-09-18.md` | Portal raw-ID fallback was fixed. Product/price management ownership, revenue-account API reachability, feature-gated inquiries, price fallback, product case matching, archive semantics, inquiry audit/status, stale funnel config, and missing CRM SPA tests remain. |
| `HR-AUDIT-2026-09-18.md` | Profile encryption/list scope was fixed, but direct bank edits bypass Finance, review mutations lack row scope, profile fields lack validation, salary basis/history is weak, restore leaves accounts disabled, recruitment conversion is under-validated, and document/training/position gaps remain. |
| `ATTENDANCE-LEAVE-LOANS-AUDIT-2026-09-18-FINISHED.md` | Several historical fixes are current. Attendance still has payroll scope drift, overnight OT, ceiling disagreement, stale auto-OT, break/rest/recurring-holiday issues, shift assignment gaps, manual OT duplicates, bulk disclosure, stale DTR; Leave has show/options/document/archived-balance/negative-days/holiday/SPA gaps; Loans has partial final-pay replay, as-of rewind, terminal visibility, write-off, workflow migration, notifications, feature-gate, disbursement, provenance, and database-invariant gaps. |
| `FORECASTING-ASSETS-AUDIT-2026-09-18.md` | Forecasts remain advisory-only; reconciliation is not rerunnable, accuracy double-counts scopes, invalid hashes broaden queries, manual method is coerced, DB invariants and decimal contracts are weak. Assets still lack operational links, maintenance status, transfer route cleanup, QR contract, deployed workflow migration, raw depreciation journal ID, and failure recovery. |
| `B2B-MAINTENANCE-AUDIT-2026-09-18.md` | Supplier expiry, customer invitation audit, token revoke parity, reset pruning, supplier document download, write throttles, parent deactivation, SPA response/RMA reachability, timed-expiry routing, and order idempotency remain. Maintenance still has predictive race/freshness, downtime trust/double-count, due-widget, dead link, polymorphic integrity, and audit gaps. |
| `PLATFORM-CROSS-CUTTING-AUDIT-2026-09-18.md` | Several top findings are fixed (imports, 8D, unsubscribe, settings transaction). Approval escalation health, direct bottleneck alerts, recovery audit entity IDs, delegated HTTP approvals, approval-board pagination, business-policy exposure, document sequence first-use race, outbox version handling, checksum verification, notification idempotency, and feature-toggle/search drift remain. |
| `FINDINGS-REGISTER-2026-09-18.md` | Historical overlay is stale in multiple locations: it says P0-07 has zero MWO references although current code creates one, and P0-08 still describes row-count AQL although current code counts sample units. Use this re-audit register for current status. |
| `MODULE-AUDIT-TRACKER-2026-09-18.md` | All rows say `audited`, but three document links are broken: payroll, accounting, and production names omit their `-FINISHED`/`-PARTIAL` suffixes. The status vocabulary does not distinguish current evidence from historical coverage. |
| `JEV-SCENARIO-MANIFEST.example.json` | Operational example only. It contains placeholder evidence; scenario R7 cites the wrong runbook line (`#97` instead of the R7 row around `#947`). Not a completed audit. |
| `JEV-SCENARIO-VERIFIER.md` | Current operational documentation matches the verifier implementation. Live Jev corroboration is available, but its results are review signals, not proof. |

## Current Module Matrix

| # | Module/feature | Re-audit result | Highest current residual risk |
|---:|---|---|---|
| 1 | CRM Sales Order | Historical FINISHED label invalid as a current closure | cancelled SO leaves draft auto-PR; invoiced partial SO excluded from MRP replan; QC/CoC/MRP response ambiguity |
| 2 | Purchasing PR/P2P | Historical FINISHED label invalid as a current closure | cancelled PO resurrected by draft GRN; RFQ partial quantity converts full line; duplicate manual PO coverage; posted partial bill cannot bill remainder |
| 3 | Payroll/H2R | Historical FINISHED label incomplete | mutable payroll inputs after approval; eligibility completeness; anomaly recompute race; inactive gov-table lookup; SSS-R3 contract; malformed 13th period |
| 4 | Accounting core | PARTIAL status remains accurate | duplicate invoice consumption; source-less credit-note state; changed-payload payment replay; bill budget bypass; fiscal-year lifecycle |
| 5 | Budgeting | Embedded Accounting feature remains incomplete | bill posting bypass; concurrent budget overrun; overlapping fiscal years; dead revision/transfer references |
| 6 | Inventory | Historical report partly remediated | stock-card WAC math; zone reclassification; count self-approval/incomplete counts; fractional QC; idempotency; lot authority |
| 7 | Quality | Historical report partly remediated | full-sampling bounds; seeded role config; decimal serialization; approval policy; calibration not linked to inspections |
| 8 | Production | Historical FINISHED label invalid as current closure | resume while machine down; pause/complete assignment ownership; false lot lineage; operation ledger divergence; downtime intervals |
| 9 | MRP/BOM | Historical FINISHED label incomplete | BOM version collision; PO UOM; manual/daily overlap; history loss; missing-BOM alert/status; MOQ precision |
| 10 | SupplyChain | Open | landed-cost capitalization; shipment/GRN handoff; delivery stock decrement; CoC recovery; active deletion; driver double-booking |
| 11 | CRM remaining | Open | no business owner for product/price manage; revenue account unreachable; inquiry feature gate; pricing/product identity; inquiry audit |
| 12 | HR | Open | direct bank edits; profile action scope/validation; restore account; salary basis/history; recruitment conversion; document cleanup |
| 13 | Attendance | Open | scope drift after employee transfer; overnight OT; OT ceilings; stale OT; break/holiday/rest math; shift validity |
| 14 | Leave | Open | detail/options contract; documents unavailable; archived balances; negative days; holiday/half-day semantics; long maternity precision |
| 15 | Loans | Open | partial final-pay replay; as-of aggregate rewind; terminal chain visibility; write-off absence; workflow migration; disbursement accounting |
| 16 | Return Management | Open | product/item mismatch; duplicate NCR; Finance-only approval role; source-state enforcement; supplier bill provenance; replacement VAT |
| 17 | Forecasting | Open | no automatic generation; accuracy double-count/zero actual; stale reconciliation; swallowed BOM errors; invalid hash broadening; manual-method coercion |
| 18 | Assets | Open | no operational asset links; stale workflow rows in deployed DB; raw depreciation journal ID; linked-asset lifecycle; failed depreciation visibility |
| 19 | B2B Portal | Open | supplier expiry; invitation audit/parent state; reset pruning; customer response/RMA SPA reachability; customer resource overexposure; order idempotency |
| 20 | Maintenance | Open | stale predictive readings; duplicate corrective MWO race; downtime ledger mismatch/double count; due-widget omissions; dead notification route |
| 21 | Dashboard | Open | missing session/password middleware; badge row-scope leaks; widget failure invisibility; inactive KPI reads; money floats; permission cache staleness |
| 22 | Admin | Open | privileged custom-role assignment; nested raw IDs in audit; bulk-role audit loss; import audit/error leakage; session control; feature dependency enforcement |
| 23 | Auth/security | Open | timing oracles; current-password reuse; history depth zero; expiry-at-login UX; per-user idle clock; session admin; ungated business policies; no 2FA policy |
| 24 | Edge/shop-floor device integration | Removed, residual stale references | no live module; dead `/api/v1/edge/*` middleware carve-outs and stale docs; factory/driver PWAs are separate live features |
| 25 | Landing/public pages | Open | CRM feature gate on inquiry inbox; PII exposure; newsletter write-only lifecycle; no PII retention/deletion; public customer disclosure; state-changing GET unsubscribe |
| 26 | Common infrastructure | Open | approval escalation health; direct bottleneck alert path; recovery audit entity IDs; outbox version ignored; sequence first-use race; vault checksum verification; notification idempotency |

## Highest-priority residual findings

1. **Purchasing G1/G2/G4/G5:** cancelled PO/draft GRN resurrection, partial RFQ conversion, posted partial-bill continuation, and incoming-QC quantity truncation.
2. **Payroll PAY-NEW-01/PAY-NEW-02/PAY-NEW-04:** post-compute input mutation, incomplete eligible employee set, and inactive government schedules.
3. **Accounting A-01/A-02/A-03/A-04:** duplicate invoice source consumption, invalid source credit notes, changed-payload payment replay, and budget bypass at bill posting.
4. **HR N1/N2/N3/N4/N5:** direct bank edits, unscoped review actions, unvalidated profile values, salary-basis mismatch, and mutable hire/employment dates.
5. **Inventory H-08 plus current zone/count/lot gaps:** fractional QC truncation, zone reclassification, incomplete counts, and non-authoritative lot selection.
6. **Production N-01/N-02/N-04/N-05:** resume during machine failure, wrong assignment clearing, false lot lineage, and independent operation output ledger.
7. **Quality operational/policy gaps:** unbounded full sampling, seeded role configuration, decimal serialization, inspection approval policy, and calibration evidence integration.
8. **Security:** B2B supplier expiry, Auth timing/current-password/session issues, Admin privileged custom roles, raw nested audit IDs, and Landing inquiry PII.

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

## TypeSafe corroboration

The verifier processed 19 scenarios sequentially using `jev-1.13.0`:

```text
covered: 0
gaps: 0
review: 19
not_applicable: 0
service_errors: 0
```

This outcome is expected for the supplied evidence: the scenarios deliberately describe
incomplete coverage or contradictory implementation/test state, and the verifier confidence
gate routes ambiguous or low-confidence cases to `review`. It corroborates that these scenarios
need human/code follow-up; it does not prove that all 19 are defects.

## Re-audit conclusion

The current `FINISHED` and `PARTIAL` labels are not reliable enough to drive release decisions.
The source has materially improved, but every module still has either residual correctness,
security, financial, quality, operational, documentation, or test-coverage gaps. The current
release gate should use this register plus focused tests, not historical filenames.
