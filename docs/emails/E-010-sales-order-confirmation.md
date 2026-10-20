# E-010 — Customer sales order confirmation

## Snapshot behavior

`SalesOrderConfirmed` now captures customer recipient/name, sales-order facts, product labels, quantities, unit prices, line totals, and the customer-portal URL when the confirmation event is created. The queued listener uses that snapshot and still suppresses delivery if the live order has since been cancelled. Old outbox rows without the optional snapshot and already queued mailables retain a current-value compatibility fallback.

## Template and verification

The redesigned message uses the shared brand shell, title/preheader, settings-based currency display, event-time order snapshot, up to ten line items, and the customer SPA order route. Plain-text content comes from the same Markdown view. Long product and contact strings are sanitized before Markdown rendering.

Focused confirmation tests: 2 passed, 20 assertions. Outbox fact-event compatibility tests: 7 passed, 15 assertions. Both used the `array` mailer. Rendered HTML was 27 KB and browser-inspected at 1440px desktop and 390px mobile in light/dark modes; screenshots are under `screenshots/e010-sales-order-*.png`. No email sent. Real email clients remain unverified.

## Review notes

The message contains external-facing financial data. Accountant review remains required. Customer contact-data handling should be reviewed by the company's data protection contact. Real-client rendering remains unverified.
