# Kwatog (Ogami ERP) Skills Index

Repo-local skills authored for this codebase specifically. Unlike the vendored [`superpowers/`](../superpowers/) and [`ruflo/`](../ruflo/) skills - which are stack-agnostic process guidance - these are concrete to **this** Laravel 11 + React 18 modular monolith.

To use these skills:

- Always-on signposting: [`.roo/rules/kwatog-loader.md`](../../rules/kwatog-loader.md) is auto-loaded every task and points the agent at the right skill for the trigger.
- On demand: ask the agent to "consult [`.roo/skills/kwatog/<name>.md`](.)" or invoke by name.
- Recurring: switch to a custom mode that embeds the skill in the system prompt (see [`.roomodes`](../../../.roomodes), e.g. `kwatog-quality-gate`).
- Discovery: `bash scripts/list-skills.sh`.

## Catalog (11 skills)

### Orientation

| Skill | Use when |
|-------|----------|
| [`overview`](overview.md) | Starting any kwatog task. Master map of `api/`, `spa/`, `docs/`, conventions, and where to look first. |

### Quality gate

| Skill | Use when |
|-------|----------|
| [`code-quality-gate`](code-quality-gate.md) | Before claiming any code change is done. Exact lint/typecheck/test commands per side. **Promoted to a Tier 1 mode** (`kwatog-quality-gate`) for strongest enforcement. |

### Building features

| Skill | Use when |
|-------|----------|
| [`add-api-endpoint`](add-api-endpoint.md) | Adding/modifying any endpoint under `api/app/Modules/`. File order, patterns, post-write verification. |
| [`add-spa-page`](add-spa-page.md) | Adding/modifying any SPA page under `spa/src/pages/`. File order, patterns, post-write verification. |
| [`add-database-migration`](add-database-migration.md) | Writing any migration. Numerical-prefix convention, FK constraints, reversibility, seed follow-up. |

### Cross-cutting concerns

| Skill | Use when |
|-------|----------|
| [`rbac-and-permissions`](rbac-and-permissions.md) | Adding, renaming, or gating any permission. Three enforcement points + seeding. |
| [`security-review`](security-review.md) | Touching user input, auth, file upload, money, or PII. Laravel + React threat-model checklist. |
| [`eloquent-performance`](eloquent-performance.md) | Writing or reviewing any Eloquent query. N+1 detection, eager loading, indexes. |
| [`spa-state-and-data-fetching`](spa-state-and-data-fetching.md) | Wiring data fetching, mutations, or state to any SPA page. react-query keying, invalidation, zustand vs server. |
| [`testing-strategy`](testing-strategy.md) | Writing any test. PHPUnit + Vitest split, minimum-viable-test-set per feature. |

### Workflow

| Skill | Use when |
|-------|----------|
| [`commit-and-pr`](commit-and-pr.md) | Before any commit/push/PR. Branch names, conventional commits, PR body, wait-for-CI. |

## How these compose with vendored skills

- **Discipline (HOW you work):** [`.roo/skills/superpowers/`](../superpowers/) - TDD, debugging, verification, planning. Generic.
- **Methodology (when structuring big work):** [`.roo/skills/ruflo/sparc-methodology.md`](../ruflo/sparc-methodology.md) - SPARC, single-agent.
- **Repo-specific (WHAT you write here):** these kwatog skills - exact files, commands, conventions.

A typical feature task touches all three:

1. Switch to `superpowers-tdd` mode (vendored, Tier 1).
2. Read [`overview`](overview.md) for orientation.
3. Read [`add-api-endpoint`](add-api-endpoint.md) (or [`add-spa-page`](add-spa-page.md)) for the workflow.
4. Read [`rbac-and-permissions`](rbac-and-permissions.md) if the change adds a permission.
5. Read [`security-review`](security-review.md) if user input is involved.
6. Run [`code-quality-gate`](code-quality-gate.md) before claiming done.
7. Read [`commit-and-pr`](commit-and-pr.md) for PR workflow.

## Adding a new kwatog skill

1. Identify a recurring kwatog-specific gap (project rule, command, gotcha) that the existing skills do not cover.
2. Add `.roo/skills/kwatog/<name>.md` following the front-matter format (`name`, `description` are required).
3. Update this INDEX.md with the new entry.
4. If the skill should fire on a clear trigger, add a one-liner to [`.roo/rules/kwatog-loader.md`](../../rules/kwatog-loader.md).
5. If adherence requires enforcement, add a custom mode in [`.roomodes`](../../../.roomodes).
6. Open a PR.

See the meta-skill [`.roo/skills/ruflo/skill-builder.md`](../ruflo/skill-builder.md) for guidance on skill anatomy and progressive disclosure.
