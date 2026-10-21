# Shared email foundation

## Stack decision

Keep Laravel's existing Markdown Mailable and `MailMessage` stack. The application already overrides Laravel's shared HTML mail layout, header, and footer; this work tightens that shared layer and does not add a template dependency or runtime compiler. Laravel's Markdown renderer produces the text alternative. Each email unit must continue using the shared layout and verify its rendered MIME parts.

## Shared shell

- Table-based, 600px email container; fluid width and reduced padding below 640px.
- System font stack; no remote CSS or JavaScript.
- `lang` from application locale and `dir="ltr"`.
- Light/dark color-scheme metadata and a dark-mode media rule; critical information remains text.
- Hidden preheader is forwarded by the shared Markdown message component from each email's view data into the HTML layout.
- Header uses the same bundled Ogami logo as the SPA landing page, inline via CID at 64px, with meaningful alt text and a text brand name. A small white backing keeps the black transparent logo visible in dark-mode clients; missing artwork falls back to the text brand name.
- Header brand link uses the configured SPA `FRONTEND_URL`, never Laravel's API `APP_URL`.
- Footer uses `company.legal_name` and `company.address` from settings. Missing required values fail rendering instead of displaying source-code fallback facts. Optional phone, email, certification, and disclaimer appear only when configured.
- Existing PDF/company settings remain the source for company details. Optional approved email disclaimer is stored at `email.footer_disclaimer`; it is seeded from `EMAIL_FOOTER_DISCLAIMER` and editable through the admin settings endpoint.
- Laravel Markdown buttons now render a 48px VML button for Outlook, an inline-styled HTML button, a descriptive HTML fallback link, and Laravel's plain-text button link. Existing panel/table components remain available; per-message key-facts/detail/timeline blocks belong to the email groups that need them.

## Content and subject rules

- Use `[OGAMI] {action or outcome}: {document type and number} · {verified key fact}`. Omit unavailable facts; never manufacture a number, amount, date, or status.
- Keep internal copy brief and action-led. Use a formal, complete signature for external recipients. State absolute dates in Asia/Manila; show relative timing only beside the date.
- Keep sensitive values out of subjects. Do not put payroll amounts, credentials, reset tokens, or private HR details in the body.
- Use the application's money/date helpers and human status labels. Templates display prepared values; they do not recalculate totals or derive business status.
- Laravel Markdown supplies the text alternative. Keep the text part useful, with the same action, document reference, and destination as the HTML part.

## Recipient, link, and attachment rules

- Preserve the trigger's existing recipient and permission logic. Deduplicate recipients; do not broaden a role audience when a target address is missing or invalid. Verify each recipient against the record's access rule before exposing record data or a deep link.
- Build record links from `FRONTEND_URL`, verify the SPA route, and preserve the intended destination through login. Email GET links must not mutate state. Reset, invitation, and sensitive-document links must use expiring tokens with the existing single-use behavior where supported.
- Reuse the shared PDF/export generator for attachments. Include only the selected record or export scope, and do not recompute totals in the email. Prefer authenticated links for payroll and other highly sensitive files.

## Accessibility and deliverability review

- Keep essential information in text; use descriptive image alt text and links; keep body text at 14px or larger and action targets at 44px or larger; preserve readable contrast in both themes.
- Check rendered HTML, plain text, links, mobile width, and dark mode in a browser. Then follow the [manual client checklist](01-inventory.md#manual-real-client-checklist-for-the-later-email-phase) in Gmail, Outlook, Apple Mail, and mobile clients. Browser captures do not prove client compatibility.
- Verify SPF, DKIM, and DMARC records with the domain administrator and sender provider. DKIM setup was reported successful for `ogamiph.dev` in AWS Singapore; SPF, DMARC, bounce handling, and provider suppression remain unverified.
- Payroll, statutory financial documents, HR notices, and personal-data handling need review by the company's accountant, HR, legal, or data-protection officer as applicable. This guide makes no compliance claim.

## Non-production delivery policy

`config/mail.php` defaults to the `log` transport. In every environment except exactly `production`, the `MessageSending` hook redirects To to `MAIL_TEST_TO`, removes Cc/Bcc and the original Reply-To, sets Reply-To to the sink, and prefixes the subject with the environment. If `MAIL_TEST_TO` is absent or invalid, it throws before transport. Production keeps configured recipients and SMTP transport. Local `.env` files were also changed to `MAIL_MAILER=log`, SMTP credentials cleared, and the active Horizon process restarted.

Set `MAIL_TEST_TO` only to a controlled test inbox or Mailpit capture address when a test inbox is needed. Never set it to a customer, supplier, employee, or applicant address. The guard is a last-mile safeguard; future queue workers must run the updated code and load current config.

## Settings to enter/verify

Existing settings rows:

- `company.legal_name` (required for mail)
- `company.address` (required for mail)
- `company.phone`, `company.email`, `company.sales_inbox_email`
- `company.tin`, `company.vat_status`, `company.certification`
- The exact light-mode landing-page image is bundled at `api/resources/images/ogami-logo.png` from `spa/public/brand-logo.png`; dark mode uses the transparent white variant at `api/resources/images/ogami-mark-dark.png`.
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

Code changes are in draft PR [#213](https://github.com/kwat0g/kwatog/pull/213) on branch `email/shared-foundation`. The exact landing-page PNG is used in light mode; a white variant preserves its transparency for dark mode. Both variants use CID images in mail and scheme-aware PNG icons in the SPA browser tab. The shared Markdown message component forwards preheaders and per-message titles into the HTML layout. Light-mode inline colors now allow dark-mode CSS overrides, mobile tables fit the viewport, and dark-mode links use a brighter color. Focused integration coverage previously passed: 6 tests, 22 assertions; title assertion now added. Browser-rendered header previews inspected desktop/mobile widths in light/dark modes: `screenshots/email-logo-header-*.png`. Full email previews will be regenerated as each type is completed. Browser screenshots do not verify real email clients. Outlook/Gmail/Apple Mail dark-mode behavior, actual provider delivery, domain authentication, and company-value approval remain unverified. No email was sent or transport contacted.
