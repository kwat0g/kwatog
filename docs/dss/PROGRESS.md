# DSS Progress (single source of truth — resume from here)

## Status: R1 and R2 workbench delivered; R3 evidence and verified lifecycle in progress. Detector promotion remains blocked on shadow validation and business-source gates.

## System-wide roadmap status (2026-10-23)

- R1 complete: all eleven registered decision families use readable, bounded business evidence. The page no longer renders raw rationale JSON, detector identifiers, or unvalidated confidence percentages.
- R2 complete: role-scoped SQL filters, search, priority sorting, counts, authorized dropdown options, URL-backed table and desktop detail panel, plus narrow-screen detail routes. Focused API tests passed (21 tests / 171 assertions); Decision Center and FilterBar tests passed (57 tests); Chromium workbench checks passed (2 tests); TypeScript check and 14-route parity check passed.
- R3 implementation in workspace: additive source identity, successful-run freshness, explicit follow-up, verified resolution, and history filters. Work-order, NCR, three-way match, and master-hygiene now have source verifiers; other aggregate findings remain unverifiable. Clearance records include the source condition, verifier, opaque run reference, bounded proof, and observation time; the API emits a permission-scoped human-readable projection. D09, D18, and D22 now expose source-derived business due times. PHP lint/Pint, TypeScript, and diff checks pass; focused tests were not run. No R3 migration has been applied; apply it before enabling code that reads its fields.
- R4 D07 slice in workspace: linked Sales Order reference appears only when order and line agree, and only for users with `crm.sales_orders.view`; remaining quantity shows product unit when available. `planned_end` remains the deadline. No blocker cause is inferred. R4 D25 v2 groups registered existing approval sources by queue and role, applies source permissions, excludes row-scoped queues, and records reminder due time. It remains partial and shadow. D03/D08 ranking and D26 source verification remain blocked on source and policy evidence.
- R5 safe language correction: payroll anomaly recommendations now say “payroll anomaly review” and “unresolved anomaly flags”; they do not imply a payroll readiness decision. Zero unresolved flags still does not prove a period is ready.
- R5 D27 partial: shared source predicates now power detection, searchable cursor-paginated current records, and exact full-scope clearance checks. Detail access checks the source permission again; record actions open existing fix workflows. List is current-source data, not historical snapshot. Null-only safety-stock gap cannot match the current non-null column; whether zero is a missing buffer remains an owner policy decision. Keep shadow pending false-positive review.
- R7 bounded admin activity summary added at `/dss/admin/activity-summary`, gated by `dss.manage`, for rolling 30 days: tracked `viewed` events (one per recommendation/user/day, not reviews), `started` events, `resolved` events with `metadata.source = source_verification`, and recommendations created with a predecessor link. Counts reflect retained records and events. They exclude dismissal reasons, free-text outcomes, saved or estimated money, accuracy, and resolution time; counts do not measure detector accuracy or realized savings. This is activity reporting only, not outcome evaluation.
- R5 D33 partial shadow slice: `dss.three_way_match_review` surfaces draft PO-linked bills whose persisted three-way snapshot is blocked. It counts fixed blocking-line categories only and links to the permission-scoped bill page. Its verifier reruns current PO/bill/GRN matching; missing data, invalid settings, query errors, and legacy bill lines without `item_id` remain unavailable. It is not duplicate-invoice detection; 26-hour recommendation expiry is not clearance proof. No impact estimate or due date. Keep shadow pending duplicate definitions, correction workflow, role review, and sampled false-positive evidence.
- R5 D16 partial shadow slice: `dss.quality_suspect_material_lot` reports only failed latest incoming inspections whose GRN material lot explicitly links to work orders and shipment lots. QC-only; traceability route requires `quality.inspections.view`. Candidate items and work orders cap at 200 per run; shipment lots cap at 200 per candidate, with overflow marked incomplete. No customer or quantity data, impact estimate, recall claim, or warning without a shipping link. Genealogy remains incomplete.
- Runner boundary: one monotonic deadline covers detection, persistence, and reconciliation. PostgreSQL refreshes its transaction-local statement timeout before each query; other drivers check before each query and after non-SQL work returns. Scheduler and admin enqueue one detector per Horizon `dss` job on dedicated `redis_dss`, capped at 260 seconds; supervisor kills at 280 seconds, detector lock lease is 310 seconds, and effective queue-connection lease is 330 seconds. Timeout callback marks active run failed; maintenance reaps leftovers every five minutes. Direct runner calls remain inline for tests and diagnostics.
- R6 audit: current notifications have per-user/type/channel preferences but no configured daily cap. Deduplicated in-app rows do not make email handoff idempotent. No DSS publisher is implemented; see `docs/superpowers/plans/2026-10-23-dss-updates-notifications.md`. Keep publishing blocked until a detector has passed shadow review and the idempotent in-app delivery policy is implemented.
- Isolated D07 demo seeder added at `api/database/seeders/DssDemoScenarioSeeder.php`; it creates linked sales-order/work-order source records, invokes the real detector, and rejects non-local/shared database names. It does not change mode. See `docs/dss/DEMO-SCENARIO.md`.
- Remaining R4–R7 work stays gated by source evidence and business-policy prerequisites in `docs/superpowers/plans/2026-10-23-dss-system-roadmap.md`; D33 is only a partial shadow slice.

- `00-discovery.md` — done 2026-10-12 (63 cron triggers, ~40 logics, E2E traces, RBAC, tech).
- `01-decision-inventory.md` — done (33 decisions scored; top D13/D26=25.0; 12 process gaps).
- `02-plan.md` — done, approved on recommended defaults 2026-10-12.
- `FINDINGS.md` — 15 items (F-01..F-15), separate-track PRs.

## Wave 0 shipped (all shadow-safe, all tests green)

- W0-1 (#200): 7 tables (partial-unique open-dedupe index), DetectorInterface/
  Emission/Registry, DssParameterService (audited writes, shadow defaults),
  dss.view/manage/admin + modules.dss (migration + seeder parity). 15 tests.
- W0-2 (#201): runner (dedupe upsert every mode, per-detector isolation + run
  logs), transparent scorer, `dss:run` hourly cron, DssServiceProvider. 25 tests.
- W0-3 (#202): lifecycle API (expiry/snooze-resurface, one event per
  transition, viewed deduped/user/day), visibility = dss.view + owner permission
  and role + advisory; shadow/unknown hidden (403 direct). Assisted mode is not
  supported until it can prefill an existing workflow safely. 36 tests.
- W0-4 (#203): /decision-center + /:id (severity groups, Why-this rationale,
  impact/confidence badges, accept/dismiss+reason/snooze+date, outcome),
  sidebar entry. Vitest helpers; tsc/eslint/prettier clean.
- W0-5 (#204): dss:snapshot-stock daily 03:45 (G-01), MasterHygieneDetector
  `dss.master_hygiene` in shadow (D27), DemandBasis F-02 (legacy sets preserved,
  per-consumer keys, UpdateSettingRequest allow-list). 70 tests incl. rewired suites.
- W0-6 (#205): admin API (fleet state, enable/mode/run-now, engine read/write
  split manage/admin, audited params) + /admin/dss page; F-13 guard test +
  audit:dss-routes parity (exit 0); F-12 verified-no-code (SettingsGovernanceTest
  + DssParameter audit). 53 DSS tests.

## Decisions covered / detectors live

- Registered (all default to shadow): D07 production work-order overdue, D09
  receivables priority, D13 NCR SLA, D18 preventive maintenance overdue, D21
  payroll anomaly summary, D22 training expiry summary, D25 approval bottlenecks,
  D26 chain bottlenecks, D27 master hygiene, partial D33 three-way match review,
  and partial D16 suspect material-lot trace.
- D13, D21, D22, and D18 cover only a slice of their broader inventory decisions.
  D26 omits `pr_pending_overdue` until its approval audience can be safely scoped.
- Full coverage audit: `AUDIT-2026-10-23.md`. Eleven of 33 decisions have detector coverage;
  registration is not promotion approval.
- Existing logic reserved for reuse: MRP engine, alert engine, 16 bottleneck
  detectors, replenishment+SS+ABC, forecasting+actuals, supplier scorecard,
  3-way match, AR dunning, NCR/8D/CAPA escalations, SPC/COPQ, predictive
  maintenance, 11 KPIs, Action Center.

## Gaps / blockers

- Process gaps G-01 (snapshots now writing; history accumulates from 2026-10-12),
  G-02..G-12 open (see 01).
- F-15 closed: projection now explicitly uses valid `material_issue` default; exact-seed migration
  preserves custom values, and unsupported admin/runtime values fail closed. F-02 remains pending
  PPC approval because the three consumer demand sets still differ.
- Single-shared demand set still needs owner call (F-02 follow-up).
- PR stack merges bottom-up: #200 → #201 → #202 → #203 → #204 → #205
  (each base retargets to main as its parent merges); docs #199 independent.

## Next

1. Apply the DSS safety/scoping migrations and verify production database compatibility.
2. Complete role QA and shadow backtesting before any detector moves to advisory.
3. Resolve F-01/F-02/F-03 and relevant G-01/G-02/G-06 gaps before inventory,
   supplier, stock-out, or cash recommendations.
4. Expand only after owners confirm thresholds, role scope, and action destinations.
