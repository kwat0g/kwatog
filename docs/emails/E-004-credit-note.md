# E-004 — Customer or supplier credit note finalized

Status: ready for draft PR; accountant review required before external production delivery.

## Message behavior

- Trigger unchanged: finalized `CreditNote` → queued listener. Recipient is customer email for customer credit notes and vendor email for supplier credit notes; each is captured in the finalized-event snapshot.
- Snapshot includes party name, note number/type/date, subtotal/VAT/total, applied amount, remaining balance, reason, related invoice/bill number, and note hash. Later edits do not change delivery recipient or message data.
- Subject identifies customer vs supplier credit and includes the note number and configured currency total.
- Message distinguishes customer receivable credits from supplier payable credits. It shows financial values at finalization, and says later applications/voids may not appear.
- Unclassified free-text `reason` is omitted from external email pending explicit company policy (EF-015).
- CTA opens the authenticated customer/supplier portal root; it does not claim to open the credit note. No external note page/PDF was found, so the message tells the party how to request a copy. EF-013 remains open for accountant/product decision.
- No attachment is added because no source-backed credit-note PDF exists. Shared Markdown layout provides branding, preheader, heading-derived title, plain-text alternative, and responsive/dark styling.

## Verification

- `FeatureEmailIntegrationTest`: 11 tests passed, 70 assertions, including customer/vendor recipient routing, snapshot mutation, amount/type/title/subject, and omitted reason text.
- CI static analysis found the enqueue-failure log referenced an undefined model variable. It now logs the event snapshot hash; full CI rerun pending.
- Temporary customer/supplier render fixture: 1 test passed, 2 assertions. No mail transport contacted and no real email sent.
- Re-rendered both existing HTML fixtures with the current landing-page logo's light/dark PNG variants; no mail transport was contacted. Both audience variants inspected at 1440px desktop and 390px mobile, in light/dark schemes. Document width matched viewport in both; long currency values wrap across lines on mobile without clipping. Customer HTML: 24,835 bytes; supplier HTML: 24,825 bytes.
- Screenshots: [customer desktop light](screenshots/credit-note-customer-desktop-light.png), [customer desktop dark](screenshots/credit-note-customer-desktop-dark.png), [customer mobile light](screenshots/credit-note-customer-mobile-light.png), [customer mobile dark](screenshots/credit-note-customer-mobile-dark.png), [supplier desktop light](screenshots/credit-note-supplier-desktop-light.png), [supplier desktop dark](screenshots/credit-note-supplier-desktop-dark.png), [supplier mobile light](screenshots/credit-note-supplier-mobile-light.png), [supplier mobile dark](screenshots/credit-note-supplier-mobile-dark.png).
- Browser previews do not prove Gmail, Outlook, Apple Mail, Yahoo, or mobile mail-client rendering. Footer preview uses test database branding values.

## Review still required

- Accountant review of external credit-note content, required document delivery, and company identity fields; data-protection review of party contact-data use.
- EF-013 remains unresolved until the accountant confirms whether an external credit-note page or PDF is required.
