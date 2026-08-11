# Process Hardening Audit — design

**Date:** 2026-08-11
**Status:** approved, pending implementation plan
**Scope:** Ogami ERP API (`api/`) plus SPA submit/retry paths (`spa/`)

## Objective

Find where business processes are fragile: partial failures, inconsistent
cross-module state, missing rollbacks, silent data loss, race conditions, and
steps that assume a previous step succeeded without verifying it.

Two phases. Phase 1 produces findings only. No hardening code is written until
Phase 1 is reviewed and a Phase 2 plan is confirmed.

Every weakness must be demonstrated from current code with `file:line`. A
process that has not been traced end to end is reported as untraced, never as
clean.

## Starting state

The audit runs against the **working tree**, not `HEAD`. At audit time the tree
carries 501 changed files (357 modified, +26,300/−12,343; 144 untracked). The
tree is what ships and what the prior audit docs describe, so it is the correct
subject. Findings are anchored to this state and note that it was dirty.

Surface: 22 modules, 215 services, 168 controllers, 53 domain event classes, 50
listener files, 9 jobs.

Existing hardening primitives (not a greenfield audit):

- 379 `DB::transaction` sites, 209 `lockForUpdate`, 17 `afterCommit`
- transactional outbox — `api/app/Common/Services/OutboxService.php`,
  `OutboxDispatcher.php`, `OutboxEventCodec.php` (57 allowlist entries)
- chain telemetry — `api/app/Common/Models/ChainStepRun.php`,
  `ChainListenerRun.php`
- 58 `Event::listen` registrations in
  `api/app/Providers/AppServiceProvider.php` (no auto-discovery)
- one `TRANSITIONS` table, in
  `api/app/Modules/CRM/Services/SalesOrderService.php`; all other status
  transitions are enforced ad hoc per service

## Prior audits are hypotheses, not evidence

Two documents already claim this scope:

- `docs/PROCESS-AUDIT-2026-08-10.md` — 499 lines, 15 boundaries marked
  "Closed slice"
- `docs/PROCESS-FAILURE-MATRIX-2026-08-11.md` — 137 lines, declares nearly
  every boundary closed and pushes residual risk to staging and providers

Both are untracked, self-authored, and never reviewed. Both cite `Edge` (audit
×5, matrix ×1) although `api/app/Modules/Edge` was deleted in commit
`c3156301`.

Every "Closed" claim is therefore treated as an unverified hypothesis and
re-derived from current code with fresh citations. Prior docs are used as a
checklist of what to examine, never as evidence that something is sound.
Contradictions are recorded in a prior-claim delta section.

## Method

Modules couple two ways, and the direct path dominates: **708 cross-module
`Services`/`Models` imports** (1,589 total such imports, less same-module ones)
versus 53 event classes and 58 listener registrations.
An event-map audit — which the outbox and codec allowlist naturally invite —
would cover the minority of coupling. A synchronous `InventoryService` call
from Production is a cross-module handoff with no outbox row, no dedupe key,
and no listener-run telemetry. That is where uninstrumented failure hides.

Approach: coupling-graph driven, with business-chain framing for naming and
trace order, and a failure-mode grep sweep as a final completeness
cross-check. Any defect the sweep finds that the traces missed means edge
collapsing was too aggressive.

## Section 1 — Process definition and classification

A process is any point where one action is expected to reliably cause or block
another. Concretely, five shapes:

1. a status transition on a domain model (`forceFill(['status' => …])`, or the
   `TRANSITIONS` table)
2. a service method writing 2+ tables in one call
3. an event → listener handoff
4. a scheduled command or job (`api/routes/console.php`, `Jobs/`)
5. an approval gate (`ApprovalService`)

**Classification is by write reach, not import reach.** This rule turns 708
imports into an inventory rather than noise. A process is cross-module only if
it *writes* across module namespaces, or triggers something that does.
Production importing `Employee` to read a name is not a cross-module process;
Production calling `InventoryService` to deduct stock is. Import-only reads are
recorded on the edge list and dismissed with that stated reason, so every
dismissal is auditable rather than invisible.

Chain vs single-module splits on step count within one namespace: 2+ sequential
state-changing steps makes it a chain.

Depth allocation: forensic on cross-module and chain processes. Single-module
processes are inventoried and explicitly parked in the untraced list.

## Section 2 — Inventory construction

Six enumerable sources:

| Source | Yields |
|---|---|
| module `routes.php` + `api/routes/api.php` | HTTP entry points |
| `AppServiceProvider.php` `Event::listen` ×58 | async edges |
| `OutboxEventCodec.php` ×57 allowlist | durable edges |
| `api/routes/console.php` | scheduled entries |
| `Jobs/` ×9 | queued entries |
| cross-module import graph ×708 | direct-call edges, filtered to write-reaching |

Inventory table columns: name, classification, domains touched, entry point
`file:line`, trigger type, disposition.

Every row carries a disposition — traced, clean, or parked-with-reason. No row
disappears silently. The untraced list is *generated* from rows lacking a
disposition, not assembled from memory.

## Section 3 — Trace protocol

Per traced process, eight fields, each cited:

1. every step and the owning service/class
2. transaction boundary, and whether it covers all steps or only some
3. behavior when step N succeeds and step N+1 fails — rollback, compensation,
   or orphaned records
4. sync vs async handoff, and behavior on failure, retry, or out-of-order run
5. idempotency under replay
6. whether the guard is reachable around via another route, job, or command
7. audit-trail attribution, or silence
8. verdict

Three checks are mechanical rather than judgment, which keeps findings out of
best-practice opinion:

**Bypass reachability.** For each guarded status field, enumerate *every*
writer — services, controllers, jobs, commands, listeners — then confirm each
carries the guard. A guard present in `SalesOrderService` but absent in a
command writing the same column is a bypass, found by enumeration.

**Boundary-vs-dispatch.** 379 `DB::transaction` sites against 17
`afterCommit`. Laravel dispatches immediately unless deferred, so an event
dispatched inside a transaction can reach a queued listener before commit — the
listener reads a row that is not there yet, or one a rollback erases. The 33
files recording outbox events are the safe path. The finding is the set
difference: dispatches inside a transaction using neither the outbox nor
`afterCommit`.

**Compensation.** Where a process writes across modules without one enclosing
transaction, determine whether any failure path exists, versus the first write
simply standing.

## Section 4 — Evidence standard

Every claim carries `file:line`.

Severe classes — data-corrupting, non-idempotent, race — additionally require an
executable probe: a throwaway test driving the actual bad outcome, or the
existing `make chain-smoke` / `make worker-recovery-smoke` harnesses. Probes are
deleted after use; none are committed.

Each severe finding is labeled **PROVEN** or **ARGUED**. A finding whose probe
proves too expensive to build stays in the report labeled ARGUED, never
silently upgraded. That label is what makes the severity list reviewable rather
than assertive, and it is the specific failure mode of the two prior documents.

SPA scope: backend plus SPA submit and retry paths, followed where the client
is the guard preventing a duplicate effect — submit-button disable, mutation
dedupe, retry-on-error, optimistic update assuming success. A duplicate that
only a disabled button prevents is a server-side finding.

## Section 5 — Severity classification

Findings group into the five classes, evidence only:

- **Data-corrupting** — inconsistent cross-module state (stock deducted, GL
  entry never posted)
- **Silent failure** — fails without surfacing to user or logs
- **Bypassable** — guard skippable via an alternate path
- **Non-idempotent** — retry or double-submit duplicates effects
- **Missing compensation** — no rollback path when a later step fails

## Section 6 — Output

Written to `docs/PROCESS-HARDENING-AUDIT-2026-08-11.md` — a new file, so
neither prior document is overwritten.

Sections: inventory table · findings grouped by severity class · clean list
with citations · untraced list · prior-claim delta.

Written incrementally as each trace completes, so a context compaction cannot
cost completed findings.

## Recorded, not acted on in Phase 1

These are changes rather than findings:

1. `docs/PROCESS-AUDIT-2026-08-10.md` and
   `docs/PROCESS-FAILURE-MATRIX-2026-08-11.md` are untracked and need a
   disposition — commit, supersede, or delete.
2. `docs/PROCESS-FLOWS.md` is stale on cut scope: Edge ×9, COPQ ×3, SPC ×2.
3. `CLAUDE.md` advertises `EdgeSystemUserResolver` — a deleted class — under
   shared helpers to reuse before reinventing.

## Scope expansion rule

If tracing surfaces a process not on the inventory — for example a job that
silently triggers another chain — it is added and traced. It is not skipped for
being outside the original list.

## Phase 2 — plan only

After Phase 1 review:

- propose a fix approach per finding (wider transaction boundary, compensation
  logic, idempotency key, explicit state guard) — proposal only
- group fixes by dependency order, since some must land before others are safe
  (no compensation step added to a transaction boundary that does not exist yet)
- flag any fix changing external behavior — API responses, job signatures,
  event payloads — for breaking-change review

Priority order is confirmed by the user before any hardening code is written.

## Cost note

Tracing runs serially in one session; no subagents, per standing instruction.
Forensic depth across 708 cross-module edges collapsed to process boundaries is
a long pass, and context will compact during it. Incremental writing is the
mitigation.
