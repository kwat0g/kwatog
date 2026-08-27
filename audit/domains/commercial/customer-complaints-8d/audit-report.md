# M034 — Customer complaints / 8D re-audit report

Audit date: 2026-08-27
Status: 🔁 Needs Re-audit  
Scope: current shared worktree for the customer-complaints-8d module only

## Re-audit decision

The 2026-08-25 report was stale for this session. Since its recorded HEAD,
the shared worktree contains substantial M034 changes in the CRM service,
portal service/resources/controllers, complaint requests, complaint SPA pages,
tests, and retention/index migrations. The findings below are based on the
current files, not on the earlier snapshot.

The former P1 stale-write, lifecycle-gate, portal-disclosure, PDF-publication,
and provenance findings are addressed in the current implementation. This
session also re-ran the SLA delivery accounting coverage and fixed three
additional complaint-path contract defects. Cancellation/investigation
semantics and permission granularity remain policy questions and are
intentionally not guessed.

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

The focused integration assertions cover sent/idempotent, failed-send, and
no-recipient cases in
`api/tests/Feature/CRM/Complaint8dSlaTest.php:66-219`. They executed against
the isolated M034 database in the 2026-08-27 sweep.

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

### M034-R04 — Missing, P2: cancellation and investigation states are unreachable

Evidence: `api/app/Modules/CRM/Enums/ComplaintStatus.php:8-18` defines both
`investigating` and terminal `cancelled`, but the transition matrix in
`api/app/Modules/CRM/Services/ComplaintService.php:48-61` only exposes
resolve/close operations. No investigation or cancellation route exists in
`api/app/Modules/CRM/routes.php:64-84`, and the current complaint detail action
surface only exposes quality-gated resolve/close actions at
`spa/src/pages/crm/complaints/detail.tsx:217-231`.

Impact: staff cannot mark a complaint as actively investigating, and duplicate
or misfiled complaints cannot be corrected through an auditable first-class
workflow. Adding either transition without a business rule for reason, actor,
and customer-visible behavior would guess at policy.

Action: product/quality owners must decide whether investigation should be an
explicit transition and whether cancellation is needed. If yes, add authorized
reasons/actors, locked transitions, audit events, and UI actions; otherwise
retire/deprecate the unused public status values. Deferred pending that
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

### M034-R08 — Broken, P1 at re-audit; fixed this session: complaint update email rendering

Before this session, the queued customer-update path called `label()` on the
`ComplaintStatus` and shared `NcrSeverity` enum casts even though neither enum
defines that method. The Blade call sites were
`api/resources/views/emails/customer/complaint-update.blade.php:10-11`, and the
missing-email fallback called the same invalid API at
`api/app/Modules/CRM/Listeners/EmailCustomerOnComplaintUpdated.php:59-62`.
The red regression run reproduced both failures: a `ViewException` during
mailable rendering and an `Error` before the internal fallback notification.

The fixed paths now derive human labels from backed enum values with
`Str::headline` at `EmailCustomerOnComplaintUpdated.php:61-67` and the Blade
view's lines 1-20. `api/tests/Feature/CRM/ComplaintEmailTest.php:28-74`
covers valid-email rendering and missing-email fallback delivery. This was a
small same-session fix because it stayed within the complaint notification
surface and did not alter the shared Quality enum.

### M034-R09 — Incomplete, P2 at re-audit; fixed this session: invalid customer filter broadened results

The internal list controller previously decoded an invalid `customer_id` hash
to `null`, while `ComplaintService::list` skipped empty customer filters. A
malformed filter therefore returned all complaints rather than no matches.
The boundary is now fail-closed at
`api/app/Modules/CRM/Controllers/ComplaintController.php:30-40`, and
`ComplaintSourceValidationTest.php:115-139` proves an invalid hash returns an
empty result set. The internal permission gate limits exposure, but the prior
behavior was still an incorrect query-boundary contract.

### M034-R10 — Incomplete, P2 at re-audit; fixed this session: NCR list/UI contract drift

The list query selected only NCR `id`, number, and status at
`ComplaintService.php:70-77`, while `CustomerComplaintResource` advertised
severity; the resource also omitted disposition even though the server's
resolve/close gate requires it. The query now selects severity and disposition,
the resource exposes disposition, and the internal SPA type and completion gate
check the same field at `spa/src/types/crm.ts:201` and
`spa/src/pages/crm/complaints/detail.tsx:149-152`. The API contract regression
is covered at `ComplaintSourceValidationTest.php:141-169`.

## Prior findings rechecked

| Earlier finding | Current result | Evidence |
|---|---|---|
| F01 stale 8D edit/finalize | Addressed | `ComplaintService.php:177-256` locks complaint then report and re-checks finalization inside transactions. |
| F02 quality completion gate | Addressed in code/UI | `ComplaintService.php:327-368`; `detail.tsx:151-152,223-231,320-326`. |
| F03 stale resolve/close | Addressed | `ComplaintService.php:370-419` uses authoritative lock/re-read and explicit transitions. |
| F04 portal disclosure/finalization | Addressed | `CustomerPortalComplaintResource.php:12-44`; `CustomerPortalService.php:303-350`. |
| F05 SLA claim/retry | Addressed and verified | `Complaint8dEscalationService.php:119-248`; delivery model/migration; `Complaint8dSlaTest.php`. |
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
- Complaint update notifications now render status/severity values and fall
  back to an internal alert when the customer email is unusable.

## Verification and evidence gaps

PHP lint passed for the changed M034 PHP files and `git diff --check` passed.
The focused Docker feature sweep ran on the isolated database
`ogami_test_m034_20260827`:

    62 passed, 205 assertions, 0 failures

This includes the SLA, lifecycle, NCR handoff, recurrence, source-validation,
email, and customer-portal complaint tests. The SPA typecheck and scoped ESLint
for the changed complaint page/types also passed. Laravel Pint remains
non-clean on pre-existing formatting drift in several touched legacy files;
the new email test itself is clean, and no unrelated formatter rewrite was
included.

Concurrency tests for 8D writes, lifecycle transitions, and SLA workers should
still be run in a dedicated multi-connection/worker environment. Browser/e2e
evidence for the internal and portal complaint flows is also not available in
this session.

## Release decision

M034 cannot be marked Verified in this session. The actionable fixes below are
contained in M034 scope and can be applied now; cancellation and RBAC remain
genuine human-decision blockers. Release as `🔁 Needs Re-audit` with explicit
pending items in the fix log.
