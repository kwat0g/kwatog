# Database recovery access

## Goal

Allow an operator to restore a verified scheduled backup when the application
database has no user accounts. Recovery access is separate from ERP accounts
and can only list/verify backups, start a restore, check restore status, and
restore a rollback copy.

## Plan

1. Publish a versioned sidecar manifest next to every scheduled database and
   private-files backup. Record exact filenames, sizes, SHA-256 checksums, and
   creation time. Write it atomically after both artifacts pass validation.
2. Keep the backup catalog useful after the database ledger is lost. Read
   sidecar manifests from the backup directory and allow normal admin restore
   requests to use them. Legacy archives can be adopted after an explicit
   integrity check; record that their checksum was captured at adoption time.
3. Add a recovery-only page and API that authenticate with a server-configured
   secret, independent of `users`, `roles`, or `backup_operations`. The page and
   `POST /api/v1/recovery/session` are publicly reachable so an operator can
   enter the secret; all backup and operation endpoints require a 30-minute,
   encrypted recovery bearer. Limit login to 5 attempts per IP per minute,
   protected reads to 60 per minute, and mutations to 10 per minute. Do not
   cache tokens or protected responses. Expose no normal ERP routes or
   permissions through this identity.
4. Queue recovery restores on Redis and keep operation state beside the backup
   files, so admission and status polling do not depend on the target database
   or its ledger. Preserve artifact checksum checks, queue pause, maintenance
   gate, migrations, and rollback-required behavior. Create a rollback copy
   when the target database exists and is readable. If the database is missing,
   restore can recreate it, but there is no automatic rollback copy. A stopped
   PostgreSQL server still needs to be brought back by the host operator.
5. Document how to configure the recovery secret and how an operator uses the
   recovery page. Never put a live secret in the repository.
6. Verify recovery with focused tests for empty-user access, invalid secrets,
   scheduled sidecar discovery, legacy adoption, and queuing a restore from a
   sidecar. Run an isolated restore drill against a disposable database.

## Safety boundaries

- Recovery identity can restore data but cannot enter the ERP or act as a
  business superadmin.
- `/recovery` and the secret-check endpoint are public. Backup listing,
  verification, restore, status, and rollback endpoints reject missing, invalid,
  expired, or secret-rotated recovery tokens. The secret must be at least 32
  characters and must be delivered over HTTPS in production.
- Recovery login and archive listing work without PostgreSQL. Restore queueing
  and status use Redis plus the persistent backup directory; restoring requires
  a reachable PostgreSQL server and an available Redis worker.
- Sidecar operation state is stored with mode `0600` in the configured backup
  directory. Database replacement cannot be rolled back automatically when the
  target database did not exist before restore.
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
- Restore makes a rollback snapshot before replacing an existing target
  database; a missing target is recreated without an automatic rollback copy.
- After success, the application is healthy and accounts from the restored
  snapshot can sign in.
