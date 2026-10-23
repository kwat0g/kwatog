# System-wide DSS design

Date: 2026-10-23

Status: Product direction accepted in conversation. Implementation has not started under this design.

## Approved product decisions

The user selected every recommended option in four rounds of questions:

1. Prioritize decisions and explain their business importance.
2. Use an overview, a recommendation table, and a detail panel.
3. Show the decision, reason, urgency, impact, owner, and next action upfront.
4. Guide users into existing business workflows and preserve their approvals.
5. Tailor the experience to executives, managers, and operational decision owners.
6. Complete delivery-risk analysis first while retaining system-wide scope.
7. Rank by business impact, urgency, and downstream effects; group related findings.
8. Explain evidence quality, freshness, and assumptions. Predictive confidence needs validation.
9. Verify resolution using source records.
10. Present the best next action, feasible alternatives, and their consequences.
11. Run checks automatically and issue selective notifications.
12. Provide repeatable scenarios in an isolated demo database.

The user also requires plain English, readable evidence rather than raw JSON, dropdown filters, and consistency with the existing system design. The planning request follows the approved discussion; it does not authorize deploying the redesign or promoting detectors to advisory.

## Existing implementation and verified gaps

The implementation uses Laravel, PostgreSQL, React, TypeScript, React Query, React Router, and the shared Atelier UI components. Nine detector families are registered in `api/app/Modules/DSS/DssServiceProvider.php`; the decision inventory contains 33 decisions. Registration does not establish production readiness.

Current behavior that affects the design:

- `spa/src/pages/decision-center/helpers.ts` serializes nested evidence with `JSON.stringify`. Both list and detail pages display internal type, domain, and detector identifiers.
- `RecommendationResource` returns raw rationale and an existing module action. Most aggregate evidence contains document numbers without stable source identifiers or authorized detail URLs.
- `RecommendationStatus::isTerminal()` treats accepted recommendations as terminal. `DssRunnerService::persist()` only deduplicates open and snoozed rows. An accepted issue can therefore generate a new open row on another run.
- `RecommendationScorer` derives urgency from recommendation expiry. Recommendation validity and the business deadline need separate meanings.
- `RecommendationService` uses `updated_at` for freshness comparisons. User interaction and detector verification need separate timestamps.
- Complete-scan disappearance currently expires a recommendation. A verified business resolution requires a defined source condition and recorded evidence.
- The list endpoint currently supplies open work only. Overview totals, filters, search, sorting, and follow-up/history require server support.
- `ActionCenterTaskService` already manages operational task assignment. DSS should link to that responsibility rather than create another competing assignment system.
- Detector health exists. DSS-specific notification publishing and outcome evaluation summaries do not yet exist.
- The receivables detector emits `PHP receivable balance` into `impact_unit`, whose database limit is 20 characters; that label is 22 characters. Currency and impact meaning need separate fields before this output is expanded.

Read `docs/dss/AUDIT-2026-10-23.md`, `docs/dss/FINDINGS.md`, and `docs/dss/01-decision-inventory.md` alongside this design. Reconfirm older findings against current code before fixing them.

## Boundaries and responsibilities

| Boundary | Responsibility | Existing implementation to reuse |
|---|---|---|
| Source modules | Authoritative business state, source permissions, approvals, and actions | Existing module services and controllers |
| Detector pipeline | Bounded evaluation, business facts, relevant subjects, completeness, source condition | `DetectorInterface`, `DetectorEmission`, `DetectorScanResult`, `DssRunnerService` |
| Decision projection | Apply viewer scope before returning evidence, counts, linked records, and available actions | `RecommendationService`, `RecommendationResource` |
| Business presentation | Human titles, enum labels, units, formatted dates, evidence tables | One shared SPA presentation module used by list and detail |
| Workbench | Overview, dropdown filters, table, responsive details, follow-up | `PageHeader`, `FilterBar`, `DataTable`, `Panel`, existing route |
| Lifecycle | Follow-up, snoozing, dismissal, source-verified resolution, recurrence, audit | Existing recommendation service, models, and events |
| Publishing | Meaningful change detection, recipient checks, deduplication, rate limits | Existing notification and queue services |
| Evaluation | Source-verified outcomes, sampled relevance, recurrence, and evidence readiness | Existing recommendation events and outcomes |

Business calculations, source joins, and permission checks stay on the server. Presentation templates do not calculate business risk. The first release adapts the current rationale shape at one SPA boundary; future structured evidence replaces legacy adaptation when all current emitters and retained rows support it.

## Main page

The header uses `PageHeader`. A compact overview shows authorized active decisions, urgent decisions, affected commitments, and verified exposure where a meaningful total exists. Counts come from the scoped query before pagination. Financial amounts remain decimal strings; mixed currencies and different exposure measures are shown separately. Receivable balance is not described as savings.

`FilterBar` provides search and dropdowns for priority, business area, work status, and responsible team. Values and the selected recommendation are reflected in the URL. Filter options come from the server's authorized scope. Changing a filter or sort resets pagination. Search, sorting, and filtering apply to the entire authorized result set, not just the downloaded page.

The table columns are Priority, Decision, Business impact, Responsible team, Business deadline or age, Evidence freshness, and Next step. The decision cell contains a short title and a reason for attention. Repeated generic detector titles are replaced by a business subject or a specific issue category.

On wide screens, selecting a recommendation opens a side panel without losing list state. On narrow screens, the same detail content uses the existing detail route. Browser back restores filters, pagination, and selection. Use shared table preferences, loading, error, empty, and retry patterns.

Technical detector identifiers, versions, namespaces, raw payloads, and implementation class names appear in the authorized administration surface. Business users receive a short explanation when checks are unavailable or incomplete.

## Recommendation details

The panel and direct detail route use the same content component:

1. A plain-language decision title and the responsible business area.
2. What happened and why it matters now.
3. A suggested next step, its prerequisites, and the existing workflow destination.
4. Feasible alternatives with factual tradeoffs. An unavailable alternative includes the specific missing prerequisite.
5. Authorized evidence tables with document references, statuses, quantities, dates, and units.
6. Affected commitments and confirmed upstream or downstream relationships.
7. Evidence quality, last successful evaluation, missing inputs, assumptions, and applicable policy.
8. Follow-up history and outcomes.

For the screenshot example, the business title could be “Review delivery DR-202610-0002 for invoicing.” The evidence shows the delivery, confirmation status, elapsed time, and the configured threshold. The system must distinguish a confirmed status-transition time from an age derived from `updated_at`; the latter cannot be labelled as a proven waiting duration.

Raw object serialization is never a business evidence fallback. Unknown legacy data produces “Detailed evidence is unavailable” and an authorized workflow destination. Original document codes remain exact even when they contain underscores.

## Evidence and decision contract

Enrich the existing recommendation contract additively with these meanings:

| Field | Meaning |
|---|---|
| `decision_code` and `policy_version` | Stable business decision definition and the rule version used |
| `source_references` | Bounded typed references with source kind, opaque public ID, display reference, and authorized destination |
| `evidence` | Typed facts and tables with labels, values, units, provenance, and completeness |
| `business_due_at` | A real business commitment or configured response deadline |
| `first_detected_at` | First observation of this occurrence |
| `last_evaluated_at` | Last detector evaluation of this issue; user edits never advance it |
| `source_observed_at` | Timestamp of the underlying source evidence where known |
| `expires_at` | Recommendation validity; it does not represent business urgency |
| `priority_reasons` | Explainable components of the current ordering |
| `alternatives` | Available choices, constraints, consequences, and workflow destinations |
| `resolution_evidence` | Source state, successful verifier/run reference, and time proving the issue cleared |

Preserve existing fields while migrating callers. Avoid one generic metadata object that requires every consumer to understand detector internals. Source links must carry real public identifiers; never fabricate a URL from a display number or expose a polymorphic PHP class name.

All summaries, filter options, search results, evidence, grouping, notifications, and outcome views use the same server-side visibility policy. Each linked source record also needs its source module authorization. A joined recommendation must not reveal restricted evidence through counts or wording.

## Priority and related issues

Keep severity, business urgency, financial exposure, dependency effects, and evidence quality distinct. Replace expiry-based urgency with source deadlines and configured service targets. Priority explanations identify the relevant factors rather than displaying an unexplained score.

Introduce a versioned policy with deterministic tie-breaking. Compare financial amounts only within the same currency and impact category. Missing amounts remain unknown. Validate the new ordering against representative scenarios and historical samples before replacing the existing score in advisory output.

Group findings only when stable source relationships prove they belong to the same issue or commitment. Preserve each underlying finding and its audit trail. Project a group after viewer authorization; do not group by matching text or infer root cause from correlation. An observed upstream delay is labelled as a contributing factor unless causality is established.

## Lifecycle and compatibility

| Business state | Meaning |
|---|---|
| New | A current verified issue needs review |
| In progress | An authorized user has started follow-up; the issue remains active |
| Snoozed | Follow-up is deferred to a specific time; urgent material changes remain eligible for review |
| Dismissed | A reason records why the advice was rejected; this does not prove the source issue cleared |
| Resolved | An explicit source condition has been successfully verified |
| Expired | Evidence or policy validity ended; the business outcome is unknown |
| Legacy action recorded | Historical accepted/executed records retain their original audit meaning |

Add `in_progress` and `resolved` states and corresponding events. New UI actions start follow-up rather than implying that acceptance completes the business work. Existing accepted/executed rows and API actions retain compatibility during the transition; neither is counted as verified resolution. Do not bulk rewrite historical acknowledgements as successful outcomes.

Expand the active dedupe invariant to open, in-progress, and snoozed records. A recurring issue after verified resolution creates a new occurrence linked to the earlier one. Dismissal behavior and repeat notifications are governed by documented occurrence and material-change rules.

Resolution requires a successful source verifier or a complete evaluation over the same explicit scope. A missing emission alone is insufficient. Failed queries, partial scans, disabled detectors, lost permission, configuration changes, deleted source records, and elapsed validity cannot count as resolution.

## Existing workflow integration

Suggested actions open existing workflows. Every business write and approval remains subject to that workflow's permissions, current state, and maker/checker rules. Assignment and execution responsibilities use existing Action Center or source workflow functionality where applicable. A DSS follow-up may reference an existing task; it must not silently resolve or snooze that task for everyone.

Opening details or a source link records a view at most; it does not claim that work started. Starting follow-up is an explicit idempotent action. Estimated consequences and user notes are labelled separately from measured outcomes.

## Updates and notifications

Retain scheduled runs as the recovery path. Add after-commit event triggers only where the owning module already has a reliable event boundary. Repeated events coalesce into a bounded detector or subject check. Queue failures remain visible to administrators.

Notify only eligible users about new urgent issues, material worsening, or relevant follow-up deadlines. Recheck permissions at delivery time. Deduplicate using recipient, occurrence, and change identity. Existing notification preferences are available, but a configured daily cap was not found in the notification service or schema; define and implement a cap before enabling DSS publishing. Shadow detectors never publish business notifications. Keep email disabled until delivery handoff is idempotent.

## Complete system coverage

The roadmap accounts for every D01–D33 decision in the existing inventory. Each capability has a named business owner, authoritative inputs, rule semantics, viewer scope, action destination, resolution condition, demo scenario, and validation evidence before advisory promotion.

The internal portal and public landing page remain interfaces to the same business processes. Portal or public users receive no internal DSS data by default. New portal-specific decisions require an identified use case and source authorization.

Forecasting and what-if analysis use the existing forecasting and MRP services where suitable. Simulations use isolated inputs and never persist production planning changes. Forecasts identify their training period, assumptions, error measures, and data sufficiency. Asset replacement, lot recall, cash optimization, and sensitive workforce predictions remain explicitly unavailable while required evidence or policy is missing.

## Demo and validation

Provide opt-in, deterministic source scenarios in a dedicated local/testing database. Each implemented decision has a manifest describing the actor, prerequisite records, expected issue, evidence, priority reasons, permitted action, and resolution check. Seed business source records and invoke real detectors; do not bypass the pipeline by inserting recommendation cards.

Reuse `ProductionAssertions::assertDemoSeedingAllowed()` and require a dedicated test database for automated scenarios. Fixtures use tagged deterministic keys and a frozen clock in tests. Repeated seeding does not duplicate recommendations, alter real accounts, change global advisory settings, or trigger external email or supplier integrations.

Acceptance includes meaningful presentation tests, PostgreSQL lifecycle/scoping tests, API query correctness, route parity, desktop/mobile browser review, keyboard access, themes, stale and partial data states, and a complete delivery-risk scenario. Notification and evaluation releases add their own focused tests.

## Completion definition

The work is complete when the agreed workbench and lifecycle operate across each delivered decision family, the D01–D33 coverage map accurately distinguishes validated capabilities from blocked ones, the delivery-risk chain has a complete demonstrated path, and every active capability has role-scoped evidence, useful actions, a verified resolution condition, and a repeatable scenario. Full system scope must remain visible without presenting unvalidated capabilities as available.

## Plan decomposition

The program roadmap is `docs/superpowers/plans/2026-10-23-dss-system-roadmap.md`. The first executable slice is `docs/superpowers/plans/2026-10-23-dss-readable-decisions.md`. Independent later subsystems receive their own detailed implementation plans at the named release boundary after their source prerequisites have been verified. This separates current executable work from evidence-dependent business capabilities.
