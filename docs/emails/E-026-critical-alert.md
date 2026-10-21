# E-026 — critical alert

## Source and behavior

- `AlertEngineService::attemptCriticalEmail()` sends only critical alerts, using active recipients from the per-alert-type `alerts.critical.notification_roles` setting.
- The alert engine records bounded retry state and keeps this transport synchronous so a send result updates the owning alert. This preserves the existing trigger and retry behavior.
- Invalid addresses are excluded. If no configured recipient has a usable email, the attempt fails and follows the existing retry/fallback path.
- The alert email is sent separately to each internal user. It has no attachment or state-changing link.

## Content and privacy

The subject uses configured email branding and a CR/LF-scrubbed, length-limited alert title. The body shows the alert's stored title, message, human-readable type/severity and related area, plus creation time converted to Asia/Manila. The related-area label is derived from the entity model name with word spacing; internal entity database IDs remain omitted. The CTA opens the authenticated SPA alert list.

The shared Markdown shell supplies the configured logo variants, company identity/footer, preheader, responsive layout, and plain-text alternative. Blade escapes alert text.

## Review and verification

This is an internal operational notice. Confirm configured alert roles match operational ownership. No personal-data or statutory content is intentionally included.

`CriticalAlertEmailDesignTest`: 1 test, 9 assertions passed on an isolated test database. It verifies the Manila display as `+08:00`, omission of the internal entity ID, SPA alert link, and HTML size. A rendered `MailChannel` message measured 19,473 HTML bytes and 622 plain-text bytes. Mailer was `array`; no real email sent.

Inspected Chromium screenshots at 1440px desktop and 390px mobile in light/dark. The message stays readable without clipping; company legal/address values are redacted in artifacts: [desktop light](screenshots/e026-critical-alert-desktop-light.png), [desktop dark](screenshots/e026-critical-alert-desktop-dark.png), [mobile light](screenshots/e026-critical-alert-mobile-light.png), [mobile dark](screenshots/e026-critical-alert-mobile-dark.png), [plain text](screenshots/e026-critical-alert.txt).

Real email clients remain unverified.
