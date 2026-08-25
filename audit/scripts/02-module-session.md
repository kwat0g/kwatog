# Module Audit Session (repeatable - run once per session, safe to run in parallel)

## Purpose
Run this at the start of every session, including multiple sessions launched
at the same time. Claude claims a module atomically before touching it, so
two sessions can never end up working on the same module or clobbering each
other's status updates.

## Step 1 - Refresh the view
Run `audit/scripts/regenerate-registry.sh` (paths are repo-relative - there is
no `/audit` at the filesystem root), then read the freshly generated
`audit/00-MODULE-REGISTRY.md`.

**If several sessions are being launched at once, ONE coordinator runs this
step, once, and hands each session its module.** `regenerate-registry.sh`
truncates the registry and then appends row-by-row, so a concurrent reader sees
a partial table and picks the wrong candidate. The script's own header comment
says there is "nothing for parallel sessions to clobber" - that is true of the
final file, false mid-write.

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
audit/scripts/claim-module.sh {domain} {module}
```
- **CLAIMED** or **RECLAIMED** → you own this module, proceed to Step 4.
  - If the result was RECLAIMED, a previous session likely crashed mid-work -
    read that module's `audit-report.md` / `action-plan.md` / `fix-log.md`
    first to see what was already done before continuing.
- **LOCKED** → another session claimed it in the moment between you reading
  the registry and trying to claim - move to the next candidate in your list
  and try again. This is expected occasionally when running sessions in
  parallel and is not an error.

**An orphaned lock does NOT mean nothing happened.** Observed in 4 of 4 modules
resumed on 2026-08-26: the crashed session had already applied substantial
fixes to the working tree and left `fix-log.md` as the blank scaffold. So the
registry status understates reality and `action-plan.md` is stale in a way that
reads as fresh. Before trusting either, `git diff` the module's files and
compare their mtimes against the lock's `claimed-at.txt`. In
`platform/backups-system-settings`, 6 of 8 fixes that session were bugs *in the
inherited unlogged code* - including a restore path that had never once worked.

`claim-module.sh` takes stale-hours as `$3` (default 6). To reclaim a lock you
have established is an orphan, pass `0`:
```
audit/scripts/claim-module.sh {domain} {module} 0
```
Never `rm -rf` a lock by hand - the script's `mkdir` race is what makes claims
atomic, and removing the directory sidesteps it.

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
(file:line, before/after summary) **as you finish it, not at the end of the
session.** Both sessions killed by an API quota error on 2026-08-26 died with
their code and tests complete and their log unwritten - logging last means a
crash loses the record of everything that was done. Re-check just the findings
you fixed to confirm resolved - not a full re-audit.
- All fixed and verified → status `✅ Verified`
- Some deferred (had `separate-recommended` items) → status `🔁 Needs Re-audit`

**Tests: use your own database, never the shared `ogami_test`.**
`RefreshDatabase` runs `migrate:fresh`, so a second suite tears the schema down
under the first. The tell is hundreds of failures with zero assertion failures
among them.
```
docker compose exec -T db psql -U ogami -d postgres -c "CREATE DATABASE ogami_test_<you> OWNER ogami;"
docker compose exec -T -e DB_DATABASE=ogami_test_<you> api php artisan test --filter='...'
```
Scope with `--filter`; leave the full suite to the coordinator.

## Step 7b - Commit before releasing
```
git add <this module's files, INCLUDING any new classes it introduced>
git commit -m "fix({module}): {summary}"
```
**Never leave a finished module uncommitted.** A crashed session that committed
loses nothing; one that did not leaves work the next session cannot see, and
that session will build new bugs on top of it.

New classes must be committed *with the module that needs them*. On 2026-08-26
the tree had accumulated 985 changed files and 98 new untracked classes across
~50 sessions that never committed. Because those classes were untracked and
interleaved, the work could not afterwards be split into per-module commits at
all - each module's commit failed to build without classes belonging to other
sessions (`BindingResolutionException: Target class [...] does not exist`). The
whole three-and-a-half days had to be committed as one unreviewable lump.

If you are one of several parallel sessions, commit only your own module's
files by explicit path. Never `git add -A`, `git stash`, or `git checkout` - the
working tree is shared and those would swallow or destroy another session's
in-flight edits.

## Step 8 - Release the module
Always run this before ending the session, even if you stopped early or hit
an error:
```
audit/scripts/release-module.sh {domain} {module} "{final status}"
```
This writes the new status into that module's own `status.md` and removes the
lock - safe to run even while other sessions are mid-claim on different
modules, since each module's status lives in its own file.

Then refresh the generated registry view:
```
audit/scripts/regenerate-registry.sh
```
**Skip that second command if you are one of several parallel sessions** - see
Step 1. Release is per-module and parallel-safe; registry regeneration is not.
Leave it to the coordinator to run once when every session has finished.

End with a short summary: what was found, what was fixed (if anything),
what's deferred and why, and which module you'd recommend next.

## Constraints
- Never modify files outside this module's scope.
- Never skip Step 7b. An uncommitted module is the single failure that
  compounds: it is invisible to the next session, which then re-fixes blind or
  layers new defects on unreviewed code.
- Never skip Step 8, even if the session gets cut short or errors out - an
  unreleased lock blocks that module for other sessions until it goes stale
  (6 hours, handled automatically by `claim-module.sh`).
- If uncertain whether something is a real bug vs. intentional design, flag
  it as a question in the audit report rather than guessing.
- If you hit a breakage that is genuinely outside your module and blocks
  everyone (a migration that fails `migrate:fresh`, a missing return that
  throws on every call), do NOT fix it and do NOT delete it. Report it to the
  coordinator with the exact error, and work around it locally in a way you can
  prove you reverted (`diff -q` / `sha256sum -c`).
