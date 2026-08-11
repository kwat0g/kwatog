# Process Hardening Audit — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Execution mode: subagent-driven**, per explicit user selection on
> 2026-08-11. This supersedes the spec's "no subagents" constraint, which
> encoded a standing default the user has now overridden for this plan.
> One fresh subagent per task, reviewed between tasks.

**Goal:** Produce an evidence-cited audit of every cross-module and chain
business process in the Ogami ERP API, identifying where they lose data, skip
guards, duplicate effects, or leave inconsistent state — findings only, no
fixes.

**Architecture:** Coupling-graph driven. Build a mechanical edge list from six
enumerable sources, collapse it to process boundaries by *write reach*, rank by
blast radius, then trace top-down at forensic depth. Every edge carries a
disposition so the untraced list is generated rather than remembered. A
failure-mode grep sweep runs last as a completeness cross-check on the
collapsing.

**Tech Stack:** Laravel 12 / PHP 8.3, PostgreSQL 16, Redis 7, PHPUnit,
React 18 SPA. All commands run through `docker compose exec`.

**Spec:** `docs/superpowers/specs/2026-08-11-process-hardening-audit-design.md`

## Global Constraints

- **Phase 1 is findings-only.** No production code is modified. No transaction
  boundary widened, no guard added, no refactor. Phase 2 proposes; the user
  confirms priority; only then does code change.
- **Subagent-driven execution.** One fresh subagent per task, reviewed between
  tasks. Each subagent starts with no context beyond this plan, so every task
  states its own paths, commands, and expected output. Because a subagent
  cannot see prior traces, **findings must be appended to the audit document
  before the task ends** — the document, not conversation memory, is the
  handoff between tasks.
- **Anchor commit is `feaa9621`** on `main`, pushed to `origin/main` — the
  commit whose code the citations describe. **The no-drift compare point is
  `80fc31ee`** (this plan's own commit), because one unrelated seeder change
  (`api/database/seeders/DashboardRoleLayoutSeeder.php`, +10/−1) landed between
  them. Citations reference `feaa9621`; Task 12 compares against `80fc31ee`. If
  the tree drifts further mid-audit, note the drift rather than silently
  re-citing.
- **Every claim carries `file:line`.** A process not traced end to end is
  reported as untraced, never as clean.
- **Severe findings** (data-corrupting, non-idempotent, race) carry an
  executable probe and are labeled **PROVEN**. A finding whose probe proves too
  expensive stays in the report labeled **ARGUED** — never silently upgraded.
- **Probes are throwaway.** Written under `api/tests/Feature/AuditProbe/`,
  deleted before the final commit. None are committed. The audit adds no test
  files to the suite.
- **Prior audit docs are unverified hypotheses.** `docs/PROCESS-AUDIT-2026-08-10.md`
  and `docs/PROCESS-FAILURE-MATRIX-2026-08-11.md` cite the deleted `Edge`
  module. Use them as a checklist of what to examine, never as evidence.
- **Classification is by write reach, not import reach.** A process is
  cross-module only if it *writes* across module namespaces, or triggers
  something that does.
- **Findings are written incrementally.** Append to the audit document as each
  trace completes, so a context compaction cannot lose completed work.
- **Baseline suite is 1,564 tests.** Verified green at `feaa9621`. The suite
  must still be 1,564 and green at the end — proof the audit changed nothing.

## Output artifact

Single file: `docs/PROCESS-HARDENING-AUDIT-2026-08-11.md`

Section order (created empty in Task 1, filled thereafter):

1. Scope and method
2. Edge inventory table
3. Findings by severity
4. Clean list
5. Untraced list
6. Prior-claim delta

## File structure

| Path | Responsibility |
|---|---|
| `docs/PROCESS-HARDENING-AUDIT-2026-08-11.md` | the only deliverable; all findings |
| `/tmp/claude-1000/-home-kwat0g-Desktop-kwatog/50066d79-9717-cfa6-ebdc-7145bac05bb4/scratchpad/edges-raw.txt` | mechanical edge dump, scratch |
| `/tmp/claude-1000/-home-kwat0g-Desktop-kwatog/50066d79-9717-cfa6-ebdc-7145bac05bb4/scratchpad/edges-writing.txt` | write-reaching edges after filter, scratch |
| `api/tests/Feature/AuditProbe/*.php` | throwaway probes, deleted in Task 12 |

Scratch files live in the session scratchpad, never in the repo.

---

### Task 1: Scaffold the audit document and capture the anchor

**Files:**
- Create: `docs/PROCESS-HARDENING-AUDIT-2026-08-11.md`

**Interfaces:**
- Produces: the six section headings every later task appends under. Later
  tasks locate their insertion point by exact heading text, so these strings
  are load-bearing.

- [ ] **Step 1: Confirm the anchor commit is what we think it is**

```bash
cd /home/kwat0g/Desktop/kwatog
git log --oneline -1
git status --porcelain | grep -vE '^\?\? \.(codex|impeccable)/' | wc -l
```

Expected: `feaa9621` or a later docs-only commit (`ebd17a83` is the spec
correction — acceptable, it touches no `api/` code). Second command prints `0`,
meaning no uncommitted code. If it prints non-zero, stop and report — the
anchor is invalid and every citation would be unverifiable.

- [ ] **Step 2: Record the exact surface counts**

```bash
cd /home/kwat0g/Desktop/kwatog/api/app
echo "services: $(find . -name '*Service.php' | wc -l)"
echo "controllers: $(find . -name '*Controller.php' | wc -l)"
echo "event classes: $(find . -path '*Events*' -name '*.php' | wc -l)"
echo "listener files: $(find . -path '*Listeners*' -name '*.php' | wc -l)"
echo "jobs: $(find . -path '*Jobs*' -name '*.php' | wc -l)"
echo "Event::listen: $(grep -c 'Event::listen' Providers/AppServiceProvider.php)"
echo "codec allowlist: $(grep -cE '::class' Common/Services/OutboxEventCodec.php)"
```

Record the actual output. If any number contradicts the spec (spec says 215 /
168 / 53 / 50 / 9 / 58 / 57), the plan is stale — record the real number and
note the discrepancy in the audit doc's scope section.

- [ ] **Step 3: Write the document skeleton**

```markdown
# Process Hardening Audit — Phase 1 findings

**Anchor commit:** feaa9621 (`main`, pushed to origin)
**Date:** 2026-08-11
**Status:** in progress
**Spec:** docs/superpowers/specs/2026-08-11-process-hardening-audit-design.md

Phase 1 is findings-only. No fix is proposed or applied here.

Every claim cites `file:line` as of the anchor commit. A process that has not
been traced end to end appears in the untraced list, never in the clean list.

Severe findings (data-corrupting, non-idempotent, race) are labeled PROVEN
where an executable probe demonstrated the bad outcome, ARGUED where the
finding rests on code reading alone.

## 1. Scope and method

<!-- filled by Task 1 Step 4 -->

## 2. Edge inventory

<!-- filled by Task 3 -->

## 3. Findings by severity

### 3.1 Data-corrupting

### 3.2 Silent failure

### 3.3 Bypassable

### 3.4 Non-idempotent

### 3.5 Missing compensation

## 4. Clean list

## 5. Untraced list

## 6. Prior-claim delta
```

- [ ] **Step 4: Fill section 1 with the recorded counts**

Under `## 1. Scope and method`, write the surface counts from Step 2 as a
table, the six edge sources, the write-reach classification rule, and the
PROVEN/ARGUED convention. Copy the rule verbatim from the spec — do not
paraphrase it, since it is what makes dismissals auditable.

- [ ] **Step 5: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog
git add docs/PROCESS-HARDENING-AUDIT-2026-08-11.md
git commit -m "docs(audit): scaffold Phase 1 findings document at feaa9621"
```

---

### Task 2: Build the mechanical edge list

**Files:**
- Create: `<scratchpad>/edges-raw.txt`
- Create: `<scratchpad>/edges-writing.txt`

**Interfaces:**
- Consumes: nothing.
- Produces: `edges-writing.txt`, one line per candidate cross-module edge in
  the form `SourceModule -> TargetModule | file:line | symbol`. Task 3 collapses
  this into process boundaries.

`<scratchpad>` is
`/tmp/claude-1000/-home-kwat0g-Desktop-kwatog/50066d79-9717-cfa6-ebdc-7145bac05bb4/scratchpad`.

- [ ] **Step 1: Dump every cross-module import with its line number**

```bash
cd /home/kwat0g/Desktop/kwatog/api/app/Modules
SP=/tmp/claude-1000/-home-kwat0g-Desktop-kwatog/50066d79-9717-cfa6-ebdc-7145bac05bb4/scratchpad
mkdir -p "$SP"
: > "$SP/edges-raw.txt"
for m in */; do m=${m%/}
  grep -rnE "^use App\\\\Modules\\\\[A-Za-z0-9]+\\\\(Services|Models)\\\\[A-Za-z0-9]+" "$m" --include=*.php 2>/dev/null \
  | grep -vE "Modules\\\\$m\\\\" \
  | sed -E "s|^([^:]+):([0-9]+):use App\\\\Modules\\\\([A-Za-z0-9]+)\\\\(Services\|Models)\\\\([A-Za-z0-9]+).*|$m -> \3 \| api/app/Modules/\1:\2 \| \5|" \
  >> "$SP/edges-raw.txt"
done
echo "lines: $(wc -l < "$SP/edges-raw.txt")"
echo "unconverted (sed miss): $(grep -c '^use ' "$SP/edges-raw.txt")"
head -4 "$SP/edges-raw.txt"
```

This pipeline was executed against `feaa9621` while writing this plan: it
produced **708 lines, 0 unconverted**, in the form
`Accounting -> Auth | api/app/Modules/Accounting/Models/OfficialReceipt.php:9 | User`.

Expected: 708 lines, `unconverted` = 0. A non-zero `unconverted` count means an
import style the pattern misses — inspect those lines before continuing. Do not
proceed on a silently truncated list; a missing edge becomes a missing trace.

- [ ] **Step 2: Filter to write-reaching edges**

An edge is write-reaching if the importing file calls a method on the imported
symbol that writes. Read-only imports (`::find`, `->name`, `where()->get()`)
are dismissed with that reason recorded.

```bash
SP=/tmp/claude-1000/-home-kwat0g-Desktop-kwatog/50066d79-9717-cfa6-ebdc-7145bac05bb4/scratchpad
cut -d'|' -f1 "$SP/edges-raw.txt" | sort | uniq -c | sort -rn
```

This prints module→module pairs by frequency. Work down it. For each distinct
pair, open the citing files and determine whether any call writes
(`create`, `update`, `save`, `forceFill`, `delete`, `increment`, `decrement`,
`insert`, or a service method that does). Write survivors to
`edges-writing.txt` in the same format, appending ` | READ-ONLY: <reason>` to
dismissed pairs in a separate `edges-readonly.txt`.

Both files are retained — the read-only file is what makes each dismissal
auditable.

- [ ] **Step 3: Append the async edges**

```bash
cd /home/kwat0g/Desktop/kwatog/api/app
grep -nE 'Event::listen' Providers/AppServiceProvider.php
grep -nE '::class' Common/Services/OutboxEventCodec.php
```

Each registration is an edge: event's owning module → listener's owning module.
Append to `edges-writing.txt` marked `ASYNC`. Note which of the 58
registrations have no corresponding codec allowlist entry — those are events
that dispatch but cannot be durably replayed, which is itself a finding
candidate for Task 10.

- [ ] **Step 4: Append scheduled and queued entry points**

```bash
cd /home/kwat0g/Desktop/kwatog/api
grep -nE '->command\(|Schedule::|->daily|->hourly|->everyFifteenMinutes|->cron' routes/console.php | head -40
find app -path '*Jobs*' -name '*.php'
```

Each scheduled command and each job is an entry point. Append marked
`SCHEDULED` or `QUEUED`, with the cron expression where present.

- [ ] **Step 5: Append HTTP entry points for write routes**

```bash
cd /home/kwat0g/Desktop/kwatog/api
php artisan route:list --json > /tmp/routes.json 2>/dev/null || \
  docker compose -f ../docker-compose.yml exec -T api php artisan route:list --json > /tmp/routes.json
```

Filter to `POST`/`PUT`/`PATCH`/`DELETE`. These are the state-changing HTTP
surface. Append marked `HTTP`.

- [ ] **Step 6: Report the edge counts**

State the four counts (direct write-reaching, async, scheduled/queued, HTTP
write routes) and the read-only dismissal count. No commit — scratch files are
not repo artifacts.

---

### Task 3: Collapse edges to process boundaries and write the inventory

**Files:**
- Modify: `docs/PROCESS-HARDENING-AUDIT-2026-08-11.md` (section 2)

**Interfaces:**
- Consumes: `edges-writing.txt` from Task 2.
- Produces: the numbered process inventory. Tasks 4–9 reference processes by
  the IDs assigned here (`P01`, `P02`, …). Task 11 generates the untraced list
  from rows whose disposition is still empty.

- [ ] **Step 1: Collapse edges into named processes**

Multiple edges belong to one process when they are steps of a single business
action. `WorkOrderService -> InventoryService` and
`WorkOrderService -> ProductionReceipt` collapse into "Work order output →
production receipt → stock". Name each process in business terms, using the
Chain 1/2/3 vocabulary from `CLAUDE.md` where it fits.

- [ ] **Step 2: Rank by blast radius**

Rank descending: writes money (GL, AP, AR, payroll) > writes stock > writes
payroll-adjacent employee state > everything else. This is the trace order for
Tasks 4–9. Record the rank so the ordering is inspectable, not implicit.

- [ ] **Step 3: Write the inventory table**

Under `## 2. Edge inventory`:

```markdown
| ID | Process | Class | Domains | Entry point | Trigger | Blast | Disposition |
|---|---|---|---|---|---|---|---|
| P01 | Payroll finalize → GL posting | cross-module | Payroll, Accounting | `api/app/Modules/Payroll/Services/PayrollService.php:NNN` | HTTP + event | money | |
```

`Class` is cross-module / chain / single-module. `Disposition` starts empty and
is filled by the tracing tasks with `traced`, `clean`, or
`parked: <reason>`. An empty disposition at Task 11 means untraced.

Every process from Step 1 gets a row. Single-module processes get a row too —
they are parked, not omitted, and parking is only visible if the row exists.

- [ ] **Step 4: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog
git add docs/PROCESS-HARDENING-AUDIT-2026-08-11.md
git commit -m "docs(audit): process inventory — N processes ranked by blast radius"
```

Replace `N` with the actual count.

---

### Task 4: Trace protocol dry run on one process

**Files:**
- Modify: `docs/PROCESS-HARDENING-AUDIT-2026-08-11.md`

**Interfaces:**
- Consumes: process `P01` (highest blast radius) from Task 3.
- Produces: the trace format every subsequent trace follows. Getting this
  format right once is why this is its own task.

This task exists to validate the protocol on a single process before spending
it across dozens. If the eight-field protocol produces something unreadable or
unfalsifiable here, fix the protocol now.

- [ ] **Step 1: Trace P01 through all eight fields**

For the highest-blast-radius process, record with `file:line` for each:

1. every step and owning class
2. transaction boundary — and whether it covers all steps or only some
3. what happens if step N succeeds and N+1 fails
4. sync vs async handoff; behavior on failure, retry, out-of-order
5. idempotency under replay
6. guard reachability — enumerate *every* writer of the guarded status field
7. audit-trail attribution
8. verdict

For field 6, enumerate mechanically:

```bash
cd /home/kwat0g/Desktop/kwatog/api/app
grep -rn "status.*=>.*PayrollStatus::" --include=*.php . | grep -vE '/tests/'
```

Adapt the enum name per process. Every writer found must be checked for the
guard. This is the check that finds bypasses by enumeration rather than
intuition.

- [ ] **Step 2: Assess whether the format holds**

Read the trace back. Can a reviewer disprove any claim in it from the citations
alone? If a field reads as opinion rather than evidence, tighten it before
proceeding. Record the finalized format under section 1 as "trace protocol".

- [ ] **Step 3: File any findings and set the disposition**

Findings go under the matching severity subsection in section 3, each with
`file:line`. If the process is sound, it goes in section 4 (clean list) with a
brief cited reason. Update P01's disposition in the inventory table.

- [ ] **Step 4: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog
git add docs/PROCESS-HARDENING-AUDIT-2026-08-11.md
git commit -m "docs(audit): trace P01, establish eight-field trace protocol"
```

---

### Task 5: Trace the money-writing processes

**Files:**
- Modify: `docs/PROCESS-HARDENING-AUDIT-2026-08-11.md`

**Interfaces:**
- Consumes: inventory rows ranked `money`, excluding P01.
- Produces: findings and dispositions for every money-writing process.

- [ ] **Step 1: Trace each money process through the eight fields**

Work down the money-ranked rows in order. Apply the Task 4 protocol verbatim to
each. Known candidates from the spec's evidence, all requiring independent
re-derivation:

- payroll finalize → GL (`PostPayrollToGlOnRequested`,
  `PayrollGlPostingRequested`)
- payroll compute claim → durable execution (`RunPayrollComputationOnRequested`)
- stock movement → GL (`PostStockMovementToGlOnRequested`)
- delivery → invoice (`DeliveryInvoiceRequested`)
- supplier invoice → AP draft
- bank file generation (`BankFileGenerationStatus`)
- asset monthly depreciation

- [ ] **Step 2: Apply the boundary-vs-dispatch check to each**

```bash
cd /home/kwat0g/Desktop/kwatog/api/app
grep -rn 'DB::transaction' --include=*.php Modules/<Module> | wc -l
grep -rn 'afterCommit\|dispatchAfterResponse' --include=*.php Modules/<Module>
```

For each event dispatched inside a transaction, determine whether it goes
through the outbox, uses `afterCommit`, or neither. Neither is a finding: the
listener can observe a row that has not committed, or one a rollback erased.

- [ ] **Step 3: Append findings incrementally**

Write each process's findings to the document *as that trace completes*, not
batched at the end. This is the compaction protection.

- [ ] **Step 4: Commit after each process**

```bash
cd /home/kwat0g/Desktop/kwatog
git add docs/PROCESS-HARDENING-AUDIT-2026-08-11.md
git commit -m "docs(audit): trace <process name> — <N> findings"
```

Frequent commits mean a lost context costs one trace, not the batch.

---

### Task 6: Trace the stock-writing processes

**Files:**
- Modify: `docs/PROCESS-HARDENING-AUDIT-2026-08-11.md`

**Interfaces:**
- Consumes: inventory rows ranked `stock`.
- Produces: findings and dispositions for stock processes.

- [ ] **Step 1: Trace each stock process through the eight fields**

Candidates requiring independent re-derivation:

- GRN → incoming QC → inventory acceptance (`AcceptGrnOnIncomingQcPass`)
- work order output → production receipt → stock
  (`CreateProductionReceiptOnOutputRequested`, `WorkOrderOutputService::record`)
- return inspection → disposition → stock (`ReturnInspectionHandoffStatus`)
- delivery → stock deduction
- weighted-average cost recalculation on receipt

Weighted-average cost deserves specific attention: `CLAUDE.md` states it
recalculates on every purchase receipt. Determine whether a concurrent receipt
can interleave the read-modify-write.

- [ ] **Step 2: Check idempotency on the receipt paths**

`WorkOrderOutputService::record()` is documented in `CLAUDE.md` as idempotent.
Verify that claim against the code rather than accepting it — a documented
guarantee that the code does not implement is a high-value finding.

- [ ] **Step 3: Append findings and commit per process**

Same incremental protocol as Task 5.

---

### Task 7: Trace the remaining cross-module processes

**Files:**
- Modify: `docs/PROCESS-HARDENING-AUDIT-2026-08-11.md`

**Interfaces:**
- Consumes: remaining cross-module inventory rows.
- Produces: findings and dispositions.

- [ ] **Step 1: Trace each remaining cross-module process**

Candidates:

- complaint → NCR (`CreateNcrOnComplaintRequested`)
- NCR → corrective work order → replacement WO
- year-end leave processing → balance rollover
- preventive maintenance generation → work order
- budget actuals sync
- supplier dispatch (`SupplierDispatchService`, `PrepareSupplierDispatch`)
- separation → clearance → final pay
- approval workflow (`ApprovalService`) as it crosses into each approving module

The approval workflow is the one to be most careful with: it is a single
service that gates transitions in many modules, so a defect in it is a defect
in every chain that depends on it.

- [ ] **Step 2: Trace the nine non-transactional status writers**

These were surfaced during exploration as leads, not findings. Each writes
status via `forceFill` with no `DB::transaction` in the same file:

```
api/app/Modules/CRM/Services/Complaint8dEscalationService.php
api/app/Modules/Quality/Services/NcrEscalationService.php
api/app/Modules/Accounting/Services/BudgetEnforcementService.php
api/app/Modules/Inventory/Services/SafetyStockRecomputeService.php
api/app/Modules/Maintenance/Services/MachineHoursService.php
api/app/Modules/HR/Services/TrainingExpiryService.php
api/app/Modules/B2B/Services/B2bAuthService.php
api/app/Modules/Landing/Services/NewsletterService.php
api/app/Modules/Landing/Services/ContactInquiryInboxService.php
```

For each: does it write more than one row? If it writes one row, the missing
transaction is harmless and it goes on the clean list with that reason. If it
writes several, a partial failure leaves inconsistent state — a finding.

- [ ] **Step 3: Append findings and commit per process**

---

### Task 8: Trace the SPA submit and retry paths

**Files:**
- Modify: `docs/PROCESS-HARDENING-AUDIT-2026-08-11.md`

**Interfaces:**
- Consumes: the HTTP write routes from Task 2 Step 5, plus findings from Tasks
  5–7 flagged as duplicate-prone.
- Produces: findings where the client is the only duplicate guard.

- [ ] **Step 1: Find mutations without submit-disable**

```bash
cd /home/kwat0g/Desktop/kwatog/spa/src
grep -rn 'useMutation' --include=*.tsx --include=*.ts . | wc -l
grep -rln 'useMutation' --include=*.tsx . | head -40
```

For each mutation on a process traced in Tasks 5–7, check whether the submit
control disables while pending. `CLAUDE.md` requires "submit button disabled
while pending" — verify rather than assume.

- [ ] **Step 2: Identify server-side gaps the client is masking**

Where a duplicate is prevented *only* by a disabled button, the finding is
server-side and non-idempotent: a replayed request, a second tab, or a direct
API call bypasses the client entirely. File it under 3.4, citing both the SPA
line and the unguarded server line.

- [ ] **Step 3: Check optimistic updates that assume success**

```bash
cd /home/kwat0g/Desktop/kwatog/spa/src
grep -rn 'onMutate' --include=*.tsx --include=*.ts . | head -20
```

An optimistic update with no rollback on error shows the user a state the
server rejected — silent failure from the user's perspective. File under 3.2.

- [ ] **Step 4: Append findings and commit**

---

### Task 9: Prove the severe findings

**Files:**
- Create: `api/tests/Feature/AuditProbe/<Name>ProbeTest.php` (throwaway)
- Modify: `docs/PROCESS-HARDENING-AUDIT-2026-08-11.md`

**Interfaces:**
- Consumes: every finding in 3.1 (data-corrupting), 3.4 (non-idempotent), and
  any race claim.
- Produces: PROVEN or ARGUED label on each.

- [ ] **Step 1: Write a probe for the highest-severity finding**

Follow the house idiom from
`api/tests/Feature/CRM/SalesOrderLifecycleConcurrencyTest.php:1` — stale-model
replay, `RefreshDatabase`, service resolved from the container, explicit
`$this->fail()` when the bad outcome does *not* occur:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AuditProbe;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe_demonstrates_the_bad_outcome(): void
    {
        // Arrange the state the finding claims is reachable.
        // Act via the real service, not a mock.
        // Assert the CORRUPTION is present — the probe passes when the
        // system misbehaves. That inversion is the point: a passing probe
        // is proof of the defect.
        $this->markTestIncomplete('Replace with the actual probe.');
    }
}
```

Note the inverted assertion convention: the probe passes when the defect
reproduces. State this in the audit doc so a later reader does not mistake a
passing probe for a passing system.

- [ ] **Step 2: Run the probe**

```bash
cd /home/kwat0g/Desktop/kwatog
docker compose exec -T api php artisan test --filter='ExampleProbeTest'
```

Expected: PASS, meaning the bad outcome reproduced. If it FAILS, the finding is
wrong — the system defends itself. Move that finding to the clean list with the
probe result as the citation. This is the step that catches over-claiming.

- [ ] **Step 3: Label the finding**

PROVEN with the probe's assertion quoted, or moved to clean. If a probe cannot
be built in reasonable time, label ARGUED and state why the probe was
impractical.

- [ ] **Step 4: Repeat for each severe finding**

- [ ] **Step 5: Commit findings only, never probes**

```bash
cd /home/kwat0g/Desktop/kwatog
git add docs/PROCESS-HARDENING-AUDIT-2026-08-11.md
git commit -m "docs(audit): prove <N> severe findings, demote <M> to clean"
```

`api/tests/Feature/AuditProbe/` is never staged. Verify before committing:

```bash
git diff --cached --name-only | grep AuditProbe && echo "STOP — probe staged" || echo "clean"
```

---

### Task 10: Failure-mode sweep as completeness cross-check

**Files:**
- Modify: `docs/PROCESS-HARDENING-AUDIT-2026-08-11.md`

**Interfaces:**
- Consumes: all findings from Tasks 4–9.
- Produces: either confirmation that edge collapsing was sound, or new findings
  proving it was too aggressive.

This is the check on the method itself. Any defect the sweep finds that the
traces missed means Task 3 collapsed away a real boundary.

- [ ] **Step 1: Sweep for status writes outside a transaction**

```bash
cd /home/kwat0g/Desktop/kwatog/api/app
grep -rn "forceFill(\['status'" --include=*.php Modules | wc -l
grep -rln "forceFill(\['status'" --include=*.php Modules | while read f; do
  grep -q 'DB::transaction' "$f" || echo "$f"
done
```

- [ ] **Step 2: Sweep for dispatch inside a transaction without deferral**

```bash
cd /home/kwat0g/Desktop/kwatog/api/app
grep -rn 'event(\|->dispatch(\|Event::dispatch' --include=*.php Modules | wc -l
grep -rln 'OutboxService' --include=*.php Modules | wc -l
```

The finding set is dispatches inside a transaction using neither outbox nor
`afterCommit`.

- [ ] **Step 3: Sweep for listeners without a dedupe key**

```bash
cd /home/kwat0g/Desktop/kwatog/api/app
find Modules -path '*Listeners*' -name '*.php' | while read f; do
  grep -qE 'WithoutOverlapping|uniqueId|dedupe|idempot' "$f" || echo "$f"
done
```

A queued listener with no dedupe key duplicates its effect on redelivery. Redis
redelivery is expected, not exceptional.

- [ ] **Step 4: Sweep for enum status writes bypassing services**

```bash
cd /home/kwat0g/Desktop/kwatog/api/app
grep -rn "update(\['status'" --include=*.php Modules | grep -vE '/tests/'
```

Direct `update()` on a status column from a controller or command bypasses
whatever guard the service enforces.

- [ ] **Step 5: Reconcile sweep against traces**

For each sweep hit: was it covered by a trace? If yes, note the trace ID. If
no, it is either a new finding or a legitimately single-module process. Any
*cross-module* sweep hit that no trace covered is a method failure — record it
explicitly in section 1 as a limitation of the collapsing, and trace it now.

- [ ] **Step 6: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog
git add docs/PROCESS-HARDENING-AUDIT-2026-08-11.md
git commit -m "docs(audit): failure-mode sweep cross-check — <N> uncovered hits"
```

---

### Task 11: Generate the untraced list and the prior-claim delta

**Files:**
- Modify: `docs/PROCESS-HARDENING-AUDIT-2026-08-11.md` (sections 5, 6)

**Interfaces:**
- Consumes: the inventory table's disposition column; both prior audit docs.
- Produces: the completed report.

- [ ] **Step 1: Generate the untraced list mechanically**

Read the inventory table. Every row whose disposition is empty or begins
`parked:` goes in section 5, with its reason. Generating this from the table —
rather than recalling what was skipped — is what makes the coverage claim
honest.

- [ ] **Step 2: Verify every inventory row has a disposition**

If any row is still blank after Step 1, it was neither traced nor consciously
parked. Either trace it now or park it with a stated reason. A blank row is a
gap in the audit, not a gap in the system.

- [ ] **Step 3: Write the prior-claim delta**

For each boundary the prior docs marked "Closed":

| Prior claim | Doc | This audit | Evidence |
|---|---|---|---|
| Production → Inventory closed | PROCESS-AUDIT-2026-08-10.md | confirmed / contradicted / untraced | `file:line` |

Note explicitly that both prior docs cite `Edge`, deleted in `c3156301`, and
whether that stale reference tracks with any other inaccuracy found.

- [ ] **Step 4: Write the executive summary at the top**

Under the header, before section 1: total processes inventoried, traced, clean,
untraced; findings by severity with PROVEN/ARGUED counts; and the three highest
findings in one line each. A reviewer reads this first and should be able to
decide Phase 2 priority from it alone.

- [ ] **Step 5: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog
git add docs/PROCESS-HARDENING-AUDIT-2026-08-11.md
git commit -m "docs(audit): untraced list, prior-claim delta, executive summary"
```

---

### Task 12: Verify the audit changed nothing

**Files:**
- Delete: `api/tests/Feature/AuditProbe/` (entire directory)

**Interfaces:**
- Consumes: nothing.
- Produces: proof that Phase 1 was findings-only.

- [ ] **Step 1: Delete every probe**

```bash
cd /home/kwat0g/Desktop/kwatog
rm -rf api/tests/Feature/AuditProbe
ls api/tests/Feature/AuditProbe 2>&1 | head -1
```

Expected: "No such file or directory".

- [ ] **Step 2: Confirm no production code was touched**

```bash
cd /home/kwat0g/Desktop/kwatog
git diff --stat 80fc31ee..HEAD -- api/ spa/
```

Expected: **empty output**. Any line here is a Phase 1 violation — Phase 1 is
findings-only. If output appears, revert those paths before continuing.

- [ ] **Step 3: Run the full suite**

```bash
cd /home/kwat0g/Desktop/kwatog
docker compose exec -T api php artisan test 2>&1 | tail -15
```

Expected: 1,564 tests, 0 failures — the same count as the anchor baseline. A
different count means a probe survived deletion or a file was modified.

- [ ] **Step 4: Confirm the only changed files are docs**

```bash
cd /home/kwat0g/Desktop/kwatog
git diff --name-only 80fc31ee..HEAD
```

Expected: only paths under `docs/`.

- [ ] **Step 5: Mark the audit complete and commit**

Change the document's `**Status:** in progress` to
`**Status:** Phase 1 complete — awaiting review`.

```bash
cd /home/kwat0g/Desktop/kwatog
git add docs/PROCESS-HARDENING-AUDIT-2026-08-11.md
git commit -m "docs(audit): Phase 1 complete — suite green at 1,564, no code changed"
git push origin main
```

- [ ] **Step 6: Stop**

Phase 1 ends here. Do not propose fixes, do not widen a transaction boundary,
do not add a guard. Present the findings and wait for the user to confirm Phase
2 priority order.

---

## Phase 2 preview (not part of this plan)

After the user reviews Phase 1:

- one fix approach per finding — wider transaction boundary, compensation
  logic, idempotency key, explicit state guard — proposal only
- fixes grouped in dependency order, since some must land before others are
  safe
- any fix changing API responses, job signatures, or event payloads flagged for
  breaking-change review

Phase 2 gets its own plan after the user confirms priority.
