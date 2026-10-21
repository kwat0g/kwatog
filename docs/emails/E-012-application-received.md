# E-012 — Applicant application received

## Message

- Trigger and recipient unchanged: public application submission queues one confirmation to the candidate address.
- Email confirms the selected position, gives the application tracking code, and links to the SPA tracking page. It asks the candidate to keep the code private.
- Body contains no resume, phone number, cover letter, or HR-only data. Company display name comes from email branding settings.
- Laravel Markdown provides the shared company logo, footer, responsive layout, dark-mode logo, and plain-text alternative.

## Verification

- `PublicRecruitmentTest::test_public_can_submit_application`: 1 test, 6 assertions passed. `Mail::fake()` captured the queued message; no email sent.
- Rendered Mailable HTML: 22,672 bytes before embedding CID logo images for browser preview.
- Playwright screenshots inspected at 1440px desktop and 390px mobile in light/dark schemes. Document width matched each viewport; tracking details and CTA remain readable.
- Screenshots: [desktop light](screenshots/e012-application-received-desktop-light.png), [desktop dark](screenshots/e012-application-received-desktop-dark.png), [mobile light](screenshots/e012-application-received-mobile-light.png), [mobile dark](screenshots/e012-application-received-mobile-dark.png).
- Browser checks do not prove Gmail, Outlook, Apple Mail, Yahoo, or mobile mail-client rendering. Footer preview uses seeded local branding data and requires owner verification.

## Review

Applicant personal-data handling requires review by the company's HR and data-protection contacts. Real-client rendering remains unverified.
