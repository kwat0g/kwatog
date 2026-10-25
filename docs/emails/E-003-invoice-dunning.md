# E-003 — Customer invoice dunning

Status: template implemented in draft PR; accountant review required before external production delivery.

## Message behavior

- Trigger and cadence unchanged: scheduled AR scan claims the next configured dunning tier and queues one reminder per overdue invoice.
- Recipient and display values are captured when that tier is claimed. Snapshot covers customer email/name, invoice number/date/due date/balance, and invoice hash. A later payment may change the current portal status; the email labels balance as the value when the reminder was generated.
- The message reports actual overdue days and avoids fixed tier labels. Tier thresholds remain controlled by `accounting.ar_dunning.tier_days_csv` in the service.
- Subject includes the configured brand, invoice number, overdue days, and currency-formatted balance.
- CTA opens the authenticated customer invoice page at the configured SPA URL. No attachment is included.
- The message asks the customer to check portal status before paying again if they recently paid; it uses the company settings footer and avoids asking the customer to reply to an automated sender.
- Existing per-invoice delivery remains. EF-012 records the unbounded number of invoice reminders a customer can receive in a single scan; aggregation needs product approval.

## Verification

- `RunArDunningCommandTest`: 9 tests passed, 29 assertions; includes configured tier behavior, fixed-tier wording removal, recipient/data snapshot, and portal link.
- Temporary render fixture: 1 test passed, 2 assertions. No transport contacted and no real email sent.
- Re-rendered the existing HTML fixture with the current landing-page logo's light/dark PNG variants; no mail transport was contacted. Browser preview inspected at 1440px desktop and 390px mobile in light/dark schemes. Document width matched viewport in both. Rendered HTML: 23,759 bytes. Screenshots: [desktop light](screenshots/invoice-dunning-desktop-light.png), [desktop dark](screenshots/invoice-dunning-desktop-dark.png), [mobile light](screenshots/invoice-dunning-mobile-light.png), [mobile dark](screenshots/invoice-dunning-mobile-dark.png).
- Re-inspected all four captures: no overflow, readable layout, and logo visible. Balance is shown as `PHP 125,000.00`; EF-120 / PR #319 corrects shared PHP currency formatting.
- Real Gmail, Outlook, Apple Mail, Yahoo, and mobile mail-client rendering remains unverified.
- Preview footer uses test database branding values; verify company identity settings before production delivery.

## Review still required

- Accountant review of external financial reminder wording and company identity fields; data-protection review of customer contact-data use.
- Product decision on EF-012 invoice aggregation/rate limits. Current per-invoice trigger remains unchanged.
