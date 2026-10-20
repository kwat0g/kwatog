# E-011 — Customer sales order decision

## Trigger and recipients

Sales resolves a customer response as accepted or returned for revision. Eligible recipients are active customer portal users attached to the response's customer, each receiving a separate message. Invalid or case-duplicate addresses are excluded. If no valid portal address remains, use the customer record's valid email; if that is also unusable, notify active users with `crm.sales_orders.confirm`.

## Snapshot and privacy

The queued mailable freezes customer display name, sales-order number/hash/total, response type, proposed delivery date, decision, and frontend portal URL when constructed inside the resolution transaction. Existing queued mailables without a snapshot retain a current-value fallback. Resolution notes and customer free-text response notes are not included.

## Verification status

`SalesOrderResponseTest`: 18 passed, 70 assertions. Covers event-time content after mutations, separate delivery to a single address, case-insensitive deduplication, inactive/invalid recipient filtering, customer-address fallback, and sales notification when no valid address exists. Mailer forced to `array`; no real email sent.

The existing template remains pending redesign. External decision wording and customer data handling need review by the company's accountant and data protection contact. Real-client rendering remains unverified.
