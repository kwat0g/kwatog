# E-011 — Customer sales order decision

## Trigger and recipients

Sales resolves a customer response as accepted or returned for revision. Eligible recipients are active customer portal users attached to the response's customer, each receiving a separate message. Invalid or case-duplicate addresses are excluded. If no valid portal address remains, use the customer record's valid email; if that is also unusable, notify active users with `crm.sales_orders.confirm`.

## Snapshot and privacy

The queued mailable freezes customer display name, sales-order number/hash/total, response type, proposed delivery date, decision, and frontend portal URL when constructed inside the resolution transaction. Existing queued mailables without a snapshot retain a current-value fallback. Resolution notes and customer free-text response notes are not included.

## Template and verification

The redesigned email uses the shared branding and title/preheader, one authenticated customer-order CTA, the decision outcome, response type, order total at decision time, and proposed delivery date when present. It excludes resolution notes from the email; the customer can review sales feedback in the portal.

`SalesOrderResponseTest`: 18 passed, 84 assertions. Covers event-time content after mutations, separate delivery to a single address, case-insensitive deduplication, inactive/invalid recipient filtering, customer-address fallback, and sales notification when no valid address exists. Mailer forced to `array`; no real email sent. Rendered HTML is about 24 KB; browser-inspected at 1440px desktop and 390px mobile in light/dark. Screenshots: `screenshots/e011-sales-order-decision-{desktop,mobile}-{light,dark}.png`.

External decision wording and customer data handling need review by the company's accountant and data protection contact. Real-client rendering remains unverified.
