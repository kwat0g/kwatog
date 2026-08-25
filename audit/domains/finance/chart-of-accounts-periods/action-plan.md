# M025 — Chart of accounts and accounting periods action plan

Audit date: 2026-08-25  
Disposition: Plan Ready

No production source fixes were applied in this first post-change audit. The
current worktree contains the earlier implementation work, but the remaining
items include financial classification, transaction recovery, permissions, and
cross-module acceptance decisions. They should be implemented in a dedicated
follow-up session and then re-audited.

## Ordered work

1. **Make configured GL roles type-safe — F-001**
   - Scope: **large**
   - Session recommendation: **separate-recommended**
   - Define a typed COA configuration map for AP, AR, VAT, payroll, inventory,
     asset, and HR control accounts. Route each configured-code lookup through
     the shared resolver, lock/recheck expected types at posting, and add
     active-but-wrong-type negative coverage. Coordinate with M026 and all
     downstream GL writers.

2. **Repair duplicate-period recovery and prove the barrier — F-002, F-006**
   - Scope: **large**
   - Session recommendation: **separate-recommended**
   - Replace the in-transaction `23505` fallback with a savepoint or whole-
     transaction retry. Add PostgreSQL interleaving tests for an existing
     period and a row-less month across manual post, `postSystem()`, and stale
     relock. Keep the advisory-lock/row-lock order documented and tested.

3. **Align CSV metadata and status authority — F-003**
   - Scope: **medium**
   - Session recommendation: **separate-recommended**
   - Advertise every supported COA column, including `is_active`, in the import
     schema. Decide whether `admin.import.manage` is a trusted exception to
     `accounting.coa.deactivate`; enforce the chosen policy in the route/service
     boundary and test dry-run, commit, rollback, and inactive-row behavior.

4. **Complete the split-permission COA UI — F-004**
   - Scope: **medium**
   - Session recommendation: **separate-recommended**
   - Keep metadata editing available to `accounting.coa.manage` without making
     a disabled status field required, and expose dedicated activate/deactivate
     actions to the independent status permission. Add component and API tests
     for view-only, manage-only, deactivate-only, and full-access roles.

5. **Resolve the open-period UI contract — F-005**
   - Scope: **medium**
   - Session recommendation: **same-session-ok**
   - Decide whether absent rows are the complete open-month model. Then either
     remove the misleading Open filter and add a deliberate month-picker/current
     month action, or expose a bounded list of open months and their close
     actions. Update the API type, empty state, and regression tests together.

6. **Build the M025 acceptance suite — F-007**
   - Scope: **large**
   - Session recommendation: **separate-recommended**
   - Add route-level FormRequest/RBAC coverage, stale hierarchy writes,
     parent/child activation policy, tree serialization, import metadata,
     configured account type checks, inactive-account negative coverage for
     manual and automated writers, and the SPA role matrix. Run it against an
     isolated PostgreSQL test database rather than the shared broken database.

7. **Restore a typechecking period page — F-008**
   - Scope: **small**
   - Session recommendation: **same-session-ok**
   - Narrow the paginated response before dereferencing `meta`, then run the
     page/component check alongside the API acceptance suite. Keep the fix
     scoped to the period page; the other four current typecheck errors are in
     unrelated modules.

## Re-audit acceptance gates

- Every configured AP/AR/VAT/payroll/inventory/asset/HR code resolves to an
  active account of the expected `AccountType`; wrong-type postings fail before
  a journal is committed.
- A duplicate period creation either serializes successfully or retries after
  rollback; no query is issued inside a failed PostgreSQL transaction.
- A post that races close either commits before close becomes authoritative or
  is rejected after close; this is proven for manual, system, and scheduler
  paths with real PostgreSQL transactions.
- CSV metadata matches the accepted importer payload, and the chosen
  `admin.import.manage` versus `accounting.coa.deactivate` authority is explicit
  and tested.
- All supported COA permission combinations can perform exactly the actions
  their API permissions grant, with no required disabled form control and no
  hidden status-only workflow.
- The periods surface has an intentional, test-covered representation of open
  months and does not advertise a filter that cannot return normal data.
- The M025 SPA typechecks cleanly, including the period pagination branch.
- API, cross-module, and SPA regression coverage exists for all listed gates.

## Session decision

Do not apply fixes in this audit session. F-001/F-002/F-006/F-007 are
financial-state or cross-module work, F-003 requires an authority decision, and
F-004 changes permission-facing UI behavior. F-008 is small, but the majority
of the plan is separate-recommended and several items are large; keep M025 at
Plan Ready for a dedicated implementation session followed by re-audit.
