---
name: code-quality-gate
description: Use BEFORE claiming any code change is complete. Runs the exact lint, typecheck, and test commands the kwatog CI enforces, in the right working directories, with the right strictness flags. Skipping this is the #1 cause of broken PRs.
---

# Code Quality Gate (kwatog)

## Iron Law

**No work is "done" until the relevant gate commands have run locally and passed.**

CI will block the PR otherwise. Saying "tests should pass" without running them is rationalization.

## Gate matrix - run only what your change touched

### If you modified anything under [`api/`](../../../api/)

```bash
# All from the api/ directory:
cd api
./vendor/bin/pint --test                 # formatter check (no auto-fix in gate)
./vendor/bin/phpstan analyse              # larastan static analysis (if phpstan.neon exists; otherwise skip)
php artisan test                          # PHPUnit feature + unit suite
```

What CI runs: PHPUnit on PR via [`.github/workflows/api-tests.yml`](../../../.github/workflows/api-tests.yml).

### If you modified anything under [`spa/`](../../../spa/)

```bash
# All from the spa/ directory:
cd spa
npm run typecheck                         # tsc --noEmit, must be zero errors on touched files
npm run lint                              # eslint with --max-warnings 0
npm run test -- --run                     # vitest one-shot (do NOT leave watch mode running)
npm run build                             # vite production build, must succeed
```

What CI runs: typecheck (continue-on-error for legacy), lint (continue-on-error for legacy), vitest (enforced), build (enforced) via [`.github/workflows/spa-tests.yml`](../../../.github/workflows/spa-tests.yml).

> Lint and typecheck are baseline-grandfathered in CI to avoid blocking on legacy debt. **Do not add new warnings or type errors in your change**, even if the legacy total stays untouched. If the count goes up because of your diff, fix it.

### Shortcut for full gate (Makefile)

```bash
make test       # phpunit + vitest
make lint       # eslint + pint dry-run
make analyse    # larastan + tsc --noEmit
```

These mirror the CI scope; when in doubt, run all three.

## Order of operations

1. Identify which side of the codebase you changed (api, spa, or both).
2. Run the gate commands for that side from the correct working directory.
3. **Read the output, do not just check the exit code.** Warnings about deprecations, new lint warnings, or skipped tests are signals to investigate before claiming done.
4. If anything fails: fix it. Do not push and "see if CI catches it" - CI is not a debugger.
5. Only after a clean run do you commit, push, and open the PR.

## Common shortcuts that are NOT acceptable

- "It compiles" - compilation is not a gate. Tests are.
- "I only touched docs" - if your diff touches a `.md` file, no gate is needed; if it also touches code, the gate applies.
- "I'll fix the lint warning later" - later means now.
- "The test was already failing on main" - check `git log main -- <test path>`. If true, raise it explicitly in the PR; do not claim done quietly.

## When the gate is honestly not applicable

- Changes purely under [`docs/`](../../../docs/), [`plans/`](../../../plans/), [`.roo/`](../../), or [`README.md`](../../../README.md): no gate needed.
- Changes purely under [`docker/`](../../../docker/) or [`docker-compose*.yml`](../../../docker-compose.yml): try `docker compose config` to validate; full gate not applicable.
- Changes to `.github/workflows/*.yml`: validate YAML syntax; trigger via a draft PR to confirm.

In all other cases, the gate applies.

## Reporting the gate

When you finish work, your completion message must include the gate commands you actually ran and their result. Format:

```
Gate run:
  api/  -> ./vendor/bin/pint --test  PASS
        -> php artisan test           PASS (132 tests)
  spa/  -> npm run typecheck          PASS
        -> npm run lint               PASS (0 warnings)
        -> npm run test -- --run      PASS (47 tests)
        -> npm run build              PASS
```

If something is skipped, say so explicitly with the reason. "I forgot" is not a reason.

## Escalation

If the same gate command fails repeatedly with no obvious fix, switch to the `🔬 Superpowers Debug` mode and apply the systematic-debugging skill. Do **not** disable a test or relax a lint rule to make the gate pass; that is sabotage.
