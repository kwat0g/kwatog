# E-018 — Approved purchase order published

## Trigger and recipients

When an approved PO is published to the supplier portal, the valid vendor-record email receives one queued copy. The dispatch service publishes the portal record, records dispatch state, and transitions the PO to supplier-visible `Sent` in one transaction; after-commit queue dispatch waits until that transaction commits (EF-029, PR #241). There are no CC/BCC recipients.

Invalid/missing vendor email and queued-delivery failure alerts are restricted to active `purchasing.view` users who can view this PO under `PurchaseOrderAccessPolicy` (EF-030, PR #242). Active supplier portal users also receive the existing in-app publication notice.

## Content and snapshot

The mailable freezes vendor contact, PO number/hash, order and expected-delivery dates, total, payment terms, and line descriptions/quantities/prices/totals when constructed. The email shows up to 10 lines and links to the exact supplier portal PO page for the rest. Totals are read from PO/item records; the template does not recompute totals. There is no attachment. The secure portal has the PO record and PDF route.

The button opens an authenticated portal page; it does not change state. The template includes the shared button's fallback URL and plain-text part. The subject includes brand, PO number, and system-formatted total.

## Verification

`SupplierPurchaseOrderMailTest` plus existing `FeatureEmailIntegrationTest` publication case: 2 passed / 27 assertions. Tests check event-time values after source mutations, line-item limit, system formatting, portal URL, settings-backed identity, safe output, HTML size, and queue integration. Mailer forced to `array`; no real email sent.

Rendered HTML is 33,141 bytes. Chromium screenshots were inspected at 1440px and 390px, light and dark; no horizontal overflow or missing logo. This does not verify Gmail, Outlook, Apple Mail, Yahoo, delivery, or inbox placement. See [screenshots](screenshots/).

Purchasing and the accountant must review PO terms and supplier-facing wording. Supplier contact handling requires data-protection review. No legal or compliance claim is made.
