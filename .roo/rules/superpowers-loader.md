# Superpowers Skill Loader

This repository vendors the [obra/superpowers](https://github.com/obra/superpowers) skill set under [`.roo/skills/superpowers/`](../skills/superpowers/). When any of the conditions below is true for the current task, **read the named skill file before taking action** and follow its guidance.

Discover available skills any time with `bash scripts/list-skills.sh`. Full catalog: [`.roo/skills/superpowers/INDEX.md`](../skills/superpowers/INDEX.md).

## Triggers

- **About to write or modify production code for a new feature or bugfix** -> read [`.roo/skills/superpowers/test-driven-development.md`](../skills/superpowers/test-driven-development.md). Write a failing test first; do not write implementation before the test fails for the right reason. Exception: throwaway prototypes, generated code, or pure config.
- **Encountering any bug, test failure, or unexpected behavior** -> read [`.roo/skills/superpowers/systematic-debugging.md`](../skills/superpowers/systematic-debugging.md). Find root cause before proposing fixes; symptom patches are not acceptable.
- **About to claim work is complete, fixed, or passing** -> read [`.roo/skills/superpowers/verification-before-completion.md`](../skills/superpowers/verification-before-completion.md). Run the verification commands and confirm output before any success claim or PR.
- **Starting a multi-step task with a spec or requirements** -> read [`.roo/skills/superpowers/writing-plans.md`](../skills/superpowers/writing-plans.md) before touching code, and [`.roo/skills/superpowers/executing-plans.md`](../skills/superpowers/executing-plans.md) when running a written plan.
- **About to do creative work (new feature, component, behavior change)** -> consult [`.roo/skills/superpowers/brainstorming.md`](../skills/superpowers/brainstorming.md) to clarify intent before designing.
- **Implementation complete, ready to integrate** -> read [`.roo/skills/superpowers/finishing-a-development-branch.md`](../skills/superpowers/finishing-a-development-branch.md) and [`.roo/skills/superpowers/requesting-code-review.md`](../skills/superpowers/requesting-code-review.md).
- **Receiving review feedback** -> read [`.roo/skills/superpowers/receiving-code-review.md`](../skills/superpowers/receiving-code-review.md). Verify each suggestion technically; do not implement blindly.
- **Two or more independent subtasks** -> consult [`.roo/skills/superpowers/dispatching-parallel-agents.md`](../skills/superpowers/dispatching-parallel-agents.md) before serializing them.
- **Need an isolated workspace for risky work** -> read [`.roo/skills/superpowers/using-git-worktrees.md`](../skills/superpowers/using-git-worktrees.md).

## How to honor a trigger

1. Recognize the trigger condition in the user's task.
2. `read_file` the named skill file before producing your plan or code.
3. Follow the skill's procedure. If it conflicts with a project-specific rule in [`.roo/rules/`](.) or [`CLAUDE.md`](../../CLAUDE.md), the project rule wins; note the conflict explicitly in your reply.

For recurring task types (TDD, debugging), prefer switching to a custom mode (`superpowers-tdd`, `superpowers-debug`) which embeds the full skill content into the system prompt for the entire task.
