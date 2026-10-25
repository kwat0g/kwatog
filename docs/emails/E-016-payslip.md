# E-016 — Payslip ready

## Security change

- Payroll finalization still queues one mail job per eligible employee; recipient, trigger, queue, retry, and status behavior are unchanged.
- Email now links to the authenticated SPA payslip page. It no longer attaches the payslip PDF or includes salary data.
- The employee must sign in. SPA auth returns them to the requested payslip page; API payroll authorization and self-service ownership checks still apply.
- PDF generation remains available through the existing authorized payslip route.

## Verification

- `SendPayslipEmailJobTest`: 3 tests, 13 assertions passed. Checks SPA URL, no payslip attachment, sent/failure state, and void cancellation.
- `EmailPayslipOnFinalizeTest`: 4 tests, 8 assertions passed. Checks recipients, queueing, idempotency, feature flag, and publication guard.
- Tests used `Mail::fake()` or `Mail::assertNothingSent()`; no email sent.
- Rendered HTML: 22,032 bytes before browser logo embedding.
- Playwright screenshots inspected at 1440px desktop and 390px mobile in light/dark schemes; document width matched viewport.
- Screenshots: [desktop light](screenshots/e016-payslip-desktop-light.png), [desktop dark](screenshots/e016-payslip-desktop-dark.png), [mobile light](screenshots/e016-payslip-mobile-light.png), [mobile dark](screenshots/e016-payslip-mobile-dark.png).
- Browser checks do not prove Gmail, Outlook, Apple Mail, Yahoo, or mobile mail-client rendering.
- Follow-up review: dark desktop CTA label is present in the screenshot; a cropped browser view confirmed it. Full-page image scaling made the label hard to see. Footer values are preview data and remain unverified.

## Review

Payslip delivery requires HR and data-protection review before production. This change does not assert legal or regulatory compliance.
