# E-026 — critical alert

## Source and behavior

- `AlertEngineService::attemptCriticalEmail()` sends only critical alerts, using active recipients from the per-alert-type `alerts.critical.notification_roles` setting who also have effective `alerts.view` permission.
- The alert engine records bounded retry state and keeps this transport synchronous so a send result updates the owning alert. This preserves the existing trigger and retry behavior.
- Invalid addresses are excluded. If no configured recipient has a usable email, the attempt fails and follows the existing retry/fallback path.
- The alert email is sent separately to each internal user. It has no attachment or state-changing link.

## Content and privacy

The subject uses configured email branding and a CR/LF-scrubbed, length-limited alert title. The body shows the alert's stored title, message, human-readable type/severity, entity type, and creation time converted to Asia/Manila. It omits the internal entity database ID. The CTA opens the authenticated SPA alert list.

The shared Markdown shell supplies the configured logo variants, company identity/footer, preheader, responsive layout, and plain-text alternative. Blade escapes alert text.

## Review and verification

This is an internal operational notice. Confirm configured alert roles match operational ownership. No personal-data or statutory content is intentionally included. Regression test excludes a configured-role user whose effective `alerts.view` permission was revoked. Local test mail remains array-only; no real email sent.

Rendered HTML was captured in Chromium at 1440px desktop and 390px mobile, in light and dark modes; the four screenshots show the logo, alert facts, CTA, and footer without viewport overflow. The network-isolated render used `MAIL_MAILER=array`; no email was sent. Rendered-MIME assertions remain pending. Real email clients remain unverified.
