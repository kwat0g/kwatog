# E-001 — Customer invoice finalized

Status: implemented in draft PR; accountant review required before external production delivery.

## Message behavior

- Trigger: `InvoiceFinalized`, delivered by the queued `EmailCustomerOnInvoiceFinalized` listener.
- Recipient: customer email captured in the event snapshot when the invoice is finalized. Missing or invalid address uses the existing accounting-permission fallback. Customer email changes after the event do not redirect the queued message.
- Snapshot: customer display name, invoice number/date/due date/total/balance, sales-order number, line items, and invoice hash ID are captured before queueing. Later record edits do not change message content or portal target.
- Subject: configured email brand, invoice number, and the system currency display service's invoice total.
- Content: issue status, dates, total and balance at issue time, up to ten line items, omitted-item count, and a note that later payments may not be reflected.
- Link: `FRONTEND_URL/portal/customer/invoices/{hash_id}`. The route exists in the SPA; customer portal authentication protects the invoice and PDF. Message has no attachment.
- Framework: shared Laravel Markdown layout, company settings footer, CID logo or readable text fallback, hidden preheader, VML/HTML button, plain-text alternative, and heading-derived HTML title.
- Safety: subject control characters removed; user-controlled Markdown fields have tags removed and Markdown metacharacters escaped. Blade escapes output. The link is authenticated navigation, not a state-changing GET.

## Verification

- `FeatureEmailIntegrationTest`: 8 tests passed; recipient, issue-time snapshot, amounts, portal path, hostile text, line-item limit, subject, HTML title, and plain-text assertions included.
- A temporary render-only fixture produced captured HTML; no message transport was contacted. Browser preview was inspected at desktop/mobile widths in light/dark schemes. Four screenshots are in [screenshots](screenshots/).
- Browser widths measured 1440px desktop and 390px mobile; document width stayed within the viewport. HTML was 24,955 bytes, under Gmail's 102KB clipping threshold. Browser inspection does not prove behavior in Gmail, Outlook, Apple Mail, Yahoo, or mobile mail apps.
- Preview footer used test database branding values. Replace/verify company settings before any production email. No real email was sent.

## Review still required

- Accountant review of external financial-document content and company identity fields; data-protection review of customer contact-data use.
- Confirm invoice snapshots are the intended issue-time meaning for all fields, especially line items and balance.
- Real client rendering remains unverified; use the manual cross-client checklist requested in the email mission before production release.
