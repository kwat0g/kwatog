# E-018 — Approved purchase order published

## Trigger and recipients

When an approved PO is published to the supplier portal, the valid vendor-record email receives one queued copy. The dispatch service publishes the portal record, records dispatch state, and transitions the PO to supplier-visible `Sent` in one transaction; after-commit queue dispatch waits until that transaction commits (EF-029, PR #241). There are no CC/BCC recipients.

Invalid/missing vendor email and queued-delivery failure alerts are restricted to active `purchasing.view` users who can view this PO under `PurchaseOrderAccessPolicy` (EF-030, PR #242). Active supplier portal users also receive the existing in-app publication notice.

## Content and snapshot

The mailable freezes vendor contact, PO number/hash, order and expected-delivery dates, total, payment terms, and line descriptions/quantities/prices/totals when constructed. The email shows up to 10 lines and links to the exact supplier portal PO page for the rest. Totals are read from PO/item records; the template does not recompute totals. There is no attachment. The secure portal has the PO record and PDF route.

The button opens an authenticated portal page; it does not change state. The template includes the shared button's fallback URL and plain-text part. The subject includes brand, PO number, and system-formatted total.

## Verification

`SupplierPurchaseOrderMailTest` plus existing `FeatureEmailIntegrationTest` publication case: 2 passed / 27 assertions. Tests check event-time values after source mutations, line-item limit, system formatting, portal URL, settings-backed identity, safe output, HTML size, and queue integration. Mailer forced to `array`; no real email sent.

After commit `a18dde45`, rendered HTML is 34,357 bytes using a network-isolated PHP container, synthetic PO and branding fixtures, and `MAIL_MAILER=array`; no email was sent. Chromium captures at 1440px/390px in light/dark show the stacked item layout, readable CTA, and document width equal to the viewport. The captures are [desktop light](screenshots/e018-supplier-po-published-desktop-light.png), [desktop dark](screenshots/e018-supplier-po-published-desktop-dark.png), [mobile light](screenshots/e018-supplier-po-published-mobile-light.png), and [mobile dark](screenshots/e018-supplier-po-published-mobile-dark.png). Fixture branding is not company-approved data. Browser rendering does not verify Gmail, Outlook, Apple Mail, Yahoo, provider delivery, or inbox placement.

Purchasing and the accountant must review PO terms and supplier-facing wording. Supplier contact handling requires data-protection review. No legal or compliance claim is made.
