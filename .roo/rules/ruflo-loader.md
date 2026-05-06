# Ruflo Skill Loader

This repository vendors a **small curated subset** of [ruvnet/ruflo](https://github.com/ruvnet/ruflo) skills under [`.roo/skills/ruflo/`](../skills/ruflo/). Most of upstream ruflo requires its runtime (MCP server, daemon, agentdb) which we do not embed; see [`.roo/skills/ruflo/INDEX.md`](../skills/ruflo/INDEX.md) for what was skipped and why.

## Triggers

- **Multi-step development task and the user explicitly wants the SPARC methodology, or the task is a greenfield feature large enough to benefit from Specification -> Pseudocode -> Architecture -> Refinement -> Completion** -> read [`.roo/skills/ruflo/sparc-methodology.md`](../skills/ruflo/sparc-methodology.md). Read it as **single-agent SPARC** in this repo; ignore the multi-agent orchestration sections that assume the ruflo runtime.
- **Driver/navigator pair-programming-style session, or continuous review while coding** -> consult [`.roo/skills/ruflo/pair-programming.md`](../skills/ruflo/pair-programming.md). Treat truth-score / verification commands as conceptual; we do not have ruflo's runtime here.
- **Authoring a new skill (in this repo or upstream)** -> read [`.roo/skills/ruflo/skill-builder.md`](../skills/ruflo/skill-builder.md) for the SKILL.md format and progressive-disclosure structure.

## How to honor a trigger

1. Recognize the trigger condition.
2. `read_file` the named skill before producing your plan or code.
3. Where the skill references ruflo runtime tools (`mcp__ruflo__*`, `npx claude-flow@alpha ...`, `swarm_init`, `memory_store`, agentdb), treat those as **conceptual guidance, not literal commands** in this Roo sandbox.
4. For SPARC-driven recurring work, prefer switching to the `ruflo-sparc` custom mode (see [`.roomodes`](../../.roomodes)).

If a project rule in [`.roo/rules/`](.) or [`CLAUDE.md`](../../CLAUDE.md) conflicts with a ruflo skill, the project rule wins; note the conflict in your reply.
