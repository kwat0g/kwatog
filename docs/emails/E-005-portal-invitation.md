# E-005 — Customer and supplier portal invitation

Status: shared security correction is in draft PR #223; template redesign is in progress in a separate draft PR.

## Message behavior

- Trigger: authorized customer or supplier portal-access manager invites or resends an account.
- Recipient: new invite uses the requested address; resend uses the existing portal user's address. The account is checked against its active customer/vendor, and the locked account email/name snapshot is used for delivery.
- Security: invitation creates an unknown random password and issues a hashed one-time reset token. The raw token exists only in the queued mail payload and link. It expires after 60 minutes; a newer request invalidates earlier links.
- Delivery: `QueuedMailable`, dedicated mail queue, after commit, shared retry and delivery ledger. Queue failure uses the existing permission-scoped in-app fallback.
- Subject: `[configured brand] Set up Customer portal access` or Supplier equivalent. No recipient name or token in subject.
- Link: configured SPA base URL plus `/portal/password-reset?type=customer|supplier&token=...`. SPA route exists; GET displays the password form, while the reset POST performs the state change.
- Content: recipient name, portal audience, expiry, security guidance, and the setup CTA. No password, attachment, or internal identifier.
- Footer and logo: shared settings-backed company footer, landing-page logo, dark-mode logo, plain-text alternative, hidden preheader, and text link fallback.
- Safety: Blade escapes recipient-controlled name. Hostile markup test confirms it remains text. No send occurred.

## Verification

- `PortalPasswordResetTest.php`: 12 tests passed, 65 assertions. Covers customer/supplier setup/reset routes, subject, preheader, plain-text link, escaped recipient input, token hashing/expiry/invalidation, and no temporary password.
- Browser previews inspected at 1440px desktop and 390px mobile in light/dark modes. All four showed no horizontal overflow; correct logo and dark colors selected. HTML size: 21,399 bytes. Screenshots: [desktop light](screenshots/portal-invitation-desktop-light.png), [desktop dark](screenshots/portal-invitation-desktop-dark.png), [mobile light](screenshots/portal-invitation-mobile-light.png), [mobile dark](screenshots/portal-invitation-mobile-dark.png).
- Browser rendering does not verify Outlook, Gmail, Apple Mail, Yahoo, or mobile mail clients. No real email was sent.

## Review still required

- Confirm portal setup wording and company identity with the company owner before production use.
- External personal-data handling requires review by HR/legal/data protection officer as applicable.
