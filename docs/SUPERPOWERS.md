# Superpowers Integration

This repo vendors a Roo-adapted copy of [obra/superpowers](https://github.com/obra/superpowers), an MIT-licensed library of agent workflow skills (TDD, systematic debugging, code review, planning, etc.).

## What got vendored

- **Skill content:** [`.roo/skills/superpowers/`](../.roo/skills/superpowers/) - 14 skills, full text adapted to Roo tool conventions. See [`INDEX.md`](../.roo/skills/superpowers/INDEX.md) for the catalog.
- **Loader rule:** [`.roo/rules/superpowers-loader.md`](../.roo/rules/superpowers-loader.md) - auto-loaded into the system prompt every task; signposts which skill to consult when.
- **Custom modes:** [`.roomodes`](../.roomodes) defines `superpowers-tdd` and `superpowers-debug` which embed skill content as mode-specific instructions for strongest adherence.
- **Provenance:** [`vendor/superpowers/`](../vendor/superpowers/) holds the upstream `LICENSE`, a trimmed `README.upstream.md`, and the pinned `UPSTREAM_SHA`.
- **Sync tooling:** [`scripts/sync-superpowers.sh`](../scripts/sync-superpowers.sh) re-pulls upstream and re-runs the adapter; [`scripts/adapt-skill.sh`](../scripts/adapt-skill.sh) does the rewrites; [`scripts/list-skills.sh`](../scripts/list-skills.sh) lists everything.

## How agents use it

### Per-task (ad hoc)

Just ask. Examples:

- "Use the superpowers TDD skill for this feature."
- "Debug this with systematic-debugging."

The agent will `read_file` the skill before acting. The loader rule in `.roo/rules/` makes the agent aware these skills exist on every task.

### Recurring task types (highest adherence)

Switch into a custom mode. The skill content becomes part of the system prompt for the entire task:

- `superpowers-tdd` - enforces red-green-refactor for any production code work.
- `superpowers-debug` - enforces root-cause-before-fix for any bug or test failure.

### Discovery

```bash
bash scripts/list-skills.sh
```

## Adaptation rules

The adapter rewrites Claude-Code-specific tool references (in backticks) to Roo equivalents:

| Upstream | Adapted to |
|---|---|
| `` `Read` `` | `` `read_file` `` |
| `` `Edit` `` / `` `MultiEdit` `` | `` `edit` `` |
| `` `Write` `` | `` `write_to_file` `` |
| `` `Bash` `` | `` `execute_command` `` |
| `` `Grep` `` | `` `search_files` `` |
| `` `Glob` `` | `` `list_files` `` |
| `` `Task` `` | `` `new_task` (or orchestrator mode) `` |
| `${CLAUDE_PLUGIN_ROOT}` | `.roo/skills` |

Plain-prose mentions (without backticks) are intentionally untouched.

## Adding or removing skills

1. Edit [`scripts/sync-superpowers.sh`](../scripts/sync-superpowers.sh) (currently it ports all 14; the curation logic is "everything in `skills/`").
2. Run `bash scripts/sync-superpowers.sh`.
3. Update [`.roo/skills/superpowers/INDEX.md`](../.roo/skills/superpowers/INDEX.md) with the new catalog.
4. If the new skill warrants Tier 1 enforcement, add a custom mode to [`.roomodes`](../.roomodes); otherwise add a trigger entry to [`.roo/rules/superpowers-loader.md`](../.roo/rules/superpowers-loader.md).
5. Open a PR.

## Sync cadence

Pinned to a single upstream SHA in [`scripts/sync-superpowers.sh`](../scripts/sync-superpowers.sh). Refresh deliberately:

```bash
# Edit UPSTREAM_SHA in the script to a newer commit, then:
bash scripts/sync-superpowers.sh
git diff   # review carefully
git add -A && git commit -m "chore(superpowers): sync to <new-sha>"
```

Recommended cadence: quarterly, or when upstream announces a feature you want.

## Limits and honesty

Adherence to vendored skills is **probabilistic, not deterministic**. See [`AGENT-TOOLS-ADHERENCE.md`](AGENT-TOOLS-ADHERENCE.md) for the calibration model and how to tighten enforcement when needed.
