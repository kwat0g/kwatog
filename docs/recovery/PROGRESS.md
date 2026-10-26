# Database recovery progress

Status: implementation complete on branch `fix/db-recovery-access`; not
merged or deployed. UI Phase 4 remains paused.

## Verified

- Recovery login and archive listing work with no ERP users and with PostgreSQL
  unreachable.
- Scheduled full backups publish sidecar manifests for database and private
  files archives.
- Legacy dumps require gzip and PostgreSQL version-header checks before
  checksum adoption.
- Recovery restore admission reuses the existing rollback, maintenance, queue,
  migration, and restore worker flow.
- Focused recovery, scheduled-manifest, and backup-regression tests pass.
- ESLint passes on changed SPA files.

## Remaining before deployment

- Set a high-entropy `BACKUP_RECOVERY_SECRET` outside the database, then restart
  the API so cached configuration loads it.
- Run the documented restore drill against a disposable PostgreSQL database.
- Confirm queue worker and backup-volume access in the deployment environment.
- The repository-wide SPA type-check reports pre-existing failures in
  `src/api/exports.test.ts`, `src/components/ui/ExportButton.tsx`,
  `src/pages/admin/users/invites.tsx`, `src/pages/auth/accept-invite.tsx`,
  `src/pages/error/Forbidden.tsx`, `src/pages/inventory/stock-transfers/create.tsx`,
  and `src/pages/purchasing/purchase-orders/create.tsx`. It reported no issue in
  the recovery page.
- `npm run audit:tokens` reports the existing `text-white` in
  `src/components/crm/ProductPhoto.tsx:31`; no recovery page token violation.
