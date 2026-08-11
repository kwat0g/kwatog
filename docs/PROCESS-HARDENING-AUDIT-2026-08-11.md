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

Scope is the Ogami ERP API (`api/`) plus SPA submit and retry paths (`spa/`),
followed where the client is the guard preventing a duplicate effect. A
duplicate that only a disabled button prevents is a server-side finding.

### 1.1 Surface counts

Measured at the anchor commit. Each was re-derived from `feaa9621` itself (via
`git ls-tree`/`git show`), not only from the working tree, so the numbers below
describe the code the citations point at.

| Surface | Count | How measured |
|---|---|---|
| Services | 215 | `find api/app -name '*Service.php'` |
| Controllers | 168 | `find api/app -name '*Controller.php'` |
| Domain event classes | 53 | `find api/app -path '*Events*' -name '*.php'` |
| Listener files | 50 | `find api/app -path '*Listeners*' -name '*.php'` |
| Jobs | 9 | `find api/app -path '*Jobs*' -name '*.php'` |
| `Event::listen` registrations | 58 | `grep -c 'Event::listen' api/app/Providers/AppServiceProvider.php` |
| `::class` tokens in codec file | 57 | `grep -cE '::class' api/app/Common/Services/OutboxEventCodec.php` |
| **Outbox codec allowlist entries** | **51** | `::class` entries inside `SUPPORTED_EVENTS`, `OutboxEventCodec.php:75-127` |

The first six match the design spec
(`docs/superpowers/specs/2026-08-11-process-hardening-audit-design.md:30`,
`:37`, `:40`). The seventh does **not**, and the discrepancy is instructive.

The spec and plan both cite 57 codec allowlist entries. 57 is the output of
`grep -cE '::class'` over the whole file, but the allowlist —
`SUPPORTED_EVENTS` at `api/app/Common/Services/OutboxEventCodec.php:75-127` —
holds exactly **51** unique entries. The grep additionally matches six lines
that are not allowlist members: `$event::class` (`:132`), `$value::class`
(`:208`, `:217`, `:225`), `Model::class` (`:268`), and `BackedEnum::class`
(`:289`).

The count of durable edges is therefore 51. This is a proxy-versus-reality
gap of exactly the kind this audit exists to find, discovered in the audit's
own instrument before a single process was traced.

Module count is 22 (`api/app/Modules/`), matching the spec's stated surface.

### 1.2 Anchor and drift

Findings are anchored to `feaa9621`. `HEAD` at the time of writing is
`d3a1b9e4`, six commits later. The spec anticipated only docs-only commits
after the anchor; that expectation is **not** met — two of the six commits
touch `api/` and `spa/`:

- `da3d8f56` — `feat(dashboard): richer department_head default layout`
- `b1fe60d1` — `feat(dashboard): widgets for maintenance, assets, returns, CRM, budget, loans`

Combined drift across `api/` and `spa/` is 7 files, +210/-4 lines, confined to
the Dashboard read-model surface (`DashboardWidgetDataService.php`, widget and
role-layout seeders, migration `0442`, a settings request, a dashboard test,
and the SPA widget registry). No service in another module, no listener
registration, no outbox codec entry, and no job changed. This is why all seven
surface counts are identical at `feaa9621` and at `HEAD`.

Per the spec (`:26`), findings remain anchored to `feaa9621` and the drift is
noted here rather than re-baselined. Line citations in this document are valid
against `feaa9621`; readers checking against a later `HEAD` should expect
offsets only in the seven files listed above.

The working tree is otherwise clean: `git status --porcelain`, excluding
untracked `.codex/` and `.impeccable/` scratch directories, printed `0`.

### 1.3 The six edge sources

The inventory is enumerated from six sources rather than assembled from
memory, so coverage is checkable
(`docs/superpowers/specs/2026-08-11-process-hardening-audit-design.md:108`):

| Source | Yields |
|---|---|
| module `routes.php` + `api/routes/api.php` | HTTP entry points |
| `api/app/Providers/AppServiceProvider.php` `Event::listen` ×58 | async edges |
| `api/app/Common/Services/OutboxEventCodec.php` ×51 allowlist | durable edges |
| `api/routes/console.php` | scheduled entries |
| `Jobs/` ×9 | queued entries |
| cross-module import graph ×708 | direct-call edges, filtered to write-reaching |

The 708 figure originates in the design spec (`:67`). Unlike the seven surface
counts above, it was not measured by this task; it was re-measured
independently at `HEAD` before Task 3 relied on it, and confirmed at 708
(sum over all 22 modules of `use App\Modules\*\(Services|Models)\` imports,
excluding same-module imports). Task 2 re-derives it a third time as its own
gate.

The last row is the reason for the classification rule below. Direct
synchronous calls outnumber event edges by roughly an order of magnitude (708
cross-module `Services`/`Models` imports versus 53 event classes and 58
listener registrations), so an event-map-only audit would cover the minority of
coupling.

Every inventory row carries a disposition — traced, clean, or
parked-with-reason. No row disappears silently. The untraced list in section 5
is *generated* from rows lacking a disposition.

### 1.4 Classification rule — write reach, not import reach

Quoted verbatim from the implementation plan
(`docs/superpowers/plans/2026-08-11-process-hardening-audit.md:47-49`; the
design spec states the same rule at `:92-94` with an additional sentence):

> **Classification is by write reach, not import reach.** A process is
> cross-module only if it *writes* across module namespaces, or triggers
> something that does.

Applied: Production importing `Employee` to read a name is not a cross-module
process; Production calling `InventoryService` to deduct stock is. Import-only
reads are recorded on the edge list and dismissed with that stated reason, so
every dismissal is auditable rather than invisible.

Chain versus single-module splits on step count within one namespace: 2+
sequential state-changing steps makes it a chain. Depth is allocated forensic
on cross-module and chain processes; single-module processes are inventoried
and explicitly parked in the untraced list.

### 1.5 Evidence standard — PROVEN / ARGUED

Every claim carries `file:line`. Quoted verbatim from the implementation plan
(`docs/superpowers/plans/2026-08-11-process-hardening-audit.md:56-58`; the
design spec states the same standard at `:164-172` in different wording):

> Severe findings (data-corrupting, non-idempotent, race) carry an executable
> probe and are labeled PROVEN. A finding whose probe proves too expensive
> stays in the report labeled ARGUED — never silently upgraded.

A probe is a throwaway test driving the actual bad outcome, or the existing
`make chain-smoke` / `make worker-recovery-smoke` harnesses. Probes are deleted
after use; none are committed. The label is what makes the severity list
reviewable rather than assertive, and it is the specific failure mode of the
two prior documents (section 6).

### 1.6 Prior audits are hypotheses, not evidence

`docs/PROCESS-AUDIT-2026-08-10.md` (499 lines) and
`docs/PROCESS-FAILURE-MATRIX-2026-08-11.md` (137 lines) already claim this
scope and mark most boundaries closed. Three properties disqualify them as
evidence: both are self-authored, both remain unreviewed, and neither was ever
committed — they are untracked files, so no reviewer has ever seen a diff of
them. Every "Closed" claim is treated as an unverified hypothesis and
re-derived from current code with fresh citations. Contradictions are recorded
in section 6.

A correction belongs here, because it is the same error this section warns
against. An earlier draft of this document, following the design spec (`:57`,
`:209`), stated that both prior documents cite `Edge`, a module deleted in
`c3156301`. That is false. The string `Edge` appears **zero** times in either
document, case-sensitively or otherwise; the only `edge` substrings are inside
"acknowledgement" and "ledger". `docs/PROCESS-FLOWS.md` does contain `Edge`
three times — not nine — and all three are the heading phrase "Edge Cases",
unrelated to the deleted module. The deletion itself is real: `c3156301`
removed `api/app/Modules/Edge` across 9 paths. The claim entered the spec
un-verified and was inherited here un-verified, which is precisely the failure
mode described above. It is withdrawn; the conclusion of this section rests on
the three properties named in the paragraph above.

### 1.7 Severity classes

Findings group into five classes, on evidence only: data-corrupting, silent
failure, bypassable, non-idempotent, missing compensation. These are the
section 3 subheadings.

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
