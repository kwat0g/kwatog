# E-016: Payslip available

| Field | Verified behavior |
|---|---|
| Trigger | Finalized payroll event, only when `payroll.payslip_email.enabled` is enabled |
| Delivery | Existing `SendPayslipEmailJob`, low queue, three attempts, `[60, 300]` backoff, publication/duplicate checks, sent/failed state on payroll row |
| Recipient | The employee email on the payroll record; no CC/BCC |
| Data in message | Payroll period only; no salary, deduction, bank, tax, or employee identity fields |
| Link | `FRONTEND_URL/self-service/payslips/{payroll_hash_id}` |
| Access | SPA confirmation page; explicit download click uses owner-only `/hr/self-service/payslips/{id}/download` endpoint and shared payslip PDF/vault service |
| Attachment | None; previous email attached the entire sensitive payslip PDF |

The email opens a protected SPA record route, not a state-changing download URL. The SPA route displays a confirmation and downloads only after the employee clicks. It preserves its route through sign-in and a required password change (EF-026). The API resolves the hash ID to a payroll row owned by the signed-in employee and applies the publication gate. No token is placed in the URL; the hash ID alone does not grant access.

The download generates and stores the same PDF through `PayslipPdfService`/`DocumentVaultService`; this does not duplicate PDF content logic. Existing queue/claim/retry and payroll email-status behavior remain. Mail is sent synchronously inside the existing worker job so `payslip_emailed_at` is written only after mailer acceptance.

Payroll and HR must verify the message text and employee email-address source. The removal of the attachment is a security change because the old PDF included highly sensitive payroll data; confirm the authenticated download satisfies employee workflow needs. HR/legal/data-protection review required; no legal compliance claim is made.

Verification: `SendPayslipEmailJobTest` 4 passed/24 assertions; `EmailPayslipOnFinalizeTest` 4 passed/8 assertions; `SelfServiceOwnerScopeTest` 1 passed/13 assertions; SPA payslip-detail test 1 passed; SPA typecheck passed. Mailer forced to `array`; no message sent.

Visual review: rendered HTML is 19.7 KB and contains no payroll amounts. Inspected Chromium screenshots at 1440px and 390px, light/dark: [desktop light](screenshots/e016-payslip-desktop-light.png), [mobile light](screenshots/e016-payslip-mobile-light.png), [desktop dark](screenshots/e016-payslip-desktop-dark.png), [mobile dark](screenshots/e016-payslip-mobile-dark.png). No horizontal overflow. Browser rendering does not establish Gmail, Outlook, Apple Mail, Yahoo, delivery, or inbox placement.
