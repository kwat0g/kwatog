# E-007 — Supplier invoice received

## Trigger and audience

`SupplierInvoiceSubmitted` is dispatched after the submission transaction completes. Its queued listener emails the supplier address captured from the vendor record at dispatch. Invalid or absent addresses send an internal fallback to users resolved with `accounting.vendors.view`. Delivery failures use the same permission-based fallback. The queued listener and mailable remain on the dedicated mail queue and after-commit path.

## Message contract

- Confirms invoice submission was received for Accounts Payable review; does not imply payment approval.
- Snapshot values: supplier address and display name, invoice number/date, linked-bill count, submitted total, PO number, and supplier portal route.
- Subject: `[brand] Supplier invoice received: {invoice number}`.
- No attachment. Link opens the authenticated supplier portal invoice detail; it does not mutate state.
- Brand identity and footer come from shared company settings. Currency display uses the system `CurrencyDisplayService`; if functional currency is unset, existing formatter omits a code.
- Invoice data is escaped by Blade. The shared Markdown renderer supplies HTML and plain-text alternatives.

## Verification

Snapshot behavior is tested by changing vendor/invoice/bill records after event creation and checking the rendered message retains event-time values. Hostile supplier name is checked for HTML escaping. `SupplierInvoiceStatusMail::render()` produced 24,492 bytes with the snapshot values and formatter; no email was sent. Browser previews at 1440px desktop and 390px mobile fit their viewports in light/dark themes. Branding shown comes from local seeded settings and needs owner confirmation. Screenshots: `screenshots/e007-supplier-invoice-{desktop,mobile}-{light,dark}.png`. Browser screenshots do not verify Outlook/Gmail/Apple Mail rendering.

External financial wording and invoice handling need review by the company's accountant. Company address, legal name, and currency settings need owner/accountant confirmation before external delivery.
