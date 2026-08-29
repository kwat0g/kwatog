# M003 — User Administration Action Plan

Status: `🔁 Needs Re-audit`
Last revised: 2026-08-30 re-audit.

The 2026-08-25 implementation pass closed all nine items of the original plan;
re-measurement on 2026-08-30 confirms **9 of 9 no longer reproduce** (see the
matrix at the top of the re-audit section in `audit-report.md`). The original
ordered list is therefore retired. What follows is the plan for the findings the
re-audit *added*.

## How `separate-recommended` was weighted here

M003 is **Tier 1**: every module authenticates and authorises through the objects
this one writes. A change to who may assign a role, or to what the authenticated
guard admits, changes the meaning of every other module's `permission:` gate at
once — there is no blast radius smaller than "the whole system". So the bar for
`same-session-ok` was set at *adding a missing containment check inside M003's
own files, with a test, and no change to any permission's meaning*. Everything
that alters the authority model, the authentication stack, or a file in another
module is gated, even where the diff would be small — a three-line middleware
that rejects inactive users is a small diff with a system-wide failure mode.

Two items met that bar and were fixed (items 8 and 9 below, both marked DONE).

## Ordered fixes

1. **Stop the HR account-deactivation path from removing the last administrator**
   - Findings: UA-B05 (P0)
   - Scope: medium
   - Session recommendation: `separate-recommended`
   - **Cross-module: the code is in `api/app/Modules/HR/`, not this module.**
     Extract the last-administrator invariant out of
     `UserAdminService::assertAtLeastOneActiveSystemAdminRemains()` into a shared
     service and call it from `UserProvisioningService::deactivateForEmployee()`
     so both surfaces share one implementation — do not copy the check. Cover all
     four call paths, and decide explicitly what the **clearance-completion
     listener** should do when the separating employee holds the sole
     administrator account: refusing silently in a queued listener is as bad as
     proceeding. Needs the M003 and HR owners in the same change.

2. **Bound `admin.users.manage` by the actor's own authority**
   - Findings: UA-B06
   - Scope: large
   - Session recommendation: `separate-recommended`
   - This is a redesign of role semantics (may actor A administer a target whose
     role out-ranks A's?), not a missing guard. Requires a human decision first —
     see the question in UA-B06 — because the two candidate answers differ in
     kind: a permission-subset comparison, or replacing the plaintext credential
     return with out-of-band delivery. Whatever is chosen must not regress
     UA-B03, whose fix *is* the plaintext return.

3. **Re-check `is_active` on every authenticated internal request**
   - Findings: UA-M01
   - Scope: small (code) / large (risk)
   - Session recommendation: `separate-recommended`
   - Alters the authentication flow for **every** route in the system; a mistake
     logs everyone out. Belongs with `auth-session` (M001), whose middleware
     stack it joins, and should land while that module is being verified rather
     than from here. Add a driver-independent test so the guarantee stops
     depending on `SESSION_DRIVER=database` — note the suite runs `array`, which
     is why this was invisible.

4. **Translate the last-admin deadlock into a 409**
   - Findings: UA-I08
   - Scope: medium
   - Session recommendation: `separate-recommended`
   - Touches lock ordering inside financial-grade `DB::transaction()` blocks that
     other concurrency tests pin. Order the administrator-set lock before the
     individual target lock (or sort the set lock deterministically), then assert
     the loser gets 409 in the existing fork harness. Changing lock order is
     exactly the kind of edit that wants its own session and its own full
     concurrency run.

5. **Decide the user delete/restore contract**
   - Findings: UA-M02
   - Scope: medium
   - Session recommendation: `separate-recommended`
   - Answer the question in UA-M02 first. If users are intentionally never
     deleted, document it and consider whether `SoftDeletes` should stay on
     `User` at all — removing a trait from the auth model is a cross-module
     change. If they may be deleted, the surface needs destroy + restore +
     `withTrashed()` binding + a last-admin guard + a session-revocation rule.

6. **Settle self-service inside the admin surface**
   - Findings: UA-M03
   - Scope: small
   - Session recommendation: `separate-recommended`
   - Small diff, but it is a policy question (may an administrator change their
     own login email without re-authentication?) and the answer should match
     whatever `auth-session` does for self-service email changes. Reclassified
     from "add the missing guard" precisely because the sibling verbs' behaviour
     may be the thing that is wrong, not `updateProfile`'s.

7. **Fix the role-less first-role assignment**
   - Findings: UA-I11
   - Scope: medium
   - Session recommendation: `separate-recommended`
   - Requires defining optimistic concurrency against a null baseline on both
     sides of the contract. Do not "fix" it by making `expected_role_id`
     nullable without deciding what a conflict means when there was no prior
     role.

8. **Stop `RbacConcurrencyTest` disarming later tests** — **DONE 2026-08-30**
   - Findings: UA-I09
   - Scope: small
   - Session recommendation: `same-session-ok`
   - One `tearDownAfterClass()` in a test file in this module's own test area,
     with an existing precedent to copy and a red-then-green proof. No production
     code, so no cross-module risk at all. See `fix-log.md` §R1.

9. **Finish the frontend polish the prior pass half-landed** — **PARTLY DONE 2026-08-30**
   - Findings: UA-P04 (done), UA-P05, UA-P06
   - Scope: small
   - Session recommendation: `same-session-ok`
   - `LuUser` in the `/admin/users` empty-state registry is fixed
     (`fix-log.md` §R2). Still open, both deliberately left: deleting the
     unreachable `spa/src/pages/admin/users-roles.tsx` (UA-P05) is a file removal
     that wants its own reviewable commit rather than a ride-along, and the
     `header: 'LuUser'` on `/admin/sessions` is another module's page.
     UA-P06 is a one-string copy fix that belongs with them.

10. **Normalise 404 bodies system-wide**
    - Findings: UA-P03
    - Scope: medium
    - Session recommendation: `separate-recommended`
    - Cross-cutting: it is Laravel's default `ModelNotFoundException` message, so
      the fix is one handler in `bootstrap/app.php` affecting every module's route
      binding. No raw integer ids leak today, so this is hardening, not a live
      oracle.

11. **Lockout DoS on the sole administrator**
    - Findings: UA-I10
    - Scope: medium
    - Session recommendation: `separate-recommended`
    - Belongs to `auth-session`. Needs a policy decision (a break-glass unlock
      path? exempt the last administrator from lockout and rely on throttling
      instead?), not a code tweak.

## Verification state

- `tests/Feature/Admin` on an isolated database: **149 passed, 614 assertions.**
- New `UserAdministrationEscalationTest`: 13 tests / 117 assertions, one of them
  proven red against unmodified source.
- Static: `php -l`, `phpstan` (clean), `pint --test` (clean on changed files;
  `RbacConcurrencyTest`'s Pint failure proven pre-existing at HEAD), SPA
  `typecheck` + `eslint`.
- **Standing blocker on `✅ Verified`:** `npm run audit:role-permissions`
  (13-role browser walk) has never completed for this module. It needs the
  Nginx/SPA stack, which is deliberately down for this pipeline. Run it against a
  stable local or preview stack before promoting the status.
