# E-019 — Supplier RFQ lifecycle notices

## Scope and triggers

This email covers the supplier-facing `published`, `extended`, `awarded`, and `cancelled` RFQ lifecycle events. The outbox listener sends one email to each invited supplier contact with a usable address. The internal `closed` event continues through the existing internal notification path; this work does not add or remove triggers.

Each email is queued after the lifecycle event commits. The outbox captures RFQ display values and the per-invitation recipient/status snapshot. A supplier receives only their own invitation data. Existing outbox messages without a snapshot retain their fallback path. Delivery retries use the deterministic key added in EF-032.

## Content and data

- RFQ number, title, deadline, supplier contact name, and invitation status come from the event-time snapshot captured by `RfqLifecycleEvent`.
- Lifecycle wording is selected by the event kind and invitation result. Award notices do not include competitor pricing, rankings, or other suppliers' results.
- Extension and cancellation free-text reasons are intentionally omitted from the external message.
- Deadline snapshots are converted explicitly to `Asia/Manila`, independent of the API process timezone, and labeled “Philippine time.”
- The message links to `/portal/supplier/rfqs/{hash_id}` on the configured frontend origin. Supplier portal authentication redirects to sign-in and preserves the intended route.
- No attachment is included. No decision or mutation occurs from the email link; it opens the authenticated portal record.
- Plain text is produced by Laravel's Markdown mail renderer. Company name, logo, address, contact, and disclaimer use the shared branding settings and layout.

## Review and verification

- `SupplierRfqLifecycleMailTest`: 1 test passed, 73 assertions. Covers all five message variants, snapshot values, Manila date display, frontend link, footer settings, hostile markup, header newline neutralization, unresolved placeholders, and HTML size.
- Chromium rendered 20 screenshots: five variants at 1440px and 390px, each in light and dark mode. All images loaded; measured document width matched viewport and no element overflowed horizontally. Screenshots are in `screenshots/`.
- Rendered HTML used the array mailer and the supplied test branding values. No email transport was contacted.
- Browser screenshots do not prove behavior in Outlook, Gmail, Apple Mail, or other real clients. Verify with the manual client checklist before external rollout.

## Open reviews

- Verify the real company legal name, address, phone, mailbox, disclaimer, sender, and frontend URL in settings before external use; automated fixtures contain test contact values.
- Purchasing should verify supplier-facing wording and status semantics. Data-protection/legal review should confirm external recipient and content policy. No legal or compliance claim is made here.
- The configured production timezone, sender deliverability, and provider bounce handling remain infrastructure checks.
