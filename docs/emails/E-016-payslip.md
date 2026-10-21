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
- UI screenshots and real email-client rendering are pending the separate E-016 design change.

## Review

Payslip delivery requires HR and data-protection review before production. This change does not assert legal or regulatory compliance.
