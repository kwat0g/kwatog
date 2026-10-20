# E-008 — Supplier purchase order cancellation

## Trigger and recipients

`PurchaseOrderCancelled` is written to the P2P outbox for cancellation or rejection. The supplier notification remains limited to POs that had `sent_to_supplier_at` set when the event was created. The supplier email captured from the vendor record receives the message; an invalid/missing address or delivery failure uses the existing `purchasing.view` fallback.

## Snapshot

The outbox event carries supplier address/name, PO hash/number, order date, cancellation date, system-formatted original total, sent-to-supplier flag, and supplier portal URL. The mail job receives scalar values, not a rehydrated PO. Internal cancellation remarks are excluded. Older pending outbox rows without a snapshot are decoded compatibly and capture current values when replayed.

## Verification

The focused feature suite checks existing sent/never-sent recipient behavior, exclusion of internal remarks, event-time values after PO/vendor edits, and snapshot preservation through `OutboxEventCodec`. Five tests passed, 14 assertions; array mailer used, isolated DB removed. The template redesign will add desktop/mobile and light/dark browser previews.
