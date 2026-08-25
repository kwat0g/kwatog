# M012 — backups & system settings action plan

Audit date: 2026-08-24  
Disposition: Plan Ready

No production source fixes were applied. Restore safety, queue admission,
artifact identity, production image compatibility, and settings governance
cross the API, queue, database, Redis, filesystem, deployment, and operator
surfaces.

## Ordered work

1. **Make the production maintenance gate real — F-001**
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Choose a shared cache/edge/drain design that survives a database restore,
     stop or pause all writers/consumers, and prove every API process observes
     the gate before DB replacement.

2. **Pin and prove the backup runtime — F-002, F-007**
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Pin the production PostgreSQL client to 16, add an image smoke check, and
     run the real scheduler/queue image against PostgreSQL 16. Retain the
     production-like restore evidence and make the release gate truthful.

3. **Define the restore state machine and rollback contract — F-003**
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Decide automatic rollback versus durable partial/rollback-required
     recovery. Cover DB restore, migration failure, private-file failure,
     worker death, maintenance-gate failure, health checks, and DB-only scope.

4. **Build durable operation admission and recovery — F-004**
   - Scope: [medium/large]
   - Session recommendation: [separate-recommended]
   - Add an atomic singleton/idempotency boundary, shared bounded lock/lease,
     heartbeats, stale reconciliation, after-commit/outbox dispatch, and
     explicit retry/unblock behavior. Add PostgreSQL interleaving tests.

5. **Introduce committed backup manifests and integrity preflight — F-005, F-006**
   - Scope: [medium/large]
   - Session recommendation: [separate-recommended]
   - Stage DB/files artifacts, validate both, publish one manifest, upload and
     verify both remote objects, enforce size/checksum/kind at restore, and
     quarantine incomplete pairs. Select manifests/IDs rather than free names.

6. **Harden settings governance and auditability — F-008**
   - Scope: [medium]
   - Session recommendation: [separate-recommended]
   - Replace permissive fallback validation with a catalog/schema, reject
     unknown keys, add old/new immutable audit events and actor/correlation
     metadata, and test security/module/financial setting boundaries.

7. **Repair recovery catalog and UI semantics — F-009**
   - Scope: [medium]
   - Session recommendation: [same-session-ok]
   - Add cursor pagination, local/remote/missing/checksum state, committed
     manifest selection, and honest labels distinguishing archive validation
     from a restore-tested recovery point.

8. **Build the M012 acceptance suite**
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Cover multi-container maintenance, client/server version compatibility,
     concurrent admission, stale worker recovery, remote head/checksum
     failures, atomic pair publication, DB/file rollback, settings audit, and
     the complete staging drill.

## Re-audit acceptance gates

- A restore blocks the live API and all writers before any destructive DB
  action, and every process shares the same gate.
- The actual production image publishes a valid PostgreSQL-16 dump and a
  paired private-file archive through the scheduled path.
- Any post-drop failure produces either an automatic verified rollback or a
  durable, operator-visible rollback-required state with tested recovery.
- At most one operation owns the backup/restore lease; crashed workers are
  reclaimed safely and do not permanently block the surface.
- Restore bytes match a committed manifest's checksum/size/kind, including
  off-site objects; missing remote objects fail before maintenance.
- Settings edits are catalog-valid, permission-appropriate, cache-consistent,
  and immutably auditable.
- The UI exposes all committed local/remote recovery points with availability
  and integrity state, and never calls an archive restore-tested without
  evidence.
- A retained target-like drill proves authenticated API, queue, scheduler,
  uploads, migrations, rollback, and post-restore health.

## Session decision

Do not apply fixes in this audit session. F-001 through F-007 are release-
impacting or cross-process recovery work; F-008 requires a settings governance
decision; and F-009 depends on the manifest/catalog contract. The one small
UI/catalog item is not a majority of safe same-session work. Keep M012 at Plan
Ready and schedule an implementation pass followed by a fresh audit.
