# Portal notifications and delivery-date negotiation

Customer orders start in `awaiting_confirmation`. A Sales delivery proposal changes the order to `delivery_negotiation`, without modifying the scheduled date. Only the customer can accept the proposal. Acceptance applies the proposed date to every line, preserves the original requested date, and returns the order to `awaiting_confirmation`. Rejection leaves the order in `delivery_negotiation` and preserves the existing schedule. Sales can propose another date without a fixed limit, or confirm the existing schedule if it can be fulfilled and no proposal is pending.

Confirmation is blocked while a proposal awaits a customer decision. Both unconfirmed states stay out of MRP demand. Customers retain edit and cancellation rights before confirmation. Editing supersedes pending proposals and returns the order to `awaiting_confirmation`. Superseded proposals cannot be accepted. A confirmation dialog binds the customer decision to the proposal shown when the dialog opened.

Both order detail pages expose delivery negotiation history, newest proposal first. Every round retains its date, reason, decision, and timestamps. Customer and supplier portals have a notification bell and inbox, refreshed every 30 seconds. Recipient identity includes the portal model class and user ID. Inbox reads and mutations cannot reach another user's notifications, including users with matching numeric IDs in another realm. Portal inbox links stay inside the correct portal.

Notifications cover order submission, review requests, delivery proposals and decisions, confirmation and cancellation, customer pricing offers and outcomes, supplier purchase-order publication and response outcomes, delivery-schedule decisions, outbound delivery progress, and finalized customer invoices. Price changes requiring approval are described as pending approval, not as accepted terms. Notices are inserted inside the business transaction where available and use deduplication keys for retried events. Existing email delivery remains in place; portals do not subscribe to internal-user broadcast channels.

Migration `2026_10_05_000001_add_sales_order_delivery_negotiation_statuses.php` widens PostgreSQL's status column, expands its lifecycle constraint, and classifies existing customer-portal drafts. Rollback maps the two new states to Draft and preserves all proposal history before restoring the previous constraint and column width.

Focused browser reproduction runs only against the isolated audit application:

```sh
node scripts/portal-negotiation-headless.cjs
```

It signs in with Sales, Customer Portal, and Supplier Portal accounts, places a 100-piece audit order, rejects three dates, accepts the fourth, verifies confirmation dialogs and original requested date preservation, and checks portal inbox navigation and isolation. It creates audit fixtures; never point it at a production host.

Validation completed: 150 cross-module backend tests (634 assertions), seven schedule/status checks (23 assertions), and 12 confirmation/concurrency checks (73 assertions). Product, order-response, notification, and metadata frontend checks passed 20 tests. Headless browser checks passed with three authenticated roles, three rejections and fourth-round acceptance, mobile overflow checks, supplier/customer isolation, and mark-read verification. Transactional rollback/reapply preserved 14 audit orders and eight proposal rows. Product desktop/mobile screenshots and financial-form interactions were also verified.
