# M025 — Chart of accounts and accounting periods action plan

Audit date: 2026-08-27
Disposition: **Plan Ready**
Claim: agent-b / `finance/chart-of-accounts-periods`

The financial, authority, permission, and cross-module items are
`separate-recommended`. Only the two contained low-risk items below were safe
to complete in this audit session. The module remains Plan Ready because the
remaining work is not a small same-session change.

## Ordered actions

1. **Make configured GL role resolution fail closed — F-001**
   - Scope: **large**
   - Session recommendation: **separate-recommended**
   - Define a role-keyed Accounting policy for AP, AR, VAT Input, VAT Output,
     discount, revenue, and the downstream control accounts. Reject conflicting
     settings instead of letting a code-keyed match choose the first role.
     Route Payroll, Inventory, Assets, HR, Supply Chain, and Return Management
     configured lookups through the typed resolver, with active/type negative
     tests. Coordinate with M026 and each consuming module; do not fix those
     dependency paths in M025.

2. **Close the internal period-boundary gap — F-009**
   - Scope: **medium**
   - Session recommendation: **separate-recommended**
   - Decide whether the service contract is year 2000–2100 and reopen reasons
     3–500 characters for non-HTTP callers. Enforce the chosen contract in
     `AccountingPeriodService`, preflight existing data, and add compatible
     year/month/reason database protections without changing the intentional
     internal-error mapping.

3. **Align CSV metadata and status authority — F-003**
   - Scope: **medium**
   - Session recommendation: **separate-recommended**
   - Advertise every accepted COA column, including `is_active`, from the
     importer metadata. Decide whether `admin.import.manage` is a trusted
     migration exception to `accounting.coa.deactivate`; enforce that choice at
     the import boundary and test dry-run, commit, rollback, and inactive rows.
     The metadata lives in shared import infrastructure and needs its owner.

4. **Make the split COA permission contract usable — F-004**
   - Scope: **medium**
   - Session recommendation: **separate-recommended**
   - Decide whether view is an implied prerequisite for manage/status. If the
     grants are independent, provide an intentional read/status workflow and
     matching API guard; if not, encode the implication and update the row/API
     tests. Cover the actual route, sidebar discovery, account fetch, and not
     only the isolated `TreeRow` component.

5. **Resolve the open-period UI model — F-005**
   - Scope: **medium**
   - Session recommendation: **separate-recommended**
   - Decide whether absent rows are the complete representation of open months.
     Then either remove the misleading Open filter and add a deliberate
     month-picker/current-month close action, or materialize a bounded set of
     open months in the API/UI. Add tests for filtered empty states and closing
     a second absent month.

6. **Add the M025 HTTP/resource acceptance suite — F-007**
   - Scope: **large**
   - Session recommendation: **separate-recommended**
   - Cover tree/show/CRUD/status response shapes, HashIDs, FormRequest
     validation, parent/child policy, API RBAC, import metadata, and the real
     SPA route/page matrix. Keep cross-module posting assertions in their
     owning modules while requiring the shared typed-account contract.

7. **Add database guards for COA enum columns — F-010**
   - Scope: **medium**
   - Session recommendation: **separate-recommended**
   - Preflight existing `accounts.type` and `accounts.normal_balance` values,
     add PostgreSQL checks plus SQLite-compatible test guards, and retain the
     service/FormRequest validation for operator-facing errors.

8. **COA form contract alignment — F-011**
   - Scope: **small**
   - Session recommendation: **same-session-ok**
   - **Completed in this session under F-011.** The create form now validates
     the 3–6 digit code, five account types, debit/credit values, and 150-char
     names; the edit form accepts the backend's 150-char name limit.

9. **Duplicate-period test fixture isolation — F-012**
   - Scope: **small**
   - Session recommendation: **same-session-ok**
   - **Completed in this session under F-012.** The duplicate-period test now
     resets `RefreshDatabaseState::$migrated` after committing fixtures, so the
     next test class receives a fresh schema rather than leaked rows.

## Rechecked gates already satisfied

- Duplicate-period recovery starts a fresh transaction after a PostgreSQL
  unique violation (F-002); the focused test passed.
- Close/post and scheduler period locking use the same advisory/row-lock order,
  with real PostgreSQL interleavings (F-006); the focused suite passed.
- Period pagination now narrows the optional query response before reading
  metadata (F-008); the full SPA typecheck passed.
- Account hierarchy cycle/type/active-parent validation, generic status
  protection, importer delegation, and inactive-account posting protection are
  present and covered by the current service tests.

## Re-audit acceptance gates

- Conflicting configured roles fail closed, and every configured downstream
  control account is active and of its declared `AccountType` before a journal
  line is built.
- Internal period close/reopen calls cannot persist out-of-contract year,
  month, or reason values; database protections match the service contract.
- CSV metadata matches accepted importer payloads, and the chosen import/status
  authority is explicit and tested.
- Every supported COA permission combination has an intentional, reachable
  API and SPA workflow with no hidden status-only path.
- The periods surface has a test-covered representation of open months and no
  misleading filter/action combination.
- HTTP/resource acceptance tests prove HashID shape, route permissions,
  validation, and tree serialization.
- SPA COA/period pages typecheck and focused tests remain green on an isolated
  PostgreSQL database.

## Session decision

Do not implement the remaining actions in this claimed session. F-001, F-003,
F-004, F-005, F-007, F-009, and F-010 are separate-recommended because they
change financial classification, permissions, shared authority, period
semantics, or database contracts. Leave M025 at Plan Ready and re-audit after
those decisions and changes land.
