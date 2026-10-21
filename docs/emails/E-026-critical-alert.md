# E-026 — critical alert

## Source and behavior

- `AlertEngineService::attemptCriticalEmail()` sends only critical alerts, using active recipients from the per-alert-type `alerts.critical.notification_roles` setting.
- The alert engine records bounded retry state and keeps this transport synchronous so a send result updates the owning alert. This preserves the existing trigger and retry behavior.
- Invalid addresses are excluded. If no configured recipient has a usable email, the attempt fails and follows the existing retry/fallback path.
- The alert email is sent separately to each internal user. It has no attachment or state-changing link.

## Content and privacy

The subject uses configured email branding and a CR/LF-scrubbed, length-limited alert title. The body shows the alert's stored title, message, human-readable type/severity and related area, plus creation time converted to Asia/Manila. The related-area label is derived from the entity model name with word spacing; internal entity database IDs remain omitted. The CTA opens the authenticated SPA alert list.

The shared Markdown shell supplies the configured logo variants, company identity/footer, preheader, responsive layout, and plain-text alternative. Blade escapes HTML; EF-115 separately tracks Markdown syntax and external URL neutralization.

## Review and verification

This is an internal operational notice. Confirm configured alert roles match operational ownership. No personal-data or statutory content is intentionally included.

`CriticalAlertEmailDesignTest`: 1 test, 11 assertions passed on `ogami_test`. It verifies the Manila display as `+08:00`, humanized related area, omission of the internal entity ID, SPA alert link, and HTML size below 102,400 bytes. Mailer was `array`; no real email sent.

Rendered the Laravel `MailMessage` in Chromium at 1440px desktop and 390px mobile, in light/dark. All logo images loaded; document width matched viewport at both sizes; CTA and related-area text stayed readable. CID logos were mapped to the same image assets as data URIs for browser preview. Company contact/legal values are redacted in artifacts: [desktop light](screenshots/e026-critical-alert-desktop-light.png), [desktop dark](screenshots/e026-critical-alert-desktop-dark.png), [mobile light](screenshots/e026-critical-alert-mobile-light.png), [mobile dark](screenshots/e026-critical-alert-mobile-dark.png), [plain text](screenshots/e026-critical-alert.txt).

EF-115 tracks Markdown syntax and external URL neutralization in a separate security fix branch. Real email clients remain unverified.

Real email clients remain unverified.
