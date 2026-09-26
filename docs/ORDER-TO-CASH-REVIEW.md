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

## Test alignment — 2026-09-27

Three `NcrReplacementSoLineageTest` cases still expected NCR close to create a work order for
an output that belongs to a root work order on a sales-order line. That contradicts the
one-owner rule above (`WorkOrder::coversSalesOrderLine()`), so the fixture now marks which
source outputs MRP owns: an order-line root output gets no NCR work order, while a child
output that still carries sales-order lineage keeps its lineage on the NCR replacement. The
MRP-rerun case asserts the MRP-planned shortfall is counted once instead of looking for a
second NCR work order.

## Validation

| Check | Result | Evidence |
| --- | --- | --- |
| Chain-1 repair suite (the commit above) | passed, with a failing-before test per fix | `8547c711` |
| First full browser run | 52 passed, 12 failed — harness gaps, not product | `/tmp/o2c-headless-O2CDEV5/report.json` |
| Material issue + stock reservation + invoice read-back | 84 passed, 6 failed | `/tmp/o2c-headless-O2CDEV6/report.json` |
| Cancel-reversal assertion read through the entry, not the invoice | 104 passed, 1 failed | `/tmp/o2c-headless-O2CDEV8/report.json` |
| Full run, all four phases | **105 passed, 0 failed, 0 5xx, 0 page errors** | `/tmp/o2c-headless-O2CDEV9/report.json` |
| Repeat with real teardown (`O2C_KEEP` unset) | **105 passed, 0 failed**, database dropped, ports freed | `/tmp/o2c-headless-O2CDEV10/report.json` |
| Isolated ports (`O2C_API_PORT=8233`, `O2C_SPA_PORT=5233`) while another run held 8230/5230 | **105 passed, 0 failed**, zero 401s | `/tmp/o2c-iso1/report.json` |
| Ten further repeats after the UI-fidelity and session work | **105 passed, 0 failed** each (O2CDEV13–16, 18–22, 25) | `/tmp/o2c-headless-O2CDEV25/report.json` |
| One repeat captured the lost session with new evidence | 6 passed, 2 failed — see the session finding below | `/tmp/o2c-headless-O2CDEV17/report.json` |
| Injected session loss (`O2C_FAULT_SESSION_DROP=finance@ogami.test`) | **105 passed, 0 failed**, 1 session + 1 CSRF recovery recorded | `/tmp/o2c-headless-O2CDEV24/report.json` |
| Session fix in place, five phases | **107 passed, 0 failed** (O2CFINAL5, O2CFINAL6) | `/tmp/o2c-headless-O2CFINAL6/report.json` |
| Same, with five real session losses mid-chain | **107 passed, 0 failed**, every role re-signed in and replayed | `/tmp/o2c-headless-O2CFINAL7/report.json` |
| The new check against the *old* interceptor (probe disabled) | 2 failed — the user is thrown to `/dashboard/plant-manager` | `/tmp/o2c-headless-O2CNOPROBE/report.json` |
| `SalesOrderChainBillingDatesTest` — three billing-date cases | passed, proven to fail before the fix | `api/tests/Feature/CRM/SalesOrderChainBillingDatesTest.php` |
| `CustomerCaseDraftInvoiceCreditTest` — two draft-invoice cases | passed; the old code returned 200 where the test now expects 422 | `api/tests/Feature/ReturnManagement/CustomerCaseDraftInvoiceCreditTest.php` |
| `tests/Feature/Accounting` (full suite) | 265 passed | local run |
| `tests/Feature/ReturnManagement` (full suite) | 135 passed, 1 pre-existing unrelated failure (`ReturnCaseCustomerReplacementTest`) | local run |
| Acceptance run after both fixes (`O2CSO1`) | **107 passed, 0 failed, 0 5xx, 0 page errors** | `/tmp/o2c-headless-O2CSO1/report.json` |
| `tests/Feature/Quality` + `tests/Feature/Inventory` after aligning stale tests to current contracts | **536 passed, 1993 assertions, 0 failures** | local run `invq0927c` |
| `NcrReplacementSoLineageTest` after the one-owner rewrite | 5 passed | `api/tests/Feature/Quality/NcrReplacementSoLineageTest.php` |

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
context, and drives five phases:

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
- **Session resilience** — the runner forces one 401 on the production work-order list
  through Playwright route interception and asserts the user stays on the page and still
  gets the data. This is the permanent regression check for the fix below; disabling the
  interceptor's session probe makes it fail (`url=…/dashboard/plant-manager`).
- **Complaint → 8D** — the customer files a complaint in the portal, an NCR opens from it,
  customer service writes and finalizes the 8D, QC dispositions and closes the NCR, and the
  customer reads the finalized report back in the portal.

Final result: **107 checkpoints passed, zero server errors, zero page errors**, with
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
- **Two findings raised by earlier passes are now fixed, each with a failing-before test.**
  1. **The closing status name was sound; the timeline and its dates were not.**
     `SalesOrderService::synchronizeCompletionState()` requires every invoice to be `paid`
     before it can choose `paid` or `closed`, so the `invoiced` status is only ever set by
     the explicit `markInvoiced()` path and the label is not misleading. The real defects
     were that the direct `delivered → closed` transition wrote no `invoiced_at` and no
     `paid_at`, and that `chain()` derived the **Invoiced** tile from the status alone, so a
     delivered order whose invoice was already finalized still showed Invoiced as pending.
     Both are fixed: the completion write now stamps `invoiced_at` from the latest invoice
     date (only once nothing on the order is still a draft) and `paid_at` from the last
     collection, and `chain()` reads the invoices themselves instead of the status. Test:
     `api/tests/Feature/CRM/SalesOrderChainBillingDatesTest.php` — a finalized invoice marks
     the step done with its date while the order stays `delivered`; a one-step close stamps
     both dates; a draft invoice leaves Invoiced pending with no date.
  2. **A return case settled against a draft invoice billed the customer twice.**
     `ReturnCaseSettlementService::createCredit()` selected only from invoices in
     `finalized|partial|paid`, so a return against a delivery whose invoice was still a draft
     wrote a customer credit note with `invoice_id = null` while the draft kept billing the
     returned goods. It now stops and tells the operator to post the invoice first:
     `Finalize invoice <number> before crediting this return; it still billed the returned
     goods.` — the same rule the supplier side already applied to an unbilled shortage.
     Returns that legitimately have no invoice (the path documented by
     `CustomerReturnNoInvoiceCreditTest`) still credit against a null invoice. Test:
     `api/tests/Feature/ReturnManagement/CustomerCaseDraftInvoiceCreditTest.php`.
- **One repeat run lost a role's session, and it is not a chain defect.** A later repeat
  reported 89 passed / 7 failed; every failure was a 401 on the finance session. Finance had
  logged in eight roles earlier, idle for roughly two minutes — far inside the configured
  15/30-minute idle timeout — and the auth log records no logout or lockout. The first
  finance call of the chain (`finalize`) was already unauthenticated, so no chain behaviour
  was involved. Two other repeat runs were fully green (105 checks each). The cookie jar
  recorded on a 401 in an overlapping run still held the session cookie, which is what
  pointed at the concurrency rule below: treat a cluster of 401s as harness session loss,
  not a regression, and re-run the scenario on its own ports.
- **Never run two of these at once.** The runner binds a fixed API port (8230) and its own
  `setUp()` frees that port by killing whatever holds it. Two overlapping runs therefore
  poison each other: the later run kills the earlier run's API server and re-points the port
  at *its* database, so the earlier run's still-authenticated browsers keep sending requests
  that now land on a database where their session id does not exist. The result is a 401
  **with the session cookie still present** — indistinguishable, in the report, from a
  genuine session drop. Two repeats that overlapped in time produced exactly that signature.
  The rule is confirmed from the other side: one run given its own `O2C_API_PORT` and
  `O2C_SPA_PORT` finished 105/0 with zero 401s *while* another run occupied 8230/5230. Run
  one scenario at a time, or give each run its own ports.
- **A second signature exists, and it is the one that reaches users.** O2CDEV17 was one of a
  sequential loop of runs, and the runner now records what the browser actually sent on the
  request that 401'd. That capture shows the opposite of the port-sharing signature: the
  request went out with **no `ogami_erp_session` cookie at all** while the context's cookie
  jar still held one, and the document was `/sign-in` with `document.referrer` = `/dashboard`
  — the SPA's own `window.location.href = '/sign-in'` reaction to a 401
  (`spa/src/api/client.ts`). The role's `sessions` row was abandoned immediately after login
  while guest rows accumulated. So the request that 401'd was made *after* the SPA had
  already thrown the user back to the sign-in screen, and the cookie that arrived with it was
  a fresh guest one. Whether the trigger was the fixed-port collision or something that races
  the post-login session rotation, the user-visible outcome is the same: a signed-in user
  lands on `/sign-in` mid-task. The port rule removes one cause; it does not by itself make
  that outcome impossible, so the SPA's hard redirect on a 401 was changed. **Fixed**:
  `spa/src/api/client.ts` now asks `GET /auth/user` (outside the interceptor) before
  navigating and, when the session is still alive, replays the failed call once; only a probe
  that also fails reaches the redirect, and a request that has already been replayed once
  never re-probes, so there is no loop. The runner keeps this honest with the **Session
  resilience** phase above: with the probe disabled the check fails and the user lands on
  `/dashboard/plant-manager`; with it enabled the user stays on the work-order list and the
  replayed call returns 200.
  The runner no longer fails on either signature: on a 401 it re-signs the role in and
  replays the call, each recovery counted in `report.session_recoveries` and printed, so
  nothing is hidden. Sign-in retries are counted separately in `report.login_retries`, the
  driver's multipart receipt upload recovers the same way, and a fetch that dies because the
  SPA hard-navigated under it is resent instead of aborting the run.
  `O2C_FAULT_SESSION_DROP=<email>` drops that role's session rows after login to exercise the
  path on demand — O2CDEV24 ran 105/0 with the recovery recorded, and O2CFINAL7 recovered
  five real losses (production, driver, finance twice, customer service) to finish 107/0.
- **The runner's screenshots used to misrepresent the UI.** A programmatic Vite server
  started from the repo root does not pick up the SPA's PostCSS/Tailwind config, so every
  page rendered unstyled and `happy-so-closed.png` read like plain text. The runner now runs
  the SPA toolchain from the SPA root and loads Tailwind against an absolute config path, so
  the screenshots show the product's real layout — checked against the running dev container,
  whose `globals.css` carries the compiled utilities.
- **A run that is not clean keeps its evidence.** Failures, server errors and session/CSRF
  recoveries all leave the run database and API server in place, so the rows behind a finding
  can be inspected without re-running with `O2C_KEEP=1`. A 401 also records the request
  headers, the document state and the cookie jar at that moment.
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

Both files were committed in `9723147a`. The follow-up work described above touches
`scripts/o2c-headless.cjs`, this file, and the product fix in `spa/src/api/client.ts`
with its tests in `spa/src/api/__tests__/client.test.ts`.

The last round of fixes — the return-case draft-invoice guard and the sales-order billing
dates — touches `api/app/Modules/ReturnManagement/Services/ReturnCaseSettlementService.php`,
`api/app/Modules/CRM/Services/SalesOrderService.php`, this file, and two new tests
(`api/tests/Feature/ReturnManagement/CustomerCaseDraftInvoiceCreditTest.php`,
`api/tests/Feature/CRM/SalesOrderChainBillingDatesTest.php`). They were committed on their
own; the payroll, HR and auth-notification files other sessions held at the same time were
left untouched.

No other session's file was edited, reverted, reformatted or staged. No reset, clean,
stash or rebase was performed, and the shared development database was never migrated or
re-seeded. The run databases created while chasing this (`ogami_test_o2c_browser_o2cdev*`)
were dropped again once the evidence was read.

## Repeating the headless scenario

1. Ensure the stack is up (`docker compose up -d`) and that no other run of this scenario is
   in flight. The runner frees port 8230 itself, which is exactly what wounds a run already
   using it — give a concurrent run its own ports.
2. From the repo root:

   ```bash
   O2C_RUN_ID=<UNIQUE6TO16> O2C_OUTPUT=/tmp/o2c-headless-<UNIQUE> node scripts/o2c-headless.cjs
   # a second run alongside the first:
   O2C_RUN_ID=<OTHER> O2C_API_PORT=8231 O2C_SPA_PORT=5231 O2C_OUTPUT=/tmp/o2c-headless-<OTHER> \
     node scripts/o2c-headless.cjs
   ```

   Optional: `O2C_KEEP=1` to keep the database and API for inspection.
3. Read `report.json` in the output directory; it carries every check with its evidence,
   plus any 5xx and page errors. Exit code is non-zero when anything failed.
4. Drop the run's database (`ogami_test_o2c_browser_<run>`) if `O2C_KEEP=1` was used. The
   fixture accounts and password `password` are local test data only.
