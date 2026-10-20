# E-010 — Customer sales order confirmation

## Snapshot behavior

`SalesOrderConfirmed` now captures customer recipient/name, sales-order facts, product labels, quantities, unit prices, line totals, and the customer-portal URL when the confirmation event is created. The queued listener uses that snapshot and still suppresses delivery if the live order has since been cancelled. Old outbox rows without the optional snapshot and already queued mailables retain a current-value compatibility fallback.

## Verification

Focused confirmation tests: 2 passed, 4 assertions. Outbox fact-event compatibility tests: 7 passed, 15 assertions. Both used the `array` mailer. No email sent. The existing template remains pending redesign; its float-based money presentation is recorded in EF-023 and must be replaced during template work.

## Review notes

The message contains external-facing financial data. Accountant review remains required. Customer contact-data handling should be reviewed by the company's data protection contact. Real-client rendering remains unverified.
