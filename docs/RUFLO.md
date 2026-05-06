# Ruflo Integration

This repo vendors a **deliberately small** subset of [ruvnet/ruflo](https://github.com/ruvnet/ruflo), an MIT-licensed multi-agent orchestration framework for Claude Code.

## Why only a subset

Ruflo is primarily a **runtime**: an MCP server, a daemon, an agentdb store, hooks, plugins, and a Cognitum.One backend, packaged as `npx ruvflo init`. Most of its 369 SKILL.md files assume that runtime is present and call into it (`npx claude-flow@alpha truth`, `swarm_init`, `memory_store`, MCP tools like `mcp__ruflo__*`).

This Roo sandbox cannot embed that runtime:

- Containers are ephemeral - daemons would die between tasks.
- Roo does not consume Claude Code's MCP namespace.
- Long-running processes are not allowed.

Vendoring those skills as-is would produce **broken-by-design** content: the agent would reach for tools that don't exist. We therefore vendor **only skills that work as standalone process guidance** with no runtime dependency.

## What got vendored

- **Skill content (3 skills):** [`.roo/skills/ruflo/`](../.roo/skills/ruflo/)
  - [`sparc-methodology.md`](../.roo/skills/ruflo/sparc-methodology.md)
  - [`pair-programming.md`](../.roo/skills/ruflo/pair-programming.md)
  - [`skill-builder.md`](../.roo/skills/ruflo/skill-builder.md)
  - Full inventory and not-ported list: [`.roo/skills/ruflo/INDEX.md`](../.roo/skills/ruflo/INDEX.md).
- **Loader rule:** [`.roo/rules/ruflo-loader.md`](../.roo/rules/ruflo-loader.md) - signposts these skills with conservative wording (treats runtime references as conceptual).
- **Custom mode:** `ruflo-sparc` in [`.roomodes`](../.roomodes), adapted to a **single-agent** SPARC workflow.
- **Provenance:** [`vendor/ruflo/`](../vendor/ruflo/) (LICENSE, trimmed README, pinned SHA).
- **Sync tooling:** [`scripts/sync-ruflo.sh`](../scripts/sync-ruflo.sh).

## What was deliberately skipped

- 34 runtime-bound skills under `.claude/skills/` (agentdb-*, swarm-*, hive-mind-*, github-*, performance-analysis, verification-quality, etc.) - all require ruflo's runtime.
- 98 agent personas under `.agents/skills/agent-*` - require ruflo's swarm orchestrator.
- 33 per-plugin skill bundles under `plugins/ruflo-*/` - tightly coupled to ruflo's plugin loader.
- The npm package, daemon, MCP server, hooks, commands, and `bin/` scripts.

If you need any of the not-ported skills, the right path is **not** to try forcing them into Roo - install ruflo separately and use it inside Claude Code, its native host. That is a different workflow from this kwatog repo.

## How agents use it

### Per-task

Ask the agent to consult a specific skill, e.g. "use the SPARC methodology for this refactor."

### Recurring

Switch into the `ruflo-sparc` custom mode for the duration of a structured-development task.

### Single-agent SPARC interpretation

When the skill text talks about multi-agent orchestration, swarms, memory stores, or `mcp__ruflo__*` calls, treat those as **conceptual guidance**, not literal commands. The custom mode and the loader rule both spell this out. In practice you will:

- Persist context in repo files (under [`docs/`](.) or [`plans/`](../plans/)) instead of ruflo's `memory_store`.
- Run phases sequentially yourself instead of dispatching to a swarm.
- Optionally switch to `superpowers-tdd` for the Refinement phase.

## Adding a vendored ruflo skill

1. Confirm the skill is genuinely standalone (no `npx claude-flow@alpha`, no `mcp__ruflo__*` calls, no daemon assumptions).
2. Add its name to the `CURATED` array in [`scripts/sync-ruflo.sh`](../scripts/sync-ruflo.sh).
3. Run `bash scripts/sync-ruflo.sh`.
4. Update [`.roo/skills/ruflo/INDEX.md`](../.roo/skills/ruflo/INDEX.md).
5. If runtime references slipped through, either edit them out manually post-adapt or extend [`scripts/adapt-skill.sh`](../scripts/adapt-skill.sh).
6. Open a PR.

## Sync cadence

Pinned to a single upstream SHA. Refresh deliberately by editing `UPSTREAM_SHA` in [`scripts/sync-ruflo.sh`](../scripts/sync-ruflo.sh) and re-running. Review the diff carefully; ruflo evolves rapidly and curated picks may need re-vetting after a sync.
