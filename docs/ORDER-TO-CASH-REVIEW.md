# Order-to-cash review — 2026-09-27

The complete order-to-cash chain (Chain 1) now runs end to end through the real SPA and
API, under role logins, with no IT intervention and no dead ends. Failures were fixed in
`8547c711`; this review's remaining work is the headless acceptance runner and the fixture
that keeps that claim honest.

## Scope and behavior

```
Sales order → MRP plan → MRP II schedule → Work order → material issue → in-process QC →
output → outgoing QC (AQL) → review → delivery draft → assignment → stock reservation →
loading / in transit / delivered → proof of delivery → portal confirm → draft invoice →
finalize → collection → GL → order closed
                       ↘ failed lot → NCR → replacement work order (once) ↘
                       ↘ customer complaint → NCR → 8D → portal report ↗
```

Behavior changed by `8547c711` (the repair pass this runner exercises):

- A planner could not open QC work from the Action Center, and the outgoing review was
  reachable only by knowing the inspection number. Review cards now open the inspection
  directly, and the in-process stage records the sample the spec asks for instead of a
  full run (`0562_seed_in_process_sample_size`). Sampling follows Z1.4 Level II
  (`0563_align_aql_sample_plan_with_z14`), so a 150-piece lot samples 20, not 150.
- A failed outgoing lot had **two owners**: MRP re-planned the order while closing the NCR
  also created a replacement work order, doubling production. `WorkOrder::coversSalesOrderLine()`
  is now the ONE rule for who replaces a batch: a root work order on a sales-order line is
  MRP's (`QueueMrpOnOutgoingInspectionFailed`), and NCR close creates a work order only for
  a stock batch. A 10-piece failed lot no longer leaves 20 open.
- `SalesOrderConfirmed` (and the other chain facts) died whenever a listener touched a
  newer row first. `ToleratesNewerModelState` marks an outbox event as a fact that may be
  delivered with newer state; the codec still refuses an older one.
- ImpEx held the delivery pages and the outbound permission did not cover dispatch
  (`0561_grant_outbound_dispatch_to_impex_officer`); the delivery status list offered
  transitions the guard refused. Both are aligned, and the portal now exposes
  `can_confirm` so the customer is not offered a confirmation the server rejects.

Nothing in the runner's own scope weakens a product guard. Where a check failed, either the
harness was not doing what the shop floor does (issuing material, reserving stock) or the
product was wrong and was fixed with a test.

## Validation

| Check | Result | Evidence |
| --- | --- | --- |
| Chain-1 repair suite (the commit above) | passed, with a failing-before test per fix | `8547c711` |
| First full browser run | 52 passed, 12 failed — harness gaps, not product | `/tmp/o2c-headless-O2CDEV5/report.json` |
| Material issue + stock reservation + invoice read-back | 84 passed, 6 failed | `/tmp/o2c-headless-O2CDEV6/report.json` |
| Cancel-reversal assertion read through the entry, not the invoice | 104 passed, 1 failed | `/tmp/o2c-headless-O2CDEV8/report.json` |
| Full run, all four phases | **105 passed, 0 failed, 0 5xx, 0 page errors** | `/tmp/o2c-headless-O2CDEV9/report.json` |
| Repeat with real teardown (`O2C_KEEP` unset) | **105 passed, 0 failed**, database dropped, ports freed | `/tmp/o2c-headless-O2CDEV10/report.json` |

Runs O2CDEV2–O2CDEV4 and O2CDEV7 aborted at login with 5xx and a `waitForURL` timeout.
Those were **not** product failures: a run killed before its teardown left `artisan serve`
bound to the fixed API port, still pointing at a database that had since been dropped, so
every session write failed. The runner now frees the port before starting its own server
and proves the new one answers a database-backed request before any logins.

## Headless acceptance

Runner: `scripts/o2c-headless.cjs`. Prerequisites: `api/tests/Browser/o2c_fixture.php`
(master data only — the browser creates every order, work order, inspection, delivery,
invoice, collection and complaint).

```bash
O2C_RUN_ID=O2C1 node scripts/o2c-headless.cjs          # from the repo root, stack up
O2C_RUN_ID=O2C1 O2C_KEEP=1 node scripts/o2c-headless.cjs   # keep the database and API
```

The runner creates `ogami_test_o2c_browser_<run>`, migrates it, runs the fixture through
`artisan tinker`, serves a temporary API on it and a temporary Vite proxy in front, then
tears everything down. The shared development database is never touched. Queues are
synchronous so every listener runs inline as a worker would; mail is captured, and
broadcasts are logged.

It logs in nine staff roles plus the customer portal account, each in its own Chromium
context, and drives four phases:

- **Happy path** — order → plan → schedule → work order → material issue → in-process QC →
  output → outgoing QC → Action Center review → two delivery drafts → assignment →
  reservation → loading/in-transit/delivered → POD → portal confirm → **one draft invoice
  per confirmed delivery** → finalize → collect both → **the order closes**.
- **Failed batch** — a failed outgoing result opens an NCR, MRP re-plans immediately, and
  closing the NCR adds **no second** replacement work order. Nothing ships from the failed
  batch.
- **Billing corrections** — finalize, cancel an unpaid invoice, the order steps back to
  delivered, cancelling reverses the invoice's own journal entry, Finance re-bills from the
  delivery page, then a credit note and a partial payment leave the invoice `partial`
  before the rest pays it off. Every journal entry is balanced at the end.
- **Complaint → 8D** — the customer files a complaint in the portal, an NCR opens from it,
  customer service writes and finalizes the 8D, QC dispositions and closes the NCR, and the
  customer reads the finalized report back in the portal.

Final result: **105 checkpoints passed, zero server errors, zero page errors**, with
`happy-so-closed.png` and `billing-invoice-paid.png` written to the output directory.

Negative checks are real API calls, not UI guesses: a work order with no good output
cannot complete, a failed result needs the checker's remarks, proof is required before the
portal offers Confirm, and a customer cannot confirm a delivery that has not arrived.

## Boundaries and remaining operational risks

- **The vehicle pool is one van per run.** A second concurrent delivery on the same vehicle
  is refused by design (`assertDispatchAssignmentAvailable`); the runner reuses the van only
  after the first shipment is confirmed and the vehicle is released. A real dispatch desk
  with one van has the same constraint.
- **Stock reservation is a dispatch responsibility, not a scheduler one.** Scheduling
  reserves accepted QC/SO capacity; departure authoritatively rechecks physical stock under
  locks. The runner reserves explicitly, as the dispatch desk does.
- **Material coverage is checked at output time.** Production cannot record more output
  than the material issued covers (`assertProductionCoverage`), so the runner issues resin
  for the gross units it is about to record — including the rejects.
- **Two known findings were reported, not fixed** (both need a product decision, and one
  lives in another session's module):
  1. `SalesOrderService::synchronizeCompletionState()` goes straight from delivered to
     `closed` and only lands on `paid` when the order is delivered but *not* fully invoiced.
     The status name reads as "customer paid"; the transition it actually marks is
     "invoiced". No runner check depends on it — the closing check is the one that matters.
  2. `ReturnCaseSettlementService::createCredit()` selects from invoices in
     `finalized|partial|paid`, so a return case settled against a delivery whose invoice is
     still a draft creates a customer credit note with `invoice_id = null` while the draft
     still holds the delivery lines. Out of this review's blast radius (Return Management).
- **The dev stack still has no queue worker or scheduler.** The runner sets
  `QUEUE_CONNECTION=sync` so listeners run inline; the operator walkthrough relies on that
  too. A production deployment needs both.

## Change isolation

This workspace is shared with other concurrent sessions. At the start and end of this work
`git status --short` listed only the two files this review owns:

```
 M api/tests/Browser/o2c_fixture.php
 M scripts/o2c-headless.cjs
```

No other session's file was edited, reverted, reformatted or staged. No reset, clean,
stash or rebase was performed, and the shared development database was never migrated or
re-seeded.

## Repeating the headless scenario

1. Ensure the stack is up (`docker compose up -d`) and no `artisan serve` is holding port
   8230 — the runner frees it itself, but a leftover server is the one condition that has
   produced misleading 401s.
2. From the repo root:

   ```bash
   O2C_RUN_ID=<UNIQUE6TO16> O2C_OUTPUT=/tmp/o2c-headless-<UNIQUE> node scripts/o2c-headless.cjs
   ```

   Optional: `O2C_API_PORT` / `O2C_SPA_PORT` for a busy machine, `O2C_KEEP=1` to keep the
   database and API for inspection.
3. Read `report.json` in the output directory; it carries every check with its evidence,
   plus any 5xx and page errors. Exit code is non-zero when anything failed.
4. Drop the run's database (`ogami_test_o2c_browser_<run>`) if `O2C_KEEP=1` was used. The
   fixture accounts and password `password` are local test data only.
