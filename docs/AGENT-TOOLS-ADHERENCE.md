# Agent-Tools Adherence Model

This doc explains, honestly, **how reliably** the vendored skills under [`.roo/skills/`](../.roo/skills/) get used in practice, and what to do when adherence is weak.

Read alongside [`SUPERPOWERS.md`](SUPERPOWERS.md) and [`RUFLO.md`](RUFLO.md).

## The three-tier model

```
Tier 1: Custom modes  (.roomodes)              [highest adherence]
Tier 2: Always-on rules (.roo/rules/)          [high adherence]
Tier 3: Skill files (.roo/skills/)             [content store]
```

| Tier | Mechanism | Loaded into system prompt? | Adherence |
|------|-----------|---------------------------|-----------|
| 1 - mode | Mode-specific instructions become part of the system prompt for the entire task | Yes, when the mode is active | Highest. Approaches enforcement. |
| 2 - rule | Rule files are auto-injected into the system prompt every task | Yes, every task | High. Agent sees triggers and consults the named skill. |
| 3 - skill | Markdown files at known paths | No - only if a tier 1 mode or tier 2 rule directs the agent to read them | None on its own. |

## Why this matters

Repository-local `.roo/skills/` files are **not** automatically discovered by the agent. The agent's `skill` tool only sees skills listed in an `AVAILABLE SKILLS` section of the system prompt, which `.roo/skills/` does not populate.

Without tier 1 or tier 2 signposting, vendored skills are inert files. The user would have to remember to say "use the X skill" every task. That is barely better than cloning on demand.

The integration in this repo therefore relies on **tier 2 always-on rules** for general signposting and **tier 1 custom modes** for must-use workflows.

## Adherence is probabilistic, not deterministic

Even with tiers 1 and 2 in place:

- Inside a custom mode, adherence is **very strong**: the workflow is system-prompt-level, the agent cannot ignore it without going off-script.
- With a tier 2 trigger rule, adherence is **most of the time**: the agent sees the rule and the trigger condition, and almost always consults the named skill when the trigger matches. Edge cases: ambiguous triggers, very short tasks where the agent skips reading, or long multi-step tasks where the rule is shadowed by later context.
- Long, multi-step skills with many sub-steps are **vulnerable to mid-task drift**. Mitigation: keep skill content imperative and short; use modes when consistency is critical.

No mechanism is "guaranteed." Treat the modes as the strongest available enforcement, not a guarantee.

## Adherence test protocol

When you suspect adherence is weak, run these tests and capture results in this doc:

### Test 1 - Default mode, debug task

Give the agent a non-trivial debugging task in default mode (no explicit instruction to use a skill). Observe whether the agent:

- [ ] References [`.roo/skills/superpowers/systematic-debugging.md`](../.roo/skills/superpowers/systematic-debugging.md) without prompting.
- [ ] Reproduces the bug before proposing a fix.
- [ ] Identifies a root cause before patching.

If any are missed, strengthen the wording in [`.roo/rules/superpowers-loader.md`](../.roo/rules/superpowers-loader.md) or promote the user to switch to `superpowers-debug` mode.

### Test 2 - `superpowers-tdd` mode, feature task

Switch to `superpowers-tdd` and ask the agent to add a small new behavior. Observe whether the agent:

- [ ] Reads the TDD skill as its first action.
- [ ] Writes a failing test before any production code.
- [ ] Confirms the test fails for the right reason before writing implementation.

If any are missed, the embedded mode instructions in [`.roomodes`](../.roomodes) need stronger or shorter wording.

### Test 3 - Tempting shortcut

Give the agent a task where there is an obvious one-line fix that bypasses the workflow. Observe whether the agent takes the shortcut or holds the line on the workflow. Use this to calibrate how imperative the wording needs to be.

## Results log (append below)

Use the format:

```
### YYYY-MM-DD - <task summary>
- Mode used: <default | superpowers-tdd | ...>
- Skill expected to be consulted: ...
- Was it consulted? yes/no/partially
- Notes:
```

(no entries yet)

## When to escalate from rule to mode

Promote a tier 2 rule to a tier 1 mode when:

- Adherence on the corresponding skill is weak across multiple tasks (test 1 or 3 fails).
- The skill's value depends on following every step (TDD, debugging, security review).
- The user finds themselves repeatedly saying "use the X skill" - that is a signal the rule alone is not strong enough.

Do **not** promote a skill to a mode when:

- The skill is situational and the trigger condition is rare (e.g. git worktrees).
- The skill is short enough that consulting it on demand is cheap and reliable.

## When to delete a vendored skill

- Adherence is permanently weak even at tier 1 (rare).
- The skill conflicts with project rules in [`.roo/rules/`](../.roo/rules/) or [`CLAUDE.md`](../CLAUDE.md) and the project rules win consistently.
- Upstream removed it and we are syncing.
