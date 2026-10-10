# Recruitment hardening and experience design

Date: 2026-10-07. Status: planned, not implemented.

## Goal

Make recruitment safe under concurrent use, complete the candidate-to-employee process, and make the public and internal interfaces understandable, accessible, and recoverable. This design covers the completed recruitment audit; it does not authorize application changes or deployment in the planning session.

## Evidence and scope

The audit inspected the public careers journey, posting management, application stages, interviews, employee conversion, permissions, notifications, bottleneck reporting, and privacy/file handling. The existing recruitment suites passed 36 tests and 111 assertions. Ten additional temporary probes reproduced missing safeguards. Browser probes covered desktop and mobile rendering, permission-dependent actions, failed submission/lookup, and accessibility attributes. Browser failure responses were simulated; no real candidate was submitted. Those passing suites are a baseline, not evidence that the new requirements already work.

The audited implementation lives in `api/app/Modules/HR`, `api/app/Console/Commands/CheckRecruitmentBottlenecks.php`, `spa/src/pages/hr/recruitment`, and `spa/src/pages/careers`. Existing employee onboarding, approval workflows, document storage, email-failure reporting, calendar aggregation, and UI controls remain the integration seams.

## Accepted defaults

The user accepted the recommended defaults on 2026-10-07:

- A candidate occupies a slot when marked **Hired**, not when converted to an employee.
- Hiring requires recorded offer acceptance. HR prepares the offer; the default approval chain reuses the existing salary policy: production manager checks, vice president approves. The submitter and the two decision makers must be different people. This is a product default, not a claim about legal requirements.
- Candidate data becomes eligible for retention review 12 calendar months after closure. Review does not automatically delete data. Legal holds and explicitly approved deletion requests take precedence.
- A date-only application deadline includes the entire selected date in the configured business timezone, currently `Asia/Manila`.

## Architecture and reuse

Keep business invariants in `RecruitmentService`, with the existing application/posting state machines as the legal transition tables. Controllers validate and authorize; resources expose facts and allowed actions; React never decides whether a transition is legal. Add a focused offer service because approvals, terms, and document generation are a distinct responsibility, not a general-purpose workflow framework.

Reuse `ApprovalService`, `HasApprovalWorkflow`, `ApprovalTypeRegistry`, `ApprovalSourceScope`, `DocumentVaultService`, `PdfRenderService`, `OutboxService`, `EmailDeliveryFailureNotifier`, `CalendarAggregatorService`, `SettingsService`, and the existing immutable `RecruitmentApplicationEvent`. Reuse the frontend authenticated download helper, request-key helper, forms, dialogs, query client, tables, filters, timelines, and Atelier design tokens. Do not add a second audit log, notification engine, calendar, form library, or candidate-account system.

The only new infrastructure dependency is malware scanning. Use a private ClamAV service, the installed Symfony Process component, and the native `clamdscan` client. No new Composer or browser package is required. No candidate ranking, artificial-intelligence screening, résumé parsing, talent marketplace, configurable interview builder, e-signature provider, or ATS integration is included.

## Business invariants

### Mutation boundaries and concurrency

1. Interview requests cannot supply `job_application_id`, `created_by`, row identifiers, outcome evidence, or other server-owned values. Reject unknown nested keys. Construct the parent and actor fields on the server after validation, including direct service callers.
2. All application decisions include `expected_stage`, `to_stage`, and `expected_version`. Every successful application-affecting mutation increments `lock_version`. A stale mutation returns `409` without side effects and includes the current version/stage and a stable error code, not another candidate's information.
3. Posting status and edits likewise include `expected_version`; the posting increments its own version. Interview edits include their own `expected_version`; they also increment the parent application's version.
4. Acquire prerequisite department/position locks when changing those relationships or converting an employee. Acquire those roots in ascending identifier order, then posting, application, child interview/offer, then employee. Paths that do not need prerequisite locks start at posting. Never acquire the posting after locking its application. Department archival and posting creation share the department lock.
5. Authorization is rechecked against the locked current record, not just the route-bound snapshot. The requested target determines the required permission.
6. No generic retry of stage mutations after `409`. The UI refreshes and asks the actor to review the new state. A lost response may show that the intended state already exists; it never implicitly advances again.
7. Employee conversion checks and locks the application before creating an employee. Concurrent requests return the already-linked employee instead of creating a second employee. The link, employee, onboarding work, and outbox writes remain transactional.

### Headcount and posting state

Occupied slots are applications in `hired`, including applications already converted. Available slots are `max(slots - occupied, 0)`. Count under the posting lock; a second candidate cannot reserve the final slot. A converted employee keeps the historic recruitment slot occupied even after later employee separation. Recruiting a replacement requires an explicit slot increase or a new posting, not rewriting a prior hire.

Mark the posting `filled` when the last slot is reserved. Both `open` and `closed` may become `filled` through hiring; closure of public intake must not break an existing candidate's completion. Conversion consumes the existing reservation and does not reserve again. The actor cannot reduce slots below occupied headcount.

`filled` may become `closed` only through a documented correction or slot increase that creates a vacancy. Public intake is not silently reopened. `closed` may explicitly become `open` when a vacancy, active prerequisites, valid deadline, and publication requirements exist. Reopening an expired posting requires a new deadline or removal of the deadline. Existing overfilled postings are flagged for review and block additional hiring; existing hires are never deleted or demoted automatically.

Keep historical department/position relations readable through soft deletion and make resources null-safe. Block department archival while a nonarchived draft/open posting or an unconverted, nonterminal application depends on it. Historical completed postings do not prevent archival forever. Publishing and converting require an active department and, when specified, an active position belonging to it. An unavailable prerequisite produces an actionable validation error, not a `500`.

For new date-only deadline inputs, normalize to end of day in the business timezone. Preserve explicit legacy timestamps exactly. A read-only integrity report lists legacy midnight deadlines for HR review; only a selected, documented repair converts one to inclusive date semantics. Do not infer historic author intent or silently change all deadlines.

### Feature availability

| State | Public listing and application | Existing candidate tracking/recovery | Internal recruitment |
|---|---|---|---|
| HR and recruitment enabled | Available subject to posting state/deadline | Available | Existing permissions apply |
| Recruitment disabled | Listing returns an empty collection with an availability reason; detail/application returns `410 recruitment_unavailable` | Available, including verified withdrawal and privacy requests | Permission-gated read-only wind-down view; recruitment writes and conversion blocked |
| HR disabled | Same intake closure | Same narrow candidate self-service | HR routes remain disabled; operators must re-enable HR to process work |

Show the disable warning and counts of active applications/unconverted hires in settings. Do not silently finish, reject, or delete existing records. Tracking is capability-authorized independently of internal feature flags; it never exposes HR notes, salary terms, resume paths, or private evaluation outcomes.

### Candidate and interview lifecycle

Application stages are `new`, `screening`, `interview`, `offer`, `hired`, `rejected`, and new `withdrawn`. Use an explicit `closure_reason` for candidate withdrawal, offer decline, offer expiry, hiring rescission, or posting cancellation; do not multiply stage enums for every reason. An expired offer remains in `offer` with an expired offer record until HR closes it or issues a replacement; expiry alone does not reject the candidate.

Normal advancement remains sequential. Rejection or withdrawal closes any non-hired stage. HR can rescind an unconverted hire with `hr.recruitment.hire`, a reason, and the normal concurrency checks; this releases the reservation, cancels the offer, and changes the application to `withdrawn`. Converted hires cannot be reopened or rescinded in recruitment; use employee separation. A new `hr.recruitment.correct` permission allows reasoned reopening of `rejected` or `withdrawn` to `screening` or `interview`, with prerequisites revalidated. It never directly creates an offer or hire. Existing interviews remain as historical evidence; a new round is required when returning to `interview`.

Interview status is separate from outcome: `scheduled`, `completed`, `cancelled`, or `no_show`. A completed interview has `completed_at <= now`, a nonfuture scheduled time, and `passed` or `failed` outcome. No-show and cancellation have reasons and no decision outcome. Scheduled interviews have null outcome; legacy `pending` is normalized to null. Legacy passed/failed rounds get completion evidence only when an existing audit event supplies a credible time; otherwise mark them for verification and do not fabricate a timestamp.

Offer advancement requires at least one verified, completed, passed interview and no unresolved scheduled round. Cancelling an unnecessary round is an explicit action with a reason. Terminal application changes cancel scheduled rounds atomically. Terminal applications reject scheduling and outcome edits; historical outcome correction requires `hr.recruitment.correct`, a reason, and an immutable event. Upcoming tracking and calendar queries include only scheduled rounds for an `interview`-stage application. A past scheduled round awaiting an outcome appears as overdue even if another future round exists.

Capture duration, meeting mode (`onsite`, `online`, `phone`), location/meeting URL, instructions, and timezone. Store timestamps consistently; render the business timezone explicitly. Never email internal interview notes or evaluation outcomes to the candidate. Provide candidate-safe rescheduling and cancellation notices; private corrections only notify internal actors when relevant.

### Ownership, next action, and reporting

Add `owner_user_id`, `next_action`, `next_action_due_at`, `stage_entered_at`, and timestamp provenance. Owners must be active users with recruitment view and application-management access. Unassigned applications remain visible; do not invent ownership. Deactivated owners are displayed as unavailable and appear in the unassigned/reassignment queue.

Record stage entry time only on a real stage change. Note edits do not reset waiting time. Backfill from the latest reliable stage-change event; if no event exists, use `applied_at` as an explicitly estimated timestamp, never `updated_at` as supposedly exact evidence.

Summary counts are SQL aggregates over all matching rows, not the first page. A current-stage distribution has all matching applications as its denominator, including terminal states. A cohort funnel instead counts recorded stage reach events and labels its denominator; do not confuse distribution with conversion. Include active applications, accepting postings, occupied/available slots, unconverted hires, overdue actions/interviews, outcome totals, and median time to hire from credible timestamps. Exclude estimated timestamps from duration metrics and show excluded counts.

Use one shared overdue selector for the dashboard, application list, and scheduled bottleneck command. Preserve the current thresholds: new 2 days, screening 3 days, interview 2 days without a future round, offer 5 days, hired 3 days awaiting conversion; explicit next-action deadlines and overdue completed-time/outcome requirements override the generic stage threshold. Preserve deduplication and dry-run behavior. List filters cover posting, department, stage, owner/unassigned, applied date, overdue status, and sorting. Filters and pagination live in the URL.

### Offers, approvals, and onboarding

An offer is a versioned record associated with an application. Status values are `draft`, `pending_approval`, `approved`, `approval_rejected`, `sent`, `accepted`, `declined`, `expired`, and `cancelled`. The application's `current_offer_id` selects its current version; changing that pointer requires the application lock. A sent/accepted version is immutable; changed terms require cancellation/supersession and a new version with new approvals. Never reuse old acceptance for amended terms.

Terms include job title, department/position, employment type, pay type, currency, exact decimal salary, proposed start date, expiry, and offer body. HR prepares and submits using `hr.recruitment.applications`. Production manager and vice president use new `hr.recruitment.offers.approve`, subject to the saved workflow and maker-checker rules. Missing approvers or workflow configuration block submission clearly. Approval-board access is scoped to the saved workflow's eligible role holders and recorded participants. Approvers see only candidate name and offer terms, not candidate contact details, resume, notes, or other applications. Do not grant broad recruitment view just to permit approval.

Generate the approved PDF through the existing renderer and confidential vault. Sending and recording a candidate response require `hr.recruitment.applications`; only an approved version can be sent. Candidate responses record actor, actual response time, channel, and a bounded evidence/reference note or private attachment. The initial implementation records verified HR evidence; it does not require a new candidate portal or e-signature integration. Offer acceptance recorded after an expired version requires issuing a fresh version, not backdating evidence to bypass expiry. An expiry task may mark expired offers but does not make candidate stage decisions.

Hiring requires a current accepted offer, available slot, and `hr.recruitment.hire`. Conversion requires both hire and employee-create permissions; linked-employee viewing additionally respects employee-view access. Prefill only candidate contact and accepted offer terms already available. Never fabricate birth date, identifiers, emergency contacts, statutory data, or other mandatory employee facts. Salary/start-date changes during conversion require an amended approved offer; do not bypass compensation controls. Show the existing onboarding checklist and linked employee after conversion.

### Public submission, capability access, and communications

Public submission accepts `Idempotency-Key` as a UUID. Reuse the existing frontend request-key utility. Store a unique posting/key pair and a fingerprint of normalized fields and resume checksum. The same key/payload returns the original successful receipt; a changed payload returns `409 idempotency_conflict`. Check replay before rejecting newly closed intake. Concurrent first submissions resolve through the unique constraint and re-read, not a `500` or duplicate upload. Compensate failed transactions by cleaning only the unreferenced newly uploaded object.

Existing-email submissions without the matching receipt key never disclose an existing tracking code or candidate state. Return a generic check-email/recovery message and keep rate limiting. The recovery endpoint always returns the same `202` response; it sends a short-lived, one-time recovery link only to the recorded address. Requesting recovery must not rotate the live tracking capability. Consuming the emailed verification token rotates it atomically and invalidates old capability access.

New tracking capabilities contain 128 bits of randomness (`RCT-` plus `bin2hex(random_bytes(16))`, stored in a widened column). Keep old codes working until verified rotation or privacy revocation. Prefer `POST /applications/track` with code in the body for the updated client; keep the legacy GET route temporarily compatible with explicit log redaction. Direct candidate links use a URL fragment, never a query parameter; clear the fragment after reading it. Tracking/recovery pages have `noindex`, `Referrer-Policy: no-referrer`, and `Cache-Control: no-store`. Never persist candidate form fields, resume contents, or bearer codes in local storage. A nonsecret request UUID may survive in session storage for response-loss recovery.

Persist candidate notification intents in the same transaction as decisions. Add a small encrypted `recruitment_notices` record holding an immutable candidate-safe snapshot and send status. A scalar `RecruitmentNoticeRequested` event references that intent through the existing outbox. Use a synchronous listener within outbox dispatch; do not mark the intent delivered merely because another queue job was enqueued. Mark sent only after transport acceptance. At-least-once delivery may duplicate an email if a worker dies after transport acceptance; document that ceiling rather than promise exactly-once mail.

Suppress obsolete scheduled/rescheduled notices after cancellation or terminal decisions, checking the current application and interview before sending. Candidate response, cancellation, rejection, offer, and receipt templates use their captured facts rather than rehydrating mutable models. Retry failures through the existing outbox recovery and notify authorized HR using the existing delivery-failure helper. Show pending/failed communication and a guarded retry action. No candidate notification includes internal notes/outcomes. Capability codes and email snapshots are encrypted and purgeable, not copied into immutable audit/outbox payloads.

### Privacy and resume safety

Record consent timestamp and privacy-policy version. Retention review date is closure plus 12 calendar months: rejection/withdrawal closure or completed employee conversion. Unconverted hires remain active and are not automatically eligible. Earlier legal deletion requests use a verified candidate request. Converted employee records have their separate employee retention policy; candidate cleanup does not delete employment history.

A new `hr.recruitment.privacy` permission controls a review queue, hold changes, and per-application deletion approval. The scheduler only queues reviews. Approved deletion revokes tracking/recovery access and suppresses notices immediately, then an idempotent cleanup removes candidate PII, resume/evidence bytes, interview free text, note bodies, encrypted mail snapshots, and request fingerprints. Preserve nonidentifying application/stage/aggregate evidence and actor decisions. Make PII columns nullable and render a tombstone; do not put invented names or emails in the record. No PII belongs in immutable application events. Keep a redacted deletion ledger for retry and backup-restoration suppression.

Deletion is complete only after blob cleanup succeeds. Failures remain visible and retryable; an inaccessible file is not evidence of deletion. Document backup expiry, legal holds, external email copies, and reapplication of deletion suppression after restore. Do not promise instantaneous deletion from backups or recipients' mailboxes. Privacy text must accurately describe the operational policy and receive organization/legal review before release; the 12-month default is not legal advice.

Resume state is `pending`, `clean`, `infected`, or `failed`, with checksum, scan time, and scanner result metadata that contains no resume text. Store uploads privately in quarantine. Only clean bytes with a matching checksum can be downloaded; response uses a safe original filename, attachment disposition, and `nosniff`. Existing resumes become pending/unverified rather than assumed clean. Rescan jobs are idempotent and recoverable. Scanner outages leave the application saved and the file blocked; show a clear review state. Support a candidate-safe replacement upload through verified capability, not a public unauthenticated overwrite.

The ClamAV daemon is private and not host-published. Use `clamdscan --stream --no-summary` against bytes accessible to the API client; invoke via argument-array `Process`, not a shell string. Enforce timeout, existing size and actual MIME restrictions, signature-age health checks, and EICAR verification. Do not log file contents or candidate names. The scanner result is a security gate, not a recruitment score.

## Interface behavior

### Internal recruitment

- Dashboard numbers are exact; each count links to the matching filtered list. Show separate recent records and totals, explicit denominators, unassigned work, next-action deadlines, and unconverted hires.
- API `available_actions` controls action rendering. Management-only, applications-only, hire-only, offer-approver-only, read-only, and privacy-only roles do not inherit unrelated actions. Keep backend enforcement authoritative.
- Posting pages expose draft preview, publish/close/reopen reasons, share link, expiry and headcount context. Closing intake explains that existing candidates remain. Filled/archived pages remain readable. Requirements use one form state so restored drafts cannot silently discard edits.
- Application detail uses a clear primary next action, owner, stage age, interview status, offer state, communication status, and onboarding link. Hired/rejected/withdrawn states have explicit terminal labels; do not infer stages from display-label substring matching.
- All successful writes invalidate posting list/detail, application list/detail/history, summary, overdue, and affected calendar/approval data through existing query-key conventions. On conflict, refresh without discarding unrelated draft text.

### Candidate experience

- Careers copy uses a dedicated recruitment introduction, not the manufacturing landing-page hero. Show actual work arrangement, location, currency/pay period, requirements as readable bullets, deadline/timezone, and a clear application CTA. Do not invent company address, legal name, salary, or response promises when settings are missing.
- Use existing large form fields and touch-sized buttons with 44 CSS-pixel primary interaction targets. Preserve the design system, responsive layout, contrast, and readable hierarchy; do not restyle the whole application shell.
- Label every input, textarea, consent checkbox, and upload control. Connect `aria-invalid` only to actual errors; use the shared `error` properties and descriptions. On failed submission, render all server errors including resume/consent, focus the first actionable error, and announce a useful summary. Preserve typed data and selected file where the browser permits.
- Distinguish unknown capability (`404`), closed/unavailable intake (`410`), stale request (`409`), field validation (`422`), rate limiting (`429` with retry information), upload rejection (`413`), and network/server failures. A service outage is not an invalid tracking code. Provide retry without requiring re-entry.
- Success provides a receipt, copyable capability when issued, a direct tracking action, email-delivery expectations, and clear next steps. Recovery is available if the receipt was lost. Public form draft persistence is disabled for PII; unsaved-navigation protection remains.
- Tracking shows only candidate-safe status and scheduled interview details, supports verified withdrawal and resume replacement, and does not expose private evaluation or offer salary. Unknown/closed job links show a helpful no-longer-hiring state and navigation to other roles.
- Render `JobPosting` structured data only from actual public facts, excluding closed/expired jobs and undisclosed salary. This is client metadata within the current SPA; a separate server-rendering/SEO architecture is not part of this scope.

## Compatibility and rollout

Use additive, separately deployable migrations. Preserve old tracking codes, historical rows, employee links, and exact legacy timestamps. Preflight invalid statuses, overfilled postings, missing prerequisites, and duplicate employee links before adding constraints. A failed preflight reports identifiers and changes no data. New protections may block unsafe operations; they must not silently rewrite historical decisions.

Deploy schema and a temporarily dual-compatible server before the updated client. Once the deployed client sends explicit target/version fields, require them at mutation boundaries; legacy unsafe `advance` requests then return `422` with an upgrade message. Do not map a legacy implicit advance to a fresh next stage. Old browser tabs must refresh, not silently perform a different action.

Launch offers only after workflow/permissions/identity configuration and legacy offer review. The migration captures existing unconverted hires as requiring legacy review. The restricted legacy-evidence operation records actual historic terms/delivery/acceptance and submits a `legacy_review` offer version for current maker-checker approval before conversion. Historic evidence timestamps and today's review/approval timestamps remain separate; do not pretend historical sends or approvals occurred. Existing converted hires remain linked and historical. Use the existing settings system for rollout gates; never overwrite operator-owned values through reseeding.

Enable scanning only after the scanner is healthy, then backfill legacy files in bounded batches. Privacy review starts with dry-run/reporting; no automated mass deletion. Roll back application releases before removing new columns. Once long capabilities, offer records, withdrawals, or privacy decisions exist, schema downgrade must refuse destructive truncation; retain additive schema or perform an explicitly approved data-preserving compatibility migration.

## Release acceptance

Release requires regression proofs for all audit findings, true PostgreSQL concurrency tests, role/feature-state coverage, real-backend candidate-to-employee and recovery journeys, keyboard/mobile checks, malware fail-closed checks, migration preservation/downgrade guards, email retry/suppression, and privacy cleanup/restore evidence. Full-project failures outside recruitment are recorded separately; they do not justify changing unrelated dirty worktree files.

Implementation order and the complete finding-to-task ledger are in [the implementation plan](../plans/2026-10-07-recruitment-hardening-plan.md).
