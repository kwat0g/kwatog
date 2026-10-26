# Database recovery access

## Goal

Allow an operator to restore a verified scheduled backup when the application
database has no user accounts. Recovery access is separate from ERP accounts
and can only list backups, start a restore, and check restore status.

## Plan

1. Publish a versioned sidecar manifest next to every scheduled database and
   private-files backup. Record exact filenames, sizes, SHA-256 checksums, and
   creation time. Write it atomically after both artifacts pass validation.
2. Keep the backup catalog useful after the database ledger is lost. Read
   sidecar manifests from the backup directory and allow normal admin restore
   requests to use them. Legacy archives can be adopted after an explicit
   integrity check; record that their checksum was captured at adoption time.
3. Add a recovery-only page and API that authenticate with a server-configured
   secret, independent of `users`, `roles`, or `backup_operations`. Use a
   short-lived encrypted bearer held in page memory, bypass Sanctum session
   startup, and rate limit credential attempts. Expose no normal ERP routes or
   permissions through this identity.
4. Queue restores through the existing recovery worker. Preserve its rollback
   snapshot, artifact checksum checks, queue pause, maintenance gate, migration,
   and rollback-required behavior. Keep recovery status available while the
   target database is being replaced.
5. Document how to configure the recovery secret and how an operator uses the
   recovery page. Never put a live secret in the repository.
6. Verify recovery with focused tests for empty-user access, invalid secrets,
   scheduled sidecar discovery, legacy adoption, and queuing a restore from a
   sidecar. Run an isolated restore drill against a disposable database.

## Safety boundaries

- Recovery identity can restore data but cannot enter the ERP or act as a
  business superadmin.
- Recovery login and archive listing work without PostgreSQL. Queueing a
  restore still requires a reachable database with the `backup_operations`
  schema and an available queue worker.
- Backup filenames remain server-validated; requests never provide paths or
  SQL.
- A checksum generated while adopting a legacy archive proves that the file
  stayed unchanged after adoption. It cannot prove the archive was authentic
  before that point.
- The secret is deployment configuration. Until it is set outside the
  database, recovery login stays disabled.

## Acceptance

- Scheduled snapshots are restorable from the normal backup page when the
  database ledger exists.
- Recovery page can list and restore those snapshots when `users` is empty.
- Restore still makes a rollback snapshot before replacing the database.
- After success, the application is healthy and accounts from the restored
  snapshot can sign in.
