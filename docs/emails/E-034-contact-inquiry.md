# E-034 — public contact inquiry alert

## Trigger and recipient

The public `POST /api/v1/landing/contact-inquiry` route remains rate-limited by `public-form`. `ContactInquiryService` stores the inquiry, then queues one notice to the validated `company.sales_inbox_email` settings value. The existing recipient destination is unchanged. Missing or invalid settings fail into an in-app alert for active users with effective `crm.inquiries.view` access.

The SPA route `/crm/inquiries/{hash}` is protected by the CRM module and `crm.inquiries.view`; the API applies the same permission. Login return-to-route behavior is source-checked through the shared auth guard. Browser login was not exercised.

## Content and data

The email carries only the generated inquiry reference, the server-side submission timestamp formatted in the application timezone, and the authenticated CRM link. It does not include the sender's name, email, phone, company, message, IP address, or user agent. Those values remain in the CRM record for permitted users. The mailable contains scalar event-time values, so later inquiry edits do not change the queued notice.

Subject uses the configured email brand name and inquiry reference. The shared Markdown shell supplies company branding, settings-backed footer, light/dark logo, and plain-text alternative. No attachment or Reply-To is set. Any follow-up occurs through the authenticated CRM record.

The mailable uses the `mail` queue, after-commit dispatch, three attempts with `[60, 300, 900]` second backoff, and a stable delivery key derived from the saved inquiry ID. Queue and exhausted-delivery failures create an in-app alert for the current effective CRM inquiry permission audience; no inquiry body or contact fields are logged.

## Verification

`ContactInquiryTest`, `ContactInquiryReceivedMailDesignTest`, and `MailQueueConfigurationTest`: 19 passed, 191 assertions on isolated database `ogami_test_email_e034_20261021`. The array mailer was used with `mailpit@example.test` as the sink. No real message was sent. The rendered message was 19,025 HTML bytes and 574 plain-text bytes.

All four Chromium previews were inspected at 1440px and 390px in light and dark color schemes. No overflow or missing logo appeared. Redacted company settings are visible in [desktop light](screenshots/e034-contact-inquiry-desktop-light.png), [mobile light](screenshots/e034-contact-inquiry-mobile-light.png), [desktop dark](screenshots/e034-contact-inquiry-desktop-dark.png), and [mobile dark](screenshots/e034-contact-inquiry-mobile-dark.png). The redacted text preview is [here](screenshots/e034-contact-inquiry-redacted.txt).

Gmail, Outlook, Apple Mail, Yahoo, and mobile email-client rendering remain unverified. The sales inbox setting and its authorized readers need company-owner confirmation. Because the link opens a contact record containing personal data, the company's data-protection/legal contact should review this handling.
