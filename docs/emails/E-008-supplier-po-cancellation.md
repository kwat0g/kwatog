# E-008 — Supplier purchase order cancellation

## Trigger and recipients

`PurchaseOrderCancelled` is written to the P2P outbox for cancellation or rejection. The supplier notification remains limited to POs that had `sent_to_supplier_at` set when the event was created. The supplier email captured from the vendor record receives the message; an invalid/missing address or delivery failure uses the existing `purchasing.view` fallback.

## Snapshot

The outbox event carries supplier address/name, PO hash/number, order date, cancellation date, system-formatted original total, sent-to-supplier flag, and supplier portal URL. The mail job receives scalar values, not a rehydrated PO. Internal cancellation remarks are excluded. Older pending outbox rows without a snapshot are decoded compatibly and capture current values when replayed.

## Verification

The focused feature suite checks existing sent/never-sent recipient behavior, exclusion of internal remarks, event-time values after PO/vendor edits, snapshot preservation through `OutboxEventCodec`, HTML escaping, frontend URL, title, preheader, and plain-text content. Five tests passed, 22 assertions; array mailer used, isolated DB removed. `SupplierPurchaseOrderCancelledMail::render()` produced 23,141 bytes with snapshot values; no email was sent. Browser previews were inspected at 1440px desktop and 390px mobile, light/dark; document width matched viewport. Hostile supplier text displays as text. Seeded branding values need owner confirmation. Screenshots: `screenshots/e008-supplier-po-cancellation-{desktop,mobile}-{light,dark}.png`. Real email-client rendering remains unverified.
- Re-inspected all four captures: hostile supplier name remains escaped text and no overflow appears; original total uses `PHP`, covered by EF-120 / PR #319.
