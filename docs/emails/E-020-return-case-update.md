# E-020 — Return case update

## Trigger and audience

The existing `ReturnCaseService::notify()` path queues this notice after commit for a public case update. Active customer portal users on the case's customer receive customer notices; active supplier portal users on its vendor receive supplier notices. Private internal replies do not trigger external email. Existing lifecycle triggers and recipient selection remain unchanged in this template change.

Internal case notifications continue through the existing notification service and permission logic. When no active portal contact exists, the service uses its existing in-app follow-up message.

## Content and data

- Subject, reference, status label, realm, and case hash come from the service values captured when the notification is queued.
- The body includes only the case reference and human-readable status. It does not include issue descriptions, messages, financial values, or attachments.
- The button links to the customer's or supplier's exact SPA problem-report route using `FRONTEND_URL`. Portal layout requires authentication and preserves the intended route through sign-in.
- The shared email shell supplies the company logo, settings-backed identity/contact footer, hidden preheader, responsive layout, and dark-mode colors. The mailable uses Laravel Markdown for plain text.
- No state change can occur by following the email link.

## Verification

- `ReturnCaseUpdateMailTest`: 2 tests passed, 30 assertions. Checks both portal routes, settings-backed footer, subject, plain-text link, hostile HTML and header input, absence of unresolved values, and HTML size.
- Chromium inspected 8 renders for customer and supplier recipients at 1440px and 390px in light/dark modes. All rendered logos loaded and all document widths matched the viewport; no horizontal overflow.
- Refreshed all 8 captures from the current rendered HTML and visually confirmed the CTA label is visible in dark mode; earlier tracked captures showed a stale blank label.
- Mailer was forced to `array`. No email transport was contacted.
- Chromium previews do not establish rendering in Outlook, Gmail, Apple Mail, or other clients.

## Related findings

- EF-034 and EF-035 were fixed separately in draft PR #249, stacked on #247. Recipient failures no longer abort other deliveries, and duplicate event/contact dispatches reuse one delivery key.
- Company settings still contain test fixture contact values in this render. Owner verification remains required before external use.
