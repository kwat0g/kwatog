# M060 — Corporate Site / Contact Fix Log

Session: 2026-08-24  
Module: `public/corporate-site-contact`

## Fixes applied

### F-01 — User-Agent column overflow

- Finding: B-01, Broken.
- Before: `api/app/Modules/Landing/Services/ContactInquiryService.php:33-38` truncated the request User-Agent to 500 characters while the database string column was 255 characters.
- After: the same service now caps it at 255 (`api/app/Modules/Landing/Services/ContactInquiryService.php:33-42`).
- Verification: `api/tests/Feature/Landing/ContactInquiryTest.php:80-94` passes with a 300-character User-Agent; the contact inquiry is created and the persisted value is 255 characters.
- Status: ✅ Verified.

### F-02 — Negative CRM page size

- Finding: I-01, Incomplete hardening.
- Before: `api/app/Modules/Landing/Services/ContactInquiryInboxService.php:39-41` applied only an upper bound before calling `paginate()`.
- After: the service clamps `per_page` to 1–100 at `api/app/Modules/Landing/Services/ContactInquiryInboxService.php:39-41`.
- Verification: `api/tests/Feature/Landing/ContactInquiryTest.php:146-156` passes for `per_page=-1` and asserts one returned row plus `meta.per_page=1`.
- Status: ✅ Verified.

### F-03 — Newsletter subscription race window

- Finding: I-02, Incomplete hardening.
- Before: `api/app/Modules/Landing/Services/NewsletterService.php:13-20` used `updateOrCreate`, which read before writing under the unique email constraint.
- After: the service uses a single database upsert at `api/app/Modules/Landing/Services/NewsletterService.php:15-28`, preserving subscribed/resubscribe behavior.
- Verification: `api/tests/Feature/Landing/NewsletterTest.php:41-59` passes the existing idempotency check; a true multi-worker race test remains in the action plan.
- Status: ✅ Verified for the contained change; concurrency test follow-up remains open.

### F-04 — Landing skip-link destination

- Finding: P-01, Polish/accessibility.
- Before: `spa/src/pages/landing/LandingPage.tsx:107-110` derived the skip-link target from the first CMS navigation item.
- After: it targets `#main-content`, and the main landmark owns that ID at `spa/src/pages/landing/LandingPage.tsx:107-124`.
- Verification: `npm run typecheck` and `npm run lint` pass in `spa`.
- Status: ✅ Verified.

### F-05 — Newsletter field accessibility and length hint

- Finding: P-02, Polish/accessibility.
- Before: `spa/src/pages/landing/components/LandingFooter.tsx:175-190` rendered an unlabeled email input with no `name`, autocomplete hint, or client-side length cap.
- After: the field has an associated label, `name="email"`, `autoComplete="email"`, and `maxLength={150}` at `spa/src/pages/landing/components/LandingFooter.tsx:175-190`.
- Verification: `npm run typecheck` and `npm run lint` pass in `spa`.
- Status: ✅ Verified.

## Verification run

- `docker compose run --rm api php artisan test --filter='Landing'`: **PASS**, 15 tests / 127 assertions.
- `npm run typecheck`: **PASS**.
- `npm run lint`: **PASS**.
- `npm run test:run`: **BLOCKED before collection** by `EACCES` writing `spa/node_modules/.vite-temp/vitest.config.ts.timestamp-...mjs`; the dependency temp directory is root-owned. No frontend unit-test result is claimed.

Open work is tracked in `action-plan.md` and was not silently included in this fix set.

## Re-audit — 2026-08-25

No production-code fixes were applied. The re-audit revalidated the five
contained fixes above and found that the remaining work is dominated by
lifecycle policy, public content-contract hardening, query/caching design, and
cross-cutting newsletter/notification operations. The module is therefore
released as `📋 Plan Ready` for a separate implementation/re-audit session.

Verification recorded for this re-audit:

- `docker compose run --rm api php artisan test --filter='Landing'` — PASS, 15 tests / 127 assertions.
- `npm run typecheck` — PASS.
- `npm run lint` — PASS.
- `npm run test:run` — blocked before collection by root-owned `spa/node_modules/.vite-temp` (`EACCES`).

## Plan execution — 2026-08-25

### F-06 — Validate CRM inquiry list filters

- Finding: I-09, Incomplete hardening.
- Before: `ContactInquiryInboxController::index()` passed the raw query array to the service (`api/app/Modules/Landing/Controllers/ContactInquiryInboxController.php:35-38`), allowing malformed array values and unbounded searches to reach query construction.
- After: `ListContactInquiryRequest` enforces the inquiry-status enum, scalar search/page/page-size inputs, a 120-character search cap, and a 100-row upper page-size bound (`api/app/Modules/Landing/Requests/ListContactInquiryRequest.php:11-35`); the controller passes only validated values (`api/app/Modules/Landing/Controllers/ContactInquiryInboxController.php:35-37`). The service's existing lower-bound clamp remains in place for negative legacy page-size callers.
- Verification: `api/tests/Feature/Landing/ContactInquiryTest.php:146-186` covers the negative page-size regression, array-shaped filters, and an overlong search. The focused test passes: 10 tests / 49 assertions.
- Status: ✅ Verified for the contained hardening change.

### F-07 — Remove the stale lead-conversion instruction

- Finding: B-02, Broken.
- Before: `ContactInquiryReceivedNotification` told operators to convert the inquiry to a lead even though the lead tables and conversion flow were removed.
- After: the notification now directs operators to reply or update the inquiry status in the CRM inbox (`api/app/Modules/Landing/Notifications/ContactInquiryReceivedNotification.php:34-37`).
- Verification: the Landing feature suite passes, including notification faking and inquiry creation.
- Status: ✅ Verified.

### F-08 — Mirror public contact limits and make phone contactable

- Finding: P-03, Polish.
- Before: the landing form only mirrored the message limit, and phone numbers were rendered as plain text.
- After: the client schema and native controls mirror the API's 150/150/150/40/2000 limits (`spa/src/pages/landing/sections/ContactSection.tsx:37-45`, `:217-257`); the landing contact section and footer render available phone numbers as `tel:` links (`spa/src/pages/landing/sections/ContactSection.tsx:171-184`, `spa/src/pages/landing/components/LandingFooter.tsx:219-226`).
- Verification: changed-file ESLint passes.
- Status: ✅ Verified.

### F-09 — Remove closed-menu partner links from the tab order

- Finding: P-04, Polish/accessibility.
- Before: customer and supplier links in the collapsed mobile menu remained keyboard reachable.
- After: both links use `tabIndex={-1}` while the menu is closed (`spa/src/pages/landing/components/LandingNav.tsx:285-299`).
- Verification: changed-file ESLint passes.
- Status: ✅ Verified.

### F-10 — Correct the CRM user-agent label

- Finding: P-05, Polish.
- Before: the inquiry detail page displayed `LuUser agent`.
- After: it displays `User agent` (`spa/src/pages/crm/inquiries/detail.tsx:123-127`).
- Verification: changed-file ESLint passes.
- Status: ✅ Verified.

### F-11 — Announce newsletter outcome states

- Finding: P-06, Polish/accessibility.
- Before: newsletter success and failure content was rendered without an assistive-technology announcement mechanism.
- After: success uses `role="status"`/polite live announcement and failure uses `role="alert"` (`spa/src/pages/landing/components/LandingFooter.tsx:169-203`).
- Verification: changed-file ESLint passes.
- Status: ✅ Verified.

## Deferred plan items

The following items remain pending and are not fresh Plan Ready work:

- M-01/M-02: newsletter lifecycle, consent, purpose, and retention need owner decisions about unsubscribe/resubscribe, provider suppression, privacy notice, and retention policy.
- I-03: durable notification queue/outbox behavior needs an operational delivery-state, retry, and operator-failure design; no partial queue change was made.
- I-04: first-use document-sequence hardening belongs to `api/app/Common/Services/DocumentSequenceService.php`, outside this module's scope.
- I-05: archive/restore and actor/reason audit semantics need a defined inquiry lifecycle and a larger route/UI change.
- I-06: newsletter email canonicalization requires an approved duplicate-reconciliation/data-migration policy.
- I-07: public customer names and operational counts require owner approval for publication.
- I-08: status transition rules require an owner decision on whether `closed` is terminal or reopenable before changing the service, UI, and tests.
- I-10: public content normalization must align the Landing reader/PDF/SPA with Admin setting validation; changing `api/app/Modules/Admin` is outside this module's scope.
- I-11: response caching needs a cross-module invalidation strategy tied to operational source records.
- The SPA test runner remains blocked before collection by the root-owned `spa/node_modules/.vite-temp` directory (`EACCES`); changing its ownership is an environment-owner action.

## Verification — plan execution

- `docker compose run --rm api php artisan test --filter='ContactInquiryTest'` — PASS, 10 tests / 49 assertions.
- `docker compose run --rm api php artisan test --filter='Landing'` — PASS, 16 tests / 137 assertions.
- `php -l` on the changed PHP source files — PASS.
- Changed-file `npx eslint` — PASS.
- Repository-wide `npm run typecheck` — blocked by unrelated existing errors (`qrcode` module, unused driver type, and duplicate JSX attribute).
- Repository-wide `npm run lint` — blocked by unrelated existing errors in `useChainProgress.tsx`, journal-entry editing, and driver delivery.
- Targeted `git diff --check` — PASS.

Session disposition: `🔁 Needs Re-audit`; the contained items above are verified, and the deferred items remain pending for a future decision/implementation session.
