# M003 — User Administration Action Plan

Status: `🔁 Needs Re-audit`

Implementation pass completed in the dedicated module session. The dominant
security/RBAC, password-delivery, audit-trail, and frontend interaction fixes
are recorded in `fix-log.md`.

## Ordered fixes

1. **Close privileged-role escalation and administrator lockout paths**
   - Findings: UA-B01, UA-B02
   - Scope: large
   - Session recommendation: `separate-recommended`
   - Define and enforce who may assign `system_admin`, prohibit self-role
     downgrade/self-deactivation unless an explicit break-glass policy allows
     it, and protect the last active system administrator. Lock target/role
     rows inside the transaction and add tests for delegated managers,
     self-targeting, last-admin removal, and system-admin assignment.

2. **Make password reset delivery failure recoverable**
   - Findings: UA-B03
   - Scope: medium
   - Session recommendation: `separate-recommended`
   - Choose one safe policy: return a one-time temporary credential through the
     same controlled response path as creation, or roll back/mark the reset and
     surface a clear admin recovery state. Add notification-failure tests and
     preserve password-history/session-expiry invariants.

3. **Audit every administrative account mutation**
   - Findings: UA-I01
   - Scope: medium
   - Session recommendation: `separate-recommended`
   - Add transactional audit records for create, unlock, activate, deactivate,
     and admin reset-password, with actor, target, old/new state, reason, IP,
     and user-agent. Keep role-change audit semantics consistent and test each
     action.

4. **Validate hashed IDs and map stale/deleted references cleanly**
   - Findings: UA-I02
   - Scope: medium
   - Session recommendation: `separate-recommended`
   - Validate active roles before create/bulk update, report missing users in
     bulk responses, and return stable 404/422/409 responses instead of raw
     database failures or silent filter no-ops. Add concurrent duplicate-email
     coverage if the endpoint keeps its current uniqueness contract.

5. **Reconcile user-management and per-user-override authority**
   - Findings: UA-B04, UA-I03, UA-I04
   - Scope: large
   - Session recommendation: `separate-recommended`
   - Decide whether role assignment and permission overrides are system-admin
     only or delegable. Align route middleware, FormRequest authorization,
     role-option endpoints, and the frontend guards. Make restore use trashed
     binding and verify the `{user}` parent before restoring; add authorization,
     restore, and delegated-role tests.

6. **Add a deliberate role-change interaction**
   - Findings: UA-I05
   - Scope: medium
   - Session recommendation: `separate-recommended`
   - Require confirmation and a meaningful reason for single-user role changes,
     show conflict recovery, and keep the server-side optimistic concurrency
     check as the source of truth.

7. **Decide and implement the admin profile correction contract**
   - Findings: UA-I06
   - Scope: medium
   - Session recommendation: `separate-recommended`
   - Confirm whether admin may edit standalone user name/email. If yes, add a
     validated update endpoint/form with uniqueness and audit behavior; if no,
     document the immutable-source rule and provide the supported correction
     path for standalone accounts.

8. **Consolidate or permission-split the Users & Roles hub**
   - Findings: UA-I07
   - Scope: medium
   - Session recommendation: `separate-recommended`
   - Either remove the duplicate `/admin/users-roles` hub in favor of the
     canonical pages or guard each tab/link by its actual permission and add it
     deliberately to navigation.

9. **Finish the low-risk frontend polish pass**
   - Findings: UA-P01, UA-P02 and the department-filter gap in Discovery
   - Scope: small
   - Session recommendation: `same-session-ok`
   - Replace visible `LuUser` copy, add role-query loading/error states, and
     expose or explicitly remove the already-supported department filter.

## Verification state

- Targeted backend feature classes pass against the running Compose PostgreSQL
  service: 27 tests and 110 assertions.
- Static RBAC/API-route checks, PHP checks, SPA typecheck/lint, and the SPA
  production build pass.
- The role-permission browser audit was attempted but was inconclusive because
  the local Nginx/SPA/Reverb containers were killed mid-run. Re-run the browser
  audit against a stable local or preview stack before marking this module
  `✅ Verified`.
