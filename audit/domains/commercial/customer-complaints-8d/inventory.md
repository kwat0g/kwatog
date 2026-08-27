# M034 — Customer complaints / 8D inventory

Audit date: 2026-08-27
Release target: 🔁 Needs Re-audit
Audit mode: discovery, hardening, and polish review with local evidence only

## Purpose and boundary

M034 covers the internal customer-complaint workflow and the customer-facing quality boundary:

- complaint intake, assignment, status lifecycle, and source-record links;
- automatic NCR creation, durable manual handoff, and recurrence-triggered 8D shells;
- 8D editing, finalization, PDF rendering, and customer visibility;
- D3/D4/finalization SLA notifications and scheduler behavior;
- internal CRM screens and the B2B customer-portal complaint screens.

The audit does not modify sales orders, inspections, NCR/CAPA, customer/product pricing, portal authentication, notification, or finance modules. Those areas were inspected as read-only dependencies where their behavior affects M034.

## Dependency exception

After the registry refresh, the remaining Tier 2 financial-statements and fixed-assets candidates were locked by other sessions. M034 was the first unlocked candidate available after an atomic claim, so the audit proceeded under the workflow's controlled dependency-cycle exception. Sales orders, quality NCR/CAPA, portal tenancy, and related services were context only; no dependency module was modified.

## Surface inventory

| Surface | Primary evidence | Audit coverage |
|---|---|---|
| Internal API routes and authorization | `api/app/Modules/CRM/routes.php:60-80`; `RolePermissionSeeder.php:285-300` | Complaint reads, writes, 8D, lifecycle, PDF, and permission granularity |
| Complaint application service | `api/app/Modules/CRM/Services/ComplaintService.php` | Creation transaction, NCR handoff, 8D updates/finalization, resolve/close, retry |
| 8D SLA worker | `api/app/Modules/CRM/Services/Complaint8dEscalationService.php`; `api/routes/console.php:117-127` | Due selection, tier gates, notification delivery, replay behavior, query bounds |
| NCR bridge and dependency | `CreateNcrOnComplaintRequested.php`; `AutoSpawn8DOnNcrRecurrence.php`; `Quality/Services/NcrService.php` | Queue retries, outbox recovery, recurrence idempotency, NCR completion requirements |
| Persistence | migrations `0098`, `0099`, `0193`, `2026_08_10_280000`, and lifecycle guard migrations | Foreign keys, one-to-one 8D constraint, status values, SLA state, retention implications |
| Internal API/UI | `ComplaintController.php`; `spa/src/pages/crm/complaints/{index,create,detail}.tsx` | Validation, source linking, actions, state display, loading/error/empty behavior |
| Customer portal API | `api/app/Modules/B2B/routes.php:75-97`; `CustomerPortalService.php`; `CustomerPortalController.php` | Tenant boundary, complaint creation, resource shape, 8D report visibility |
| Customer portal UI | `spa/src/pages/portal/customer/complaints/index.tsx`; `spa/src/api/b2b/customer.ts` | Form, list, status/8D gating, recovery states, pagination and contract alignment |
| Design/deployment surface | `docs/DESIGN-SYSTEM.md`; `docs/DEPLOY.md`; `docs/RESTORE-DRILL.md` | Atelier density/accessibility conventions, migration order, worker/rollback evidence |
| Verification | focused CRM and B2B feature tests | 62 tests, 205 assertions passed on isolated PostgreSQL database `ogami_test_m034_20260827` |

## Observed workflow

1. An internal user or authenticated customer-portal user submits a complaint. `ComplaintService::create` creates the complaint and an empty 8D report inside a transaction, then attempts the Quality NCR handoff. Expected handoff failures remain durable as `manual_required` with an outbox replay path.
2. Internal staff edit D1–D8 fields, finalize the report once all fields are non-empty, and may resolve or close the complaint. The server requires a finalized 8D report and a closed, dispositioned linked NCR for both lifecycle transitions.
3. A scheduler invokes `complaints:check-8d-slas` every 15 minutes. The service records each complaint/tier outcome in a durable ledger, keeps the compatibility `sla_alert_levels` marker, and retries failed or recipientless deliveries.
4. The internal detail page and API hide the PDF until finalization, while the customer portal's 8D endpoint additionally requires resolved/closed status and a closed, dispositioned NCR. The internal list now includes the NCR fields its resource advertises.

## Historical verification baseline

The clean Docker-backed focused run passed on 2026-08-24:

    docker compose run --rm --no-deps api php artisan test \
      tests/Feature/CRM/AutoSpawn8DOnRecurrenceTest.php \
      tests/Feature/CRM/Complaint8dDueAtStampingTest.php \
      tests/Feature/CRM/Complaint8dFinalizeGuardTest.php \
      tests/Feature/CRM/Complaint8dSlaTest.php \
      tests/Feature/CRM/ComplaintNcrHandoffTest.php \
      tests/Feature/B2B/PortalValidationTest.php \
      tests/Feature/B2B/PortalTokenCrossGuardTest.php

Result: 21 passed, 58 assertions, 19.53 seconds.

The suite covers creation due dates, required-field finalization, sequential finalization idempotency, sequential SLA tier dedupe, terminal complaint skipping, NCR manual handoff/replay, portal validation, token cross-guards, and recurrence 8D idempotency. It does not cover concurrent stale updates/finalization, concurrent SLA workers, portal resource redaction, finalized-only API/PDF boundaries, source-party mismatch, cancellation, or assignment authorization.

## Historical baseline observations

- Discovery: the module has a coherent service/listener split and the complaint-to-NCR failure path is durable rather than silently losing the complaint.
- Hardening: 8D and complaint state transitions are not consistently lock-and-reload operations; portal and PDF publication rules are stronger in the SPA than at the API boundary; SLA tier claims are not atomically claimed.
- Polish: the internal form exposes customer/product but not its own `sales_order_id` field, portal request types advertise `product_id` while the service ignores it, and portal complaint retrieval is unbounded and has no search/filter/pagination contract.
- The internal SPA follows the Atelier guidance with dense panels/tables, semantic chips, form draft safety, and loading/error/empty states. No browser/e2e run was available.

## Current re-audit observations

- Broken notification rendering was found and fixed: the complaint email view and missing-email fallback previously called `label()` on enums that do not define it.
- Incomplete filter hardening was found and fixed: an invalid internal customer hash now produces no records instead of broadening the list to all complaints.
- The internal CRM resource and SPA now expose/check NCR disposition consistently with the server-side resolve/close gate.
- Cancellation remains an explicit policy question; the `cancelled` and `investigating` states have no first-class transition route or documented actor/reason contract.

## Evidence gaps

- No deployed permission matrix or staging worker run was available.
- No browser/e2e verification of internal or customer-portal complaint flows was run.
- No concurrent worker/lifecycle/SLA test or browser/e2e verification was run in this session.
- Cancellation/investigation semantics and internal permission granularity still require product/RBAC-owner decisions.
- The worktree contains pre-existing user changes in backup, notification, landing, Docker, and database-script areas; unrelated dependency modules remain read-only.
