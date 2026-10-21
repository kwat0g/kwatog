# E-026 — critical alert

## Source and behavior

- `AlertEngineService::attemptCriticalEmail()` sends only critical alerts, using active recipients from the per-alert-type `alerts.critical.notification_roles` setting.
- The alert engine records bounded retry state and keeps this transport synchronous so a send result updates the owning alert. This preserves the existing trigger and retry behavior.
- Invalid addresses are excluded. If no configured recipient has a usable email, the attempt fails and follows the existing retry/fallback path.
- The alert email is sent separately to each internal user. It has no attachment or state-changing link.

## Content and privacy

The subject uses configured email branding and a CR/LF-scrubbed, length-limited alert title. The body shows the alert's stored title, message, human-readable type/severity, entity type, and creation time converted to Asia/Manila. It omits the internal entity database ID. The CTA opens the authenticated SPA alert list.

The shared Markdown shell supplies the configured logo variants, company identity/footer, preheader, responsive layout, and plain-text alternative. Blade escapes alert text.

## Review and verification

This is an internal operational notice. Confirm configured alert roles match operational ownership. No personal-data or statutory content is intentionally included. Local test mail remains array-only; no real email sent.

Browser screenshots and actual rendered-MIME assertions are pending because this workspace has no PHP CLI or active Docker test services. Real email clients remain unverified.
