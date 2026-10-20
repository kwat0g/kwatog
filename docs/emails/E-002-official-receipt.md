# E-002 — Customer official receipt issued

Status: template implemented in draft PR; accountant approval required before external production delivery.

## Message behavior

- Trigger and recipient stay unchanged: `OfficialReceiptIssued` → queued listener → customer email captured at issue time. Missing/invalid address uses existing accounting-permission fallback.
- The event snapshot supplies receipt number, date, amount, customer display name/email, related invoice number/hash ID, and receipt hash ID. Later record changes do not alter the recipient or rendered values.
- Subject includes configured brand, receipt number, and the configured currency display value.
- Body identifies issued status, receipt number/date/amount, related invoice, and explains this message summarizes the receipt record. It directs the customer to company contact details for a copy or payment question.
- CTA opens the authenticated related invoice page at the configured SPA base URL. It does not claim to open the official receipt.
- No attachment: discovery found no customer official-receipt PDF or portal route. This is tracked as EF-009; an accountant must confirm whether the current email summary is an acceptable customer communication or a separate receipt PDF/page is required.
- Shared Markdown framework supplies company settings, preheader, title, button, dark/mobile styling, and plain-text alternative.

## Verification

- `FeatureEmailIntegrationTest`: 9 tests passed, 52 assertions, including issue-time recipient and content snapshot, amount, subject, SPA link, and plain-text output.
- Temporary render fixture: 1 test passed, 2 assertions. No mail transport was contacted and no real email was sent.
- Browser preview inspected at 1440px desktop and 390px mobile, in light/dark schemes. Document width stayed within viewport. HTML size: 22,816 bytes. Screenshots: [desktop light](screenshots/official-receipt-desktop-light.png), [desktop dark](screenshots/official-receipt-desktop-dark.png), [mobile light](screenshots/official-receipt-mobile-light.png), [mobile dark](screenshots/official-receipt-mobile-dark.png).
- Browser render does not verify Gmail, Outlook, Apple Mail, Yahoo, or mobile email clients.
- Preview footer uses test database branding values; production identity remains subject to owner/accountant verification.

## Review still required

- Accountant review of the external official-receipt wording, required document format, and company identity fields; data-protection review of customer contact-data use.
- Confirm with the accountant whether the linked invoice page and message summary are sufficient for this use. EF-009 remains open pending that decision.
