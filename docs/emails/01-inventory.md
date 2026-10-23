# Phase 1 — Email inventory and classification

**Status:** owner directed work to proceed. Inventory is based on the 2026-10-20 static inspection; later source changes are called out below and in `PROGRESS.md`. Runtime recipient checks remain outstanding. `D` records the initial grade before redesign. Four credential-bearing welcome/reset messages were marked `F` pending reachability/security review. No email was sent.

## Master inventory

`E-001`–`E-035` IDs match [00-discovery.md](00-discovery.md). External-facing financial/statutory documents need review by the company's accountant; personal-data and payroll messages need review by HR/legal/data protection officer. No legal-compliance claim is made.

| ID | Module / email | Audience | Purpose | Sensitivity | Priority | Current → target | Trigger → recipient rule → primary data source |
|---|---|---|---|---|---|---|---|
| E-001 | Accounting — finalized invoice | External customer | Transactional-document | Confidential (financial) | P1 | D → document | Invoice finalized → invoice customer email → finalized invoice and customer record |
| E-002 | Accounting — official receipt | External customer | Transactional-document | Confidential (financial) | P1 | D → document | Receipt issued → receipt customer email → receipt and invoice/payment records |
| E-003 | Accounting — invoice dunning | External customer | Action-required | Confidential (financial) | P1 | D → action | Scheduled dunning tier → invoice/customer address → invoice balance, due date, dunning run |
| E-004 | Accounting — credit note finalized | External customer or supplier | Transactional-document | Confidential (financial) | P1 | D → document | Credit note finalized → party email → credit note and party records |
| E-005 | B2B — portal invitation | External supplier or customer | Account-security | Confidential | P1 | D → security | Portal invitation → invitee-entered address → invitation/token and portal identity |
| E-006 | B2B — portal password reset | External supplier or customer | Account-security | Confidential | P1 | D → security | Reset request → portal account email → reset token and portal identity |
| E-007 | B2B — supplier invoice status | External supplier | Status-update | Confidential (financial) | P2 | D → status | Supplier invoice submitted → vendor email → bill and vendor records |
| E-008 | B2B — supplier PO cancelled | External supplier | Transactional-document | Confidential (financial) | P1 | D → document | PO cancelled → vendor email → purchase order and vendor records |
| E-009 | CRM — customer complaint update | External customer | Status-update | Confidential | P2 | D → status | Complaint updated → customer email → complaint/customer, public update fields |
| E-010 | CRM — sales order confirmed | External customer | Transactional-document | Confidential (financial) | P1 | D → document | Sales order confirmed → customer email → sales order/customer |
| E-011 | CRM — sales order decision | External customer | Status-update | Confidential (financial) | P1 | D → status | Customer response decision → portal users or customer fallback → sales order/response/decision |
| E-012 | HR — application received | Applicant/candidate | Status-update | Highly sensitive (HR/personal data) | P2 | D → candidate | Application received → candidate-entered email → application record |
| E-013 | HR — application status | Applicant/candidate | Status-update | Highly sensitive (HR/personal data) | P2 | D → candidate | Stage/status changed → candidate email → application and stage transition |
| E-014 | HR — interview scheduled | Applicant/candidate | Action-required | Highly sensitive (HR/personal data) | P2 | D → candidate | Interview scheduled → candidate email → application/interview schedule |
| E-015 | HR — interview changed | Applicant/candidate | Status-update | Highly sensitive (HR/personal data) | P2 | D → candidate | Interview details changed → candidate email → application/interview schedule |
| E-016 | Payroll — payslip ready notice | Internal staff | Transactional-document | Highly sensitive (payroll; email contains no salary data) | P1 | D → secure document | Payroll finalized and feature toggle on → employee email → finalized payroll/period and authenticated SPA self-service payslip page; no PDF attached |
| E-017 | Purchasing — supplier PO decision | External supplier | Status-update | Confidential (financial) | P1 | D → status | Supplier PO response decided → portal user email(s) or vendor fallback → PO/response/decision |
| E-018 | Purchasing — supplier PO published | External supplier | Transactional-document | Confidential (financial) | P1 | D → document | PO dispatch/publish → vendor email → purchase order and vendor |
| E-019 | Purchasing — RFQ lifecycle | External supplier | Action-required / status-update | Confidential | P1 | D → action | RFQ invited/updated/closed lifecycle event → invited supplier contact(s) → RFQ/invitation |
| E-020 | Returns — return case update | External customer or supplier | Status-update | Confidential | P2 | D → status | Case opened/updated/resolved/evidence received → party contacts filtered by visibility → case and public update |
| E-021 | Returns — return request update | External customer or supplier | Status-update | Confidential | P2 | B → status | Return request lifecycle update → validated event-time party email; authorized internal fallback on failure → event-time return request snapshot |
| E-022 | Supply Chain — delivery confirmed | External customer | Transactional-document | Confidential | P1 | B → document | Delivery confirmed → validated event-time customer email; authorized internal fallback on failure → event-time delivery/sales-order/invoice snapshot |
| E-023 | Common — generic user notification | Internal staff, approvers, executives, system admins, and customer portal contacts (varies by caller) | Action-required / status-update / alert-exception | Internal to highly sensitive (varies by type) | P1 | B → action/status/alert | `NotificationService::send()` plus explicit internal per-type email opt-in; one direct customer delivery-date proposal path bypasses that preference → caller-selected users or active customer portal contacts → caller-supplied title/message/link and related record |
| E-024 | Common — unread notification digest | Internal staff, approvers, executives, system admins | Digest-report | Internal to highly sensitive (depends on unread items) | P2 | D → digest | Scheduled digest → users with global digest opt-in and usable email → persisted unread notification rows |
| E-025 | Common — scheduled export | Internal staff / system admin | Digest-report | Internal to confidential (financial), may include personal data | P1 | D → report | Scheduled export due → export's configured recipients; exporter checks owner permission/scope → export query, filters, columns, artifact |
| E-026 | Common — critical alert | Internal staff / system admin | Alert-exception | Internal; may include confidential record identifiers | P1 | D → alert | Critical alert with retry → recipients resolved through alert permission/role logic → alert row/title/message/entity |
| E-027 | Production — daily production summary | Internal staff / executives | Digest-report | Internal | P2 | D → report | Daily scheduler → production summary audience → daily summary values |
| E-028 | Production — weekly production summary | Internal staff / executives | Digest-report | Internal | P2 | D → report | Weekly scheduler → production summary audience → weekly summary values |
| E-029 | Auth — password reset link | Internal staff / system admin | Account-security | Confidential | P1 | D → security | Password-reset request → affected user address → reset service token/expiry |
| E-030 | Auth — temporary password reset | Internal staff / system admin | Account-security | Highly sensitive (credentials) | P0 | F → security | Legacy reset flow → affected user email → temporary password argument |
| E-031 | Auth — welcome with temporary password | Internal staff / system admin | Account-security | Highly sensitive (credentials) | P0 | F → security | Admin user provisioning → new user email → temporary password and account record |
| E-032 | HR — employee password reset | Internal staff | Account-security | Highly sensitive (credentials) | P0 | F → security | Employee account reset → user email → temporary password |
| E-033 | HR — employee welcome with temporary password | Internal staff | Account-security | Highly sensitive (credentials) | P0 | F → security | Employee provisioning → new user's email → temporary password and employee/account record |
| E-034 | Landing — contact inquiry received | System admin / sales inbox | Status-update | Confidential (personal data) | P2 | D → alert | Public inquiry submitted → configured sales/admin recipients → inquiry contact details and message |
| E-035 | Test harness — email integration message | Test recipient only | System-ops (test-only) | Public-ish test data | N/A | N/A | Explicit feature-test path → fake mail transport expected → fixed test payload; excluded from production coverage |

## Generic notification email trigger coverage (E-023)

The following trigger families use the same generic email composition. They do **not** each have a dedicated template. Internal mail requires the individual recipient's enabled email preference for that type; the default is no email. The direct customer proposal path is a separate caller and does not use the internal preference table. The business owner/permission rule is selected by each caller, so a generic mail is not proof of RBAC-safe access to every deep link.

| Module / trigger families found | Recipient rule found in callers | Inventory disposition |
|---|---|---|
| Approval lifecycle: `approval.action_required`, `approval.completed`, `approval.rejected`, `approval_reminder`, `approval_escalation` | Current approver, requester, or escalation audience, usually permission-resolved | Included E-023; not guaranteed by default preference. Missing-email recommendation below. |
| Procurement and purchasing: PO pending approval, PR/PO approved, RFQ lifecycle, supplier deterioration, auto-convert skipped | Permission/role/approval audience | Included E-023; verify role and active-user filtering per caller. |
| Quality: incoming/in-process/outgoing QC required, inspection review/failure, auto-NCR, NCR recurrence/escalation/effectiveness, return-to-supplier | QC heads/reviewers/permission audience | Included E-023; high risk of internal detail in generic body/link. |
| Inventory and finance operations: low stock, GRN received, item cost review, replenishment blocked, invoice payment submitted, payment posted, invoice auto-failure, dunning escalation | Inventory/finance permission audience or requester | Included E-023; verify amounts and row visibility. |
| Production and maintenance: work-order completed/paused, machine breakdown, assignment needed/escalated, shift handover, missing timeout, repeat breakdown, part handover, MRP completion | Department supervisors, assigned technician, permission audience, or user | Included E-023; some similar notices are in-app only. Check duplicate channels. |
| HR/leave/attendance/training: leave submitted/pending HR/approved/rejected, separation initiated, recruitment new application, training expiry, missing time-out, loan submitted/decided | HR audience, employee/requester, or permission audience | Included E-023; employee-specific data requires privacy check. |
| Returns, customer service, and supplier chain: RMA created, return/case updated, customer response, supplier schedule cancellation, supplier shipment late, 8D SLA | Case contacts, customer/vendor portal users, dispatch team, or permission audience | Included E-023 where `send()` is used; `sendInApp` and portal-only events are not emails. Verify public/internal filtering. |
| Customer portal delivery negotiation: proposed delivery date | Each valid active customer portal email, normalized and deduplicated; one recipient per message | Included E-023; recipient privacy and Sales failure fallback fixed in draft PR #256. |
| Chain alerts and system exceptions: delivery confirmation for finance, outgoing-QC missing, critical operational/chain outcome, email delivery failure | Relevant finance/ops audience, owner, or configured admin permission | Included E-023 or E-026. Some failure paths intentionally use in-app fallback only. |

These are type families, not a claim that every similarly named event is mailed. Calls using `sendInApp()` and `sendPortalInApp()` deliberately do not email. Some types are built dynamically in services, so runtime preference rows and event instances are needed to finish a unique type-by-type count.

## Coverage matrices

### Module × email

| Module | Dedicated email IDs | Generic notifications (E-023) / other |
|---|---|---|
| Auth / Admin | E-029–E-031 | User provisioning and password reset paths |
| Accounting | E-001–E-004 | Approval, payment, bill, dunning-escalation notifications |
| Assets | — | Monthly depreciation jobs found; no dedicated email path found |
| Attendance | — | Overtime and missing-timeout notifications; generic email only where `send()` and opted-in |
| B2B / supplier & customer portals | E-005–E-008 | Portal event notices include in-app-only delivery paths |
| CRM | E-009–E-011 | Pricing, complaint SLA, customer response, order chain notifications |
| HR / Recruitment | E-012–E-015, E-032–E-033 | Onboarding, separation, training, applicant alerts |
| Inventory | — | Low-stock, GRN, cost-review, replenishment notices |
| Leave / Loans | — | Leave and loan status notifications |
| Maintenance | — | Assignment, breakdown, repeat-breakdown, handover notices |
| MRP | — | Run completion may be in-app-only; inspect each path |
| Payroll | E-016 | Payslip ready notification and failure/reconciliation paths |
| Production | E-027–E-028 | Work-order and machine notifications |
| Purchasing | E-017–E-019 | PR/PO approvals, RFQ lifecycle, supplier-performance alerts |
| Quality | — | QC, NCR recurrence/escalation/effectiveness notices |
| Return Management | E-020–E-021 | Return-case and RMA notifications |
| Supply Chain | E-022 | Invoice auto-failure, finance delivery notice, dispatch exceptions |
| Landing / Public | E-034 | Contact inquiry notification |
| Common / Admin operations | E-023–E-026 | Generic notifications, critical alerts, digest, scheduled exports |

`—` means no dedicated Mailable/Notification view was found for that module in the static search, not proof that no generic email can be triggered there.

### Role × email received (current source-level rule)

| Role / audience | Known email IDs | Resolution rule and unresolved risk |
|---|---|---|
| External customer | E-001–E-004, E-009–E-011, E-020–E-023 | Most use the customer/party email on the triggering record; some use portal-user email lists/fallbacks. App RBAC does not cover external addresses; verify ownership, public fields, and duplicates per flow. |
| External supplier | E-004, E-005–E-008, E-017–E-021 | Vendor email, supplier contact, invitation email, or supplier portal user depending on trigger. Fallback and inactive portal-user handling need verification. |
| Applicant/candidate | E-012–E-015 | Candidate-provided application email. Do not expose internal notes or interview details beyond intended recipient. |
| Internal staff / approver | E-023, E-024, E-026–E-033 | Caller-specific role/permission or employee/user resolution; E-023 and digest require explicit preferences. Temporary credential messages are P0. |
| Executive | E-023, E-027–E-028 | Summary audience is code-defined; verify exactly which roles/permissions and whether summaries contain sensitive rows. |
| System admin / sales inbox | E-026, E-034 and failure notifications | Permission audience or configured inbox. Verify settings values, no open relay, and no broad body exposure. |

### Trigger → recipient rule → data source (cross-cutting)

| Trigger group | Recipient rule from code | Source of truth | Review focus |
|---|---|---|---|
| Accounting finalized documents | Customer/vendor relationship email on document party | Invoice, receipt, credit-note and party models | Correct party, amount/tax, issue date, financial approval, duplicate send |
| Supplier/customer transaction lifecycle | Vendor/customer record, invited contacts, or active portal users with fallback | PO, RFQ, invoice, delivery, response, party/contact models | External recipient selection and snapshot timing |
| Recruitment | Candidate address stored on application | Application and interview records | PII minimization; public vs internal comments |
| User and portal account security | Target account address | User/portal identity and reset/invitation token | No plaintext credentials; signed/expiring frontend link; no token logs |
| Internal operational event | Caller-selected role/permission/owner; email opt-in and valid non-empty address | Business record plus generic notification payload | RBAC scope, in-app/email preference coherence, sensitive generic message content |
| Digest | Global digest preference and user address | Persisted unread notification rows | Active account status, sensitive item summarization, deep-link authorization |
| Scheduled export | Configured recipients; exporter's owner permission and row-scope checks | Shared exporter result, selected columns/filters/date range | Recipients must be authorized for exported records; exact artifact parity |
| Payslip | Employee email and enabled setting | Final payroll/period and authenticated SPA self-service payslip page; PDF remains behind the authorized app route and is not attached | Correct employee mapping, portal authorization, retry duplicates; email body contains no salary data |

## Missing-email recommendation track

This is a recommendation list, not an instruction to add email. In-app/Reverb notices exist for some rows. Make decision with process owners after the polish work; avoid defaulting all notices to email.

| Candidate event/action | Current evidence | Possible useful recipient / purpose | Recommendation |
|---|---|---|---|
| Approval requested, reminded, escalated, approved/rejected | Generic notification keys exist; email is opt-in per type and disabled unless preference row enabled | Current authorized approver/requester; action needed/outcome | Decide which approval types are mandatory email vs in-app/digest; preserve current opt-in behavior until approved. |
| Low stock / replenishment blocked | In-app/generic notification paths found; no dedicated email | Inventory planner / authorized stock owner | Aggregate repeated items into digest; define severity and quiet hours first. |
| Maintenance due / work assigned / breakdown | Scheduled reminders and generic notices exist; some call paths only notify in-app | Assigned technician and maintenance supervisor | Identify missed high-severity/out-of-shift alerts; use dedupe and escalation criteria. |
| Quality inspection/NCR overdue and containment actions | Generic alert notifications and scheduled escalation paths exist; no dedicated template | QC owner, production/quality approver | Decide whether high-severity containment requires guaranteed mail; never email restricted/customer details without review. |
| Supplier/customer document delivery | PO, invoice, receipt, credit note, delivery, complaint and return emails exist; coverage is incomplete across related lifecycle events | External contact recorded on transaction | Identify missing lifecycle messages (e.g. shipment dispatched/ETA, payment received) and confirm recipient consent/contractual need. |
| Account onboarding | Welcome messages exist but include temporary passwords; no onboarding guidance-only secure variant found | New staff/portal user | Replace credential delivery with secure activation/reset before treating as complete. |
| Scheduled export / production summary delivery failure | Failure notification is present for some commands; other job failures rely on Horizon/failed-job monitoring | Export owner / operations admin | Check all mail/scheduler failures reach a monitored channel; avoid sending full error details. |
| Backup/restore and system health | Backup/restore jobs and scheduler-health mechanisms exist; no dedicated direct email found in static search | System administrators | Decide whether mail alert is needed; otherwise verify existing health dashboard/monitoring is monitored. |
| Training expiry, onboarding, separation | In-app/generic messages and scheduled reminders exist | Employee, manager, HR based on visibility | Confirm sensitive content, preference policy, cadence, and stop conditions. |

## Likely unnecessary/noisy or sensitive email

- E-030–E-033 temporary-password messages are inappropriate if live; confirm reachability and move to secure activation/reset flow in a separate security fix.
- E-023 generic notification mail spans internal event families and one customer portal delivery-date proposal. PR #256 adds central active-user/address validation and fixes customer address disclosure; the exhaustive caller-by-caller visibility audit remains pending (EF-042).
- E-024 digest summarizes unread notifications without marking them read; it may repeat the same backlog every run. Check schedule, volume, and whether sensitive records enter the digest.
- E-003 dunning runs and E-019 RFQ invitation loops can create bursts; confirm suppression, recipient dedupe, idempotency, and stop conditions.
- E-016 local security change removes the PDF attachment and links to authenticated self-service. HR and data-protection review remains required before production.

## Company data and infrastructure still unconfirmed

Settings schema exists and PDF branding settings are reused. Read-only presence checks found core identity rows populated, but values were not copied here. The sales inbox and VAT status equal prior code fallback strings and need owner verification. `company.logo_path` is empty; bundled API logo assets exist. The shared-foundation change removes fallback values for legal entity and address and adds an email footer disclaimer setting. Before external mail, verify legal company name, registered address, company phone, general and sales inboxes, TIN, VAT status, certification label, logo, public website, sender identity, Reply-To policy, and approved disclaimer. Keep statutory fields subject to accountant review. The owner reported DKIM setup succeeded for `ogamiph.dev` in the AWS Asia Pacific (Singapore) region. SPF, DMARC, bounce/complaint handling, provider suppression, and monitored Reply-To remain unverified with domain/email administrators.

## Owner follow-up before production email

1. Verify local and staging mail transports stay on a sink before any send-capable test. Never send real mail during development or testing.
2. Verify company settings and approved disclaimer; accountant review required for statutory fields.
3. Confirm secure payslip delivery and resolve the P0 temporary-password paths in the separate security-fix track.
4. Confirm mandatory vs opt-in email categories and escalation audiences; current database preference defaults disable generic mail unless explicitly enabled.
5. Ask domain/email administrators to verify SPF, DMARC, bounce/complaint handling, provider suppression, and monitored Reply-To. DKIM setup was reported successful for `ogamiph.dev` in Singapore; domain validation remains external to this code audit.

## Manual real-client checklist for the later email phase

- Send to controlled test inboxes only after isolated sink testing; then test Gmail web/mobile, Outlook desktop/web/mobile, Apple Mail, and Yahoo.
- Check images-blocked view, desktop/mobile widths, light/dark appearance, link destinations after sign-in, plain-text version, attachment name/content, long names/remarks, Japanese text and `₱` glyph, and reply behavior.
- Capture screenshots and record client/version/date. Browser/Mailpit checks cannot establish real-client rendering or deliverability.
