# M014 — Employee master action plan

Status: Plan Ready
Audit date: 2026-08-27
Recommendation: separate-recommended

No production source or test changes were made in this audit session. The
module has several contained small items, but the dominant work crosses
lifecycle/RBAC, leave accounting, storage, shared import rollback, and PII
policy boundaries. Implement the items below in order, then run a fresh
three-pass re-audit.

## Ordered actions

1. Close the employee/account lifecycle matrix — R-001 and R-002.
   - Size: [large]
   - Timing: [separate-recommended]
   - Define which statuses may be provisioned, how archive/restore changes
     account access, and how terminal employees are handled. Implement locked,
     audited guards/reactivation and test active, terminal, archive, restore,
     and existing-account cases.

2. Apply row scope to every employee write and account operation — R-003.
   - Size: [large]
   - Timing: [separate-recommended]
   - Pass the authenticated actor through update, delete, restore, photo,
     account status/provision/deactivate/reset, and bulk paths. Reuse the
     DepartmentScope policy and add custom-grant negative tests, including
     out-of-department hashes.

3. Make onboarding a reversible projection of canonical state — R-004.
   - Size: [medium]
   - Timing: [separate-recommended]
   - Recompute after relevant employee/account/shift writes, clear steps whose
     source is absent, and clear completed_at when the tracker is incomplete.
     Test removal and re-addition of each derived source.

4. Make leave-balance initialization single-owner and hire-date aware — R-005.
   - Size: [medium]
   - Timing: [separate-recommended]
   - Remove the full-balance/async insert race or make the service perform the
     same prorated calculation. Align the hire year and assert exact credits
     for Jan 1, mid-year, year-end, and backdated hires.

5. Define the employee date/history correction contract — R-011 through R-014.
   - Size: [medium]
   - Timing: [separate-recommended]
   - Enforce regularized >= hired on update; decide whether hire-date edits
     are prohibited or approval-based; record employment-type and hire-date
     from/to history; and suppress duplicate regularized history on unchanged
     saves. Add API and UI regression coverage.

6. Make employee document retention and storage failure-safe — R-006 and R-007.
   - Size: [medium]
   - Timing: [separate-recommended]
   - Choose quarantine versus permanent deletion, align request limits with
     document columns, handle original filenames, and compensate storage when
     row creation fails. Test delete/restore/download and over-limit input.

7. Make employee photo replacement/deletion failure-atomic — R-008.
   - Size: [medium]
   - Timing: [separate-recommended]
   - Store a replacement before removing the old path, commit the database
     pointer, clean old files after commit, and handle partial failures
     idempotently. Add storage-failure tests.

8. Extend the shared import rollback contract — R-009.
   - Size: [large]
   - Timing: [separate-recommended]
   - Coordinate with the common import owner so employee imports register
     hired history, onboarding, shift, leave, outbox, and auto-created
     positions, or expose a module rollback hook. Add a committed employee
     batch rollback test. This is a dependency blocker for M014 and is not
     changed here.

9. Align employee CSV validation with the published import contract — R-010.
   - Size: [medium]
   - Timing: [separate-recommended]
   - Decide which create invariants imports may relax, then enforce age/date
     safety consistently and document optional fields. Test underage,
     future-dated, and invalid date-order rows.

10. Make bulk provisioning input and error handling explicit — R-015 and R-016.
    - Size: [small]
    - Timing: [same-session-ok]
    - Return one result per requested input, identify invalid/missing hashes,
      and replace raw Throwable messages with stable public errors plus
      correlation IDs. Add invalid-ID, unknown-ID, and infrastructure-error
      tests.

11. Serialize account credential races — R-017 and R-018.
    - Size: [medium]
    - Timing: [separate-recommended]
    - Lock/re-read users inside reset transactions, make notification delivery
      correspond to the winning password, and retry unique email collisions
      safely. Add concurrent reset/provision tests.

12. Approve and encode a PII field-level visibility matrix — R-019.
    - Size: [large]
    - Timing: [separate-recommended]
    - Get HR/legal decisions for address, contact, emergency contact, linked
      email, IDs, bank, salary, documents, and exports. Encode the matrix in
      resources, export columns, update requests, and role-negative tests.

13. Refresh shared process/schema documentation — R-020 and R-021.
    - Size: [small]
    - Timing: [separate-recommended]
    - Remove the obsolete direct separation endpoint and daily pay references,
      document the clearance-backed route and semi-monthly contract, and
      update QA/API examples. This requires coordination because shared docs
      are outside the module-only write boundary.

14. Reconcile document recovery UX with the API — R-022.
    - Size: [small]
    - Timing: [same-session-ok]
    - Either add a permissioned/audited restore control to the employee detail
      UI or remove the recoverable restore contract and its contradictory
      wording.

## Acceptance gates for re-audit

- Lifecycle/account operations have one status matrix, actor scope, locking,
  history, and archive/restore behavior.
- Onboarding and leave balances reconcile from canonical employee state.
- Date and employment-type corrections are validated, approved, and audited.
- Documents and photos cannot leave a broken pointer/orphaned file, and the
  chosen document retention policy is consistent in API and UI.
- Employee import rollback removes every M014-owned dependent record or
  invokes the agreed shared rollback hook.
- Bulk errors are per-input and do not expose infrastructure details.
- Credential races are serialized and notification state matches the winning
  operation.
- PII and export fields have approved role-negative tests.
- Shared docs and UI describe only current routes, pay types, and recovery
  behavior.

## Session decision

Keep M014 at Plan Ready. R-015, R-016, and R-022 are individually
same-session-ok, but the module-wide audit is not a small/safe change set and
the remaining blockers require separate lifecycle, policy, and shared-pipeline
coordination. No production fix is included in this commit.
