# Friday Demo Readiness Hardening Plan

**Date:** 2026-08-11  
**Objective:** Stabilize only the paths required for Friday's defense demo, in the exact two-round order below, then freeze.  
**Delivery rule:** Every implementation starts RED, makes the smallest safe fix, and records its focused GREEN command before integration.

## Non-negotiable execution rules

- Main is already changing concurrently. Give each numbered task its own isolated worktree and branch (`demo-hardening/r1-1-chain-bank` through `demo-hardening/r2-6-wo-output`); do not edit or clean another task's worktree, and integrate only reviewed commits.
- Round 1 is three parallel Luna/max TDD tasks. Round 2 begins only after all Round 1 branches are integrated and the Round 1 focused suites are green. Round 2's three tasks then run in parallel. The final gate starts only after all six tasks are integrated.
- Never run `migrate:fresh`, `db:wipe`, reset/restore, a demo seeder, or any other destructive/data-mutating command against the current database. Tests must use their isolated test database.
- `DefenseHeroSeeder` is additive and immutable: stable natural keys, `firstOrCreate`/guarded upserts, no deletes, no truncation, no rewriting an existing business document into a different state.
- Agents may implement and test the fixture code in an isolated test database, but must not apply it to the current/demo database. Only the user may do that, after taking and validating a backup.
- Do not opportunistically refactor. A task branch may touch only its listed files unless a newly discovered compile failure makes one adjacent file unavoidable; document that exception before integration.

## Round 1 — parallel Luna/max TDD

### 1. Preserve terminal listener failure and fail closed on bank-file storage

**Priority:** MUST before demo. **Dependency:** none; blocks Round 2 and the chain/worker gate.

**Exact files**

- `api/app/Common/Services/ChainListenerRunService.php`
- `api/tests/Feature/Infrastructure/ChainListenerRunTest.php`
- `api/app/Modules/Payroll/Services/BankFileService.php`
- `api/tests/Feature/Payroll/BankFileIntegrityTest.php`

**RED / expected failure**

- Add a queue-event ordering test that marks a listener run failed, then delivers a later `JobExceptionOccurred`; today the later retry update can replace terminal `failed` state/fields. Assert status/outcome, `failed_at`, error, and failed-job correlation remain terminal and unchanged.
- Add correlation coverage for an existing `failed_jobs.uuid`: a terminal run should use/backfill that correlation when the current schema already permits it. Include the safe negative case where no matching row/schema field is available.
- Mock the local disk so `put()` returns `false`; today generation can continue and persist a successful `BankFileRecord`. Assert an exception, no successful record, no disbursed/success transition, and the existing manual-recovery state.

**Minimal fix**

- Make terminal `failed` monotonic in `ChainListenerRunService`; retry/exception callbacks become no-ops for a terminal run and cannot erase its failure metadata.
- Correlate/backfill to `failed_jobs` by the queue UUID using only existing columns and guarded schema capabilities. If migration-free correlation is not representable, retain the terminal state, log the missing correlation, and do not add a migration for Friday.
- Check the boolean returned by `Storage::disk('local')->put(...)`; treat `false` exactly like a thrown write failure before persisting success.

**GREEN commands**

```bash
cd api && php artisan test tests/Feature/Infrastructure/ChainListenerRunTest.php
cd api && php artisan test tests/Feature/Payroll/BankFileIntegrityTest.php
```

**Cross-module acceptance:** A permanently failed chain step stays visible/recoverable after later queue events; a failed bank write cannot claim payroll output exists. Existing listener retry/replay and successful bank generation still pass.

**Rollback / scope guard:** Revert these four files. No queue schema change, migration, retry-policy redesign, storage-disk change, or payroll lifecycle redesign. Failed-job repair is migration-free or explicitly skipped. **Deferred:** schema-backed correlation expansion.

### 2. Lock and reload authoritative accounting rows

**Priority:** MUST before demo. **Dependency:** none; blocks finance rehearsal and Round 2.

**Exact files**

- `api/app/Modules/Accounting/Services/InvoiceService.php`
- `api/app/Modules/Accounting/Services/BillService.php`
- `api/app/Modules/Accounting/Services/JournalEntryService.php`
- `api/tests/Feature/Accounting/InvoiceDraftNumberingTest.php`
- `api/tests/Feature/Accounting/InvoiceCollectionTest.php`
- `api/tests/Feature/Accounting/BillServiceTest.php`
- `api/tests/Feature/Accounting/JournalEntryServiceTest.php`

**RED / expected failure**

- For invoice `finalize()` and `recordCollection()`, bill `pay()`, and journal-entry `reverse()`, hold a stale model, change its persisted status/balance in a separate write, then call the service. Today a pre-transaction check can accept stale state. Assert a business-rule failure and zero duplicate number, JE, collection, payment, reversal, or status transition.
- Add happy-path assertions that calculations and returned state come from the locked row, not the caller's stale attributes.

**Minimal fix**

- Enter the transaction first, re-query by primary key with `lockForUpdate()`, validate status/balance/relationships on that authoritative row, and use it for every downstream calculation and write.
- Preserve current public signatures, event/outbox behavior, numbering, and validation messages where practical; return a fresh authoritative model.

**GREEN commands**

```bash
cd api && php artisan test tests/Feature/Accounting/InvoiceDraftNumberingTest.php tests/Feature/Accounting/InvoiceCollectionTest.php
cd api && php artisan test tests/Feature/Accounting/BillServiceTest.php tests/Feature/Accounting/JournalEntryServiceTest.php
```

**Cross-module acceptance:** Delivery-created invoices still finalize once, AR collections change the locked balance once, GRN-created bills pay once, and posted JEs reverse once with balanced ledger effects.

**Rollback / scope guard:** Revert the seven files. No schema, accounting-policy, tax, numbering, approval, or event-contract changes. **Deferred:** broader accounting concurrency redesign beyond these four mutations.

### 3. Separate receiving from QC authority and gate RMA disposition

**Priority:** MUST before demo. **Dependency:** none; blocks receiving/returns rehearsal and Round 2.

**Exact files**

- `api/app/Modules/Inventory/routes.php`
- `api/app/Modules/Inventory/Controllers/GoodsReceiptNoteController.php`
- `api/app/Modules/Inventory/Services/GrnService.php`
- `api/tests/Feature/Inventory/ReceiveGoodsAuthorizationTest.php` (new)
- `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php`
- `api/tests/Feature/ReturnManagement/DispositionTest.php`
- `api/tests/Feature/ReturnManagement/ReturnInspectionHandoffTest.php`

**RED / expected failure**

- As an inventory-only user with `inventory.grn.create` but without `quality.inspections.manage`, POST `/api/v1/inventory/receive-goods` with `qc.result=passed`, `passed_with_remarks`, or `failed`. Today the combined endpoint can accept the QC decision. Assert 403 and no GRN, inspection completion, stock movement, rejection, or downstream bill.
- Confirm the same user may submit a receiving-only/pending payload; the GRN remains `pending_qc` for Quality.
- Stage an inspected RMA with multiple required product-linked inspections where one is pending, failed, missing, or cancelled. Today `dispose()` can rely on handoff status alone. Assert disposition and stock/credit/NCR side effects are blocked until every required active product-linked inspection is `passed`; all-passed succeeds.

**Minimal fix**

- At the HTTP boundary, require `quality.inspections.manage` whenever the combined request submits a terminal QC result; keep inventory-only receiving restricted to pending/handoff behavior. Add a service-layer backstop so alternate callers cannot smuggle a terminal QC decision.
- Inside the existing locked RMA transaction, derive the distinct required product IDs from RMA lines and query authoritative return-stage inspections for that RMA/product set. Permit disposition only when each required product has a passed inspection; do not treat handoff `generated` as a pass.

**GREEN commands**

```bash
cd api && php artisan test tests/Feature/Inventory/ReceiveGoodsAuthorizationTest.php tests/Feature/Inventory/GrnQcGateTest.php
cd api && php artisan test tests/Feature/ReturnManagement/DispositionTest.php tests/Feature/ReturnManagement/ReturnInspectionHandoffTest.php
```

**Cross-module acceptance:** Inventory can receive, Quality alone decides QC, passed incoming QC releases stock through the existing flow, and RMA stock/credit/NCR actions cannot precede all required Quality passes.

**Rollback / scope guard:** Revert the seven files. Do not change seeded role grants, generic inspection allocation, GRN lifecycle semantics, or RMA schema. **Deferred:** delivery inspection allocation.

## Round 2 — parallel after Round 1 is integrated GREEN

### 4. Add an immutable defense fixture and read-only verifier

**Priority:** MUST before demo. **Dependency:** all Round 1 tasks; user-controlled fixture application precedes browser verification.

**Exact files**

- `api/database/seeders/DefenseHeroSeeder.php` (new)
- `api/app/Console/Commands/VerifyDemoReadiness.php` (new; command `demo:verify`)
- `api/tests/Feature/Demo/DefenseHeroSeederTest.php` (new)
- `api/tests/Feature/Demo/VerifyDemoReadinessCommandTest.php` (new)
- `api/tests/Feature/SupplyChain/AutoInvoiceOnDeliveryConfirmTest.php`
- `Makefile`
- `scripts/defense-smoke-walk.js`
- `docs/DEMO-SCRIPT.md`
- `docs/SEEDS.md`

**RED / expected failure**

- In an isolated test DB only, run the seeder twice and assert stable counts/IDs and unchanged completed business facts; it currently does not exist.
- Assert `demo:verify` performs zero inserts/updates/deletes while reporting every required actor, permission, chain document, stable IATF term, and link.
- Assert the hero AR invoice is a draft produced from a genuinely confirmed delivery/invoice handoff, not an orphan/directly fabricated invoice. Existing supply-chain behavior remains covered.
- Browser-script fixture selectors/labels must fail RED against drifting or ambiguous demo terms, then use stable seeded identifiers and IATF wording.

**Minimal fix**

- Seed a compact, deterministic hero chain additively, with stable codes/credentials documented for the defense. Reuse domain services/events where lifecycle provenance matters, especially delivery confirmation to draft invoice.
- Implement `demo:verify` as queries plus a non-zero exit and actionable diagnostics only. Add Make targets that clearly distinguish read-only verification from the user-only seed command; align browser walk and docs to the same stable identifiers and IATF terms.

**GREEN commands (isolated test DB; never seed current DB)**

```bash
cd api && php artisan test tests/Feature/Demo/DefenseHeroSeederTest.php tests/Feature/Demo/VerifyDemoReadinessCommandTest.php
cd api && php artisan test tests/Feature/SupplyChain/AutoInvoiceOnDeliveryConfirmTest.php
cd spa && npm run test:run -- scripts/defense-smoke-walk.js
```

**Cross-module acceptance:** The documented buyer-to-delivery chain resolves to one confirmed delivery and its real draft invoice; verifier, Makefile, browser walk, and demo script name the same actors/documents/IATF concepts.

**Rollback / scope guard:** Revert the nine files; because the fixture is additive, rollback is code rollback, not data deletion. Do not run the seeder/reset current DB, mutate existing fixtures, fabricate terminal rows, or repair the full Playwright suite. **Deferred:** full Playwright repair and dashboard work.

### 5. Make MRP reruns preserve downstream work

**Priority:** MUST before demo. **Dependency:** Round 1; independently parallel within Round 2.

**Exact files**

- `api/app/Modules/MRP/Services/MrpEngineService.php`
- `api/tests/Feature/MRP/MrpNettingTest.php`
- `api/tests/Feature/MRP/MrpRerunSafetyTest.php` (new)

**RED / expected failure**

- Rerun the same SO and assert no accumulating duplicate draft auto-PRs/planned WOs. Today each plan creates another set.
- Mix superseded-plan children: draft auto-PR vs manual/submitted/approved PR and planned vs released/in-progress/completed WO, each with representative downstream PO/receipt/output links. Assert only superseded draft auto-PRs and planned WOs are eligible for reuse/cancellation; all progressed/manual children and downstream documents remain byte-for-byte linked and unchanged.
- Simulate a stale SO and assert the rerun locks/reloads it before status/line/previous-plan decisions.

**Minimal fix**

- Lock and reload the SO first, then lock its active plan/eligible children in deterministic order.
- Reconcile the new requirements against only the previous superseded plan's `is_auto_generated=true,status=draft` PRs and `status=planned` WOs: reuse compatible rows, cancel eligible surplus rows, and create only missing rows. Never repoint, cancel, or rewrite progressed/manual documents or their descendants.
- Keep plan version/history and diagnostics; make the new active plan's links explicit without deleting history.

**GREEN commands**

```bash
cd api && php artisan test tests/Feature/MRP/MrpNettingTest.php tests/Feature/MRP/MrpRerunSafetyTest.php
```

**Cross-module acceptance:** Repeated SO MRP remains bounded while purchasing and production documents that have progressed keep their original provenance, quantities, links, and status.

**Rollback / scope guard:** Revert the three files. No schema, netting formula, scheduling, PO/receipt, or output rewrite; no deletion of plan history. **Deferred:** broader MRP optimization and cleanup of historical duplicates.

### 6. Recheck WO state and namespace cache idempotency

**Priority:** MUST before demo. **Dependency:** Round 1; independently parallel within Round 2.

**Exact files**

- `api/app/Modules/Production/Services/WorkOrderOutputService.php`
- `api/tests/Feature/Production/WorkOrderOutputFgReceiptTest.php`

**RED / expected failure**

- Pass a stale in-progress WO whose persisted row is now completed/cancelled; today the locked row is not status-rechecked. Assert no output, defect, totals, mold shots, stock receipt, or outbox row.
- Use the same idempotency token on two different WOs; today the global cache key can return the first WO's output. Assert independent outputs. Replay the token on the same WO and assert the original output is returned once.

**Minimal fix**

- After `lockForUpdate()` reload, reject missing/non-in-progress authoritative WOs before any side effect.
- Namespace the cache key by operation and WO identity (for example `production:work-order:{id}:record-output:{token}`), preserving the 24-hour behavior. Validate any cached output belongs to that WO before returning it.

**GREEN commands**

```bash
cd api && php artisan test tests/Feature/Production/WorkOrderOutputFgReceiptTest.php
```

**Cross-module acceptance:** A terminal WO cannot gain production/output/inventory facts, distinct WOs cannot share cached results, and same-WO request replay remains non-duplicating.

**Rollback / scope guard:** Revert the two files. No cache flush, schema, controller contract, receipt handoff, or mold logic change. **Deferred:** durable database-backed idempotency schema.

## Final gate — in this order

1. **Focused suites:** rerun every GREEN command above from the integrated branch. Any regression stops the gate.
2. **Full backend:** `cd api && php artisan test`.
3. **SPA:** `cd spa && npm run lint && npm run typecheck && npm run test:run && npm run build && npm run audit:tokens`.
4. **Static audits:** `node scripts/rbac-static-audit.js`; `node scripts/role-permission-audit.js`; `python3 scripts/spa_route_audit.py`. Record known noise; do not broaden Friday scope merely to silence it.
5. **Chain/worker smokes:** `make chain-smoke` then `make worker-recovery-smoke`, using their isolated infrastructure only.
6. **Operator data checkpoint:** stop. The user takes and validates a backup, then manually applies `DefenseHeroSeeder` to the intended demo environment. Agents do not run it, reset it, or clean it up.
7. **Read-only fixture gate:** only after step 6, run `cd api && php artisan demo:verify --no-interaction`; inspect a before/after database write counter or audit snapshot to confirm zero mutation.
8. **Browser smoke:** only after the verifier passes, run `cd spa && npm run test:defense` against the intended demo URL. This is the focused defense walk, not full Playwright repair.
9. **Manual rehearsal and freeze:** rehearse the documented roles and chain, confirm the delivery-confirmed draft invoice and IATF wording, record screenshots/results, tag the candidate, and freeze code/data/config except for an explicitly approved rollback.

## Explicitly deferred until after the demo

- Delivery inspection allocation.
- Maintenance multi-MWO handling.
- Final-pay loan settlement.
- Blob disaster recovery.
- Full Playwright repair.
- Static-audit noise cleanup.
- Durable idempotency schema.
- Dashboard work.
