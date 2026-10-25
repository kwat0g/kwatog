# E-014 — Applicant interview scheduled

## Message

- Trigger and recipient unchanged: scheduling an interview queues one email to the candidate address.
- Shows the position, event-time schedule in the configured Manila timezone, location, interviewer, tracking code, and configured company address.
- Keeps the existing arrival and identification instructions. Includes the SPA application-tracking link.
- Uses the shared company branding, footer, responsive layout, dark-mode logo, and Markdown plain-text alternative.
- Queue-time snapshot fix is in parent functional PR EF-026; recipient and trigger stay unchanged.

## Verification

- Rendered Mailable HTML: 24,415 bytes before browser logo embedding.
- Playwright screenshots inspected at 1440px desktop and 390px mobile in light/dark schemes. Document width matched viewport; event facts and CTA remain readable.
- Screenshots: [desktop light](screenshots/e014-interview-desktop-light.png), [desktop dark](screenshots/e014-interview-desktop-dark.png), [mobile light](screenshots/e014-interview-mobile-light.png), [mobile dark](screenshots/e014-interview-mobile-dark.png).
- Focused queue/snapshot and content assertions are recorded in `RecruitmentApplicationTest`; no email sent.
- Browser checks do not prove Gmail, Outlook, Apple Mail, Yahoo, or mobile mail-client rendering. Preview footer uses seeded local branding data and requires owner verification.

## Review

Applicant personal-data handling requires review by the company's HR and data-protection contacts. Real-client rendering remains unverified.
