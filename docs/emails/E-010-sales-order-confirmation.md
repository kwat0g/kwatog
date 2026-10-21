# E-010 — Customer sales order confirmation

## Snapshot behavior

`SalesOrderConfirmed` now captures customer recipient/name, subtotal, VAT, total, product labels, quantities, unit prices, line totals, and the customer-portal URL when the confirmation event is created. The email shows subtotal, VAT, and total from that snapshot so line-item math is clear. The queued listener uses the snapshot and still suppresses delivery if the live order has since been cancelled. Old outbox rows without the optional snapshot and already queued mailables retain a current-value compatibility fallback; breakdown rows are omitted when old snapshots lack those fields.

## Template and verification

The redesigned message uses the shared brand shell, title/preheader, settings-based currency display, event-time order snapshot, up to ten line items, and the customer SPA order route. Plain-text content comes from the same Markdown view. Long product and contact strings are sanitized before Markdown rendering.

Focused confirmation tests were updated to verify the subtotal/VAT breakdown and distinct SPA/API hosts. Current result pending CI. Outbox fact-event compatibility tests previously passed: 7 tests, 15 assertions. Rendered HTML is 29,277 bytes. Inspected four browser screenshots at 1440px desktop and 390px mobile in light/dark modes: subtotal PHP 120.00 plus VAT PHP 14.40 equals order total PHP 134.40; the mobile item description wraps without horizontal overflow. No email sent. Real email clients remain unverified. Footer screenshot uses seeded branding data; owner must verify company details before external delivery.

## Review notes

The message contains external-facing financial data. Accountant review remains required. Customer contact-data handling should be reviewed by the company's data protection contact. Real-client rendering remains unverified.
