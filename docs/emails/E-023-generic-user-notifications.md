# E-023 — Generic user notifications

## Trigger and audience

The same `UserNotificationMail` renders most email-enabled `NotificationService::send()` events. Each selected internal user must have an enabled email preference for that notification type; the default remains no email. `NotificationService` queues each selected recipient after the transaction commits. Caller code supplies the audience and business authorization rule.

`SalesOrderResponseService::proposeDeliveryDate()` also directly queues this mailable to active customer portal users on the order's customer account. That external path has no internal notification preference row. Its grouped recipient list and failure fallback are documented separately as EF-041.

## Content and data

- The mailable captures the caller-provided title, message, and link at dispatch time. The template does not reload the related record or expose notification type keys, internal IDs, or exception details.
- Subject uses the configured display name: `[Ogami Philippines] {notification title}`. Title and message are rendered as escaped plain text; HTML, active Markdown links, control characters, and script/style contents are neutralized.
- The shared Laravel Markdown shell supplies company branding, settings-backed identity/contact footer, preheader, dark-mode logo, responsive layout, and a plain-text alternative.
- A CTA is shown only for an absolute path or an absolute URL on the configured frontend origin. Absolute links are rebuilt from `FRONTEND_URL`; external, scheme-relative, malformed, and backslash links are omitted. The button's GET target only opens the SPA page; any available action requires a deliberate interaction there.
- No attachments. Delivery remains one message per opted-in internal recipient and per direct customer recipient list; no digest or rate aggregation is added here.

## Verification

- `UserNotificationMailDesignTest` and `NotificationServiceHardeningTest`: 20 tests, 65 assertions passed. Covers shared branding, subject, plain text, SPA links, same-origin absolute URL normalization, hostile title/body/name input, rejected external URL, non-string fallback, queue preference/after-commit behavior, and bounded notification envelope.
- Four Chromium renders inspected at 1440px desktop and 390px mobile, in light and dark modes. Logos and CTA loaded; no horizontal overflow. Screenshots are in `screenshots/e023-user-notification-*.png`.
- The project resolves test email to the array mailer; no email transport was contacted.
- Static route presence confirmed for `/purchasing/purchase-orders/{id}` and `/portal/customer/orders/{id}`. E-023 has many caller-specific link routes; exhaustive route-to-caller verification remains in the coverage audit.
- Real Gmail, Outlook, Apple Mail, and mobile email clients remain unverified.

## Findings and review

- EF-041: direct customer delivery-date proposal groups portal-user addresses in one visible To list. Separate recipient-privacy fix required.
- EF-042: the generic internal email boundary trusts caller activity and address checks. Separate recipient-resolution fix required.
- EF-043: the direct customer path has no app-user ID for its existing email failure callback, so provider failure does not notify an authorized Sales user. Separate reliability fix required.
- Callers can include confidential or highly sensitive context in generic message text. Caller-level record visibility and HR/accounting/data-protection review remain required; this template does not infer permissions from a notification type.
- Company settings used by the test seeder are fixtures, not approval of production company contact values.
