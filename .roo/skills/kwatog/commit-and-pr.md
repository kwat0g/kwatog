---
name: commit-and-pr
description: Use before any git commit, push, or PR for kwatog. Codifies branch naming, conventional-commit prefixes, PR body expectations, the kwat0g/kwatog repo target, and the wait-for-CI step.
---

# Commit and PR Workflow (kwatog)

## Branch protection (non-negotiable)

- **NEVER commit directly to `main`.** All work goes through a feature branch and a PR.
- Repo target is **`kwat0g/kwatog`**. Always pass `--repo kwat0g/kwatog` to `gh` commands.
- Branch must be pushed and a PR opened before claiming work is done; otherwise the work is lost when the sandbox container dies.

## Branch names

`<type>/<short-kebab-summary>`

Types: `feat`, `fix`, `chore`, `docs`, `refactor`, `test`, `perf`, `ci`, `build`.

Examples:

- `feat/crm-product-archival`
- `fix/invoice-pdf-overflow`
- `chore/bump-laravel-11-21`
- `docs/sprint-9-checklist`

## Commits - conventional-commit format

```
<type>(<scope>): <imperative summary, lowercase, no trailing period>

<optional body explaining WHY, not what>

<optional footer: BREAKING CHANGE: ..., Refs: #N>
```

Scopes commonly used in kwatog (match what already shows up in `git log`):

- `crm`, `accounting`, `hr`, `inventory`, `purchasing`, `quality`, `maintenance`, `assets`, `attendance`, `payroll`, `auth`, `admin`
- `api` (cross-module backend), `spa` (cross-module frontend)
- `ci`, `docs`, `infra`, `schema`, `seed`

Good examples:

- `feat(crm): add part-number prefix validation to product create`
- `fix(accounting): prevent invoice paid total exceeding gross`
- `chore(spa): bump react-query to 5.59.x`
- `docs(patterns): add filter-bar pattern to PATTERNS.md`

Each commit is one logical change. If you find yourself writing `and` in the summary, split it.

## Before you push

Run [`code-quality-gate.md`](code-quality-gate.md). The PR body must include the gate result. CI will fail otherwise; fixing in a follow-up commit pollutes the history.

Also confirm:

- No `.env`, secret, or credential committed (check `git diff --cached`).
- No `console.log`, `dd()`, `dump()`, or commented-out blocks left behind.
- No unrelated changes piggybacking on this PR.

## Pushing

```bash
git push -u origin HEAD:<feature/branch-name>
```

Never force-push to `main`. Force-pushing your own feature branch is fine if it has not been opened as a PR yet, or if rebased intentionally; coordinate with reviewers if it has been pushed already.

## Opening the PR

```bash
gh pr create \
  --repo kwat0g/kwatog \
  --head <feature/branch-name> \
  --title 'feat(<scope>): <one-line summary>' \
  --body "$(cat <<'EOF'
## Summary
<what changed and why>

## Linked
<issue / sprint task IDs, e.g. Refs: docs/TASKS.md task 47>

## Gate run
api/  -> ./vendor/bin/pint --test  PASS
      -> php artisan test           PASS
spa/  -> npm run typecheck          PASS
      -> npm run lint               PASS
      -> npm run test -- --run      PASS
      -> npm run build              PASS

## Manual checks
- Hit the new endpoint via curl / SPA: PASS
- Permission check (denied user gets 403): PASS

## Risk
<low / medium / high; what could break>

## Rollback
<one-line plan if this needs revert>
EOF
)"
```

The PR body is non-negotiable. Reviewers do not chase you for what your PR does; you tell them.

## After opening - wait for CI

```bash
gh pr checks <PR-NUMBER> --repo kwat0g/kwatog
```

If CI fails:

- **Read the actual logs.** "Tests fail" is not a diagnosis.
- Fix locally with the gate command. Push the fix as a new commit (do not force-push without warning).
- Re-check.

Only after CI is green and reviews approve does the PR merge. Squash-merge is the default.

## Comments on the PR

```bash
gh pr comment <PR-NUMBER> --repo kwat0g/kwatog --body 'CI is green; ready for review.'
```

For long replies, use a markdown body file or `--body-file`.

## Reverts

If a merged PR breaks main:

```bash
git revert -m 1 <merge-commit-sha>
git push origin HEAD:revert/<original-branch-name>
gh pr create --repo kwat0g/kwatog --head revert/<original-branch-name> --title 'Revert "<original PR title>"' --body 'Reverts #<PR-NUMBER>; reason: ...'
```

Do not revert silently. Always tell the original author and explain in the revert PR body.

## Pre-existing project rule on shell escaping

When commit messages or PR bodies contain shell metacharacters (`$`, backticks, quotes), use single-quotes, `printf %q`, or heredocs with single-quoted delimiters. The global Roo rules already cover this; do not paste long bodies inline without escaping.
