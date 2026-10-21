# E-023 — Generic user notifications

## Trigger and audience

The same `UserNotificationMail` renders most email-enabled `NotificationService::send()` events. Each selected internal user must have an enabled email preference for that notification type; the default remains no email. `NotificationService` queues each selected recipient after the transaction commits. Caller code supplies the audience and business authorization rule.

`SalesOrderResponseService::proposeDeliveryDate()` also directly queues this mailable to valid, active customer portal users on the order's customer account. That external path has no internal notification preference row. Each normalized address receives a separate message; no customer's address appears in another recipient's To header. Dispatch and provider failures notify active Sales users with `crm.sales_orders.confirm` access, including current permission overrides.

## Content and data

- The mailable captures the caller-provided title, message, and link at dispatch time. The template does not reload the related record or expose notification type keys, internal IDs, or exception details.
- Subject uses the configured display name: `[Ogami Philippines] {notification title}`. Title and message are rendered as escaped plain text; HTML, active Markdown links, control characters, and script/style contents are neutralized.
- The shared Laravel Markdown shell supplies company branding, settings-backed identity/contact footer, preheader, dark-mode logo, responsive layout, and a plain-text alternative.
- A CTA is shown only for an absolute path or an absolute URL on the configured frontend origin. Absolute links are rebuilt from `FRONTEND_URL`; external, scheme-relative, malformed, and backslash links are omitted. The button's GET target only opens the SPA page; any available action requires a deliberate interaction there.
- No attachments. Internal delivery remains one message per valid opted-in mailbox; direct customer delivery remains one message per valid active portal mailbox. Stable keys are used when the caller provides a dedupe key; no digest or rate aggregation is added here.

## Verification

- PR #255 template tests: `UserNotificationMailDesignTest` plus `NotificationServiceHardeningTest`, 20 tests and 65 assertions passed. PR #256 recipient regressions: 18 unit tests/46 assertions, 2 direct proposal tests/11 assertions, and 5 mailable tests/32 assertions passed. Covers active/valid recipient filtering, mailbox deduplication, stable delivery keys, no cross-recipient To exposure, current permission/override failure fallback, plus template, links, and queue behavior.
- Four Chromium renders inspected at 1440px desktop and 390px mobile, in light and dark modes. Logos and CTA loaded; no horizontal overflow. Screenshots are in `screenshots/e023-user-notification-*.png`.
- The project resolves test email to the array mailer; no email transport was contacted.
- Static route presence confirmed for `/purchasing/purchase-orders/{id}` and `/portal/customer/orders/{id}`. E-023 has many caller-specific link routes; exhaustive route-to-caller verification remains in the coverage audit.
- Real Gmail, Outlook, Apple Mail, and mobile email clients remain unverified.

## Findings and review

- EF-041 and EF-043: fixed in draft PR #256. Recipient lists are private; failed delivery alerts authorized active Sales users.
- EF-042: central active-user and address validation fixed in draft PR #256. Caller-specific record visibility and RBAC still require exhaustive E-023 review; the mail template does not infer permissions from a notification type.
- EF-044: employee maintenance repair rejection previously pointed to a supervisor-only route, and the assigned employee's submit-repair request was blocked by route/FormRequest permission checks. Fixed in draft PR #257 with the existing order-scoped maintenance access rule and an employee self-service link.
- Callers can include confidential or highly sensitive context in generic message text. HR/accounting/data-protection review remains required.
- Company settings used by the test seeder are fixtures, not approval of production company contact values.
