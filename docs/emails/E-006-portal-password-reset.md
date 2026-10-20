# E-006 — Customer and supplier portal password reset

Status: template redesign is in progress in the portal-security email draft PR.

## Message behavior

- Trigger: customer or supplier portal user requests password recovery.
- Recipient: active portal account email, only when the related customer/vendor is active. Unknown and inactive email requests return the same generic API response. Request endpoints use the existing `throttle:auth` middleware.
- Delivery: reset token is stored hashed, expires in 60 minutes, and is single-use. Issuing a newer token invalidates prior active links. Message uses `QueuedMailable`, dedicated mail queue, after commit, shared retry and delivery ledger.
- Subject: `[configured brand] Reset your customer|supplier portal password`. Does not disclose recipient identity or token.
- Link: configured SPA base URL plus `/portal/password-reset?type=customer|supplier&token=...`. SPA route presents the reset form; the POST endpoint validates and consumes the token.
- Content: recipient greeting, request context, one-time expiry, newer-link behavior, ignore-if-unrequested guidance, and a secure CTA with plain-text fallback. No current password, attachment, or internal identifier.
- Footer, preheader, logo variants, and plain text come from the shared mail framework.

## Verification

- `PortalPasswordResetTest.php`: included in the 12 passing tests, 65 assertions documented under E-005; verifies supplier/customer trigger typing, subject, frontend route, and token reset behavior.
- Browser previews inspected at 1440px desktop and 390px mobile in light/dark modes. All four showed no horizontal overflow; correct logo and dark colors selected. HTML size: 19,639 bytes. Screenshots: [desktop light](screenshots/portal-password-reset-desktop-light.png), [desktop dark](screenshots/portal-password-reset-desktop-dark.png), [mobile light](screenshots/portal-password-reset-mobile-light.png), [mobile dark](screenshots/portal-password-reset-mobile-dark.png).
- Browser rendering does not verify Outlook, Gmail, Apple Mail, Yahoo, or mobile mail clients. No real email was sent.

## Review still required

- Review account-security copy with the person responsible for portal support.
- Personal-data handling requires review by the company's data protection contact.
