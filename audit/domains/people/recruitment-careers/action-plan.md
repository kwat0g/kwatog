# M016 — People / Recruitment & Careers action plan

Status: `📋 Plan Ready`  
Audit date: 2026-08-25  
Overall recommendation: `separate-recommended`

This plan is intentionally deferred from the audit session. Most items touch permissions, concurrency, lifecycle/state-machine behavior, audit history, or cross-module notification/conversion behavior. The next session that claims this existing Plan Ready module should execute the items below in order; it should not re-run the plan-risk decision before fixing.

## Ordered fix items

| Order | Finding | Fix item | Scope | Session recommendation |
|---:|:---:|---|:---:|:---|
| 0 | Decisions | Confirm the four domain decisions recorded in the audit report: hiring interview gate, slot-cap semantics, conversion department/position ownership, and public feature/deadline semantics. Encode the decisions in tests and documentation before changing behavior. | small | `separate-recommended` |
| 1 | F01 | Enforce `hr.recruitment.hire` for any transition into `Hired`, including direct service/controller invocation; keep `hr.recruitment.applications` for non-hiring stage actions. Add an applications-only denial test and a permitted hiring test. | medium | `separate-recommended` |
| 2 | F04 | Move public posting status/deadline validation into the submission transaction: lock the posting, recheck it, and only then create the application/file association. Add a two-connection race test for closing/archive versus submission and preserve duplicate-email handling. | medium | `separate-recommended` |
| 3 | F05 | Define whether interview edits are allowed after Offer/Hired/Rejected. Lock the application and interview in a consistent order, revalidate stage/terminal policy, and make the SPA controls obey the same rule. Add sequential and concurrent outcome/stage tests. | medium | `separate-recommended` |
| 4 | F06 | Make archive check-and-delete atomic and define update mutability by posting status and application presence. Resolve races with submission and with concurrent posting updates; add lifecycle tests for Draft/Open/Closed/Filled and archived records. | medium | `separate-recommended` |
| 5 | F08 | Resolve notification recipients through `hr.recruitment.view` rather than role slug alone. Validate configured roles or safely filter invalid roles, retain the documented fallback, and test that non-view users receive neither candidate PII nor private links. | small | `separate-recommended` |
| 6 | F02 | Require `Hired` bottleneck alerts to have no `converted_employee_id`, and add a deduplication test for converted versus unconverted hires. | small | `same-session-ok` |
| 7 | F07 | Define the event retention/redaction contract, include all meaningful interview fields in before/after snapshots, and enforce the required immutability/retention behavior at the persistence boundary. Add snapshot and mutation-attempt tests. | medium | `separate-recommended` |
| 8 | F09 | Introduce the module’s explicit `StateMachine` and `TRANSITIONS` convention (or document an approved exception), make transition/idempotency rules uniform, and return stable `DomainException(message, 'CODE', httpStatus)` errors for policy failures. Update unit/feature tests for every allowed and denied edge. | medium | `separate-recommended` |
| 9 | F03 | Apply the confirmed public feature-toggle policy consistently to routes, controller responses, and the public SPA. Add enabled/disabled tests; if public careers is intentionally exempt, document and test that exception instead. | small | `separate-recommended` |
| 10 | F10 | Expose the full supported interview edit contract or narrow the API contract deliberately; persist stage/status/archive filters in URLs; and remove or implement the posting-detail `trashed` parameter. Add UI/API contract tests. | medium | `same-session-ok` |
| 11 | F11 | Link all public labels to controls, add a visible tracking-input label, replace opacity surface variants with approved opaque tokens, and attach server errors to every posting form field. Run keyboard/accessibility and visual checks. | small | `same-session-ok` |
| 12 | F12 | Add regression coverage for F01–F08, including two-connection race tests and recruitment Playwright flows. Resolve the unrelated holidays migration and SPA typecheck blockers, then run the focused backend suite, lint, typecheck, token audit, and browser checks. | large | `separate-recommended` |

## Acceptance evidence

The module can move from Plan Ready to Verified only when all of the following are available:

- an applications-only actor cannot reach `Hired`, while a hire-authorized actor can;
- closed/archived postings cannot accept submissions in a check/write race;
- interview outcome changes obey the confirmed stage/terminal policy under sequential and concurrent execution;
- posting archive/update invariants are atomic and covered for each lifecycle status;
- notification recipients are permission-filtered and contain no unauthorized candidate data;
- converted hires do not create bottleneck alerts;
- event snapshots contain the agreed non-PII workflow state and cannot be mutated/deleted through supported persistence paths;
- all transition failures expose stable error codes/statuses and the `TRANSITIONS` map is covered;
- public feature/deadline behavior matches the recorded decisions;
- SPA filters, archive scope, interview editing, labels, surface tokens, and form errors match the API/design-system contract;
- focused backend tests, recruitment browser tests, PHP lint, targeted ESLint, SPA typecheck, and token audit pass without the current environment blockers.

## Session handoff

The first fix session should start with item 0, then implement items 1–5 before touching polish. If a decision remains unresolved, defer only the affected item and record the exact question in `fix-log.md`; do not silently choose behavior. If all items are completed, re-check only the findings fixed, append file:line before/after entries to `fix-log.md`, and release as `✅ Verified`. If work stops partway for a real constraint, record the pending item and reason and release as `🔁 Needs Re-audit`.
