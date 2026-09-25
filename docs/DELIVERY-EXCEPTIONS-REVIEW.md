# Delivery exceptions and truck returns — 2026-09-25

Role-based headless acceptance and focused backend regressions passed. This review extends `DELIVERY-DISPATCH-REVIEW.md` with failed-delivery recovery; it does not change RFQ or quotations.

## Process and invariants

1. The assigned Driver or ImpEx records an immutable delivery outcome: received by the customer, returning on the truck, and unaccounted quantities. Damaged quantities are subsets of received/returning goods. Every line must reconcile to its original dispatched quantity.
2. A driver report does not receive inventory. The delivery remains pending depot reconciliation and cannot be confirmed or invoiced through the normal status action.
3. Warehouse makes one final physical count. Actual returned goods enter quarantine against their exact dispatch movements. A difference from the driver report requires an explanation. Missing goods remain recorded as a variance and never produce a fictitious stock receipt.
4. Returned goods use the existing RMA inspection/disposition path. Passing inspection and an explicit restock disposition are required before these goods can supply another dispatch. This custody return does not authorize a customer credit.
5. The original delivery keeps its dispatched quantity. A fully refused delivery with no customer receipt ends as Returned and produces no invoice. A partial receipt uses the actual customer quantity for SO coverage, customer confirmation, invoicing and customer-return source limits.
6. Redelivery uses a new delivery against the original SO's remaining quantity. Reusing an approved output is limited to stock actually returned, inspected and restocked. Goods lost or scrapped do not replenish its dispatch capacity.

The normal workflow still uses proof of delivery, customer confirmation, invoice and CoC handoffs. Exception inputs are progressive: two main quantity fields per line, optional damaged quantities, and an automatically displayed residual. Unknown save results retain the original user-scoped request and UUID for retry after reload.

## Accounting boundary

Existing `Delivery` stock movements are intentionally excluded from `MovementGlPostingService`. Inspection of the current delivery/invoice path found AR/revenue/VAT posting but no corresponding COGS entry. A truck return must therefore restore inventory custody without manufacturing an inventory/COGS GL reversal or customer credit. The explicit source-linked truck-return movement follows this rule.

The existing outbound COGS posting gap requires a separate Accounting follow-up. Accurate quantity and stock-value reconciliation here must not be described as proof of a complete financial posting chain.

## Verification

| Check | Result | Evidence |
| --- | --- | --- |
| Compiled SPA (`tsc -b` + Vite) | Passed | `/tmp/delivery-exception-build-final.log` |
| Scoped frontend ESLint | Passed | `/tmp/delivery-exception-eslint-final.log` |
| Existing customer delivery Vitest | Three tests passed | `/tmp/delivery-exception-portal-vitest-final.log` |
| Real-role headless Chromium | 26 checkpoints passed; zero JavaScript errors | `/tmp/delivery-exception-headless-DXP0925C/report.json` |
| Settled read-only revisit | Three checkpoints passed; zero JavaScript errors | `/tmp/delivery-exception-readonly-DXP0925C/report.json` |
| Backend focused regression | 95 tests, 419 assertions passed | `/tmp/ogami-delivery-exceptions-focused-20260925.log`; isolated DB `ogami_test_delivery_excp0925sv01` |
| PHP syntax | Passed for changed API PHP files | Backend verification, including new migration and test class |

The backend run covered the new six-case `DeliveryAttemptOutcomeServiceTest`, ordinary delivery status/quantity/provenance/driver/confirmation/invoicing tests, customer portal confirmation and return visibility, cross-document return quantities and disposition/cost, and Quality maker-checker/result-author tests. The new cases include unaccounted-only reconciliation without stock/RMA/GL creation, a partial source recovery that cannot be repeated beyond the actual receipt, safe request replay and changed-payload rejection, customer-received damage holds, and checked-restock-only reuse of outgoing capacity. Early new-test failures were invalid fixtures (same outgoing maker/checker and an omitted required depot variance reason); the service guards remained intact.

The dedicated browser scenario is `scripts/delivery-exception-headless.cjs`, with prerequisites in `api/tests/Browser/delivery_exception_fixture.php`. It verified a six-unit full refusal, quarantine/Quality/restock and redelivery, followed by a four-unit partial receipt and redelivery of the remaining two. Final totals were ten units received/invoiced and five unrelated unapproved units still in inventory. Quantity reports and depot receipts each exercised loss of a successful response, reload and exact-request retry. Other-driver access/reporting and driver depot-receipt authority were denied. Confirmation and reuse before QC were blocked; the inspector could not approve their own return inspection.

Database reconciliation agrees: four deliveries ended as one `returned` and three `confirmed`; the SO records `10.00` delivered. Both truck RMAs completed, with all three split-source return lines released. Gross dispatch was 18 units / ₱81.00; truck receipt was eight units / ₱36.00, yielding ten net units / ₱45.00. The invoices created by confirmation are drafts; this acceptance does not claim Finance finalized them or that it tested invoice journal posting.

The browser saved four screenshots. Warehouse retry and customer partial-receipt layouts were visually checked. The driver capture still caught a brief save-refresh interval and the final internal screenshot caught loading; a read-only revisit verified settled terminal driver/internal reconciliation states and captured replacements under `/tmp/delivery-exception-readonly-DXP0925C/`. The runner now waits on the form disappearing and the loaded outcome before future screenshots. No new business writes were needed for the revisit.

The browser uses actual Sanctum/CSRF role cookies, the real API, and an isolated PostgreSQL database. Quality measurement/review requests use authenticated QC/checker browser contexts; operator quantity reports, depot receipt, disposition, dispatch and customer confirmation use the UI. The inert Pusher transport supplies no business events.

### Initial verification findings

- The first startup omitted `artisan serve --no-reload`. Laravel stripped the database/config/session overrides from its child process and the first login failed with “Session store not set on request.” No delivery or return request ran. The login path updated the default database's ImpEx demo account metadata: a read-only audit found user 44's `last_activity=2026-09-25 15:48:57` and failed-login counter zero, matching that failed session attempt. No authentication metadata was blindly restored. Subsequent servers use `--no-reload` and their child-process database, config-cache, session-cookie and stateful-domain values are checked before business tests.
- The first correctly isolated business run (`DXP0925A-retry`) passed report/receipt retries, permission denials, and pre-QC stock/invoice guards. It stopped because the runner selected Production for disposition, while the seeded authority belongs to Customer Service. The runner now uses that existing role; permissions were not widened.
- The same run exposed that the existing return inspection stage passed directly without independent review. Truck-origin return inspections now require a separate checker before QC-cleared stock can regain dispatch capacity. Ordinary customer returns retain their existing inspection rules.
- Run `DXP0925B` passed the independent checker and recorded disposition, then exposed a blocked redelivery: the new depot bridge had prefilled the final-disposition movement quantity. That made the existing RMA workflow skip physical quarantine release and allow completion with stock still held. Depot receipts now use the quarantine receipt reference; final movement quantities are written only by disposition. Truck-return release also selects its original lot before movement rather than assigning a lot after generic stock selection.
- The first visual review identified generic warehouse item labels and a raw truck-return reason code. Internal delivery resources now expose the product identity/UOM; the RMA presents a readable origin reason. Pending/wholly returned deliveries no longer display an irrelevant missing-proof prompt. The final driver screenshot waits for the retry state to settle before capture.

## Isolation and remaining work

The workspace already contained extensive changes. A scoped baseline is preserved at `/tmp/ogami-delivery-exceptions-baseline-0925-rgy16gs8`. Verification databases use explicit database names and separate configuration-cache paths; fixtures use the array cache, captured mail, synchronous queues and logged broadcasts. No shared operational database migration or deployment is included.

Final browser resources: database `ogami_test_dispatch_browser_dxp0925c`, run `DXP0925C`, fixture `/tmp/delivery-exception-fixture-DXP0925C.json`, isolated API port 8125 and preview/host port 5221. Child runtime environment verification is saved at `/tmp/delivery-exception-runtime-DXP0925C.txt`. Owned API, preview and host relay processes have been stopped and the temporary Vite config removed. Databases, compiled assets, logs and browser evidence are retained; the normal development server remains running.

Physical lot reservations before dispatch and customer reporting of a whole shipment that has not arrived remain the next Delivery follow-ups. Their implementation is separate from this depot-return phase.

This phase records one immutable stop report and one final all-line depot count per delivery. It does not yet provide an amendment, split depot-receipt, or late-found-goods workflow. Those cases need an explicit compensating record; staff must not edit ledger/status history to force them through. Unaccounted goods retain a documented quantity variance, but carrier claims and the accounting treatment of loss remain separate follow-ups.
