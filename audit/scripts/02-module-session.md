# Module Audit Session (repeatable - run once per session, safe to run in parallel)

## Purpose
Run this at the start of every session, including multiple sessions launched
at the same time. Claude claims a module atomically before touching it, so
two sessions can never end up working on the same module or clobbering each
other's status updates.

## Step 1 - Refresh the view
Run `/audit/scripts/regenerate-registry.sh`, then read the freshly generated
`/audit/00-MODULE-REGISTRY.md`.

## Step 2 - Build a candidate list, in priority order
1. Modules already `🔍 Auditing In Progress` or `🔧 Fixing In Progress` and
   NOT currently locked (Locked? = no) - these were interrupted, resume them first.
2. Modules `🔁 Needs Re-audit`.
3. Modules `🔲 Not Started`, ordered by Tier, then by dependency order
   (don't start a module before something it depends on has at least been
   through discovery once).

Skip anything showing `🔒 yes` in the Locked column - another session already
owns it.

## Step 3 - Claim a module atomically
Take the first candidate and run:
```
/audit/scripts/claim-module.sh {domain} {module}
```
- **CLAIMED** or **RECLAIMED** → you own this module, proceed to Step 4.
  - If the result was RECLAIMED, a previous session likely crashed mid-work -
    read that module's `audit-report.md` / `action-plan.md` / `fix-log.md`
    first to see what was already done before continuing.
- **LOCKED** → another session claimed it in the moment between you reading
  the registry and trying to claim - move to the next candidate in your list
  and try again. This is expected occasionally when running sessions in
  parallel and is not an error.

State clearly which module you claimed and why.

## Step 4 - Audit (discovery → hardening → polish)
Scope strictly to this one module. You may READ dependency modules for
context but do not audit or modify them. Run three passes, evidence-grounded
with file:line citations, no assumptions:

1. **Discovery** - what exists, what's stubbed, what's missing entirely
2. **Hardening** - where the process/state machine can break, get stuck, skip
   validation, or violate conventions (Money as centavos, ULIDs,
   `DomainException(message, 'CODE', httpStatus)`, `StateMachine` TRANSITIONS,
   `DB::transaction()` on writes, RolePermissionSeeder-based checks)
3. **Polish** - frontend completeness per role, against `docs/DESIGN-SYSTEM.md`

Classify every finding as **Broken** / **Missing** / **Incomplete** / **Polish**.
Write to `{module}/audit-report.md`.

## Step 5 - Build the action plan
Write `{module}/action-plan.md`: ordered fix items, each tagged with:
- Scope: small / medium / large
- **Session recommendation**: `same-session-ok` or `separate-recommended`

Use these criteria:
- `separate-recommended` if it touches Money/financial calculations, changes
  a StateMachine's TRANSITIONS, affects permissions/RBAC, has cross-module
  side effects, or the plan is large enough that fixing everything now risks
  running low on context mid-fix
- `same-session-ok` if it's contained, low-risk, and the total plan is small

## Step 6 - Decide: fix now or hand off
- Majority `same-session-ok` + small total scope → proceed to Step 7.
- Otherwise → go to Step 8 now with status `📋 Plan Ready`, don't fix.

## Step 7 - Fix (only if same-session-ok)
Implement fixes per existing conventions. Log each to `{module}/fix-log.md`
(file:line, before/after summary). Re-check just the findings you fixed to
confirm resolved - not a full re-audit.
- All fixed and verified → status `✅ Verified`
- Some deferred (had `separate-recommended` items) → status `🔁 Needs Re-audit`

## Step 8 - Release the module
Always run this before ending the session, even if you stopped early or hit
an error:
```
/audit/scripts/release-module.sh {domain} {module} "{final status}"
/audit/scripts/regenerate-registry.sh
```
This writes the new status into that module's own `status.md`, removes the
lock, and refreshes the generated registry view - safe to run even while
other sessions are mid-claim on different modules, since each module's
status lives in its own file.

End with a short summary: what was found, what was fixed (if anything),
what's deferred and why, and which module you'd recommend next.

## Constraints
- Never modify files outside this module's scope.
- Never skip Step 8, even if the session gets cut short or errors out - an
  unreleased lock blocks that module for other sessions until it goes stale
  (6 hours, handled automatically by `claim-module.sh`).
- If uncertain whether something is a real bug vs. intentional design, flag
  it as a question in the audit report rather than guessing.
