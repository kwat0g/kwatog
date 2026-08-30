# M056 — Inspections & Certificates fix log

**Audit date:** 2026-08-25  
**Claim:** quality/inspections-certificates  
**Result:** no production-code fixes applied

## Current session — full re-audit after source changes

The module was reclaimed from `📋 Plan Ready` after the registry refresh. Source files had changed after the prior report, so discovery, hardening, and polish were rerun. The current report and action plan were rewritten with current file/line evidence. The fresh plan is intentionally deferred because it contains P0 findings and coordinated cross-module/large-scope work.

Verification performed:

- Focused Quality/return backend command was attempted, but all 50 tests stopped before assertions because PostgreSQL host `db` could not be resolved for `ogami_test` (`SQLSTATE[08006]`). No test pass/fail result is inferred from that run.
- PHP syntax checks passed for the Quality source files and relevant migrations.
- `npm run audit:tokens` from `spa` passed with 776 files checked.
- `npm run audit:rbac` from `spa` passed with 0 referenced-but-unseeded permissions; the catalog contained 248 permissions and 242 static references.
- `npm run typecheck` from `spa` remained blocked by unrelated errors in `src/pages/assets/detail.tsx` and `src/pages/return-management/detail.tsx`; no M056 path appeared in the output.
- `git diff --check` was clean.

No production files outside this module's audit artifact directory were modified by this session. The module remains `📋 Plan Ready` and must be released after the registry refresh.

## Current resumed-plan session — deferred at ordered item 1

- Plan item 1 (`IC-01`, `IC-02`) was not implemented because it requires human/product and Inventory decisions about whether fractional received quantities are valid inspection units, how quantity conversion and accepted quantities must be represented, and whether AQL Ac/Re defects are failed sampled units or failed parameter rows.
- Plan items 2–10 remain pending and were not attempted; the ordered plan was stopped at the first decision-dependent item.
- No production code was modified and no fix verification was run in this session.

## Session aborted 2026-08-30 — API quota exhausted mid-discovery

The 2026-08-30 session was killed by `403 pre-consume quota failed` during the
hardening pass. It wrote **no audit-report, action-plan or fix-log entry**,
applied **no production change**, and left no committed work. Nothing here was
verified by the coordinator.

**Partial state, recorded second-hand from the session's last output and NOT
independently confirmed:** it had surveyed `api/routes/console.php` and reported
that **no scheduled command runs inspections directly**, and was about to execute
the three commands that touch inspection data to check whether each distinguishes
"nothing to do" from "everything threw". That check was never completed.

It also left a scratch probe behind, untracked and uncommitted:
`api/tests/Feature/Quality/ZzQcInvariantProbeTest.php`. Read it before deleting —
it may encode which invariants had already been set up — but do not trust it as
evidence of anything, since no run of it was reported.

## Re-audit 2026-08-30 (second session that day) — P0 closed, 4 fixes shipped

**Claim:** CLAIMED. **Commit:** `8d126a1f`.

### First, the reason four sessions could not measure anything

The `SQLSTATE[08006]` "host `db` could not be resolved" that blocked the
2026-08-25 session was not a broken test bootstrap — **every container in the
compose project was stopped.** `ogami-db` had exited 39 minutes before this
session began. Started `db` + `redis` only (no `down`, no volume change), created
`ogami_test_qc2`, and the suite ran immediately. Every prior "verification
performed" line in this file above should be read as static-analysis only.

Pre-existing lifecycle baseline captured **before** touching anything:
**56 passed / 0 failed / 130 assertions.** A real run with real assertions —
not the zero-assertion signature of two suites sharing one database.

### Prior work assessed

11 open findings. **9 still reproduce verbatim** (IC-01, IC-03, IC-04, IC-05,
IC-06, IC-07, IC-08, IC-09, IC-10, IC-11). **IC-02 partly superseded**: the AQL
table, arrow rule, lot clamp and both Ac/Re boundaries measured **correct** —
what remains is only the per-row-vs-per-unit definition question. 8 new findings
added (IC-12…IC-20).

The aborted session's scratch probe `ZzQcInvariantProbeTest` was read, run,
and **promoted**: its CoC and evidence cases are now
`api/tests/Feature/Quality/CoCEvidenceIntegrityTest.php`. Both scratch probes
deleted.

### Fix 1 (P0) — CoC bound to its evidence

`CoCService::assertEligible()` trusted `status = passed` alone. Measured at
HEAD, all four of these **issued a certificate**: zero measurement rows; 45 of
50 sampled units unresolved; every reading rewritten to fail after issue; all
evidence rows deleted after issue (same number `COC-202608-0001`, blank
critical-dimension table). Added `assertEvidenceSupportsCertificate()` with four
typed refusals.

### Fix 2 (P1) — one certificate number, one document

`generateForInspection()` re-rendered and re-stored on every call, so the vault
held N documents claiming one number with N different checksums (`issued_at` and
the requesting user are in the payload). Now streams the certificate on file.
Side benefit: the `GET /coc` endpoint no longer writes.

### Fix 3 (P2) — `measured_value` bounded

`numeric` against `decimal(12,4)`: `10.00005` stored as `10.0001` and evaluated
PASS (HTTP 200); `1e20`, `-1e20`, `99999999999999` → HTTP **500**. All five now
HTTP 422, nothing stored.

### Fix 4 (P1/P3) — SPA completion safety and input labels

Complete was gated only on `unresolvedCount`, never `dirtyCount`, and
`save.onSuccess` never cleared dirty flags while the seeding effect deliberately
preserves them — so a saved row stayed dirty forever. Both fixed. Every numeric
input had the same `aria-label="Measured value"`; now includes parameter, sample
and unit.

### Verification

- `CoCEvidenceIntegrityTest`: **7 pass** on the change; **6 of 7 fail** against `git show HEAD:` source (restored, `sha256sum -c` OK). The 7th is a labelled pass-either-way control asserting a properly inspected lot still certifies.
- Lifecycle suite after: **54 passed / 2 failed**. Both failures are `tests/Feature/SupplyChain/CocAutoAttachOnConfirmTest`, sharing one fixture that mass-assigns a `passed` outgoing inspection with **zero measurement rows** — the exact falsification the guard exists to stop. No production path reaches that state: `complete()` refuses unresolved rows and the no-spec fallback stays `draft`. Guard kept; fixture needs its SupplyChain owner. **Not touched — out of module.**
- `php -l` clean · PHPStan `[OK] No errors` · ESLint 0 · `npm run typecheck` clean.
- Pint fails on both changed PHP files, **inherited**: the fixer list is byte-identical when run against the `git show HEAD:` extract of each file (CoCService: `unary_operator_spaces, braces_position, not_operator_with_successor_space, single_line_empty_body, blank_line_before_statement, binary_operator_spaces`; RecordMeasurementsRequest: same plus `control_structure_braces, statement_indentation`). Zero new violations.

### Deliberately NOT done

- **Backed out mid-fix:** sending `measured_value` to the API as a string. `InspectionMeasurementResource` returns decimals as JSON floats, so this would have half-migrated the contract. Reverted with an `AUDIT NOTE` in `detail.tsx` so the next reader does not re-derive it.
- **Not changed, by protocol:** the AQL defect definition, the CoC permission gate, and what `createIncomingForItem()` claims. Each changes an IATF-auditable meaning and needs a human ruling.
- **Not measured:** IC-04, IC-06, IC-09 (static citations only); the outgoing-delivery gate by my own probe (its existing test passes, 5/5); sequence uniqueness under true concurrency — my two-connection probe **deadlocked** (B blocks on A's uncommitted unique insert) and was abandoned rather than reported as a result.

`ogami_test_qc2` dropped. `db`/`redis` left running.
