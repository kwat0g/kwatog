# M034 — Customer complaints / 8D re-audit report

Audit date: 2026-08-25  
Status: 🔁 Needs Re-audit  
Scope: current shared worktree for the customer-complaints-8d module only

## Re-audit decision

The 2026-08-24 report was stale for this session. Since its recorded HEAD,
the shared worktree contains substantial M034 changes in the CRM service,
portal service/resources/controllers, complaint requests, complaint SPA pages,
tests, and retention/index migrations. The findings below are based on the
current files, not on the earlier snapshot.

The former P1 stale-write, lifecycle-gate, portal-disclosure, PDF-publication,
and provenance findings are addressed in the current implementation. This
session also fixed the SLA delivery accounting gap and the two small portal
correctness gaps. Cancellation semantics and permission granularity remain
policy questions and are intentionally not guessed.

## Findings

### M034-R01 — P1, Incomplete at re-audit; fixed this session: SLA delivery ledger

The original gap was the complaint JSON marker being the only durable claim
record. It is now addressed by
`api/database/migrations/2026_08_26_000000_create_complaint_8d_escalation_deliveries.php:13-35`
and `api/app/Modules/CRM/Models/Complaint8dEscalationDelivery.php:11-43`.
The service now locks the complaint and one `(complaint, tier)` row,
persists an idempotency key, attempts, recipient count, pending state, error,
and sent timestamp at
`api/app/Modules/CRM/Services/Complaint8dEscalationService.php:119-248`.
Notification rows and the compatibility JSON marker remain in the same
transaction; a failed send is recorded as retryable by `:250-405`, and an
empty deliverable audience does not consume the tier at `:201-207`.

The focused integration assertions now cover sent/idempotent, failed-send, and
no-recipient cases in
`api/tests/Feature/CRM/Complaint8dSlaTest.php:66-219`. They remain unexecuted
because the shared test reset fails in the unrelated holiday migration noted
below.

### M034-R02 — P2, Incomplete at re-audit; fixed this session: cancelled portal order

The request validator now checks the owned order status and returns a field
validation error for `cancelled` at
`api/app/Modules/B2B/Requests/Customer/CreateComplaintRequest.php:27-43`.
The authoritative CRM transaction-time check remains at
`api/app/Modules/CRM/Services/ComplaintService.php:441-445`, and the negative
API regression is at
`api/tests/Feature/B2B/CustomerPortalServiceTest.php:414-440`.

### M034-R03 — P2, Broken at re-audit; fixed this session: portal audit action

Portal complaint creation now writes the stable action
`customer.complaint.submitted` at
`api/app/Modules/B2B/Services/CustomerPortalService.php:281-298`, matching the
contract assertion at
`api/tests/Feature/B2B/CustomerPortalServiceTest.php:398-412`.

### M034-R04 — Missing, P2: cancellation is still an unreachable status

Evidence: `api/app/Modules/CRM/Enums/ComplaintStatus.php:8-18` defines
`cancelled` as terminal. No cancellation route exists in
`api/app/Modules/CRM/routes.php:64-84`, and the current complaint detail action
surface only exposes quality-gated resolve/close actions at
`spa/src/pages/crm/complaints/detail.tsx:217-231`.

Impact: duplicate or misfiled complaints cannot be corrected through an
auditable first-class workflow. Adding a transition without a business rule
for reason, actor, and customer-visible behavior would guess at policy.

Action: product/quality owners must decide whether cancellation is needed. If
yes, add an authorized reason, locked transition, audit event, and UI action;
otherwise retire/deprecate the public status contract. Deferred pending that
decision.

### M034-R05 — Incomplete, P2: internal complaint permissions remain one manage gate

Evidence: every internal complaint endpoint, including reads, 8D editing,
finalization, lifecycle changes, retry, and PDF, uses
`crm.complaints.manage` at `api/app/Modules/CRM/routes.php:65-84`. The only
seeded complaint permission remains `crm.complaints.manage` at
`api/database/seeders/RolePermissionSeeder.php:317`.

Impact: the least-privilege role matrix cannot distinguish review, mutation,
finalization, lifecycle, retry, and formal-artifact download. Splitting these
permissions requires an explicit role decision and coordinated RBAC rollout.

Action: define the role/action matrix with the RBAC owner, then add permissions,
route gates, UI checks, and matrix tests in a dedicated authorization session.
Deferred pending that decision.

## Prior findings rechecked

| Earlier finding | Current result | Evidence |
|---|---|---|
| F01 stale 8D edit/finalize | Addressed | `ComplaintService.php:177-256` locks complaint then report and re-checks finalization inside transactions. |
| F02 quality completion gate | Addressed in code/UI | `ComplaintService.php:327-368`; `detail.tsx:151-152,223-231,320-326`. |
| F03 stale resolve/close | Addressed | `ComplaintService.php:370-419` uses authoritative lock/re-read and explicit transitions. |
| F04 portal disclosure/finalization | Addressed | `CustomerPortalComplaintResource.php:12-44`; `CustomerPortalService.php:303-350`. |
| F05 SLA claim/retry | Addressed in current code; integration verification pending | `Complaint8dEscalationService.php:119-248`; delivery model/migration. |
| F06 provenance/assignment validation | Addressed in current service/request | `StoreComplaintRequest.php:60-137`; `ComplaintService.php:428-475`. |
| F07 unfinished PDF | Addressed | `ComplaintController.php:106-126`. |
| F08 cancellation | Still open; R04 | `ComplaintStatus.php:8-18`; CRM routes. |
| F09 permission granularity | Still open; R05 | CRM routes and RolePermissionSeeder. |
| F10 source-linking contracts | Addressed for the reported mismatch | `create.tsx:49-73,132-189`; portal request rejects unsupported `product_id` at `CreateComplaintRequest.php:37-43`. |
| F11 unbounded portal history | Addressed | `CustomerPortalService.php:218-243`; `CustomerPortalController.php:212-226`; portal page pagination/filter bar. |
| F12 customer deletion cascade | Addressed by current migration | `api/database/migrations/2026_08_25_160100_protect_customer_complaint_retention.php:11-29`; regression test `ComplaintSourceValidationTest.php:113-139`. |

## Strengths observed

- Complaint creation, the 8D shell, and NCR handoff remain transactional with a
  durable manual-recovery path.
- 8D update/finalize and complaint lifecycle writes now use one documented
  complaint → report lock order and publish lifecycle/finalization events only
  after successful writes.
- Resolve/close require a finalized 8D report and a closed, dispositioned NCR;
  the internal UI copy and action visibility match that server rule.
- The customer portal uses an explicit allowlist resource and only returns the
  8D report after terminal complaint status, finalization, and NCR completion.
- Portal complaint history is scoped, paginated, filterable, and indexed for
  customer/date access. The portal form rejects the previously advertised but
  unsupported product linkage.
- Formal internal PDF download is server-gated on `finalized_at`.
- SLA escalation now has a durable complaint/tier outcome ledger with retryable
  pending state and compatibility JSON markers.

## Verification and evidence gaps

PHP lint passed for the current CRM/B2B production and M034 test files.

PHP lint and scoped diff checks pass after the current-session fixes. The
focused Docker feature suite still cannot reach M034 assertions: the shared
`ogami_test` database reset is not in a clean state and fails before assertions
with duplicate `migrations`/`roles` relations. An earlier reset attempt also
failed in the unrelated dirty-worktree migration
`api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:42`,
which attempts to drop the `holidays_date_name_unique` index while PostgreSQL
still owns it as a table constraint. Those dependencies are outside M034 and
were not modified. No M034 test assertion has executed in this session.

Concurrency tests for 8D writes, lifecycle transitions, and SLA workers should
still be run after the shared migration issue is repaired. Browser/e2e evidence
for the internal and portal complaint flows is also not available in this
session.

## Release decision

M034 cannot be marked Verified in this session. The actionable fixes below are
contained in M034 scope and can be applied now; cancellation and RBAC remain
genuine human-decision blockers. Release as `🔁 Needs Re-audit` with explicit
pending items in the fix log.
