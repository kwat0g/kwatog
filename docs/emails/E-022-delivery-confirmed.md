# E-022 — Customer delivery confirmation

## Trigger and audience

`DeliveryService::confirm()` records a customer delivery as confirmed and stores `DeliveryConfirmed` in the durable outbox after the business transaction. The existing queued listener sends to the event-time customer master email. Missing or invalid addresses retain the existing internal `supply_chain.view` fallback. Trigger and recipient behavior are unchanged.

## Content and data

- The event snapshot supplies delivery reference, sales order, actual delivery timestamp, issued invoice reference, customer contact, customer email, and customer portal hash. The mailable uses that snapshot after queue delay.
- The message shows reference, sales order, confirmed status, delivery time converted to Asia/Manila, and invoice reference only when an invoice number exists. It does not claim an invoice is pending when no number is present.
- Receiver name, position, and free-text delivery remarks are omitted from external mail. No PDF or proof-of-delivery attachment is sent.
- CTA opens exact `/portal/customer/deliveries/{hash}` SPA route using `FRONTEND_URL`. Portal sign-in protects access; GET does not change delivery state.
- Shared Laravel Markdown shell supplies the landing-page company logo, settings-backed footer, preheader, responsive/dark-mode HTML, and generated text alternative.

## Verification

- `CustomerDeliveryConfirmedMailDesignTest` plus delivery integration cases: 4 tests, 25 assertions passed. Checks subject, company/footer/logo, portal route, Manila timestamp, optional invoice, hostile contact input, omitted receiver/remarks, plain text, unresolved values, HTML size, and outbox-replay snapshot accuracy.
- Chromium inspected 4 screenshots at 1440px/390px in light/dark; both logo variants and CTA label render, with no horizontal overflow. Real-client rendering remains unverified.
- Mailer forced to `array`; no email transport contacted.

## Review notes

- Local company settings in test fixtures need owner verification. Customer-facing financial references need accountant review; personal-data handling needs the company's data protection contact.
- Full `FeatureEmailIntegrationTest` has 3 unrelated failures reproduced on parent #252; see EF-039 in [FINDINGS.md](FINDINGS.md).
