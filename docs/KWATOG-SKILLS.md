# Kwatog-Specific Skills

Repo-local agent skills authored for this codebase. They live under [`.roo/skills/kwatog/`](../.roo/skills/kwatog/) and complement the vendored [`superpowers/`](../.roo/skills/superpowers/) (generic discipline) and [`ruflo/`](../.roo/skills/ruflo/) (methodology) skills.

Unlike the vendored ones, these are **concrete to kwatog**: real file paths, real commands, real conventions extracted from [`docs/PATTERNS.md`](PATTERNS.md), [`docs/SCHEMA.md`](SCHEMA.md), existing modules in [`api/app/Modules/`](../api/app/Modules/), and existing pages in [`spa/src/pages/`](../spa/src/pages/).

## What's included (11 skills)

See [`.roo/skills/kwatog/INDEX.md`](../.roo/skills/kwatog/INDEX.md) for the full catalog. Summary:

- [`overview`](../.roo/skills/kwatog/overview.md) - orientation map for the repo
- [`code-quality-gate`](../.roo/skills/kwatog/code-quality-gate.md) - exact lint/test commands CI enforces (also promoted to the `kwatog-quality-gate` Tier 1 mode)
- [`add-api-endpoint`](../.roo/skills/kwatog/add-api-endpoint.md), [`add-spa-page`](../.roo/skills/kwatog/add-spa-page.md), [`add-database-migration`](../.roo/skills/kwatog/add-database-migration.md) - feature-building workflows
- [`rbac-and-permissions`](../.roo/skills/kwatog/rbac-and-permissions.md), [`security-review`](../.roo/skills/kwatog/security-review.md), [`eloquent-performance`](../.roo/skills/kwatog/eloquent-performance.md), [`spa-state-and-data-fetching`](../.roo/skills/kwatog/spa-state-and-data-fetching.md) - cross-cutting concerns
- [`testing-strategy`](../.roo/skills/kwatog/testing-strategy.md), [`commit-and-pr`](../.roo/skills/kwatog/commit-and-pr.md) - quality workflow

## How agents use them

Three usage patterns (see also [`AGENT-TOOLS-ADHERENCE.md`](AGENT-TOOLS-ADHERENCE.md) for the calibration model):

### 1. Always-on signposting

[`.roo/rules/kwatog-loader.md`](../.roo/rules/kwatog-loader.md) is auto-loaded into the system prompt every task. It lists trigger conditions (e.g. "when writing a migration -> read `add-database-migration.md`") so the agent consults the right skill automatically.

### 2. On-demand

Ask the agent in plain language:

- *"Use the kwatog add-api-endpoint skill for this new /crm/vendors endpoint."*
- *"Before we commit, apply the security-review checklist."*
- *"Run the quality gate."*

### 3. Custom mode (strongest)

Switch in the Roo Code mode selector to **🛡️ Kwatog Quality Gate** whenever the task touches code in `api/` or `spa/`. The gate commands are embedded in the mode's system prompt; the agent must run them and report results before claiming done.

Combine with vendored modes for full coverage:

- **🧪 Superpowers TDD** for new feature work (enforces red-green-refactor)
- **🔬 Superpowers Debug** for bugs (enforces root-cause-first)
- **🧭 Ruflo SPARC** for structured greenfield work
- **🛡️ Kwatog Quality Gate** for the final "is it done" gate

A typical feature task: switch to `superpowers-tdd` during implementation, then switch to `kwatog-quality-gate` before declaring done.

## Composition with canonical docs

The kwatog skills intentionally do **not** duplicate [`docs/PATTERNS.md`](PATTERNS.md) or [`docs/SCHEMA.md`](SCHEMA.md). Instead, they:

- Tell the agent **which PATTERNS.md section to read** for the layer they're building.
- Add the **workflow** (file order, verification commands, post-write checks).
- Capture the **gotchas** that PATTERNS.md does not (permission seeding, N+1 detection, query key stability).

If PATTERNS.md and a skill appear to conflict, PATTERNS.md wins and the skill should be updated.

## Adding a new skill

The bar for a new kwatog skill is a recurring kwatog-specific gap not covered by the existing 11. Process:

1. Identify the gap from real usage (ideally via the adherence log in [`AGENT-TOOLS-ADHERENCE.md`](AGENT-TOOLS-ADHERENCE.md)).
2. Author `.roo/skills/kwatog/<name>.md` with front-matter `name` + `description` and short, imperative body. Keep under ~200 lines.
3. Update [`.roo/skills/kwatog/INDEX.md`](../.roo/skills/kwatog/INDEX.md) and add a trigger line to [`.roo/rules/kwatog-loader.md`](../.roo/rules/kwatog-loader.md) if the skill has a clear trigger condition.
4. If enforcement matters (the skill is easy to skip), promote to a custom mode in [`.roomodes`](../.roomodes).
5. Open a PR with `docs(kwatog-skills): add <name> skill` following [`.roo/skills/kwatog/commit-and-pr.md`](../.roo/skills/kwatog/commit-and-pr.md).

## Maintenance

Unlike the vendored skills ([`SUPERPOWERS.md`](SUPERPOWERS.md), [`RUFLO.md`](RUFLO.md)), kwatog skills have no upstream to sync from. They evolve with this codebase:

- When [`docs/PATTERNS.md`](PATTERNS.md) changes a pattern, review the skills that point at it.
- When a new CI step is added, update [`code-quality-gate.md`](../.roo/skills/kwatog/code-quality-gate.md).
- When a new convention emerges (for example, a new preferred form lib), update the relevant skill in the same PR that introduces the convention.

Periodic review: during sprint planning, scan [`.roo/skills/kwatog/INDEX.md`](../.roo/skills/kwatog/INDEX.md) for skills that have become stale.

## Honest adherence caveat

Same caveat as the vendored skills: agent adherence to procedural guidance is **probabilistic, not deterministic**. The quality-gate mode approaches enforcement; the tier-2 loader rule is strong but not guaranteed. See [`AGENT-TOOLS-ADHERENCE.md`](AGENT-TOOLS-ADHERENCE.md) for the calibration model and the adherence test protocol.

If you notice the agent routinely skipping the gate or a skill, the escalation order is:

1. Make the trigger in [`.roo/rules/kwatog-loader.md`](../.roo/rules/kwatog-loader.md) more imperative and specific.
2. Promote the skill to a custom mode in [`.roomodes`](../.roomodes).
3. If still weak, add a CI check that enforces it independently of the agent.
