# Superpowers Skills Index

**Source:** [obra/superpowers](https://github.com/obra/superpowers) @ commit `f2cbfbefebbfef77321e4c9abc9e949826bea9d7`
**License:** MIT (see [`vendor/superpowers/LICENSE`](../../../vendor/superpowers/LICENSE))
**Adapted for:** Roo (Claude Code tool names rewritten; see [`scripts/adapt-skill.sh`](../../../scripts/adapt-skill.sh))

To regenerate from upstream, run [`scripts/sync-superpowers.sh`](../../../scripts/sync-superpowers.sh).

## Ported skills (14 / 14)

All upstream skills under `skills/` were ported. Each is process guidance and contained no runtime hard dependencies.

| Skill | Use when |
|-------|----------|
| [`brainstorming`](brainstorming.md) | Before any creative work - features, components, behavior changes. Explores intent and design before code. |
| [`dispatching-parallel-agents`](dispatching-parallel-agents.md) | 2+ independent tasks with no shared state or sequential dependencies. |
| [`executing-plans`](executing-plans.md) | You have a written implementation plan to execute with review checkpoints. |
| [`finishing-a-development-branch`](finishing-a-development-branch.md) | Implementation complete, tests pass, decide how to integrate (merge/PR/cleanup). |
| [`receiving-code-review`](receiving-code-review.md) | Receiving code review feedback; verify before implementing. |
| [`requesting-code-review`](requesting-code-review.md) | Completing tasks, major features, or before merging. |
| [`subagent-driven-development`](subagent-driven-development.md) | Executing plans with independent tasks in the current session. |
| [`systematic-debugging`](systematic-debugging.md) | Any bug, test failure, or unexpected behavior, before proposing fixes. |
| [`test-driven-development`](test-driven-development.md) | Implementing any feature or bugfix, before writing implementation code. |
| [`using-git-worktrees`](using-git-worktrees.md) | Starting feature work that needs isolation from current workspace. |
| [`using-superpowers`](using-superpowers.md) | Starting any conversation - establishes how to find and use skills. |
| [`verification-before-completion`](verification-before-completion.md) | Before claiming work is complete, fixed, or passing. |
| [`writing-plans`](writing-plans.md) | You have a spec or requirements for a multi-step task, before touching code. |
| [`writing-skills`](writing-skills.md) | Creating, editing, or verifying skills. |

## Not ported

None. All 14 upstream skills under `skills/` were portable as standalone process guidance.

The upstream `commands/`, `agents/`, `hooks/`, `.claude-plugin/`, `.cursor-plugin/`, `.codex-plugin/`, `.opencode/`, and `gemini-extension.json` artifacts are **out of scope** because they target host-specific runtimes (Claude Code, Cursor, Codex, Gemini, OpenCode) that we do not run in this Roo sandbox.

## Adaptation notes

The adapter (see [`scripts/adapt-skill.sh`](../../../scripts/adapt-skill.sh)) applies these rewrites only when tool names appear inside backticks:

- `` `Read` `` -> `` `read_file` ``
- `` `Edit` `` / `` `MultiEdit` `` -> `` `edit` ``
- `` `Write` `` -> `` `write_to_file` ``
- `` `Bash` `` -> `` `execute_command` ``
- `` `Grep` `` -> `` `search_files` ``
- `` `Glob` `` -> `` `list_files` ``
- `` `Task` `` -> `` `new_task` (or orchestrator mode) ``

Unwrapped occurrences in normal prose are left untouched. The `${CLAUDE_PLUGIN_ROOT}` placeholder is mapped to `.roo/skills`.

## How to use

- **On demand in any task:** ask the agent to "consult [`.roo/skills/superpowers/<skill>.md`](.)" or invoke a skill by name; the loader rule [`.roo/rules/superpowers-loader.md`](../../rules/superpowers-loader.md) signposts the high-priority ones automatically.
- **Recurring task types:** switch to a custom mode that bakes a skill into the system prompt (see [`.roomodes`](../../../.roomodes) for `superpowers-tdd`, `superpowers-debug`).
- **Discover available skills:** `bash scripts/list-skills.sh`.

## Sync cadence

Pinned to a single upstream SHA. Refresh deliberately by editing `UPSTREAM_SHA` in [`scripts/sync-superpowers.sh`](../../../scripts/sync-superpowers.sh) and running it; review the diff before committing.
