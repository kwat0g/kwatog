# M060 — Corporate Site / Contact Audit Report

Session date: 2026-08-24 (Asia/Manila)  
Domain/module: `public/corporate-site-contact`  
Tier: 4  
Scope: public landing page, contact inquiry intake, newsletter opt-in, quality-policy download, and the module-owned CRM inquiry reader.

## Executive result

The module is functional and has a coherent public-to-CRM path. Public contact and newsletter writes are validated and rate-limited; contact inquiries are persisted transactionally, assigned an inquiry number, and are visible only through authenticated CRM routes with explicit permissions. The focused backend suite passed after this session's fixes: 15 tests and 127 assertions.

The session fixed five contained issues:

- contact inquiry User-Agent capture could exceed the database column;
- negative CRM page sizes reached `paginate()`;
- newsletter subscription used a read-then-write upsert path;
- the landing skip link depended on mutable CMS navigation data;
- the newsletter input had no programmatic label or autocomplete hint.

The remaining work is not a reason to block the current module release, but it should be planned separately: newsletter unsubscribe/admin operations and consent/retention, asynchronous notification delivery, first-use document-sequence contention, inquiry archive/audit history, email identity canonicalization, and an owner decision on publishing live customer names and operational counts.

No formal legal/compliance audit was performed. Consent, retention, customer-publication approval, and anti-abuse requirements are explicitly left as owner questions where the repository does not establish the policy.

## Discovery

### Public and authenticated surfaces

- `api/app/Modules/Landing/routes.php:13-23` exposes public contact inquiry, newsletter, quality-policy, contact-settings, and landing-content endpoints. Contact inquiry, newsletter, and PDF generation use the `public-form` limiter; contact/content reads are intentionally public.
- `api/app/Modules/Landing/routes.php:25-30` exposes the inquiry options/list/detail/status routes only behind Sanctum plus `crm.inquiries.view` or `crm.inquiries.manage`.
- `api/app/Modules/Landing/Requests/StoreContactInquiryRequest.php:14-32` caps names/company/email/phone/message and permits unauthenticated submission by design.
- `api/app/Modules/Landing/Requests/SubscribeNewsletterRequest.php:9-20` validates only a required email up to 150 characters.
- `api/bootstrap/app.php:145-164` confirms the guest API and public-form rate limits. The public-form limiter is 10 requests per minute per IP.

### Persistence and workflow

- `api/app/Modules/Landing/Services/ContactInquiryService.php:31-45` writes the inquiry, sequence number, and initial `new` status in one transaction.
- `api/app/Modules/Landing/Services/ContactInquiryService.php:47-72` sends the sales notification after commit and catches delivery failures, preserving the inquiry and notifying eligible CRM users in-app.
- `api/app/Modules/Landing/Services/NewsletterService.php:13-28` now uses a database upsert keyed by the unique email column and restores an unsubscribed row to `subscribed` when a person resubscribes.
- `api/database/migrations/0220_create_newsletter_subscribers_table.php:16-24` stores a unique raw email, status, source IP, and `unsubscribed_at`, but does not provide an unsubscribe token or delivery state.
- `api/app/Modules/Landing/Models/ContactInquiry.php:21-44` uses hash IDs and soft deletes. `api/app/Modules/Landing/Resources/ContactInquiryResource.php:15-31` avoids exposing the integer ID but returns the inquiry's PII, source IP, and User-Agent to permitted CRM readers.
- `api/database/migrations/0447_reshape_quote_requests_into_contact_inquiries.php:42-60` explicitly introduced soft deletes so an inbox could dismiss spam without destroying the original message; the current module routes do not expose that lifecycle.
- `api/database/migrations/2026_08_13_221000_add_enum_lifecycle_status_guards.php:39-40` constrains newsletter and inquiry status values at the database layer.

### Landing content and publication behavior

- `api/app/Modules/Landing/Controllers/LandingContactController.php:16-25` publishes company contact settings with bounded coordinates.
- `api/app/Modules/Landing/Controllers/LandingContentController.php:19-41` publishes live customer names, live trust points, live stats, and CMS-controlled landing copy to the unauthenticated landing route.
- `api/app/Modules/Landing/Controllers/LandingContentController.php:75-120` derives employee/customer/product counts and every active customer name without a public-visibility or consent predicate.
- `api/app/Modules/Landing/Controllers/QualityPolicyController.php:18-38` repeats the active-customer publication in the downloadable quality-policy PDF.
- The current implementation intentionally removed stale seeded claims, which is a good data-freshness decision, but freshness does not establish permission to publish each live record.

### Frontend and accessibility

- `spa/src/pages/landing/LandingPage.tsx:107-124` now gives the skip link a stable `#main-content` destination and assigns that ID to the main content landmark.
- `spa/src/pages/landing/components/LandingFooter.tsx:175-204` contains the newsletter form; the input now has a hidden visible-to-assistive-technology label, `name`, `autoComplete="email"`, and the API's 150-character limit.
- `spa/src/pages/landing/sections/ContactSection.tsx:37-46` performs useful local validation, but only mirrors the message length cap. The server remains the authoritative validator for the other field limits in `StoreContactInquiryRequest.php:25-31`.
- `spa/src/pages/landing/components/CookieBanner.tsx:27-73` records a local accept/decline choice, but has no linked privacy/retention explanation or later preference-management path.
- `docs/DESIGN-SYSTEM.md:171-175` explicitly exempts the landing page's separate namespace from the Atelier token-discipline gate; no design-system violation was raised from that gate.

## Findings

### Broken

#### B-01 — User-Agent capture exceeded the database contract — fixed

The intake service previously truncated the request User-Agent to 500 characters while the originating `user_agent` column is a default-length string (`api/database/migrations/0219_create_quote_requests_table.php:30-32`, retained when the table was reshaped). A User-Agent between 256 and 500 characters could therefore make a valid public submission fail at persistence.

The service now caps the value at 255 in `api/app/Modules/Landing/Services/ContactInquiryService.php:33-42`. `api/tests/Feature/Landing/ContactInquiryTest.php:80-94` covers a 300-character User-Agent and confirms the record is created with a 255-character value.

Classification: Broken, remediated in this session.

### Missing

#### M-01 — Newsletter is write-only

The module has a public subscribe route (`api/app/Modules/Landing/routes.php:14-16`) and a status model with `subscribed`/`unsubscribed` states (`api/app/Modules/Landing/Enums/NewsletterStatus.php:7-10`), but no module-owned route, service, or UI for unsubscribe, subscriber review, export, suppression, provider delivery status, or bounce handling. The `unsubscribed_at` column exists (`api/database/migrations/0220_create_newsletter_subscribers_table.php:16-24`) without a user-facing lifecycle that can set it.

Impact: marketing operations cannot honor the full subscriber lifecycle from the product surface, and the system cannot demonstrate whether an address is deliverable or suppressed.

Classification: Missing. Separate implementation recommended.

#### M-02 — Consent, purpose, and retention contract is not represented

The contact request accepts only the business fields in `api/app/Modules/Landing/Requests/StoreContactInquiryRequest.php:21-32`; the newsletter request accepts only an email in `api/app/Modules/Landing/Requests/SubscribeNewsletterRequest.php:16-20`. The frontend forms likewise have no consent/purpose notice or retention reference (`spa/src/pages/landing/sections/ContactSection.tsx:204-265`, `spa/src/pages/landing/components/LandingFooter.tsx:175-204`). The cookie banner is a local-storage choice, not a record of contact/newsletter consent (`spa/src/pages/landing/components/CookieBanner.tsx:14-23`).

The repository does not establish the applicable policy, so this is an owner question rather than a legal conclusion: confirm whether explicit consent, a privacy link, retention/deletion behavior, and a newsletter unsubscribe link are required for this deployment.

Classification: Missing / policy-dependent. Separate implementation recommended.

### Incomplete

#### I-01 — CRM page-size lower bound — fixed

The inquiry reader previously passed a negative `per_page` value into Laravel pagination while only applying an upper bound. The service now clamps the value to 1–100 at `api/app/Modules/Landing/Services/ContactInquiryInboxService.php:39-41`. `api/tests/Feature/Landing/ContactInquiryTest.php:146-156` verifies `per_page=-1` returns one row and reports `meta.per_page=1`.

Classification: Incomplete hardening, remediated in this session.

#### I-02 — Concurrent newsletter subscribe race — fixed, with concurrency test still desirable

The unique email key in `api/database/migrations/0220_create_newsletter_subscribers_table.php:16-24` protects the final state, but the former read-then-write `updateOrCreate` path could race under simultaneous first subscriptions. The service now uses one database upsert at `api/app/Modules/Landing/Services/NewsletterService.php:15-28`; the existing idempotency test remains at `api/tests/Feature/Landing/NewsletterTest.php:41-59`.

The focused suite proves sequential idempotency, not a multi-worker race. Add a concurrency-oriented integration test or database-level load check when the newsletter lifecycle is next implemented.

Classification: Incomplete hardening, remediated in this session; residual verification follow-up.

#### I-03 — Notification delivery is synchronous on the public write path

After the inquiry transaction commits, the public request directly calls `Notification::route(...)->notify(...)` at `api/app/Modules/Landing/Services/ContactInquiryService.php:47-52`. The notification class is a normal `Notification` and does not declare a queue contract (`api/app/Modules/Landing/Notifications/ContactInquiryReceivedNotification.php:7-18`). The catch/fallback preserves the inquiry, but SMTP/provider latency is still paid by the visitor and retries/delivery state are not durable in this module.

Classification: Incomplete operational reliability. Queue/outbox design is cross-system and separate-session recommended.

#### I-04 — First-use inquiry-number sequence can contend

`api/app/Common/Services/DocumentSequenceService.php:62-84` locks an existing sequence row but inserts one when absent. The table has a unique `(document_type, year, month)` constraint (`api/database/migrations/0007_create_document_sequences_table.php:13-22`). Two truly simultaneous first submissions can therefore race on the insert even though subsequent increments are serialized.

This is a shared common-service change, outside the safe scope of this module session. Seed the row before traffic or add unique-violation retry/serialization in a separate hardening session.

Classification: Incomplete concurrency hardening. Separate-session recommended.

#### I-05 — Inquiry lifecycle has storage support but no archive/audit surface

The model includes `SoftDeletes` (`api/app/Modules/Landing/Models/ContactInquiry.php:21-24`) and the migration explains that an inbox should dismiss spam without destroying the source record (`api/database/migrations/0447_reshape_quote_requests_into_contact_inquiries.php:51-54`). Current routes expose only list/detail/status (`api/app/Modules/Landing/routes.php:27-30`), and the status service only saves the new status (`api/app/Modules/Landing/Services/ContactInquiryInboxService.php:49-53`). No archive/restore action or actor/audit record is visible in this module.

Classification: Incomplete workflow. Add explicit archive/restore permissions, UI, and audit semantics in a separate session.

#### I-06 — Newsletter identity is raw, case-sensitive input

The unique constraint is on the raw `email` string (`api/database/migrations/0220_create_newsletter_subscribers_table.php:16-19`), the request does not canonicalize it (`api/app/Modules/Landing/Requests/SubscribeNewsletterRequest.php:16-20`), and the upsert key is the submitted value (`api/app/Modules/Landing/Services/NewsletterService.php:17-27`). `user@example.com` and `USER@example.com` can therefore represent separate subscribers depending on database collation.

Classification: Incomplete data identity. Normalize at the boundary and reconcile existing rows in a separate migration/data-cleanup decision.

#### I-07 — Live public proof points need an explicit publication contract

The unauthenticated content endpoint publishes all active customer names and live employee/customer/product counts (`api/app/Modules/Landing/Controllers/LandingContentController.php:19-41`, `:75-120`); the quality-policy PDF publishes active customer names as well (`api/app/Modules/Landing/Controllers/QualityPolicyController.php:18-38`). There is no visibility/consent predicate in those queries. This may be intentional marketing behavior, but the repository does not prove that every active customer or count is approved for public disclosure.

Classification: Incomplete governance / owner question. Confirm the publication contract before adding a `publicly_visible` field, allow-list, or cached approval workflow.

### Polish

#### P-01 — Skip-link target was CMS-dependent — fixed

The landing skip link previously used the first CMS navigation link; it now targets the stable main landmark at `spa/src/pages/landing/LandingPage.tsx:107-124`.

Classification: Polish/accessibility, remediated in this session.

#### P-02 — Newsletter field lacked a programmatic label — fixed

The footer newsletter input now has an associated screen-reader label, `name`, `autoComplete`, and `maxLength` at `spa/src/pages/landing/components/LandingFooter.tsx:175-190`.

Classification: Polish/accessibility, remediated in this session.

#### P-03 — Minor contact UX consistency remains

The public contact form's local schema only mirrors the message cap (`spa/src/pages/landing/sections/ContactSection.tsx:37-46`), so users can submit overlong name/company/phone values before receiving the server response. The phone presentation is also plain text in the contact section and footer (`spa/src/pages/landing/sections/ContactSection.tsx:171-175`, `spa/src/pages/landing/components/LandingFooter.tsx:219-221`) rather than a `tel:` link.

Classification: Polish. Safe small follow-up; not release-blocking.

## Re-audit — 2026-08-25

### Re-audit result

The five fixes recorded in the 2026-08-24 session remain present and the focused
backend suite still passes. This re-audit found no regression in contact intake,
newsletter upsert behavior, permission gates, public rate limiting, or the
landing skip link. It did find one user-visible broken contract and several
incomplete hardening/polish gaps. Because the dominant work changes lifecycle
policy, public-content contracts, or cross-module operations, no production-code
fixes were applied in this session. M060 is handed off as `📋 Plan Ready`.

### Re-audit discovery and hardening findings

#### B-02 — Broken: inquiry email promises a CRM lead-conversion flow that was removed

`ContactInquiryReceivedNotification` still tells the recipient to “convert the
inquiry to a lead” (`api/app/Modules/Landing/Notifications/ContactInquiryReceivedNotification.php:34-37`).
The later CRM scope cut explicitly leaves `contact_inquiries` as a plain inbox,
drops `converted_to_lead_id`, and drops the `leads` table
(`api/database/migrations/0454_drop_crm_sales_funnel_tables.php:23-49`). The
current module exposes only options/list/detail/status routes
(`api/app/Modules/Landing/routes.php:27-30`), and the SPA documents that sales
orders are created directly rather than promoted from this inbox
(`spa/src/types/crm.ts:232-237`). The delivered internal email therefore gives
operators an action that no longer exists.

Classification: Broken. A small same-session-safe copy correction is listed in
the action plan, but it was deferred because the overall plan is dominated by
separate-recommended work.

#### I-08 — Incomplete: inquiry lifecycle policy is not enforced consistently

The API writes any enum value directly with `forceFill()`
(`api/app/Modules/Landing/Services/ContactInquiryInboxService.php:49-53`),
while the controller catches `BusinessRuleException` even though this service
does not throw one (`api/app/Modules/Landing/Controllers/ContactInquiryInboxController.php:44-57`).
The detail UI offers actions until an inquiry is closed but no reopen action
(`spa/src/pages/crm/inquiries/detail.tsx:64-78`), and dashboard badges count only
`new` and `in_progress` rows (`api/app/Modules/Dashboard/Services/BadgeService.php:375-381`).
The API can nevertheless move a closed row back into the active badge set. The
repository does not establish whether closed is terminal or reopening is
intentional, so the transition policy must be decided and then enforced in the
service, UI, and tests.

Classification: Incomplete. Separate-recommended because this changes the
module's lifecycle/state contract and audit semantics.

#### I-09 — Incomplete: CRM list filters accept unvalidated query shapes

`ContactInquiryInboxController::index()` passes the raw query array into the
service (`api/app/Modules/Landing/Controllers/ContactInquiryInboxController.php:34-36`).
The service interpolates `status`, `search`, and `per_page` without a request
contract (`api/app/Modules/Landing/Services/ContactInquiryInboxService.php:20-41`).
The normal SPA sends scalar values, but malformed array values or an unbounded
search string can reach query construction instead of returning a controlled
422 response. The existing test covers only a negative scalar page size
(`api/tests/Feature/Landing/ContactInquiryTest.php:146-156`).

Classification: Incomplete hardening. Add a bounded request/FormRequest and
malformed-input tests; small/medium same-session-safe but deferred behind the
larger lifecycle plan.

#### I-10 — Incomplete: public CMS settings are typed only at the TypeScript boundary

The admin request validates most landing settings only as an outer `array`
(`api/app/Modules/Admin/Requests/UpdateSettingRequest.php:269-282`). The public
controller then returns arbitrary nested arrays without normalizing required
keys or scalar types (`api/app/Modules/Landing/Controllers/LandingContentController.php:57-72`).
The PDF path filters objectives only by `isset()` and passes them to a view that
assumes string `title`/`body` values
(`api/app/Modules/Landing/Controllers/QualityPolicyController.php:29-50`,
`api/resources/views/pdf/quality-policy.blade.php:174-181`). The SPA declares
non-null structured interfaces but performs no runtime validation
(`spa/src/api/landing.ts:23-81`). A malformed admin edit can therefore produce
blank sections, undefined UI fields, or a PDF error while the API still returns
200 for content.

Classification: Incomplete. Separate-recommended because the fix must align the
admin validation, public response normalizer, PDF renderer, and frontend contract.

#### I-11 — Incomplete/operations: public content performs repeated live queries without a response cache

The unthrottled public content route returns customer names, trust points,
proof-point counts, stats, and CMS sections (`api/app/Modules/Landing/routes.php:19-22`).
The controller separately counts customers/products/employees for each of those
views (`api/app/Modules/Landing/Controllers/LandingContentController.php:23-41`,
`:75-120`), producing repeated database work for each uncached request. Settings
are cached by `SettingsService`, but the operational counts and customer-name
query are not. This is not a correctness failure at current test scale, but a
traffic burst can turn a public marketing read into avoidable database load.

Classification: Incomplete. Separate-recommended for a bounded/cached snapshot
strategy with invalidation tied to operational data changes.

### Re-audit polish findings

#### P-04 — Polish/accessibility: closed mobile menu leaves partner links in the tab order

The mobile navigation anchors receive `tabIndex={-1}` while closed
(`spa/src/pages/landing/components/LandingNav.tsx:263-275`), but the customer and
supplier `Link` elements in the same collapsed sheet do not
(`spa/src/pages/landing/components/LandingNav.tsx:280-299`). The sheet is only
visually collapsed with `max-h-0` (`:253-260`), so keyboard users can reach
off-screen partner links while the menu appears closed.

Classification: Polish/accessibility. Small same-session-safe; deferred because
the current re-audit plan is not majority same-session-safe.

#### P-05 — Polish: CRM detail labels the user-agent field as “LuUser agent”

The submission metadata label contains the literal `Lu` prefix
(`spa/src/pages/crm/inquiries/detail.tsx:113-127`). This is a visible typo in a
module-owned system-admin surface.

Classification: Polish. Small same-session-safe; deferred with P-04.

#### P-06 — Polish/accessibility: newsletter success and error states are not announced

The footer replaces the form with a plain `div` on success and renders a plain
paragraph on failure (`spa/src/pages/landing/components/LandingFooter.tsx:169-204`).
Neither state has `role="status"`/`aria-live` or `role="alert"`, so a screen-reader
subscriber may not learn whether the action succeeded or failed.

Classification: Polish/accessibility. Small same-session-safe; defer with the
frontend test and consent/lifecycle work.

### Re-audit verification evidence

- `docker compose run --rm api php artisan test --filter='Landing'` — **PASS**, 15 tests / 127 assertions.
- `npm run typecheck` in `spa/` — **PASS**.
- `npm run lint` in `spa/` — **PASS**.
- `npm run test:run` in `spa/` — **BLOCKED before collection** by `EACCES` writing `spa/node_modules/.vite-temp/vitest.config.ts.timestamp-…mjs`; both `spa/node_modules` and `.vite-temp` are root-owned (`stat` confirms `root:root`).
- `git diff --check` — **PASS** before audit-artifact edits.

## Verification evidence

- `docker compose run --rm api php artisan test --filter='Landing'` — **PASS**, 15 tests, 127 assertions. PHPUnit emitted four existing doc-comment metadata deprecation warnings in unrelated suites.
- `npm run typecheck` in `spa` — **PASS**.
- `npm run lint` in `spa` — **PASS**.
- `npm run test:run` in `spa` — **blocked before collection**: Vite could not write `spa/node_modules/.vite-temp/vitest.config.ts.timestamp-...mjs` because the directory is root-owned and not writable by the workspace user. No SPA unit tests were collected.

## Session disposition

The 2026-08-24 implementation session released the contained fixes as
`✅ Verified`. This 2026-08-25 re-audit supersedes that queue status: M060 is
released as `📋 Plan Ready` because B-02 is a broken user-facing contract and
the remaining findings require separate implementation decisions. No source
fixes were applied during the re-audit.
