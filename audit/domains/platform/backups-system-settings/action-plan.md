# M012 — backups & system settings action plan

Audit date: 2026-08-27
Disposition: 📋 Plan Ready

No production source fixes were applied in this re-audit. The current plan
contains two small same-session-ok polish/governance items, but most work is
cross-process recovery design or external evidence. The gate therefore does
not permit implementation in this session.

## Ordered work

1. **Make restore failure and gate release fail-safe — F-003, F-011**
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Define automatic versus operator-led rollback, keep the shared maintenance
     gate and queue held after rollback failure, check `up`/resume postconditions,
     and cover DB, migration, files, worker-death, and failed-release paths.

2. **Publish coherent backup pairs — F-006**
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Stage database and private-file artifacts per run, validate/upload both,
     commit one manifest with pair identity and checksums, and quarantine
     incomplete publications.

3. **Fence operation leases and stale reconciliation — F-012**
   - Scope: [medium/large]
   - Session recommendation: [separate-recommended]
   - Implement conditional lease claims/heartbeats/reaper updates using the
     observed token, make queued-to-running one-shot, reject stale worker
     writes, and add interleaving/replay tests.

4. **Make dispatch durable — F-004**
   - Scope: [medium]
   - Session recommendation: [separate-recommended]
   - Add an outbox or idempotent dispatcher, bounded retry/receipt state, and
     an operator-visible retry/unblock path for post-commit queue failures.

5. **Enforce committed manifest schema at every restore entry point — F-010**
   - Scope: [medium]
   - Session recommendation: [separate-recommended]
   - Require committed versioned manifests with complete kind/size/checksum/
     remote-key identity for ID and filename resolution; reject or explicitly
     migrate legacy completed rows; add the missing regression test.

6. **Run target-like production recovery evidence — F-007**
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Use the built production image and isolated staging to prove API gate,
     queue, scheduler, local/off-site artifacts, uploads, migration, rollback,
     health, audit, timestamps, checksums, and RTO. Retain the signed log.

7. **Require and transmit settings change reasons — F-013**
   - Scope: [small]
   - Session recommendation: [same-session-ok]
   - Add a reason field/validation to the admin editor, send it to the API, and
     preserve the existing immutable audit record and redaction behavior.

8. **Correct UI success/posture state — F-014**
   - Scope: [small]
   - Session recommendation: [same-session-ok]
   - Set “Saved” only after mutation success, handle failed saves visibly, and
     derive the recovery icon from the posture state.

9. **Build the M012 acceptance suite**
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Cover manifest compatibility, dispatch failure, lease interleavings,
     pair publication, failed gate release, DB/file rollback, off-site
     retrieval, settings reason/audit, SPA error states, and the full staging
     drill.

## Re-audit acceptance gates

- Every API instance and writer observes the same maintenance gate before
  destructive restore work, and a failed release cannot resume traffic.
- A full backup publishes one committed database/private-file recovery point;
  incomplete pairs are not restorable or presented as coherent snapshots.
- Restore admission accepts only complete committed manifests and verifies
  local/off-site bytes against their recorded identity before maintenance.
- At most one worker owns a fenced operation lease; stale/replayed workers
  cannot overwrite a newer owner or perform concurrent destructive work.
- A post-commit dispatch failure is retried or operator-recoverable without a
  permanently held singleton lock.
- Settings edits are catalog-valid, permission-appropriate, reasoned, cached
  consistently, and immutably auditable.
- The UI does not display success or a green recovery posture when the server
  reports an error, warning, or no restorable snapshot.
- A retained target-like drill proves authenticated API, queue, scheduler,
  uploads, migrations, rollback, audit, and post-restore health.

## Session decision

Do not apply fixes in this audit session. Only F-013 and F-014 are tagged
same-session-ok; the plan is neither a majority same-session-ok plan nor small
in total scope. Keep M012 at 📋 Plan Ready, commit only the module audit
artifacts, and schedule implementation plus a fresh re-audit.
