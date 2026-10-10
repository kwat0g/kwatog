# Finance and Assets deep audit — 2026-10-05

Read-only source audit of the current dirty workspace. No product files, business data, containers, queues, or test databases were changed. No PHPUnit/browser tests were run. Investigate-first and Impeccable technical-audit guidance were applied; UI conclusions are source-level, not rendered WCAG certification. Existing documents were not treated as proof of current defects. Findings below separate directly visible mechanisms from policy-dependent risks.

## Ranked findings

### F1 — P1 confirmed: contra assets increase the balance sheet

Evidence: `api/app/Modules/Accounting/Services/Statements/BalanceSheetService.php:61` computes balances relative to account normal balance; line 68 adds every asset balance positively. `api/database/seeders/ChartOfAccountsSeeder.php:57` defines account 1410 Accumulated Depreciation as an asset with credit normal balance. Consequently DR depreciation expense 100 / CR 1410 100 increases reported assets by 100 and decreases synthetic income/equity by 100, introducing a 200 imbalance. The trial balance remains balanced because it reports debit/credit totals independently.

Minimal fix: balance-sheet section amounts must follow section orientation (assets debit minus credit; liabilities/equity credit minus debit), preserving negative contra balances. Do not globally change normal-balance accounting or trial-balance presentation.

Disposable test recipe: seed PPE 1000 against equity 1000, post depreciation 100, assert PPE plus accumulated depreciation totals 900, equity including income totals 900 and balanced=true. Add debit-normal contra-equity case. Check dashboard/PDF consumers of the same service.

### F2 — P1 confirmed: a partial credit application can be replayed twice

Evidence: `api/app/Modules/Accounting/Services/CreditNoteService.php:233` locks the credit note and target, checks remaining balances, then creates a fresh application every call (line 330). `ApplyCreditNoteRequest.php:24` accepts no idempotency key; `spa/src/pages/accounting/credit-notes/detail.tsx:90` submits none. Locks prevent over-application but do not prevent duplicate intent. A 100 application against credit 1000 and invoice 1000, retried after a lost response, records two applications and reduces both balances to 800.

Minimal fix: operation key stored under a unique constraint, compare credit, target and amount before replaying the existing application. Preserve key for retries; create a new key only for genuinely new application intent.

Disposable recipe: submit identical partial application twice with same key, require one application and balances 900; changed target/amount with same key must reject; independent keys must remain valid. Include concurrent duplicate requests.

### F3 — P1 confirmed: backdated disposal leaves future depreciation history inconsistent

Evidence: `api/app/Modules/Assets/Services/AssetService.php:338` and line 520 allow any disposal date on/after acquisition and on/before today, with no latest-depreciation-period bound. `DepreciationService.php:294` walks history; line 316 resets accumulated depreciation to each existing historical row while calculating the disposal balance. Already-posted rows after the requested disposal month are never reversed or removed.

Trigger: asset acquired January, depreciated through September, then disposal requested for March while March is open/reopened. Catch-up rewinds register accumulated balance to March (when residual still remains), derecognizes that balance, yet April–September expenses and accumulated-depreciation credits remain. If fully depreciated, calculateRow returns null and historical rewind behavior differs again. Both paths lack an explicit backdated-disposal policy.

Minimal fix: reject disposal month before latest posted asset depreciation period unless a separately approved correction workflow reverses later depreciation first. Validate both request and final execution under asset lock.

Disposable recipe: post January–September, request March disposal, assert controlled 422 and no new journals/status/register changes. If backdating is supported, assert every subsequent depreciation reversal and register/GL reconciliation; test partial and fully depreciated assets.

### F4 — P2 confirmed: scientific-notation asset input reaches BCMath despite validation error

Evidence: `StoreAssetRequest.php:64` and `UpdateAssetRequest.php:57` after-validation callbacks call trim/string cast before type guards. `StoreAssetRequest.php:69` uses is_numeric then line 72 calls Money::gt. `UpdateAssetRequest.php:60` uses is_numeric then line 67 calls Money::cmp. `api/app/Common/Support/Money.php:83` delegates directly to bccomp. PHP is_numeric accepts scientific notation such as 1e3; BCMath decimal strings do not. The decimal rule can register an error, but after callbacks still execute and attempt comparison. Arrays can also produce array-to-string warnings before normal validation completes.

Minimal fix: skip comparison whenever either involved field already has validation errors; require scalar decimal input before casting. Apply same guard to create and update. Do not weaken decimal rules.

Disposable recipe: create with acquisition_cost=1e3, salvage_value=1; update salvage_value=1e3; arrays for either field. Require field-specific 422, no 500/warning, no writes. Runtime proof remains pending: host php executable unavailable, no container was invoked.

### F5 — P2 confirmed: asset insurance is write-once and largely invisible

Evidence: `StoreAssetRequest.php:44` accepts policy number/provider/expiry/insured value; `AssetService.php:230` persists them. `UpdateAssetRequest.php:32` and `AssetService.php:268` omit every insurance field. `spa/src/pages/assets/edit.tsx` has no insurance controls; `detail.tsx` displays no insurance fields despite `AssetResource.php:76` exposing them.

Trigger/impact: policy renews or provider changes after acquisition. Authorized asset updater has no shipped path to correct it or even inspect policy expiry in detail. This reduces usefulness of recorded insurance and any future reminder automation.

Minimal fix: display insurance details and permit the four existing insurance fields through update request/service/edit form under assets.update. Keep acquisition/depreciation-history immutability guards. Add a bounded expired/soon-to-expire list before considering notifications.

Recipe: update insurance after depreciation history exists, assert only insurance changes, then verify detail view, field errors and keyboard labels. No visual contrast/responsiveness verdict was inferred from source alone.

### F6 — P2 confirmed: accepted zero-cost asset has no ordinary disposal correction path

Evidence: `StoreAssetRequest.php:39` permits acquisition_cost=0. `AssetService.php:581` rejects zero-cost/zero-proceeds/no-depreciation disposal with instruction to correct acquisition cost, but update whitelist at line 268 does not permit acquisition cost. Thus the displayed remedy is unavailable. A no-proceeds zero-cost asset cannot complete normal approved disposal.

Minimal fix: choose explicit policy: reject zero-cost acquisitions at creation, or allow controlled register-only disposal with recorded reason, or provide a narrowly audited pre-posting cost correction. Do not silently widen cost editing after depreciation exists. UI error must describe an actually available action.

Recipe: create zero-cost asset, submit no-proceeds disposal and approve. Verify chosen policy produces a complete, auditable lifecycle or useful corrective route; preserve valid zero-proceeds disposal of nonzero-cost assets.

### F7 — P2 policy-dependent risk: prior-year profits vanish unless closing journals are manually posted

Evidence: `BalanceSheetService.php:79` includes only current fiscal-year income although balance-sheet assets/liabilities are all-time. `FiscalYearService.php:44` closes only the fiscal-year status; repository search found no retained-earnings/closing-journal automation. A balanced cash sale of 100 in year A leaves cash 100 in year B, but year-B synthetic income is zero unless a manual closing transfer moved year-A income into equity.

This is not proof that live accounts lack closing journals; existing manual accounting policy/data were not inspected. Required follow-up: document whether year-end closing journals are mandatory and inspect disposable scenarios before selecting cumulative unclosed-earnings presentation versus reviewed closing workflow.

Recipe: prior-year sale with no closing journal, next-year balance sheet; then repeat with explicit closing journal. Require equation and no double counting in the chosen accounting policy.

## Coverage matrix and safeguards already present

| Surface / chain | Inspected current source | Existing safeguards / limitations |
|---|---|---|
| Chart of accounts | AccountService, requests/routes; seeded contra account | Posted type/normal balance immutable; parent type/cycle checks. Contra presentation defect isolated above. |
| Manual journals / reversal | JournalEntryService, state machine, observer | Lock-then-guard, different maker for manual posting, locked accounts/lines, source-owned reversal paths, period gate. Statement cache observer exists. |
| Period close/reopen | AccountingPeriodService, SPA periods | PostgreSQL transaction advisory locks cover missing-row months; reopening reason required; 48-hour auto-relock rechecks locked state. Do not report close/post race as missing. |
| Invoices / sales / delivery / credit limit | InvoiceService including source-chain resolve/lock/finalize, routes | Customer locking, source ownership, confirmed-delivery match, prebill review, posted journals; collections use replay key and compare full payload. Sales completion synchronization exists. |
| Collections / receipts | InvoiceService, OfficialReceiptService, invoice UI | Atomic collection, GL, receipt and balances; receipt locks and replay checks. Collection void/correction route was not found; treat as process capability requiring product policy, not permission to directly reverse source GL. |
| Supplier bills / purchasing / accepted GRN | BillService and AutoCreateBillOnGrnAccepted | GRN lock and existing-bill fence; draft staging before posting; matching/override checks; staged payments with separate approval; idempotency; void/replacement controls. Missing actor throws, failures retry instead of silently succeeding. |
| Customer/supplier credits | CreditNoteService, requests/UI | Locked balances/party/source checks; applied credits intentionally immutable; controlled void of unapplied finalized note. Replay gap F2 remains. |
| Financial statements / aging / dashboard | Three statement services, InvoiceService aging, BillService aging | Reversed original plus mirror included by date; historical collection/credit cutoff reconstruction; archived AR customers handled. F1/F7 remain. |
| Budget lifecycle / transfers / actuals | BudgetService, BudgetTransferService, BudgetConsumptionService, SyncBudgetActuals, request service | Status locks, maker-role separation, headroom checks, sorted line locks; live actual/PO commitment calculation; durable sync request/run records. GL lacks department dimension; existing pooling is explicit, not verified per-department expense attribution. |
| Payroll / HR finance connection | Accounting period gate and source registry; payroll finalization event integrations | Payroll accounting callers use canonical journal/period gate. Detailed payroll computation belongs to root audit. |
| Asset register / history / associations | AssetService, model/requests/resource, create/edit/detail/index | Disposal two-phase chain, requester cannot self-approve, separate approval permission; associations category-match and cross-model locks; archived targets included in deletion/disposal association checks. Salvage bound and post-history life freeze exist. |
| Depreciation / backfill / automation | Full DepreciationService, routes, runner UI, scheduled entry | Asset row locks, unique asset-month claim, durable period identity, supplemental backfill, prior-period completeness; only completed periods allowed for manual runner. Catch-up includes disposal month intentionally; F3 remains. |
| Asset custody | AssetTransferService and routes comment | Transfer API intentionally removed from shipped scope. Historical model/service retained. Do not describe unreachable transfer-service weaknesses as production endpoint exploits. |
| Insurance / lifecycle UX | Assets create/edit/detail and resource | Input labels/error states and association error handling exist; no insurance update/detail path. Rendered mobile/contrast/keyboard checks remain pending. |

## Existing automation and safe opportunities

Current automation: accepted GRN stages reviewed draft bill; delivery confirmation notifies finance; finalized invoices/receipts/credits have customer mail listeners; daily tiered AR dunning locks invoices and reports blocked/failed mail; first-day monthly depreciation uses durable requested event; reopened periods auto-relock after 48 hours; monthly budget actual sync has durable outbox/run visibility. Queue retry and system-actor infrastructure already exist, so reuse them.

Recommended plan, in order:

1. Reproduce F1–F4 in root-controlled disposable database. Correct section signs, reject unsupported backdated disposal, add application replay identity and validation callback guards. These are narrow correctness work with explicit regression cases.
2. Run fiscal-boundary and GL/register reconciliation probes. Resolve F7 accounting policy before introducing closing automation. Add read-only discrepancies for PPE cost, accumulated depreciation and disposed asset future rows; never auto-repair journals.
3. Finish insurance visibility/editing and zero-cost policy. Add safe read-only renewal/expiry worklist, scoped to existing permissions. Notifications require recipient policy and durable dedupe; no live mail from this audit.
4. Add reviewed finance worklists: accepted receipts lacking bill draft, delivery awaiting invoice review, blocked dunning, pending payment approvals, depreciation/backfill blockers. Link to owning document and existing run status; do not auto-post or auto-approve.
5. Verify representative Finance/Assets pages at 390px/768px/desktop, keyboard-only dialogs, field errors, stale-data refresh and permission-denied paths. Source audit cannot assign defensible numerical WCAG/theme scores without rendered evidence.

Acceptance gates: no duplicate money intent after lost responses; report equation holds with contra accounts and fiscal rollover policy; asset register/GL reconciliation survives disposal dates; invalid money inputs return 422; write actions remain behind existing role gates and approval chains. Stop after these bounded probes and ranked plan; do not run broad overnight production jobs.
