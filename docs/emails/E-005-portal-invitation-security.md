# E-005 — Customer or supplier portal invitation

Status: credential delivery risk fixed in separate security PR. Visual redesign remains pending.

## Audited paths

- Customer invite and resend: `PortalAccessController` → `PortalAccessService` → `PortalInvitationService`.
- Supplier invite and resend: same chain with supplier records.
- Recipients are the email entered by an authorized portal-access manager or the existing portal user's email on resend. Route permissions are the `accounting.customers.manage` / `b2b.portal_access.manage` permissions. Duplicate/cross-entity account checks remain in `PortalInvitationService`.
- Mail is queued on the dedicated `mail` queue after commit. Failure notification uses the existing permission-scoped fallback; no temporary credential is included in failure text.
- The frontend route `/portal/password-reset` exists and accepts `type` plus token query parameters. Reset tokens are hashed in the database, expire after 60 minutes, are invalidated when a newer token is issued, and are consumed on reset.

## Security correction

- Previous invite emails carried the account password in the queued mailable payload and email body. The password stayed usable until changed or rotated.
- Invitations now store an unknown random password, then issue a reset token and send a one-time password setup link. API response still contains account identity only.
- Link token remains a bearer secret in the email and queue payload until delivery; no token is stored raw in the database. Existing reset page lets the recipient choose a password without first logging in.
- Existing triggers and invitee recipients remain unchanged. Re-inviting still rotates credentials and revokes existing API tokens.

## Remaining E-005 template work

- Current view is functional but still uses its legacy Markdown copy/layout. Rebuild it on the shared email framework with the customer/supplier distinction, company settings, safe external-facing copy, and plain-text verification.
- Inspect both variants in desktop/mobile and light/dark previews. No real email sent.
- Company identity values and recipient onboarding copy need owner review before production use.
