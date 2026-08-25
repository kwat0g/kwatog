# M034 — Customer complaints / 8D fix log

Session date: 2026-08-25  
Module status: 🔁 Needs Re-audit

## Existing working-tree fixes verified

The claimed module already contained uncommitted complaint-path changes made after the prior report. They were preserved and checked rather than rewritten:

- **M034-F01:** `api/app/Modules/CRM/Services/ComplaintService.php:178-257` now uses complaint/report lock ordering, authoritative finalization checks, and post-commit finalization events.
- **M034-F03:** `ComplaintService.php:52-62,370-418` now uses an explicit lifecycle matrix and lock/reload transitions.
- **M034-F04/F07:** `api/app/Modules/B2B/Resources/CustomerPortalComplaintResource.php:19-45`, the complaint boundary in `CustomerPortalService.php:303-345`, and `api/app/Modules/CRM/Controllers/ComplaintController.php:103-126` provide safe portal/PDF publication gates.
- **M034-F05 core:** `api/app/Modules/CRM/Services/Complaint8dEscalationService.php:76-184` now bounds candidate scans and claims SLA tiers under a row lock; `NotificationService.php:142-170` makes in-app inserts transactional.
- **M034-F06:** `api/app/Modules/CRM/Requests/StoreComplaintRequest.php:60-137` and `ComplaintService.php:389-443` validate source identity and assignee authority at both boundaries.
- **M034-F10/F11:** `spa/src/pages/crm/complaints/create.tsx:39-73,160-188` and the portal complaint API/UI provide searchable source selectors, bounded pagination, and history filters.

## Changes made in this session

- **M034-F02:** Added `ComplaintService::ensureQualityCompletionReady()` at `api/app/Modules/CRM/Services/ComplaintService.php:327-368`. Before: resolve/close required only a generated NCR handoff. After: both transitions require a finalized 8D report and a closed, dispositioned NCR; `close` follows only `resolved` in the transition matrix.
- **M034-F02 polish:** Updated `spa/src/pages/crm/complaints/detail.tsx:149-160,217-326,397-414` so lifecycle actions and confirmation copy match the server-side quality gate.
- **M034-F10 hardening:** Updated `api/app/Modules/CRM/Controllers/ComplaintController.php:71-84` to pass only validated 8D fields to the service.
- **M034-F10 polish:** Added debounced sales-order search to `spa/src/pages/crm/complaints/create.tsx:39-73,173-188`.
- **M034-F12:** Added `api/database/migrations/2026_08_25_160100_protect_customer_complaint_retention.php:11-29`, changing customer complaint history from cascading deletion to restricted deletion.
- **Regression coverage:** Added completion-gate cases to `api/tests/Feature/CRM/ComplaintLifecycleTransitionTest.php` and a retention case to `api/tests/Feature/CRM/ComplaintSourceValidationTest.php`; updated the portal 8D fixture to represent a closed/dispositioned NCR.

## Verification

- PHP lint passed for the changed M034 PHP files.
- Complaint route listing passed and showed the expected endpoints/middleware.
- Scoped ESLint passed for the changed internal complaint pages.
- Scoped `git diff --check` passed.
- Backend focused tests were attempted on `ogami_test_m034_20260825` but stopped before assertions because unrelated migration `2026_08_25_210000_enforce_one_active_holiday_per_date.php:42` tries to drop an index-backed constraint incorrectly.
- SPA-wide typecheck was attempted but is blocked by unrelated existing errors in assets and return-management files; no M034 error was reported.

## Deferred

- **M034-F05 residual:** durable per-recipient/channel SLA delivery ledger and retry semantics; requires shared notification/queue coordination.
- **M034-F08:** cancellation route/reason/NCR correction policy needs a product decision.
- **M034-F09:** permission split needs the shared RBAC owner and role-assignment rollout.
- Clean backend migration/test evidence and browser/e2e evidence remain pending.

The module must be released through `release-module.sh` so the lock is removed and the registry is refreshed.

## Re-audit fixes applied

### M034-R01 — durable SLA delivery ledger

- `api/database/migrations/2026_08_26_000000_create_complaint_8d_escalation_deliveries.php:13-35`
  and `api/app/Modules/CRM/Models/Complaint8dEscalationDelivery.php:11-43`
  add one unique delivery record per complaint/tier with status, attempts,
  recipient count, idempotency key, timestamps, and last error.
- `api/app/Modules/CRM/Services/Complaint8dEscalationService.php:119-248`
  now claims and sends under the complaint transaction/row lock, marks the
  ledger sent with the compatibility JSON marker, and keeps an empty audience
  pending. `:250-405` records failed attempts as retryable instead of losing
  the outcome in logs only.
- Before: `sla_alert_levels` was the only claim marker and a failed send left
  no per-tier attempt or retry state. After: the ledger is the durable
  complaint/tier outcome while the JSON remains a compatibility summary.

### M034-R02 — cancelled portal source order

- `api/app/Modules/B2B/Requests/Customer/CreateComplaintRequest.php:27-43`
  rejects an owned cancelled order with a field-level validation error; the
  authoritative CRM check remains at `ComplaintService.php:441-445`.
- `api/tests/Feature/B2B/CustomerPortalServiceTest.php:414-440` covers the
  negative API path and verifies no complaint is created.
- Before: request validation checked only existence and ownership. After: it
  rejects known cancelled orders early without weakening the transaction-time
  race guard.

### M034-R03 — portal complaint audit action

- `api/app/Modules/B2B/Services/CustomerPortalService.php:281-298` now writes
  `customer.complaint.submitted`, matching the contract assertion at
  `api/tests/Feature/B2B/CustomerPortalServiceTest.php:398-412`.
- Before: the service wrote `customer_cmp.submit`. After: the stable action
  identifier is consistent for audit consumers and tests.

## Current verification

- PHP lint passed for the current-session CRM/B2B production, migration, model,
  and test files.
- Scoped `git diff --check` passed.
- The focused Docker feature suite still cannot reach assertions because the
  shared `ogami_test` reset currently finds duplicate `migrations`/`roles`
  relations before any test runs. An earlier reset attempt reached unrelated
  `api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:42`
  and PostgreSQL rejected dropping `holidays_date_name_unique` while its
  same-named table constraint still required the index. Neither dependency was
  modified from this module session.

## Still deferred

- **M034-R04:** cancellation policy needs an explicit product/quality decision
  on reason, actor, transition, audit, and customer-visible behavior.
- **M034-R05:** permission split needs the RBAC owner's role/action matrix.
- Clean-database integration, concurrency, and browser evidence remain pending
  after the shared migration issue is repaired.
