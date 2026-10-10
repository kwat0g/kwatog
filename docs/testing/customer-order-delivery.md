# Customer order ownership and delivery dates

Customer-submitted draft orders can be edited or cancelled by their owning customer. Internal sales can review and confirm them, but cannot edit, cancel, delete, or apply commercial counteroffers to them.

Sales can propose a replacement delivery date with a reason. Confirmation is blocked while the proposal is pending. The customer accepts or declines in the portal. Acceptance changes only line delivery dates and preserves each original requested date. Production planning continues to use the line delivery dates. Editing the draft or cancelling it supersedes pending proposals. Accepted dates do not automatically confirm orders.

Place Order starts with an empty requested date. Edit SO loads the original requested date. Customers must request a date more than three calendar days after today in Asia/Manila; October 4 permits October 8 onward. An original date earlier than this minimum remains visible when editing, but the customer must select a valid date before saving. New-order retries return the original result even after its date falls inside the lead-time window.

Customer order quantities must be positive whole numbers. Numeric serializations such as `100.00` are accepted; fractional quantities such as `1.5` are rejected. Internal material quantity behavior is unchanged.

The additive migration `2026_10_04_000001_add_sales_order_delivery_negotiation.php` adds a requested-date snapshot and proposal author. Existing orders use their current delivery dates until a negotiated date is accepted. Keep these additive columns when rolling application code back after acceptance to retain original-date history.

Run browser verification only against an isolated audit environment:

```bash
node spa/e2e-real/customer-order-delivery.cjs
```

The script defaults to `http://localhost:18080`. It signs in with separate customer and sales sessions, creates a labelled audit order, checks draft editing and sales API restrictions, exercises date proposal and customer acceptance, then cancels the fixture. Screenshots are saved under `.impeccable/review/customer-order-ownership`.

The production database was queried read-only: 20 products exist, and 4 have active customer price agreements for Toyota Motor Philippines, Inc. The live SO-202610-0027 was checked without changing its record.
