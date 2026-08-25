# M014 — Employee master action plan

Status: Plan Ready  
Audit date: 2026-08-24

## Recommended implementation order

1. Lifecycle and account safety: F-001, F-002, F-003, F-004.
   - Scope: [medium]/[large]
   - Timing: [separate-recommended]
   - Route all status/separation/archive transitions through one invariant-preserving service; constrain provisioning to server-resolved employee data and an assignable-role policy.

2. Sensitive data and authorization: F-005, F-008, F-009, F-014.
   - Scope: [medium]/[large]
   - Timing: [separate-recommended]
   - Define field-level PII and document policies, preserve row scope for exports/documents, and prevent masked values from being written back.

3. Import and financial integrity: F-007, F-013.
   - Scope: [large]/[small]
   - Timing: [separate-recommended]
   - Reuse canonical employee creation side effects, make import rollback complete, and export money without floating-point conversion.

4. Account delivery/session behavior: F-012.
   - Scope: [medium]
   - Timing: [separate-recommended]
   - Move notifications after commit, queue them, report delivery state accurately, and revoke sessions/tokens on reset/deactivation.

5. Department hierarchy correctness: F-010, F-011.
   - Scope: [medium]
   - Timing: F-010 [same-session-ok]; F-011 [separate-recommended]
   - Preserve parent_id in the tree response, add a child read-edit-save regression test, then add cycle and head validation.

6. UI contract polish: F-006, F-015.
   - Scope: [small]
   - Timing: F-006 [separate-recommended] because salary changes cross the payroll/maker-checker boundary; F-015 [same-session-ok].
   - Remove or redirect silently discarded salary fields and correct compensation/status labels.

## Acceptance gates for re-audit

- Every employee lifecycle transition has an allowed-state matrix, required data, authorization, history, and account/clearance invariants.
- Provisioning cannot accept arbitrary privileged role IDs or unverified recipient addresses.
- Archive, separation, deactivation, clearance, and final pay have an explicit coordinated state model.
- Sensitive fields, documents, exports, and row scopes are covered by role and negative-path tests.
- Import and rollback tests prove creation of all canonical dependent records and cleanup after a failed batch.
- Department child hierarchies survive read-edit-save and cycle/head validation rejects invalid graphs.
- Money exports preserve exact decimal values.
- Notification delivery is post-commit/queued and credential reset invalidates prior sessions.

## Session decision

The majority of findings are tagged [separate-recommended], and the module needs cross-module lifecycle, payroll, security, and policy decisions. Do not apply production fixes in this audit session. Keep the module Plan Ready and schedule implementation followed by a fresh re-audit.
