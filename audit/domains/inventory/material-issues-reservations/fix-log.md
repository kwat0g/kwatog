# M042 — Material Issues & Reservations Fix Log

## 2026-08-25 re-audit

The prior report/plan were stale relative to later changes in the shared
worktree, so discovery, hardening, and polish were re-run. No production-code
fix was applied in this re-audit. The first ordered item requires a
process-owner decision between a final-at-create issue and an explicit
draft/pick/issue lifecycle; work-order issue ownership is also unresolved.

Refreshed artifacts:

- `audit-report.md` — current three-pass findings with file:line evidence.
- `action-plan.md` — ordered remediation plan with scope and session
  recommendations.

Pending before implementation:

- M042-F01 lifecycle decision.
- M042-F02–F04 reservation matching/order and work-order actual ownership.
- M042-F05–F15 hardening, API, permission, lot-policy, and SPA work.

## 2026-08-25 resumed plan session

The existing report and action plan were read in full after claiming M042.
Module source mtimes and the fix log show no M042 code changes after those
artifacts were written, so discovery was not repeated. No production-code fix
was applied: ordered item M042-F01 still requires the process owner to choose
between final-at-create issue semantics and an explicit draft/pick/issue
lifecycle. M042-F02–F15 remain pending because their implementation depends on
that lifecycle choice and the related work-order issue-ownership decision.

The module is released as `🔁 Needs Re-audit` so the next session can continue
after those decisions are supplied.

## 2026-08-25

No production-code fixes were applied. The module was claimed, audited, and
released as `📋 Plan Ready` because the majority of findings are inventory
state-machine, reservation, financial, idempotency, permission, or
cross-module changes that require a separate hardening session.

Audit artifacts written:

- `audit-report.md` — discovery, hardening, polish findings, and evidence.
- `action-plan.md` — ordered remediation items with scope and session
  recommendations.

Verification recorded in the audit report:

- Focused PHP suite: 30 tests reached setup but failed before assertions because
  PostgreSQL host `db` could not resolve.
- SPA `npm run typecheck`: passed.
- SPA `npm run audit:tokens`: passed (`769 files checked`).
- SPA `npm run audit:api-routes`: blocked because the API service was not
  running.

This is a plan handoff, not a claim that any finding is fixed. The next session
must implement the P0 reservation/lifecycle/idempotency controls, run the
focused tests against PostgreSQL, and then perform a fresh M042 re-audit.

## 2026-09-01 re-audit (session 4) — first session to actually execute anything

Claim: `RECLAIMED` (orphan lock from 2026-08-25, 154h stale).

**Prior state assessed:** three prior sessions, 15 findings, **zero production
fixes**, and a log that honestly self-flagged its verification as never having
run ("reached setup but failed before assertions because PostgreSQL host `db`
could not resolve"). That self-flag was accurate — nothing in M042 had ever been
measured. This session measured it.

### Verification numbers (real, non-zero assertions)

| Run | Result |
|---|---|
| Baseline `tests/Feature/Inventory` (before any change) | **177 passed / 621 assertions / exit 0** |
| Probe suite (24 probes, 5 runs) + 3 two-process race scenarios | all quoted in `audit-report.md` |
| `tests/Feature/Inventory` after adding the new test | **188 passed / 660 assertions / exit 0** |

188 = 177 + 11 new; 660 = 621 + 39 new. **No pre-existing test changed and none
regressed.** One earlier run was discarded because another session cycled the
database container ("the database system is starting up", 0 assertions — the
documented tell); it was re-executed after the container returned healthy.

### Production code changed: NONE

Deliberate, and argued from measurement rather than caution. The module's P0
cluster (M042-N01, N02a/b/c) is one defect — `stock_levels.reserved_quantity` is
an unattributed scalar that is never reconciled against `material_reservations` —
and the measured interaction is that **fixing the obvious half would make the
system worse**: N01's spurious refusal (a reservation cannot be drawn against)
is today accidentally containing N02b (issuing against one reservation releases
another work order's). Correct the ordering alone and issues that currently fail
safely start succeeding *and* over-releasing. Detail in `action-plan.md` item 1
and the "Critical interaction" section of `audit-report.md`.

Everything else is explicitly outside containment per the session brief: item 2
changes what an issue may exceed, item 5 changes a money figure, items 9/10/13
change database contracts, a shared immutability exposure, and who may read.
Items 6 and 8 *are* contained and are flagged `same-session-ok` for whoever takes
item 1, since they edit the same method.

### Added

- `api/tests/Feature/Inventory/MaterialIssueReservationInvariantTest.php` —
  11 tests / 39 assertions pinning the invariants that were **measured to hold**:
  `available = on_hand − reserved` against raw SQL, `reserved ≤ on_hand` (single
  and cumulative, and under an adjustment), stock not issuable out from under a
  reservation, negatives refused at all three reserve/release/issue call sites,
  release flooring and idempotency, the `decimal:0,3` quantity rule against 8
  hostile values including a map payload, both registry roles completing their
  part while `employee` is refused everywhere, and WAC-neutral cancellation that
  cannot be repeated.

  **Labelled in the file's own docblock, and repeated here:** every one of these
  passed on its FIRST run against unmodified source. They are forward regression
  guards, **not** evidence any finding was fixed. The P0 defects are deliberately
  **not** encoded as green tests — asserting broken behaviour green is precisely
  how the 8D SLA ledger stayed invisible for its entire life.

  Pint: the new file failed `--test` on creation and was fixed in place (it is
  mine, so no inheritance claim); it now passes, `php -l` is clean, and the test
  was re-run green after reformatting.

### Removed

- Scratch probes `api/tests/Feature/Inventory/ZzM042ProbeTest.php` and
  `api/m042_race.php` deleted. Scratch databases `ogami_test_matiss` and
  `ogami_race_matiss` dropped.

### Out-of-module defects — reported, not touched

- **`App\Common\Services\DocumentSequenceService`** (shared by every module) loses
  a first-of-month race: its lock-or-create uses a plain `insert()` where
  `StockMovementService::lockOrCreate()` correctly uses `insertOrIgnore()`. Two
  concurrent processes generating the first number of any type in any month
  produced `SQLSTATE[23505] duplicate key`, which `DB::transaction()`'s deadlock
  retry does not cover. Measured; see `audit-report.md` Race 3.
- **`Production\Services\WorkOrderService`** consumes material and maintains
  `work_order_materials` actuals but never creates a `MaterialIssueSlip`, while
  this module's canonical path creates the slip and never maintains the actuals.
  Two disjoint paths (M042-N12) — a process-owner question, not a unilateral fix.
- **`material_issue_slips` / `_items` / `material_reservations` are fully mutable
  with 0 non-internal `pg_trigger` rows** (M042-N13). Same exposure as the
  `stock_movements` N4 finding that `warehouse-stock-control` deferred, and any
  fix must be column-scoped because `stampLot()` and the GL handoff legitimately
  update rows. Not unilaterally triggered, per that handoff.

### Released as `📋 Plan Ready`

The audit is complete and fully measured. The plan is ordered, and the first item
is a coherent unit of work that a fix session can take in one pass together with
the two contained items.
