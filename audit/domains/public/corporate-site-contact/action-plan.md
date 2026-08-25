# M060 — Corporate Site / Contact Action Plan

Initial session: 2026-08-24  
Re-audit session: 2026-08-25  
Module: `public/corporate-site-contact`

## Session recommendation

`same-session-ok` was appropriate: the immediately actionable findings were small, local, and testable inside the module. They were implemented and verified. The remaining items are marked `separate-recommended` where they require a business decision, new permissions/lifecycle, asynchronous delivery, data migration, or a shared common-service change.

## Re-audit disposition

`📋 Plan Ready`. The prior five contained fixes remain verified, but the
re-audit found a stale lead-conversion promise, an implicit inquiry lifecycle,
weak public CMS shape validation, repeated live content queries, and several
small accessibility/UX defects. The dominant work is
`separate-recommended`; no production-code changes were made during the
re-audit.

## Completed in this session

1. **[small] [same-session-ok] [✅ Verified] Align captured User-Agent with the database.**
   - Changed `api/app/Modules/Landing/Services/ContactInquiryService.php:33-42` to cap `user_agent` at 255 characters.
   - Regression coverage: `api/tests/Feature/Landing/ContactInquiryTest.php:80-94`.
   - Acceptance evidence: a 300-character User-Agent creates an inquiry and persists 255 characters.

2. **[small] [same-session-ok] [✅ Verified] Bound CRM inquiry pagination to a positive range.**
   - Changed `api/app/Modules/Landing/Services/ContactInquiryInboxService.php:39-41` to clamp `per_page` to 1–100.
   - Regression coverage: `api/tests/Feature/Landing/ContactInquiryTest.php:146-156`.
   - Acceptance evidence: `per_page=-1` returns HTTP 200 with one row and `meta.per_page=1`.

3. **[small] [same-session-ok] [✅ Verified] Make newsletter subscribe writes atomic.**
   - Changed `api/app/Modules/Landing/Services/NewsletterService.php:15-28` from read-then-write behavior to a database upsert on the existing unique email key.
   - Existing idempotency coverage: `api/tests/Feature/Landing/NewsletterTest.php:41-59`.
   - Follow-up verification: add a multi-worker/concurrent integration test when the newsletter operations work is scheduled.

4. **[small] [same-session-ok] [✅ Verified] Stabilize the landing skip link.**
   - Changed `spa/src/pages/landing/LandingPage.tsx:107-124` to target `#main-content` instead of the first CMS navigation link.
   - Acceptance evidence: SPA type-check and lint pass.

5. **[small] [same-session-ok] [✅ Verified] Make the newsletter input discoverable and API-aligned.**
   - Changed `spa/src/pages/landing/components/LandingFooter.tsx:175-190` to add an associated label, `name`, `autoComplete="email"`, and `maxLength={150}`.
   - Acceptance evidence: SPA type-check and lint pass.

## Separate-session plan

1. **[large] [separate-recommended] [📋 Plan Ready] Define the newsletter lifecycle.**
   - Owner decisions: unsubscribe mechanism/token, subscriber admin view/export, suppression/bounce handling, provider sync, retention, and whether resubscribe is permitted after unsubscribe.
   - Implement public unsubscribe, authenticated subscriber operations, audit/consent fields, provider delivery state, and tests for subscribe/unsubscribe/resubscribe/suppression.
   - Evidence: `api/app/Modules/Landing/routes.php:14-30`, `api/app/Modules/Landing/Enums/NewsletterStatus.php:7-10`, `api/database/migrations/0220_create_newsletter_subscribers_table.php:16-24`.

2. **[large] [separate-recommended] [📋 Plan Ready] Establish consent, purpose, and retention behavior.**
   - Confirm the deployment's privacy/marketing requirements before changing the public forms.
   - Add the required notice/link, explicit consent field and timestamp if required, retention/deletion policy, and a user-visible unsubscribe path.
   - Evidence: `api/app/Modules/Landing/Requests/StoreContactInquiryRequest.php:21-32`, `api/app/Modules/Landing/Requests/SubscribeNewsletterRequest.php:16-20`, `spa/src/pages/landing/components/CookieBanner.tsx:27-73`.

3. **[medium] [separate-recommended] [📋 Plan Ready] Move inquiry notification delivery behind a durable queue/outbox.**
   - Keep the inquiry transaction as the source of truth.
   - Add durable delivery state, retry/backoff, idempotency, and an operator-visible failure path; preserve the current in-app fallback.
   - Evidence: `api/app/Modules/Landing/Services/ContactInquiryService.php:47-72`, `api/app/Modules/Landing/Notifications/ContactInquiryReceivedNotification.php:7-18`.

4. **[medium] [separate-recommended] [📋 Plan Ready] Harden first-use document sequence creation.**
   - Seed the `contact_inquiry` sequence row before public traffic, or add safe unique-violation retry/serialization to the shared `DocumentSequenceService`.
   - Add a concurrent first-submission test.
   - Evidence: `api/app/Common/Services/DocumentSequenceService.php:62-84`, `api/database/migrations/0007_create_document_sequences_table.php:13-22`.

5. **[medium] [separate-recommended] [📋 Plan Ready] Complete inquiry archive and audit semantics.**
   - Add archive/restore routes and UI, permission checks, actor/reason capture, audit-log entries, and tests for active/trashed visibility.
   - Preserve the original inquiry and keep status transitions constrained to the current enum.
   - Evidence: `api/database/migrations/0447_reshape_quote_requests_into_contact_inquiries.php:51-60`, `api/app/Modules/Landing/routes.php:27-30`, `api/app/Modules/Landing/Services/ContactInquiryInboxService.php:49-53`.

6. **[medium] [separate-recommended] [📋 Plan Ready] Confirm and enforce public proof-point visibility.**
   - Obtain business approval for publishing active customer names and employee/customer/product counts.
   - If approval is selective, add an explicit allow-list/visibility field and apply it consistently to JSON and the quality-policy PDF; add publication tests.
   - Evidence: `api/app/Modules/Landing/Controllers/LandingContentController.php:19-41`, `:75-120`, `api/app/Modules/Landing/Controllers/QualityPolicyController.php:18-38`.

7. **[medium] [separate-recommended] [📋 Plan Ready] Canonicalize newsletter identity.**
   - Normalize trim/case at the boundary, inspect existing case variants, reconcile duplicates with an approved policy, and add a migration/index strategy appropriate to PostgreSQL.
   - Add tests for case variants and resubscribe behavior.
   - Evidence: `api/database/migrations/0220_create_newsletter_subscribers_table.php:16-19`, `api/app/Modules/Landing/Requests/SubscribeNewsletterRequest.php:16-20`, `api/app/Modules/Landing/Services/NewsletterService.php:17-27`.

8. **[medium] [separate-recommended] [📋 Plan Ready] Define and enforce inquiry status transitions.**
   - Decide whether `closed` is terminal or reopenable, then encode the transition table in the service and expose the same actions in the SPA.
   - Add tests for every allowed/denied transition and audit the actor/reason for lifecycle changes.
   - Evidence: `api/app/Modules/Landing/Services/ContactInquiryInboxService.php:49-53`, `api/app/Modules/Landing/Controllers/ContactInquiryInboxController.php:44-57`, `spa/src/pages/crm/inquiries/detail.tsx:64-78`.

9. **[small/medium] [same-session-ok] [📋 Plan Ready] Validate CRM list query parameters.**
   - Add a bounded request contract for scalar `status`, `search`, `page`, and `per_page`; reject malformed arrays and cap search length.
   - Add feature tests for malformed shapes and the existing negative-page-size regression.
   - Evidence: `api/app/Modules/Landing/Controllers/ContactInquiryInboxController.php:34-36`, `api/app/Modules/Landing/Services/ContactInquiryInboxService.php:20-41`.

10. **[medium] [separate-recommended] [📋 Plan Ready] Normalize and version the public landing content contract.**
    - Validate nested landing settings at the admin boundary, normalize the public JSON shape, make PDF objective rendering type-safe, and add malformed-setting tests.
    - Keep the SPA interfaces aligned with the normalized response rather than relying on TypeScript assertions alone.
    - Evidence: `api/app/Modules/Admin/Requests/UpdateSettingRequest.php:269-282`, `api/app/Modules/Landing/Controllers/LandingContentController.php:57-72`, `api/app/Modules/Landing/Controllers/QualityPolicyController.php:29-50`, `spa/src/api/landing.ts:23-81`.

11. **[medium] [separate-recommended] [📋 Plan Ready] Bound repeated public content queries.**
    - Introduce a bounded snapshot/cache for live counts and customer names, with invalidation when the source records change.
    - Add query-count or cache-hit coverage for the public content route.
    - Evidence: `api/app/Modules/Landing/Controllers/LandingContentController.php:23-41`, `:75-120`, `api/app/Modules/Landing/routes.php:19-22`.

12. **[small] [same-session-ok] [📋 Plan Ready] Remove the stale lead-conversion email instruction.**
    - Replace the unavailable “convert the inquiry to a lead” instruction with the current inbox follow-up behavior.
    - Evidence: `api/app/Modules/Landing/Notifications/ContactInquiryReceivedNotification.php:34-37`, `api/database/migrations/0454_drop_crm_sales_funnel_tables.php:23-49`.

## Small polish backlog

- **[small] [same-session-ok] [📋 Plan Ready]** Mirror server max lengths in `spa/src/pages/landing/sections/ContactSection.tsx:37-46` and render phone numbers as `tel:` links at `:171-175` and `spa/src/pages/landing/components/LandingFooter.tsx:219-221`.
- **[small] [same-session-ok] [📋 Plan Ready]** Add a focused frontend test once the existing root-owned `spa/node_modules/.vite-temp` permission issue is corrected by the environment owner.
- **[small] [same-session-ok] [📋 Plan Ready]** Remove the `Lu` typo from the CRM user-agent label at `spa/src/pages/crm/inquiries/detail.tsx:113-127`.
- **[small] [same-session-ok] [📋 Plan Ready]** Keep partner links out of the closed mobile-menu tab order and announce newsletter success/error states at `spa/src/pages/landing/components/LandingNav.tsx:253-299` and `spa/src/pages/landing/components/LandingFooter.tsx:169-204`.
