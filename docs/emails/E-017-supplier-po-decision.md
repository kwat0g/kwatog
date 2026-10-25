# E-017 — Supplier purchase-order decision

## Trigger and recipients

Purchasing accepts or rejects a supplier response. Price-increase proposals wait for the PO re-approval outcome before this email is queued. The current resolver selects active supplier portal users; if none exist, it falls back to the vendor address. It does not include CC or BCC.

EF-028 tracks recipient-address exposure: active portal users currently share one To header. Keep this fix separate from the template PR; send to the same eligible users individually after that review.

## Snapshot and content

The queued mailable captures the supplier contact display name, PO number/hash, total, response label, proposed delivery date, decision, and frontend portal URL when constructed in the decision transaction. Later edits do not change queued content. Resolution notes and supplier free-text notes are excluded.

Accepted/rejected copy distinguishes accepted proposed terms, a response returned for revision, and other response types. The total is described as the PO total when the decision was recorded. The proposed delivery date is labeled as proposed. There is no attachment or state-changing link.

## Verification

`SupplierPoDecisionMailTest`: 1 passed / 18 assertions. It mutates the PO, supplier contact, and response date after constructing the mailable and confirms the content stays at its captured values. The test also checks human response labels, exact SPA URL, company identity, plain-text link, and absence of unresolved values/script content. Mailer was forced to `array`; no email was sent.

Rendered HTML is 25,301 bytes. Chromium screenshots were inspected at 1440px and 390px, light and dark; no horizontal overflow or missing logo. This does not verify Gmail, Outlook, Apple Mail, or Yahoo rendering, mail delivery, or inbox placement. See [screenshots](screenshots/).

Purchasing and the accountant must review the external wording and PO amounts. The data-protection contact must review supplier contact handling. No legal or compliance claim is made.

Follow-up preview review reopened desktop-dark and mobile-light: the CTA is visible and mobile details wrap without clipping. Currency still displays `PHP` pending EF-120. The preview footer contains test-domain contact values and certification copy; these must be verified in company settings before external delivery.
