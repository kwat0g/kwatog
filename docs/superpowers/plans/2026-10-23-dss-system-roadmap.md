# System-wide DSS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the approved system-wide DSS with readable recommendations, a consistent workbench, explainable priorities, verified resolution, connected business decisions, selective notifications, and repeatable demonstrations.

**Architecture:** Extend the existing detector, recommendation, permission, event, and notification boundaries. Keep authoritative state and calculations in source modules, use one presentation boundary for business language, and release each decision family with evidence and a resolution check. This document is the program roadmap; the linked first-slice plan contains exact code and test steps.

**Tech Stack:** Laravel, PostgreSQL, Redis/queues, React, TypeScript, React Query, React Router, existing Atelier UI components, PHPUnit, Vitest, and Playwright.

---

## Approved specification and scope

Read `../specs/2026-10-23-dss-system-design.md`. The user selected every recommended product choice and requested a plan before further implementation.

Current inventory: eleven registered detector families and 33 catalogued decisions. The original nine recommendation families have readable evidence; D33 adds a partial, shadow-only three-way bill-match slice, and D16 adds a positive-only material-lot trace slice. Existing migration, permission, runner, and UI work is already present in a dirty workspace. Preserve it. Commit only explicitly reviewed task files; do not stage unrelated changes or alter applied historical migrations.

Do not start a new recommendation engine, task assignment product, notification transport, design system, AI chat, or generic graph platform. The roadmap uses existing boundaries. Forecasting and simulation are included as capability releases with explicit evidence prerequisites.

## Delivery order

| Release | Working outcome | Dependency | Exit evidence |
|---|---|---|---|
| R1 | Current eleven families display business titles and structured evidence on list and detail | Current API | Presentation and browser review prove no raw JSON, internal detector labels, or unvalidated confidence percentages |
| R2 | Overview, server filters/search/sorting, recommendation table, responsive detail panel | R1 | Scoped totals match queries beyond page one; URL navigation and mobile/keyboard flows work |
| R3 | Evidence identity, independent freshness, follow-up, verified resolution, stable dedupe | R1; R2 consumes the new contract | Concurrency, partial scan, stale evidence, legacy history, and recurrence tests |
| R4 | Connected delivery-risk decisions, transparent prioritization, supported alternatives | R2 + R3; source-clock fixes | One real source chain demonstrates cause evidence, affected commitments, action, and resolution |
| R5 | Decision expansion across every business domain in the coverage table | R3; per-decision prerequisites | Each family has owner, evidence, source scope, action, verifier, and isolated scenario |
| R6 | Automatic targeted rechecks and selective notifications | R3 + validated family | No shadow publishing; deduplication, permission revocation, retry, cap, and preference tests |
| R7 | Measured outcomes, role-specific business views, forecasting and simulation | R4/R5; history and backtesting | Verified outcomes separated from user reports; forecasts have evaluation evidence; simulations produce no business writes |

Demo scenarios are delivered with each release, beginning in R1/R2. Notification plumbing can proceed independently after R3; predictive capabilities never block the workbench for existing verified rules.

## File and ownership map

| Area | Existing files | Planned files or changes |
|---|---|---|
| Business presentation | `spa/src/pages/decision-center/helpers.ts`, `index.tsx`, `detail.tsx` | `presentation.ts`, `DecisionEvidence.tsx`, focused tests; delete JSON flattening and confidence display |
| Workbench | `spa/src/components/ui/DataTable.tsx`, `FilterBar.tsx`, `Panel.tsx`, `spa/src/components/layout/PageHeader.tsx` | Reuse primitives; keep page orchestration in `index.tsx`; extract shared `RecommendationDetails.tsx` and `RecommendationActions.tsx` |
| API contract | `spa/src/api/dss.ts`, `spa/src/types/dss.ts`, `api/app/Modules/DSS/Resources/RecommendationResource.php` | Add typed context, summary/options types, and history states without removing legacy fields initially |
| Query/visibility | `api/app/Modules/DSS/Services/RecommendationService.php`, `Requests/ListRecommendationsRequest.php`, `Controllers/RecommendationController.php`, `routes.php` | Extract `Services/RecommendationQuery.php`; add scoped summary/options handlers and filter validation |
| Evidence/verification | `Contracts/DetectorEmission.php`, `Contracts/DetectorScanResult.php`, `Services/DssRunnerService.php`, `Models/DssRecommendation.php` under `api/app/Modules/DSS/` | Typed source references, evaluation timestamps, verification results, and additive migrations |
| Lifecycle | `Services/RecommendationService.php`, `Enums/RecommendationStatus.php`, `Enums/RecommendationEventType.php`, existing requests | Add start/resolved semantics and preserve legacy acknowledgement history |
| Scoring and relationships | `Services/RecommendationScorer.php`, `Detectors/ChainBottleneckDetector.php` | Add `Services/DecisionPriorityPolicy.php`, `Services/DeliveryRiskService.php` with bounded source joins |
| Source decisions | Existing module services listed below | Add a focused detector only after its policy and source facts are ready; register in `DssServiceProvider.php` |
| Notifications | `api/app/Common/Services/NotificationService.php`, `api/app/Modules/Auth/Services/UserNotificationService.php`, `api/routes/console.php` | `api/app/Modules/DSS/Services/DssNotificationPublisher.php`, bounded jobs/listeners using existing delivery transport |
| Evaluation | `Models/DssRecommendationEvent.php`, `RecordOutcomeRequest.php`, `Models/DssForecastRun.php`, `Models/DssScenario.php` under DSS | Scoped outcome summaries; forecast and scenario services only for validated capabilities |
| Demo | `api/app/Common/Support/ProductionAssertions.php`, existing source-model factories | `api/database/seeders/DssScenarioSeeder.php`, `api/tests/Feature/DSS/DssScenarioTest.php`, `docs/dss/DEMO-SCENARIOS.md` |
| Role navigation | `api/database/seeders/RolePermissionSeeder.php`, `spa/src/components/layout/Sidebar.permissions.test.tsx` | Explicit decision consumers and scoped filter choices; additive permission migration only if required |

Paths below starting with `Services/`, `Detectors/`, `Enums/`, `Requests/`, `Contracts/`, or `Models/` refer to `api/app/Modules/DSS/` unless an absolute repository path is shown.

## R1: Readable existing decisions

Execute `2026-10-23-dss-readable-decisions.md` as the first bounded implementation plan.

- [x] Build the shared business presentation adapter for all eleven current recommendation types.
- [x] Render whitelisted scalar facts and bounded evidence tables; preserve exact source document codes.
- [x] Replace internal detector/domain labels and generic repeated titles on both routes.
- [x] Replace rule confidence percentages with evidence availability language.
- [x] Keep business calculations, ranking, and source links unchanged in this slice.
- [x] Review desktop, mobile, and direct-detail states using the recorded Decision Center fixture/browser checks.

Exit: existing recommendations are understandable before the new workbench is introduced. Unknown or malformed evidence has a readable unavailable state and never becomes raw JSON.

## R2: Workbench and scoped query contract

Create a separate implementation plan named `2026-10-23-dss-workbench.md` at execution. Its complete software outcome is the overview/table/details experience.

- [x] Extract one authorized recommendation query used by list, summary, filter options, detail availability, and history. Reapply source record policy wherever evidence is expanded.
- [x] Add bounded `search`, `status`, `domain`, `owner_role`, `sort`, `direction`, `page`, and `per_page` validation. Derive domain/team options from authorized records and eligible checks; reject hidden values rather than accepting a filter for an inaccessible team.
- [x] Add `/api/v1/dss/recommendations/summary` and `/api/v1/dss/recommendations/options` before the dynamic recommendation route. Responses contain scoped counts and typed display options. Summary applies active filters except pagination; the UI labels the result as the current view.
- [x] Search persisted business subjects and display references. Order in SQL with an allow-listed sort and stable ID tie-breaker. Do not sort or filter only the current page in JavaScript.
- [x] Integrate `PageHeader`, compact real summary values, `FilterBar`, and `DataTable`. Preserve table density and column preferences.
- [x] Keep filters and selected ID in the URL. Share `RecommendationDetails.tsx` between the wide-screen panel and existing detail route. Preserve focus and list state when closing/backing out.
- [x] Expose status choices supported by the current API, including follow-up and history statuses implemented in the workspace.
- [x] Test 41 authorized records with 20-per-page pagination, summary counts, stable sorting, and a hidden high-impact record. Separate tests cover scoped options, filters, and search.
- [ ] Extend the 41-record pagination case across multiple business areas in one fixture; existing API pagination case uses one domain, with separate cross-domain UI fixtures.
- [x] Browser-check 1440px, 1100px, and 390px layouts, keyboard row activation, explicit detail links, focus restoration, URL state, and list retry. Recorded Chromium workbench checks passed (2 tests).
- [ ] Complete browser review for dark theme, no-results, and unavailable-coverage states; current component tests cover no-results and unavailable health, not the full browser review.

Commands at this gate: focused `RecommendationScopingTest`, new `RecommendationQueryTest`, `DssRouteGuardTest`, Decision Center Vitest, `npm run typecheck`, and `node scripts/check-dss-route-parity.mjs`.

Exit: the page follows existing ERP layouts and supports a full authorized queue without false counts or inaccessible filter options.

## R3: Source evidence, freshness, and verified lifecycle

R3 implementation code is present in the workspace. The lifecycle migration has not been applied and focused tests were not run, so this work is not release-ready. Completed code slices and remaining gates are separated below.

- [x] Add stable source references and rule identity to work-order, NCR, and three-way-match emissions. Keep human document numbers separate from public IDs.
- [ ] Extend stable source identity and decision-specific rule identity to all remaining emissions. Label aggregate samples and their bounded counts; D26 aggregate groups remain non-verifiable.
- [x] Separate receivables amount currency (`impact_currency`) from metric label (`impact_metric`) in the emission/resource code; preserve money as decimal strings.
- [ ] Apply and verify the additive lifecycle schema that persists the new impact fields and fields below.
- [x] Implement separate first-detected, last-evaluated, and expiry semantics in runner code; action/audit updates do not refresh detector evaluation time. `source_observed_at` is populated only where detector sources provide it.
- [ ] Provide source-observation timestamps for each detector only where a defensible source clock exists; never infer observation time from recommendation writes.
- [x] Add source-derived due times for D09, D13, D18, D22, and the registered approval queue.
- [ ] Correct other elapsed workflow clocks affected by F-05 before presenting them as proven waiting durations.
- [x] Implement `in_progress` and `resolved` transitions, Start follow-up and Resolved events, and preserve accepted/executed history as acknowledgement/action records in workspace code.
- [x] Implement source verifiers for work orders, NCR response, and the partial three-way-match slice; clear only when their source checks prove that specific condition cleared.
- [ ] Expand the partial unique index and confirm all active-row queries against the applied open/in-progress/snoozed schema. Preserve legacy API actions through migration.
- [ ] Add source-verification rules for every decision. Aggregate absence—including D26 scan absence—does not prove a sampled source issue cleared.
- [ ] Verify that missing/deleted sources, permission loss, changed parameters, disabled checks, partial scans, and query failures remain unavailable or expired rather than verified resolution.
- [x] Implement predecessor references for recurrence after a resolved occurrence in workspace code; original event history remains attached to prior occurrences.
- [ ] Reuse source tasks or Action Center task references for assignment. Do not silently mutate their global state when DSS is snoozed or dismissed.
- [ ] Test two concurrent starts, repeated start requests, runner/action races, unchanged issue across runs, partial scans, source deletion, resolved source, reoccurrence, old clients, and historical outcomes. Focused R3 tests have not been run.
- [ ] Apply additive PostgreSQL migrations and pass upgrade and rollback tests. Deploy backend compatibility before new UI actions; retain new state reading support during rollback until rows are reconciled safely.

Exit: following advice remains visible as active work until source verification, with one active occurrence per detector and business issue.

## R4: Delivery-risk intelligence and priority

Create `2026-10-23-dss-delivery-risk.md`. Keep the analysis read-only and use real source links.

Delivered partial slices (not completion of delivery-risk prioritization):

- [x] D07 exposes a linked sales order only when its order/line match and the viewer has `crm.sales_orders.view`; remaining quantity uses product units where available. `planned_end` is shown as the deadline; no blocker cause is inferred.
- [x] D25 groups registered approval sources by queue and role, applies source permissions, excludes row-scoped queues, and records reminder due time. It remains partial and shadow.

- [ ] Map sales-order commitments to actual planning, material, purchase, production, inspection, delivery, and invoicing records. Use confirmed foreign-key relationships; record unavailable relationships explicitly.
- [ ] Start with D03/D07/D08/D25/D26. Add D04/D05/D06 as their feasibility and constraint inputs pass the capability conditions in the table below.
- [ ] Group related findings around the affected commitment while retaining each finding's identity and evidence. Apply authorization before counting related commitments or presenting a group.
- [ ] Separate observed contributing factors from proven causes. Display the current chain stage, responsible team, affected date, and evidence of delay.
- [ ] Implement `DecisionPriorityPolicy.php` with versioned components for business risk, source deadline urgency, downstream commitments, normalized impact, and stable tie-breaking. Unknown financial values stay unknown. Do not compare raw currency amounts with unit counts.
- [ ] Use existing configured policy targets; add audited bounded parameters only where a decision lacks an authoritative target. Compare old/new ordering in shadow before changing advisory order.
- [ ] Provide policy-authored actions and alternatives: review or unblock the actual step, assess a feasible reschedule, assess partial delivery when the source policy permits it, or request commercial confirmation. Display the prerequisite that makes an alternative unavailable.
- [ ] Deliver an isolated scenario containing an order, linked production work, a blocking inspection or material issue, and a delivery commitment. Resolve the underlying blocker through its source workflow and verify the DSS occurrence.

Exit: users can explain why one customer commitment takes priority, inspect its proven dependencies, act in the correct module, and see verified resolution.

## R5: Full decision coverage and prerequisite work

Delivered partial slices (not full decision coverage):

- [x] D21 language now describes payroll anomaly review and unresolved flags; it does not claim payroll readiness.
- [x] D33 shadow slice reports draft PO-linked bills with saved blocked three-way snapshots, counts fixed blocking-line categories, links to the permission-scoped bill page, and verifies saved bill review state only. It is not duplicate-invoice detection; expiry is not clearance proof.

Each row below is part of the full program. “Existing” means a limited detector exists; it does not mean the complete catalogued decision is delivered. A source prerequisite is a concrete release dependency, not an instruction to invent missing evidence.

| ID | Decision capability | Starting point | Required completion evidence |
|---|---|---|---|
| D01 | What, how much, and when to replenish | `Inventory/Services/AutoReplenishmentService.php`, `SafetyStockRecomputeService.php`; `Forecasting/Services/StockOutProjectionService.php` | Reconcile demand definitions and invalid movement names F-02/F-15; verify usable stock, lead times, buffers, budgets, and history G-01/F-06/F-07 |
| D02 | Supplier choice and tradeoffs | `Purchasing/Services/SupplierPerformanceService.php`, `SupplierQuoteService.php`, `ApprovedSupplierService.php` | Correct price semantics F-01 and record price history G-02; compare price, lead time, approval status, and observed performance |
| D03 | Customer orders at risk | `MRP/Services/MrpEngineService.php`, current chain findings | Stable order links, committed dates, material/capacity/quality evidence, and verified blocker clearance |
| D04 | Feasible customer promise date | `MRP/Services/CapacityPlanningService.php`, `MrpEngineService.php` | Snapshot material availability and capacity constraints; distinguish earliest feasible estimate from an approved commitment |
| D05 | Production sequence alternatives | `MRP/Services/CapacityPlanningService.php` | Machine/mold compatibility, availability, setup constraints, and objective comparison; G-09 operating history resolved where used |
| D06 | Shortage response alternatives | MRP shortages and purchasing commitments | Validate substitution approval, partial quantities, lead times, and escalation permissions; show only feasible alternatives |
| D07 | Production intervention | Existing `WorkOrderInterventionDetector.php` | Add linked commitments, blockers, remaining quantity with units, business deadline, and source completion verifier |
| D08 | Dispatch or consolidation priorities | Existing supply-chain delivery records and chain checks | Verify readiness, customer commitments, vehicle/capacity constraints, and actual dispatch outcomes |
| D09 | Collection priority | Existing `ReceivablesPriorityDetector.php` | Invoice-level authorized evidence, decimal amounts, dispute/payment state, reliable dunning semantics F-10, and actual collection linkage |
| D10 | Payment priorities under cash limits | Existing bills and `Dashboard/Services/CashFlowForecastService.php` | G-06 cash position, terms, approval status, commitments, and discounts; compare feasible payment sets without creating payments |
| D11 | Price and margin review | `MRP/Services/BomCostingService.php`, `Inventory/Services/ItemCostReviewService.php` | Agree cost basis, effective prices, tax treatment, currencies, margin policy, and commercial approval authority |
| D12 | Credit exposure review | Existing confirmation gate in `CRM/Services/SalesOrderService.php:128-178` | Gate sums open invoice balances, other confirmed/in-production orders, and current order; null/zero limit bypasses at `:137-143`. Validate authority, holds, overrides, and exposure scope before calling full D12 ready. |
| D13 | NCR/CAPA/8D readiness and urgency | Existing `NcrSlaDetector.php` and quality workflows | Expand beyond response SLA to actual corrective-action readiness, ownership, and approved closure rules G-10 |
| D14 | Defect contributing factors | `Quality/Services/DefectParetoService.php`, `SpcService.php` | Separate failed-measurement counts from counted-batch physical defects; cap input scan cost; add distinct inspection counts and owner-approved sample semantics; distinguish association from cause |
| D15 | Process drift | `Quality/Services/SpcService.php` | Verify sampling, control limits, minimum history, alarm policy, and shadow false-alarm rate |
| D16 | Suspect-lot impact | `DSS/Detectors/SuspectMaterialLotTraceDetector.php`; `Quality/Services/TraceabilityService.php` | Partial positive-only warning for latest failed incoming inspection with explicit material-lot→work-order→shipment-lot links; caps items/work orders at 200 and shipments at 200 per item, marking overflow incomplete. QC only, no impact estimate or exhaustive/recall claim. Audit genealogy completeness against G-03 before broadening |
| D17 | Maintenance job priorities | `Maintenance/Services/PredictiveMaintenanceService.php`, `DowntimeAnalyticsService.php` | Asset criticality, downtime/business impact, spares G-05, stable source identity F-08, and ownership/query semantics G-11 |
| D18 | Preventive maintenance review | Existing `PreventiveMaintenanceDetector.php`, `MaintenanceScheduleService.php` | Correct due-date scope, machine/asset links, history G-09, and approved deferral constraints; no automatic deferral |
| D19 | Repair or replace analysis | Assets, maintenance costs, and finance sources | Reconciled lifecycle cost, downtime, replacement quotations, useful-life assumptions, and authorized business policy |
| D20 | Staffing and overtime options | `Attendance/Services/OvertimeDecisionPolicy.php`, `EmployeeAvailabilityService.php` | Actual workload, staffing/skills, attendance evidence, labor rules, and existing approval scope |
| D21 | Payroll anomaly review | Existing `PayrollAnomalySummaryDetector.php`, `Payroll/Services/PayrollAnomalyService.php` | Extend anomaly totals to source preflight checks; preserve employee-level confidentiality and verify actual period readiness before using any readiness language |
| D22 | Training, contract, and probation deadlines | Existing `TrainingExpirySummaryDetector.php`, HR expiry workflows | Add each applicable expiry source, renewal supersession, owner/department scope, and source resolution |
| D23 | Return disposition | `ReturnManagement/Services/ReturnCaseService.php`, `ReturnCaseSourceService.php`, `ReturnCaseSettlementService.php` | Define approved disposition rules G-04; verify inspection, commercial obligations, source quantities, and settlement approval |
| D24 | Slow/excess/obsolete stock | `Inventory/Services/AbcClassificationService.php`, stock snapshots | Reconcile history G-01, valuation, reservations, expiry/age, and classification freshness F-11 |
| D25 | Approval bottlenecks | Existing `ApprovalBottleneckDetector.php`, approval registry and workflows | Preserve current approver/delegation scope; supply authorized unblock steps and stable waiting clocks |
| D26 | Missing downstream handoffs | Existing `ChainBottleneckDetector.php`, `Common/Services/ChainBottleneckService.php` | Stable source references, source clocks F-05, scoped related findings, and explicit per-handoff resolution |
| D27 | Automation-blocking master data | Existing `MasterHygieneDetector.php` | Human gap labels, authorized affected records, exact missing prerequisites, and rerun verification |
| D28 | Cash shortfall outlook | `Dashboard/Services/CashFlowForecastService.php` | G-06 opening cash/commitments, scenario assumptions, actual reconciliation, history, and forecast evaluation |
| D29 | Budget trajectory | `Accounting/Services/BudgetActualsSyncService.php`, `BudgetConsumptionService.php` | Reconciled actuals and commitments, fiscal-period policy, trend basis, and backtested estimates |
| D30 | Executive business briefing | `Dashboard/Services/KpiSnapshotService.php` and existing analytics | Resolve weekly/daily grain G-07; scope executive aggregates and explain changes using verified underlying decisions |
| D31 | Order, supplier, or machine scenarios | `Models/DssScenario.php`, existing MRP/capacity/forecast services | Read-only baseline snapshot, isolated scenario inputs, explicit constraints, comparison, and proof of zero production writes |
| D32 | Workforce attendance patterns | `Dashboard/Services/AttendanceForecastService.php` and attendance sources | Approved restricted purpose, sufficient history and validation, aggregate-first presentation; individual attrition prediction remains unavailable without those conditions |
| D33 | Duplicate/conflicting transactions | Shadow `dss.three_way_match_review` covers only PO-linked draft bills with persisted blocked three-way snapshots; its verifier checks saved bill review state only. Later PO/GRN changes are not recalculated or verified. It is not duplicate-invoice detection; 26-hour expiry is not clearance proof. | Verify duplicate definitions, narrow investigation scope, explanations, correction workflow, external-source-aware verifier, and sampled false-positive evidence before expanding or promoting |

All module service paths in this table are prefixed with `api/app/Modules/`; `Common/Services/ChainBottleneckService.php` is under `api/app/`.

Split R5 into business-domain plans: planning/purchasing/inventory; finance/commercial; quality/returns; maintenance/assets; people/payroll. Each plan must include exact source-policy changes, contract changes, meaningful tests, a source-derived demo, and its specific promotion gate. Prerequisite data fixes remain separately reviewable changes with compatibility and reconciliation evidence.

## R6: Scheduling, runtime isolation, and notification publishing

See `2026-10-23-dss-updates-notifications.md`; lifecycle identity is now in the working tree.

- [ ] Reuse the hourly runner as recovery. Add bounded, coalesced after-commit jobs for existing reliable source events.
- [ ] Add a cumulative query/worker execution boundary. PostgreSQL detection now sets a transaction-local per-statement timeout; multiple statements may still exceed the total budget, and SQLite/non-SQL runtime still use the after-return timer. Keep worker execution below lock lifetime and make interrupted runs visibly incomplete.
- [ ] Persist a successful evaluation and its observed evidence consistently. An interrupted emission loop must not leave apparently current partial results without an incomplete run record.
- [ ] Publish only new urgent issues, material worsening, or eligible follow-up reminders. Use occurrence/change identity rather than mutable `updated_at` as the notification key.
- [ ] Reuse existing recipient preferences. No configured daily cap exists in the current notification service or schema; define and implement one before publishing. Recheck source permissions at delivery; revoked access suppresses the message.
- [ ] Keep email disabled until delivery handoff is idempotent; `NotificationService` can queue email for a deduplicated send even when its in-app notification row was ignored as a duplicate.
- [ ] Test duplicate triggers, concurrent schedule/manual runs, failed delivery retry, midnight/date-zone limits, permission revocation, and shadow/disabled checks.

Exit: timely advice reaches the responsible user without repeated messages or unbounded scans, and failures remain visible.

## R7: Evaluation, executive view, and predictive capabilities

Create separate evaluation and forecast/scenario implementation plans after the supporting decision families pass validation.

- [x] Add a `dss.manage`-only rolling 30-day admin activity summary: tracked `viewed` events (one per recommendation/user/day, not reviews), `started` events, `resolved` events whose metadata source is `source_verification`, and created recommendations with a predecessor link. Counts reflect retained records and events; they do not establish that recommendations were reviewed or that source conditions stayed clear.
- [ ] Add server-scoped reviewed-issue totals, dismissal categories, and resolution time only when event data supports those claims; current activity summary intentionally omits them.
- [ ] Separate self-reported outcomes, estimated exposure, collected/recovered amounts, and verified savings. Do not sum overlapping recommendations or call adoption an accuracy measure.
- [ ] Assess sampled relevance/false positives per rule. Calculate precision or recall only with a labelled reference set and stated denominators.
- [ ] Tailor executive/manager/operator views through permitted data and meaningful ordering; administrative access alone does not define a business audience.
- [ ] Validate D04/D05/D14/D15/D20/D28/D29 forecasting components and D30/D31 summaries/scenarios against their row-specific prerequisites. Record model/policy version, history window, error metrics, assumptions, and insufficiency.
- [ ] Reuse the existing forecast/scenario models where their schema fits. Simulate with immutable baseline inputs; prove no workflow, inventory, payroll, payment, or planning state is modified.

Exit: the system reports what was actually observed and measured, with predictive features limited to validated data and explicit assumptions.

## Scenario matrix and delivery checks

| Scenario | Required assertions |
|---|---|
| Delivery risk with a real linked blocker | Correct affected order, evidence, team, priority reasons, source action, and verified clearance |
| Production work late | Exact work-order reference, planned date, quantities/units, and active follow-up across reruns |
| Receivables exposure | Decimal amount, currency, invoice scope, payment state, and no false savings claim |
| Quality response breach | Actual response clock, source severity, permitted next step, and closure rules |
| Maintenance and training expiry | Valid due/renewal evidence, responsible role, and no restricted employee leakage |
| Payroll anomalies | Period-level evidence for permitted viewers; protected employee facts remain inaccessible |
| Approval and master-data issue | Current role/delegation and approved source access; readable gap and unblock action |
| Stale/partial/failed evaluation | Honest unavailable state; no false healthy status, financial estimate, or verified resolution |
| Concurrent and repeated actions | One active occurrence, idempotent actions/events, no duplicate publishing |

- [ ] Include a source scenario and expected result manifest with each delivered detector or policy change.
- [ ] Run the focused tests named in that release plan before broader integration gates.
- [ ] At final integration, run `php artisan test tests/Feature/DSS` inside the API test environment, the focused Decision Center Vitest suite, `npm run typecheck`, and route parity.
- [ ] Review browser behavior in one batched desktop/mobile pass, correct observed issues, and confirm once. Record what was actually exercised.
- [ ] Use additive schema rollout, API compatibility, and per-detector shadow/advisory switches. Do not promote all detectors because the UI is complete.
- [ ] Update `docs/dss/PROGRESS.md` with delivered capability, observed validation, unresolved source prerequisites, and the next bounded plan.

## Stop conditions

Stop a release when its observable exit criteria pass. Stop a specific capability at its stated evidence prerequisite; continue independent capabilities. Never report complete system coverage merely because all modules have a label or registered detector. Do not execute this roadmap until implementation is requested.
