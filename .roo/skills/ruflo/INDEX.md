# Ruflo Skills Index

**Source:** [ruvnet/ruflo](https://github.com/ruvnet/ruflo) @ commit `f1a82d9ee327eaeb92f0f8d59ad17b1048695f55`
**License:** MIT (see [`vendor/ruflo/LICENSE`](../../../vendor/ruflo/LICENSE))
**Adapted for:** Roo (Claude Code tool names rewritten; see [`scripts/adapt-skill.sh`](../../../scripts/adapt-skill.sh))

To regenerate from upstream, run [`scripts/sync-ruflo.sh`](../../../scripts/sync-ruflo.sh).

## Why so few skills are ported

Ruflo is primarily a **multi-agent orchestration framework**: an MCP server, a daemon, an agentdb store, hook system, and a Cognitum.One backend, packaged as `npx ruvflo init`. Most of its 369 SKILL.md files (under `.claude/skills/`, `.agents/skills/`, and per-plugin `skills/` directories) assume that runtime is present and call into it (e.g. `npx claude-flow@alpha truth`, `swarm_init`, `memory_store`, `agentdb` MCP tools).

This Roo sandbox cannot embed that runtime: containers are ephemeral, no long-running daemons or MCP servers, and Roo does not consume Claude Code's MCP namespace. Skills that depend on those calls would be **broken-by-design** if vendored.

We therefore vendor **only skills that work as standalone process guidance** - things an agent can read and follow without a backing service.

## Ported skills (3)

| Skill | Use when |
|-------|----------|
| [`sparc-methodology`](sparc-methodology.md) | Multi-step development tasks where you want a structured Specification -> Pseudocode -> Architecture -> Refinement -> Completion flow. The methodology is portable; the multi-agent orchestration sections are aspirational here and should be read as "single-agent SPARC." |
| [`pair-programming`](pair-programming.md) | Driver/navigator-style sessions, real-time review while coding. Treat the truth-score / verification sections as guidance, not literal commands. |
| [`skill-builder`](skill-builder.md) | Authoring new skills (in this kwatog repo or upstream). Useful meta-skill for adding more entries under [`.roo/skills/`](../). |

## Not ported (34 skills under `.claude/skills/`)

All of the following depend on the ruflo runtime, MCP server, agentdb, or Cognitum.One backends that we cannot embed. They were intentionally **not** vendored:

```
agentdb-advanced              hive-mind-advanced            v3-cli-modernization
agentdb-learning              hooks-automation              v3-core-implementation
agentdb-memory-patterns       performance-analysis          v3-ddd-architecture
agentdb-optimization          reasoningbank-agentdb         v3-integration-deep
agentdb-vector-search         reasoningbank-intelligence    v3-mcp-optimization
agentic-jujutsu               stream-chain                  v3-memory-unification
flow-nexus-neural             swarm-advanced                v3-performance-optimization
flow-nexus-platform           swarm-orchestration           v3-security-overhaul
flow-nexus-swarm              verification-quality          v3-swarm-coordination
github-code-review            worker-benchmarks
github-multi-repo             worker-integration
github-project-management
github-release-management
github-workflow-automation
```

**Reason:** each requires `npx claude-flow@alpha`, MCP tool calls (e.g. `mcp__ruflo__*`), the ruflo daemon, agentdb, or external services like `flow-nexus`. None can run in this sandbox.

Also intentionally **out of scope**:

- The 98 agent personas under `.agents/skills/agent-*` - all require ruflo's swarm runtime to be useful as agents.
- The 33 per-plugin `plugins/ruflo-*/` skill bundles - tightly coupled to ruflo's plugin loader.
- `.claude-plugin/`, `.claude/commands/`, `.claude/agents/`, `.claude/hooks/`, `.claude/checkpoints/`, `.claude/helpers/`, `bin/`, daemon, MCP server, npm package - host-specific or runtime-specific.

If you ever want one of the not-ported skills, the practical path is **not** to vendor it but to install ruflo separately and use it inside Claude Code (its native host). That is a different workflow from what this kwatog repo enables.

## How to use

- **On demand:** ask the agent to "consult [`.roo/skills/ruflo/<skill>.md`](.)" or invoke by name.
- **Recurring task types:** switch to the `ruflo-sparc` custom mode (see [`.roomodes`](../../../.roomodes)).
- **Discover available skills:** `bash scripts/list-skills.sh`.

## Sync cadence

Pinned to a single upstream SHA. Refresh deliberately by editing `UPSTREAM_SHA` and (if desired) the `CURATED` array in [`scripts/sync-ruflo.sh`](../../../scripts/sync-ruflo.sh), then running it; review the diff before committing.
