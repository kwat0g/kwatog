# Database recovery progress

Status: correcting the recovery implementation on branch
`fix/db-recovery-empty-db`; not merged or deployed. UI Phase 4 remains paused.

## Verified

- Recovery login and archive listing work with no ERP users and with PostgreSQL
  unreachable.
- Recovery restore queueing and status polling now use a protected, expiring
  token and file-backed operation state, so they do not require the target DB
  ledger.
- Scheduled full backups publish sidecar manifests for database and private
  files archives.
- Legacy dumps require gzip and PostgreSQL version-header checks before
  checksum adoption.
- Recovery restore uses Redis and the existing worker lifecycle. It saves a
  rollback copy when the target database exists; a missing target can be
  recreated, but has no automatic rollback copy.
- Login attempts are rate limited by IP. Protected responses use `no-store`,
  operation URLs accept UUIDs only, and changing the recovery secret revokes
  outstanding tokens.
- Within the recovery API, the only unauthenticated route is
  `POST /api/v1/recovery/session`; it returns no backup data or action without
  the separate recovery secret. Production Compose keeps API off published
  ports, and production Nginx redirects HTTP to HTTPS. The live deployment
  configuration was not inspected.
- `RecoveryAccessOfflineDatabaseTest`: 4 tests, 58 assertions passed while the
  PostgreSQL connection was deliberately unreachable.
- A disposable PostgreSQL drill restored a marker table into a database that
  did not exist, using the real `db-restore.sh`; it finished without claiming
  rollback availability. The Laravel migration call was stubbed in this drill.
- ESLint passed for the two changed recovery SPA files.

## Remaining before deployment

- Set a high-entropy `BACKUP_RECOVERY_SECRET` outside the database, then restart
  the API so cached configuration loads it.
- Run the documented restore drill against a disposable PostgreSQL database.
- Confirm queue worker and backup-volume access in the deployment environment.
- Confirm production uses the checked Nginx config and has a valid TLS
  certificate for `/recovery`.
- Run the DB-backed recovery test class and an isolated drill against an
  existing empty database with migrations enabled. The attempted DB-backed
  test run could not connect because the temporary test runtime had no
  database password; no database was changed.
- The repository-wide SPA type-check reports pre-existing failures in
  `src/api/exports.test.ts`, `src/components/ui/ExportButton.tsx`,
  `src/pages/admin/users/invites.tsx`, `src/pages/auth/accept-invite.tsx`,
  `src/pages/error/Forbidden.tsx`, `src/pages/inventory/stock-transfers/create.tsx`,
  and `src/pages/purchasing/purchase-orders/create.tsx`. It reported no issue in
  the recovery page.
- `npm run audit:tokens` reports the existing `text-white` in
  `src/components/crm/ProductPhoto.tsx:31`; no recovery page token violation.
