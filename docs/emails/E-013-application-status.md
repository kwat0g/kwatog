# E-013 — Applicant application status

## Message

- Trigger and recipient unchanged: recruitment stage transition queues one update to the candidate address.
- Shows the previous and current public stage labels, position, tracking code, and a link to the SPA tracking page.
- Uses the shared company logo/footer, responsive layout, dark-mode logo, and Markdown plain-text alternative.
- Does not include rejection reasons, interview notes, or other internal HR data.
- Raw enum display and outcome-copy condition bug is tracked separately as EF-025 and fixed in the parent functional PR.

## Verification

- Rendered Mailable HTML: 23,220 bytes before browser logo embedding.
- Playwright screenshots inspected at 1440px desktop and 390px mobile in light/dark schemes. Document width matched viewport; labels and CTA remain readable.
- Screenshots: [desktop light](screenshots/e013-application-status-desktop-light.png), [desktop dark](screenshots/e013-application-status-desktop-dark.png), [mobile light](screenshots/e013-application-status-mobile-light.png), [mobile dark](screenshots/e013-application-status-mobile-dark.png).
- Focused stage-transition and rejection tests passed (2 tests, 11 assertions) with `Mail::fake()`; no email sent.
- Browser checks do not prove Gmail, Outlook, Apple Mail, Yahoo, or mobile mail-client rendering. Footer preview uses seeded local branding data and requires owner verification.

## Review

Applicant personal-data handling requires review by the company's HR and data-protection contacts. Real-client rendering remains unverified.
