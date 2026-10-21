# E-021 — Return request update

## Trigger and audience

`ReturnRequestService` emits `ReturnRequestUpdated` for approval submission, supplier return dispatch, approval/rejection, receipt, inspection, disposition, split, completion, and cancellation changes. The queued listener sends one notice to the customer or supplier master email captured when the event is created. Missing or invalid addresses trigger the existing internal fallback for users with `return_management.view`. The existing triggers and audience remain unchanged.

## Content and data

- Event-time RMA reference, status label, party contact/name, party email, and record hash are captured before the queued listener runs. The stable event ID and normalized recipient form the delivery key.
- The external body contains the reference, human-readable status, and action-specific summary. It omits free-text reason, resolution enum, refund amount, internal notes, and attachments.
- Customer link opens `/portal/customer/returns/{hash}`. Supplier link opens `/portal/supplier`; the SPA has no supplier return-request detail route. Links use `FRONTEND_URL` and require portal sign-in; neither link changes state.
- Shared Laravel Markdown shell supplies branding, footer, hidden preheader, HTML, and text alternative. Company footer values come from settings.
- Company settings used in screenshots contain test values. Owner must verify identity/contact values before external delivery. Supplier-facing content needs Purchasing review; any return/refund process description needs accountant review. Personal-data handling needs HR/legal/data-protection review where applicable.

## Verification

- `ReturnRequestUpdateEmailSnapshotTest` and `ReturnRequestUpdateMailDesignTest`: 3 tests passed, 58 assertions. Checks snapshot recipient/status, stable delivery key, removal of sensitive fields, customer/supplier links, action copy, hostile contact input, plain text, branding, and HTML size.
- Chromium inspected 8 actual-rendered-HTML previews at 1440px and 390px, light/dark. Both logo images loaded, CTA text stayed visible, and document width matched viewport; no horizontal overflow. Screenshots are in `screenshots/e021-rma-update-return-request-*.png`.
- Refreshed all 8 captures from the current rendered HTML and visually confirmed CTA labels in dark mode; earlier tracked captures showed stale blank labels.
- Mailer forced to `array`; no transport contacted. Browser rendering does not prove Outlook, Gmail, Apple Mail, or mobile client behavior.

## Related findings

- EF-036 event-time snapshot and idempotency were fixed separately in #251.
- EF-037 external data minimization and the portal link were fixed in #252.
- Manual review remains: supplier route specificity, company settings, Purchasing/accounting wording, and applicable personal-data handling.
