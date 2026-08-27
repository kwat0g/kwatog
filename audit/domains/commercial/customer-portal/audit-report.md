# M035 — Customer Portal audit report

Session date: 2026-08-27
Audit card: `batch2-agent-b`
Claimed module: `commercial/customer-portal` (preferred target; fallback was not used)
Registry state at claim: `🔁 Needs Re-audit`
Decision: `📋 Plan Ready`

## Scope and method

This re-audit covered the customer portal API, session/tenancy boundary,
customer-facing resources, customer SPA routes and forms, and the available
customer browser specification. Dependency modules were read for contracts only;
no dependency code was audited or changed. The review used discovery,
hardening, and polish passes, with the code-review and security-review checklists
applied to response boundaries, authentication, input limits, auditability,
decimal contracts, and accessibility.

Prior artifacts, current git diff, and mtimes were checked before trusting the
previous status. The only pre-existing worktree change remains the coordinator's
generated `audit/00-MODULE-REGISTRY.md`; it was not edited.

## Finding register

Every finding has one of the required classifications: Broken, Missing,
Incomplete, or Polish. Resolved entries are retained so the re-audit disposition
is explicit.

### Prior findings rechecked

| ID | Classification | Current disposition | Evidence and disposition |
|---|---|---|---|
| F-01 | Broken | Resolved | Customer auth now uses the session-backed `customer_portal` guard and regenerates the session in `api/app/Modules/B2B/Services/B2bAuthService.php:168-171`; `spa/src/api/b2b/customer.ts:18-75` has no customer token storage path. |
| F-02 | Broken | Partially resolved; residual F-19 open | Dashboard invoice/delivery/complaint fields now use customer-safe resources at `api/app/Modules/B2B/Controllers/CustomerPortalController.php:53-64`, but customer orders still use the internal resource; see F-19. |
| F-03 | Incomplete | Resolved | Order detail eager loads retain `sales_order_id` for child relations at `api/app/Modules/B2B/Services/CustomerPortalService.php:130-135`; the focused relation test passes. |
| F-04 | Incomplete | Resolved | Customer routes apply password-change and expiry middleware at `api/app/Modules/B2B/routes.php:90-94`; history checks/records are implemented at `api/app/Modules/B2B/Services/PortalPasswordHistoryService.php:18-75`. |
| F-05 | Incomplete | Resolved | Login state is serialized with a locked transaction at `api/app/Modules/B2B/Services/B2bAuthService.php:70-137`; customer auth tests pass. |
| F-06 | Incomplete | Resolved | Same-month schedule retries compare fingerprints and normalize the unique-index race at `api/app/Modules/B2B/Services/CustomerPortalService.php:376-424`. |
| F-07 | Incomplete | Resolved | Outstanding balances remain decimal strings and use `Money` accumulation at `api/app/Modules/B2B/Services/CustomerPortalService.php:67-76`. |
| F-08 | Incomplete | Resolved | Customer query and date inputs are validated at `api/app/Modules/B2B/Controllers/CustomerPortalController.php:74-79,114-118,225-232,297-299,311-314`. |
| F-09 | Incomplete | Resolved | Proofs use a storage stream and sanitized/RFC-5987 filename headers at `api/app/Modules/B2B/Controllers/CustomerPortalController.php:181-215`; the hostile-filename test passes. |
| F-10 | Incomplete | Resolved | Login lookup normalizes email with `LOWER(email)` at `api/app/Modules/B2B/Services/B2bAuthService.php:58-63`; reset lookup does the same at `api/app/Modules/B2B/Services/PortalPasswordResetService.php:102-107`. |
| F-11 | Missing | Incomplete; open | The SPA now offers a customer-owned order selector at `spa/src/pages/portal/customer/complaints/index.tsx:46-56,83-103`, and the API persists `sales_order_id` at `api/app/Modules/B2B/Services/CustomerPortalService.php:253-283`. Product provenance remains explicitly prohibited at `api/app/Modules/B2B/Requests/Customer/CreateComplaintRequest.php:46-52`; a multi-product order cannot identify the affected product. |
| F-12 | Incomplete | Attribution improved; consistency residual F-20 open | Portal actor metadata is written by `api/app/Modules/B2B/Services/CustomerPortalService.php:286-306`, while CRM keeps its valid internal `created_by`. The audit write occurs after the CRM transaction; see F-20. |
| F-13 | Missing | Resolved | Customer list services paginate and cap page size, for example `api/app/Modules/B2B/Services/CustomerPortalService.php:108-123,226-250,369-374`; SPA pages retain paginator metadata and controls. |
| F-14 | Incomplete | Resolved | Dashboard delivery and complaint panels are rendered at `spa/src/pages/portal/customer/dashboard.tsx:160-206`. |
| F-15 | Incomplete | Resolved | Undelivered rows fall back to `scheduled_date` at `spa/src/pages/portal/customer/dashboard.tsx:171-175` and the delivery list equivalent. |
| F-16 | Polish | Resolved | The login visibility button is keyboard reachable at `spa/src/pages/portal/PortalLoginPage.tsx:201-209`. |
| F-17 | Missing | Incomplete; open | `spa/e2e/customer-portal.spec.ts:120-197` now covers four mocked login/dashboard/password-change/responsive flows, but does not cover the full role matrix or a real API boundary, and this run could not execute it because of the permission blocker recorded below. |
| F-18 | Incomplete | Resolved | Public customer auth routes are feature-gated at `api/app/Modules/B2B/routes.php:81-88`; the disabled-feature assertion is in `api/tests/Feature/B2B/CustomerPortalAuthTest.php:242-258`. |

### Current open findings

#### F-19 — Broken — customer order endpoints expose the internal sales-order response boundary (P1)

`api/app/Modules/B2B/Controllers/CustomerPortalController.php:53-64,70-93`
and `:99-104` serialize customer dashboard/order responses with
`App\Modules\CRM\Resources\SalesOrderResource`. That resource exposes internal
workflow and operational fields at
`api/app/Modules/CRM/Resources/SalesOrderResource.php:31-44,59-120`, including
`next_statuses`, edit/cancel capabilities, internal notes, MRP/work-order and
inspection projections, internal timestamps, and deleted-state metadata when
relations are loaded. The detail service explicitly loads work orders at
`api/app/Modules/B2B/Services/CustomerPortalService.php:126-150`, so this is not
only a theoretical optional field.

Impact: a customer response is not an allowlisted customer contract and can
disclose internal production/workflow state or controls. Create a dedicated
customer sales-order resource/DTO, remove internal relations and capabilities,
and assert both allowed fields and forbidden fields in dashboard/list/detail
tests.

#### F-20 — Incomplete — complaint actor audit can commit separately from complaint creation (P1)

`api/app/Modules/B2B/Services/CustomerPortalService.php:270-284` delegates to
`ComplaintService::create`, whose authoritative complaint/NCR/8D work runs in a
transaction at `api/app/Modules/CRM/Services/ComplaintService.php:111-170`.
The customer actor audit row is inserted only afterward at
`CustomerPortalService.php:286-306`. If that insert fails, the complaint has
already committed but the request returns an error; a client retry can submit a
duplicate complaint and the durable external attribution is absent for the
first row.

Impact: complaint state and audit evidence can diverge at the customer write
boundary. Put the actor event in the same transaction or use a durable
post-commit outbox/retry contract, and test an audit persistence failure plus a
retry without weakening the internal `created_by` foreign key.

#### F-21 — Broken — customer layout calls an internal-only business-policy endpoint (P1)

`spa/src/layouts/PortalLayout.tsx:162-169` invokes
`customerPortalApi.businessPolicies()` for every customer portal page, and
`spa/src/api/b2b/customer.ts:70-74` requests `/business-policies`. The route is
protected only by `auth:sanctum` at `api/routes/api.php:101-102`; Sanctum is
configured to inspect the `web` guard at `api/config/sanctum.php:18`, while the
customer guard is a separate session guard at `api/config/auth.php:21-24`.
Consequently a normal customer session cannot authenticate this request. The
layout silently leaves the runtime currency unset, so `formatPeso` falls back to
an unlabelled decimal at `spa/src/lib/formatNumber.ts:30-37`. The current browser
spec masks this mismatch by mocking a 200 response at
`spa/e2e/customer-portal.spec.ts:32-37`.

Impact: customer pages make a failing request on every navigation and monetary
values can omit the configured currency. Expose a minimal customer-safe currency
contract under the customer guard, or remove this query from the customer
layout; do not broaden the internal policy endpoint. Add a real customer-session
test for the response and the currency display path.

#### F-22 — Incomplete — customer decimal quantity contract disagrees across API and SPA (P2)

The API serializes sales-order and invoice quantities as strings at
`api/app/Modules/CRM/Resources/SalesOrderItemResource.php:22-27` and
`api/app/Modules/B2B/Resources/CustomerPortalInvoiceResource.php:36-43`, but
the customer types declare `PortalSoItem.quantity` and
`PortalInvoiceDetail.items[].quantity` as numbers at
`spa/src/types/b2b.ts:112-119,151-158`. Delivery quantities are additionally
cast to a PHP float at `api/app/Modules/B2B/Resources/CustomerDeliveryResource.php:34-42`
while the SPA declares another number at `spa/src/types/b2b.ts:206-212`.

Impact: generated/static contracts invite arithmetic on decimal values and the
delivery resource can lose precision before serialization. Standardize portal
decimal quantities as strings (or a documented decimal type) end to end, then
use quantity formatting at the display boundary and add contract assertions.

#### F-23 — Missing — schedule submission has no application-level payload bound or mutation throttle (P1)

`api/app/Modules/B2B/Requests/Customer/CustomerStoreDeliveryScheduleRequest.php:16-24`
requires at least one line but does not cap `lines`, bound quantity, or limit
numeric precision. The authenticated route group at
`api/app/Modules/B2B/routes.php:90-112` applies no mutation throttle to complaint
or schedule writes, and the SPA can append unlimited lines at
`spa/src/pages/portal/customer/delivery-schedules.tsx:55,92-164`.

Impact: an authenticated customer can submit an unnecessarily large validation/
JSON payload or repeatedly invoke a write endpoint. Add an explicit maximum
line count, quantity/precision bounds, and a deliberate per-account mutation
throttle; keep the database uniqueness/idempotency contract and test rejected
oversized requests.

#### F-24 — Polish — month selector uses UTC conversion for a local month value (P2)

`spa/src/pages/portal/customer/delivery-schedules.tsx:21-26` constructs a local
first-of-month `Date` and then calls `toISOString().slice(0, 7)`. In a positive
UTC-offset deployment, during the first hours of a month the local midnight is
still the previous UTC date, so the first option can show the previous month.
Format the year/month from local date components instead and add a timezone-edge
test.

## Positive controls observed

- Customer authentication is a dedicated HTTP-only session guard; login state is
  row-locked and protected routes enforce the customer portal model.
- Customer ownership checks exist in the service layer and the customer tenancy
  middleware; HashIDs are used for customer-facing entity identifiers.
- Invoice, delivery, complaint, and user resources are explicit allowlists, and
  proof streaming now has a bounded/read-stream path and header sanitization.
- Focused backend authorization, validation, password, reset, schedule,
  complaint, and cross-guard tests pass on the unique audit database.

## Verification

- API: `CustomerPortalAuthTest.php` — **9 tests, 50 assertions passed**.
- API: `CustomerPortalServiceTest.php`, `CustomerPortalAccessLifecycleTest.php`,
  `PortalPasswordResetTest.php`, `PortalValidationTest.php`, and
  `PortalTokenCrossGuardTest.php` — **43 tests, 170 assertions passed**.
- Database used for all API checks: `ogami_test_m035_agent_b` only.
- SPA scoped ESLint for customer portal/API/layout files: passed.
- SPA full `tsc --noEmit`: failed only at unrelated existing
  `src/pages/assets/detail.tsx:6,67` (`qrcode` module/type and implicit `any`).
- Browser: blocked before execution by `EACCES` while Playwright attempted to
  remove/write root-owned `spa/test-results/...` and `.last-run.json`. The failed
  generated directories were cleaned; no source or module files were removed.

## Release decision

`📋 Plan Ready`. The majority of the open plan is separate-recommended: the
customer response boundary, complaint transaction/audit consistency, auth-safe
policy contract, input/rate controls, and browser coverage require coordinated
API/UI tests or contract decisions. No production code was changed in this
session. The module must not be marked verified until F-19 and F-21 are fixed,
F-11/F-20/F-23 are resolved, and the browser suite can execute.
