# Shared email foundation

## Stack decision

Keep Laravel's existing Markdown Mailable and `MailMessage` stack. The application already overrides Laravel's shared HTML mail layout, header, and footer; this work tightens that shared layer and does not add a template dependency or runtime compiler. Laravel's Markdown renderer produces the text alternative. Each email unit must continue using the shared layout and verify its rendered MIME parts.

## Shared shell

- Table-based, 600px email container; fluid width and reduced padding below 640px.
- System font stack; no remote CSS or JavaScript.
- `lang` from application locale and `dir="ltr"`.
- Light/dark color-scheme metadata and a dark-mode media rule; critical information remains text.
- Hidden preheader is supported through a `preheader` value in each message's view data.
- Header uses the inline CID logo with meaningful alt text and a text brand name.
- Header brand link uses the configured SPA `FRONTEND_URL`, never Laravel's API `APP_URL`.
- Footer uses `company.legal_name` and `company.address` from settings. Missing required values fail rendering instead of displaying source-code fallback facts. Optional phone, email, certification, and disclaimer appear only when configured.
- Existing PDF/company settings remain the source for company details. Optional approved email disclaimer is stored at `email.footer_disclaimer`; it is seeded from `EMAIL_FOOTER_DISCLAIMER` and editable through the admin settings endpoint.
- Laravel Markdown buttons now render a 48px VML button for Outlook, an inline-styled HTML button, a descriptive HTML fallback link, and Laravel's plain-text button link. Existing panel/table components remain available; per-message key-facts/detail/timeline blocks belong to the email groups that need them.

## Non-production delivery policy

`config/mail.php` defaults to the `log` transport. In every environment except exactly `production`, the `MessageSending` hook redirects To to `MAIL_TEST_TO`, removes Cc/Bcc and the original Reply-To, sets Reply-To to the sink, and prefixes the subject with the environment. If `MAIL_TEST_TO` is absent or invalid, it throws before transport. Production keeps configured recipients and SMTP transport. Local `.env` files were also changed to `MAIL_MAILER=log`, SMTP credentials cleared, and the active Horizon process restarted.

Set `MAIL_TEST_TO` only to a controlled test inbox or Mailpit capture address when a test inbox is needed. Never set it to a customer, supplier, employee, or applicant address. The guard is a last-mile safeguard; future queue workers must run the updated code and load current config.

## Settings to enter/verify

Existing settings rows:

- `company.legal_name` (required for mail)
- `company.address` (required for mail)
- `company.phone`, `company.email`, `company.sales_inbox_email`
- `company.tin`, `company.vat_status`, `company.certification`
- `company.logo_path` (bundled logo fallback exists in `api/resources/images`)
- `company.public_url`
- `email.brand_name`
- `email.footer_disclaimer` (optional; approved text only)
- `pdf.footer_disclaimer`, `pdf.accent_color`

The local database has rows for core identity settings. The sales inbox and VAT status matched previous fallback literals in the settings-presence check; validate these values with the owner. No values are reproduced here. Statutory fields require accountant review; personal-data handling requires HR/legal/data-protection review.

## Add a new email

1. Add or reuse a Mailable/Notification in its module and keep the existing trigger, recipient, and authorization logic.
2. Use `emails.*` Markdown view content with `<x-mail::message>` so it inherits the shared shell.
3. Pass record-derived `preheader` and display values from the source model/service. Escape user text through Blade; do not compute money or status labels in the view.
4. Include the primary action as an authenticated SPA link; never make an email GET link mutate state.
5. Add a plain-text/render test and recipient/data assertions. Use `Mail::fake()`; never contact SMTP from tests.
6. Inspect the message in a browser at desktop/mobile and light/dark widths after local delivery isolation. Record what client compatibility remains unverified.

## Verification status

Code changes and focused tests are on branch `email/shared-foundation`. The focused suite passed: 13 tests, 31 assertions. No email was sent or transport contacted. No email-client rendering was performed. Real-client rendering, actual provider delivery, domain authentication, and company-value approval remain unverified.
