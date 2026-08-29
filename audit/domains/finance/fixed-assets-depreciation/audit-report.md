# M031 — Fixed Assets & Depreciation audit report

Initial audit date: 2026-08-24
Re-audit date: 2026-08-27
Claim: `finance / fixed-assets-depreciation`  
Registry tier: 2  
Dependency exception: the remaining `Not Started` graph has no strict
topological frontier; M028 is locked by another session and the remaining
finance/procurement/operations modules form a cycle. M031 was selected as the
first unlocked Tier-2 exception. Dependencies were read for context only.  
Current recommended status: `📋 Plan Ready`
Session recommendation: `separate-recommended`

## Current re-audit verdict

The current checkout is backend-executable and the existing module suite is
green: **24 tests / 115 assertions** on the private database
`ogami_test_m031_agent_b`. The SPA toolchain also passes its current checks:
**41 test files / 282 tests**, typecheck, and scoped ESLint. No asset-specific
browser test exists, so source-level SPA fixes are not promoted to browser-
verified status.

Release is still not financially verified. The current code reproduces a P0
disposal-month reconciliation defect: disposing an asset mid-month and then
running that month's depreciation leaves a non-zero accumulated-depreciation
credit in the GL. The live restore route also cannot bind a soft-deleted asset.
Salvage values are not bounded by acquisition cost, and automated journal maker
attribution is intentionally discarded at the Accounting boundary. F06/F11
remain product-scope decisions. The gate is therefore `📋 Plan Ready`; no
production code is changed in this re-audit.

The original finding text below records the pre-fix state. The current states,
new evidence, and open work are in the re-audit section at the end.

## Initial audit verdict (2026-08-24)

Production-readiness score: **45/100 — blocked for an unqualified financial
release**.

The module has a real asset register, idempotent per-asset/month uniqueness,
transactional journal posting, soft deletion, and several useful stale-model
regression tests. The release risk is in the financial and custody seams:
asset calculations bypass the repository's centavo/Money arithmetic, arbitrary
period backfills can build non-chronological depreciation histories, deletion
and schedule edits are not guarded by the same lifecycle invariant, and the
transfer implementation is disconnected from its live HTTP/UI surface while
still lacking an authoritative asset lock. The report is intentionally a plan
handoff; no production code was changed in this session.

## Initial discovery (2026-08-24)

### Implemented surface

- Asset register list/options/show/create/update/delete/restore/dispose/QR API:
  `api/app/Modules/Assets/routes.php:14-24`.
- Depreciation history and manual run API:
  `api/app/Modules/Assets/routes.php:26-29`.
- Asset model, department relation, depreciation history, book value, and
  straight-line/200% declining-balance calculation:
  `api/app/Modules/Assets/Models/Asset.php:20-110`.
- Monthly execution through a synchronous command and a durable outbox,
  queued listener, and overlapping-period lock:
  `api/app/Console/Commands/RequestMonthlyDepreciation.php:15-66`,
  `api/app/Modules/Assets/Listeners/RunMonthlyDepreciationOnRequested.php:15-57`.
- Asset transfer model/service/controllers and SPA clients/pages exist, but
  the route group is commented out as a scope cut:
  `api/app/Modules/Assets/routes.php:31-45`.
- SPA list/create/edit/detail pages and an in-page depreciation runner are
  live; the old admin depreciation page and transfer pages are retained but
  not routed: `spa/src/routes/assetsRoutes.tsx:6-30`.

### Persistence and conventions observed

- `assets` stores two-decimal financial columns and `asset_depreciations`
  enforces one row per asset/year/month:
  `api/database/migrations/0104_create_assets_table.php:23-46`,
  `api/database/migrations/0105_create_asset_depreciations_table.php:17-29`.
- Disposal and depreciation call the canonical journal service, whose period,
  balance, and Money checks are implemented at
  `api/app/Modules/Accounting/Services/JournalEntryService.php:102-135,187-237,386-421`.
- Asset permissions are seeded separately for register, disposal, depreciation,
  and transfer actions: `api/database/seeders/RolePermissionSeeder.php:344-367`.

## Initial findings and historical fix notes

### M031-F01 — Broken: asset financial calculations use binary floats instead of Money/centavos

Priority: **P0**
Classification: **Broken**
Scope: **large**
Session recommendation: **separate-recommended**

`Asset::getMonthlyDepreciationAttribute()` converts acquisition cost, salvage,
and accumulated depreciation to `float` before calculating monthly charges and
book value (`api/app/Modules/Assets/Models/Asset.php:73-103`). Disposal repeats
the conversion for proceeds, cost, accumulated depreciation, book value, gains,
and losses (`api/app/Modules/Assets/Services/AssetService.php:117-139`). The
monthly runner also accumulates totals and remaining depreciable value as
`float`, then formats only at persistence/JE boundaries
(`api/app/Modules/Assets/Services/DepreciationService.php:47-78,102-121`).

This bypasses the repository's canonical string/centavo arithmetic used by the
journal service (`api/app/Modules/Accounting/Services/JournalEntryService.php:14,108-109,205-214,386-418`). Per-asset `depreciation_amount` and
`accumulated_after` can therefore be rounded differently from the consolidated
JE, and disposal gain/loss decisions depend on binary floating-point values.
The risk applies to a financial ledger even though the schema is decimal(15,2).

Action: perform every calculation in integer centavos or the shared `Money`
type; round each asset row once, aggregate those same rounded rows for the JE,
and add boundary tests for salvage/cost equality, final-period caps, declining
balance, large values, and a multi-asset JE/detail reconciliation.

### M031-F02 — Broken: depreciation period eligibility and ordering are not authoritative

Priority: **P0**  
Classification: **Broken**  
Scope: **large**  
Session recommendation: **separate-recommended**

`DepreciationService::runForMonth()` accepts a target period and selects assets
by their *current* status plus acquisition date through the target month
(`api/app/Modules/Assets/Services/DepreciationService.php:33-45`). It does not
enforce that the target is a completed/current period, that earlier periods
have been processed, or that the asset was still in service during that
period. The command and HTTP controller validate only the year/month shape
(`api/app/Console/Commands/RunMonthlyDepreciation.php:38-45`,
`api/app/Modules/Assets/Controllers/AssetDepreciationController.php:54-66`).
The accounting period guard rejects closed periods but treats a missing period
as open (`api/app/Modules/Accounting/Services/AccountingPeriodService.php:145-163`).

An operator can therefore post a future period, run declining-balance periods
out of order using the current accumulated balance, or backfill a period after
an asset has been disposed and silently omit it. The unique key in
`0105_create_asset_depreciations_table.php:27-29` prevents duplicate rows but
does not make period history chronological or prove that a consolidated JE
exists exactly once per period.

Action: define the supported period policy (scheduled previous-month close,
controlled historical backfill, and disposal/acquisition-month treatment),
enforce it inside the service, require an explicit period/rebuild workflow for
out-of-order backfills, and record/reconcile the period-level journal identity.
Test future-period rejection, closed/missing-period behavior, declining-balance
out-of-order attempts, disposal during a backfill, and retry after a failed
outbox execution.

### M031-F03 — Broken: lifecycle edits and deletion can invalidate financial history

Priority: **P1**  
Classification: **Broken**  
Scope: **large**  
Session recommendation: **separate-recommended**

The update request permits useful-life and salvage-value changes
(`api/app/Modules/Assets/Requests/UpdateAssetRequest.php:27-36`), and the
service applies them after checking only that the asset is not disposed
(`api/app/Modules/Assets/Services/AssetService.php:76-91`). It does not freeze
schedule-defining fields after depreciation has posted or create a controlled
reforecast/reversal. Existing depreciation rows retain their old amounts while
future rows use the new schedule.

Deletion is a separate untransactional stale-read path: it checks the in-memory
status and history, then soft-deletes without a row lock
(`api/app/Modules/Assets/Services/AssetService.php:161-170`). Depreciation locks
asset rows while it writes (`api/app/Modules/Assets/Services/DepreciationService.php:36-45`),
and disposal also locks/rechecks the row (`api/app/Modules/Assets/Services/AssetService.php:109-155`). A stale delete can therefore pass its checks before
one of those writers commits and hide an asset after financial history or a
disposal has landed.

Action: use one locked transaction for delete eligibility; re-check status and
depreciation history under lock; prevent deletion once any financial or custody
event exists; and freeze or explicitly version useful life, salvage, and method
after the first depreciation. Add two-connection tests for delete versus
depreciation/disposal and schedule edit versus depreciation.

### M031-F04 — Incomplete: disposal date, proceeds, and reason lack a complete audit contract

Priority: **P1**
Classification: **Incomplete**
Scope: **medium**
Session recommendation: **separate-recommended**

`DisposeAssetRequest` accepts any date and an optional `remarks` value
(`api/app/Modules/Assets/Requests/DisposeAssetRequest.php:16-22`). The service
uses the supplied date for both the journal and asset state without checking it
against acquisition/today or the asset's locked lifecycle, and it never stores
or includes `remarks` in the journal description/audit payload
(`api/app/Modules/Assets/Services/AssetService.php:142-155`). The canonical
journal service blocks closed periods, but does not replace the missing
asset-specific date policy.

Action: define and enforce disposal-date bounds, preserve a required reason in
the asset event/audit record and journal reference, and test pre-acquisition,
future, closed-period, zero-proceeds, and exact-book-value disposals.

### M031-F05 — Broken: transfer approval can apply stale or disposed custody state

Priority: **P1**  
Classification: **Broken**  
Scope: **medium**  
Session recommendation: **separate-recommended**

Although the transfer HTTP surface is currently hidden, the retained service
has a latent custody race. Creation reads the asset without a lock and checks
only the source department (`api/app/Modules/Assets/Services/AssetTransferService.php:38-45`);
it does not require an active asset or prevent overlapping pending transfers.
Approval locks the transfer row, but not the asset, and then updates the asset
department without rechecking its current department or status
(`api/app/Modules/Assets/Services/AssetTransferService.php:58-82`). Two pending
requests from the same source can both complete against stale source data, and
a pending request can move an asset after it has been disposed. The existing
tests cover sequential movement and approval-versus-rejection, not this
asset-row race (`api/tests/Feature/Assets/AssetTransferTest.php:66-93`,
`api/tests/Feature/Assets/AssetTransferRejectRaceTest.php:41-80`).

Action: lock and re-read the asset in create/approve, require active status and
the recorded source department at approval, serialize or reject overlapping
pending transfers, and add concurrent/stale/disposed regression tests before
re-enabling routes.

### M031-F06 — Missing: transfer/custody HTTP and SPA surface is deliberately disconnected

Priority: **P1**  
Classification: **Missing**  
Scope: **large**  
Session recommendation: **separate-recommended**

The entire `/asset-transfers` route group is commented out and explicitly
described as a scope cut (`api/app/Modules/Assets/routes.php:31-45`). The live
route audit exposes nine asset/depreciation routes and no transfer routes. At
the same time, transfer API clients and pages remain in
`spa/src/api/assets.ts:38-56` and `spa/src/pages/assets/transfers/`, and transfer
permissions are still seeded (`RolePermissionSeeder.php:365-367`). The SPA
route file also documents that the transfer page was removed
(`spa/src/routes/assetsRoutes.tsx:20-23`).

This leaves the module's documented custody workflow unavailable while leaving
dead clients, permissions, and implementation code that can drift unnoticed.
If the scope cut is intentional, the retained surface should be explicitly
retired; if transfers are part of M031, the complete guarded API/UI path must
be restored after F05 is fixed.

### M031-F07 — Incomplete: SPA permission gates disagree with the API contract

Priority: **P2**  
Classification: **Incomplete**  
Scope: **small**  
Session recommendation: **separate-recommended**

The update API uses `assets.update` (`api/app/Modules/Assets/routes.php:18-22`,
`api/app/Modules/Assets/Requests/UpdateAssetRequest.php:15-17`), but the SPA
edit route and detail-page Edit button gate on `assets.create`
(`spa/src/routes/assetsRoutes.tsx:23-26`, `spa/src/pages/assets/detail.tsx:103-106`).
Likewise, the list page shows “Run depreciation” to anyone with
`assets.depreciation.view`, while the POST endpoint requires
`assets.depreciation.run` (`spa/src/pages/assets/index.tsx:77-83`,
`api/app/Modules/Assets/routes.php:26-28`). Custom least-privilege roles can
therefore reach UI actions that always return 403, or be unable to reach an
update page despite holding the actual update permission.

Action: align each UI guard with its server permission and add role-matrix
tests for view-only, update-only, depreciation-view-only, finance, and admin
users.

### M031-F08 — Incomplete: backend fields and editable forms silently diverge

Priority: **P2**  
Classification: **Incomplete**  
Scope: **medium**  
Session recommendation: **separate-recommended**

The create request/service and resource support depreciation method and
insurance fields (`api/app/Modules/Assets/Requests/StoreAssetRequest.php:37-45`,
`api/app/Modules/Assets/Services/AssetService.php:63-70`,
`api/app/Modules/Assets/Resources/AssetResource.php:34-48`), but the SPA type
and create form stop at location (`spa/src/types/assets.ts:4-35`,
`spa/src/pages/assets/create.tsx:21-31,106-117`). The edit form presents
category, acquisition date, and acquisition cost, but the update request and
service ignore those fields (`spa/src/pages/assets/edit.tsx:22-32,75-83`,
`api/app/Modules/Assets/Requests/UpdateAssetRequest.php:27-36`,
`api/app/Modules/Assets/Services/AssetService.php:85-88`). Its initial
`department_id` is always `undefined` (`edit.tsx:59-69`) and the submit path
converts that to `null` (`edit.tsx:75-83`), so saving an unchanged asset can
silently clear its department. The depreciation list response also omits the
journal identifier even though asset detail exposes it
(`api/app/Modules/Assets/Controllers/AssetDepreciationController.php:31-44`,
`api/app/Modules/Assets/Resources/AssetResource.php:49-57`).

Action: decide which fields are mutable, make unsupported fields read-only,
or implement them end-to-end; preserve existing department values; align the
TypeScript response/request types; and add API fixture/type tests for every
financial and insurance field.

### M031-F09 — Broken: QR detail renders a URL as if it were an image

Priority: **P2**  
Classification: **Broken**  
Scope: **small**  
Session recommendation: **same-session-ok after the semantic fixes**

`AssetQrCodeService` returns a deep-link URL payload, not SVG or PNG data
(`api/app/Modules/Assets/Services/AssetQrCodeService.php:9-33`). The detail page
uses that URL as `<img src>` and offers it as a `.png` download
(`spa/src/pages/assets/detail.tsx:180-205`). The browser therefore requests the
asset detail HTML route as an image; the QR panel cannot display a QR code or
produce a valid PNG.

Action: render the QR from the payload with the client QR library already named
by the service, or add a real image endpoint and use the correct content type;
verify the panel and download path in a browser test.

### M031-F10 — Polish: January depreciation defaults to an invalid month and has weak recovery feedback

Priority: **P3**  
Classification: **Polish**  
Scope: **small**  
Session recommendation: **same-session-ok**

The live runner initializes `month` with JavaScript's zero-based
`getMonth()` (`spa/src/pages/assets/DepreciationRunner.tsx:19-24`). In January
that produces month `0`, while the submit button is disabled outside 1–12
(`DepreciationRunner.tsx:54-72`). The old retained admin page has the same
default (`spa/src/pages/admin/depreciation.tsx:13-16`) and neither runner
surfaces the server's validation/closed-period reason beyond a generic failure
toast (`DepreciationRunner.tsx:26-35`).

Action: calculate the previous calendar year/month correctly at the boundary,
show the server error envelope, and add a January and closed-period browser/API
case.

### M031-F11 — Missing/question: acquisition and maintenance linkage is not a complete user workflow

Priority: **P2**  
Classification: **Missing**  
Scope: **large**  
Session recommendation: **separate-recommended**

The schema and module docs describe machine/mold/vehicle custody and
maintenance-facing asset use. Migrations add `asset_id` to vehicles and
machines (`api/database/migrations/0106_add_asset_id_to_vehicles_table.php:17-21`,
`api/database/migrations/0109_add_asset_id_to_machines_table.php:22-29`), and the
asset status enum includes `under_maintenance` (`api/app/Modules/Assets/Enums/AssetStatus.php:7-16`).
However, the asset register create/update contract has no linked machine,
vehicle, mold, employee custodian, or maintenance transition, and the
maintenance/asset search found no writer that moves an asset into or out of
`under_maintenance`. `AssetService::create()` only persists the register fields
shown at `api/app/Modules/Assets/Services/AssetService.php:54-70`.

This is a required gap if the documented custody/maintenance workflow is in
scope; it may be an intentional register-only boundary. Resolve that question
before implementing cross-module links, then add a single ownership/status
timeline and role-specific flows if the answer is yes.

## Strengths

- Asset creation, update, disposal, and depreciation use database transactions
  on their primary writes; disposal and update re-read/lock the asset before
  mutating it.
- The depreciation table's unique asset/year/month key and the runner's locked
  asset scan provide a useful idempotency base
  (`api/database/migrations/0105_create_asset_depreciations_table.php:19-29`,
  `api/app/Modules/Assets/Services/DepreciationService.php:36-55`).
- Disposal journals are balanced through the canonical journal service and
  are protected against duplicate disposal by the locked status check
  (`api/app/Modules/Assets/Services/AssetService.php:109-155`).
- Durable outbox staging, queued execution, retry configuration, and
  per-period overlap control are present
  (`api/app/Console/Commands/RequestMonthlyDepreciation.php:58-64`,
  `api/app/Modules/Assets/Listeners/RunMonthlyDepreciationOnRequested.php:25-46`).
- The existing regression tests cover stale update/dispose and transfer
  approve/reject races, even though they do not cover the remaining asset-row
  and financial-period cases.

## Verification and evidence limits

- PHP syntax check passed for every M031 Assets PHP file, both depreciation
  commands, and all six feature test classes.
- `php artisan route:list --path=assets` passed and showed **9 live routes**;
  no `/asset-transfers` route is registered.
- `npm run typecheck` passed in `spa`.
- Scoped ESLint passed for the M031 SPA API/types/routes/pages.
- `./vendor/bin/phpunit tests/Feature/Assets` collected **14 tests** but all
  errored before assertions because the local Compose `db` hostname was
  unavailable (`SQLSTATE[08006]`, `ogami_test`, 0 assertions). No test result
  is being treated as evidence of runtime correctness.
- No M031 production or test files were modified before this report.
- No browser/E2E run, two-connection PostgreSQL race test, migration rehearsal,
  or production-like GL reconciliation was available.

## Release blockers / next action

1. Replace F01 float arithmetic and define F02 period/backfill semantics before
   any financial hardening is considered safe.
2. Fix F03/F04 lifecycle and disposal invariants, then add real PostgreSQL
   concurrency tests.
3. Decide whether transfers and maintenance/source linkage are in scope; do not
   re-enable F06 until F05 is hardened.
4. Align F07/F08 API/UI contracts, repair F09/F10, and run role-matrix/browser
   coverage.

Next action: a dedicated fixed-assets financial-hardening session should
implement F01-F04 with Money/period/accounting decisions and verify them against
a live PostgreSQL test service before any `✅ Verified` status is considered.

## Re-audit evidence and current findings — 2026-08-27

### Verification performed

- Current module diff: no M031 implementation or test changes are uncommitted;
  the only pre-existing working-tree change is the coordinator's generated
  registry timestamp in `audit/00-MODULE-REGISTRY.md`, which was not edited.
- Backend: `vendor/bin/phpunit --no-configuration --bootstrap
  vendor/autoload.php tests/Feature/Assets tests/Unit/Assets` → **24 passed,
  115 assertions**, with `DB_DATABASE=ogami_test_m031_agent_b`.
- Backend static checks: PHP lint passed for the Assets module and depreciation
  commands; PHPStan reported `[OK] No errors`.
- `php artisan route:list --path=assets` shows **9 live routes**, including
  restore and no transfer route.
- SPA: `npm run test:run` → **41 files / 282 tests passed**; `npm run
  typecheck` and scoped ESLint for M031 sources passed. There is no
  asset-specific browser/E2E test.
- Pint remains non-green on the inherited module style baseline (**27 files,
  20 issues**); no source was reformatted during this audit.

### Current finding state

| Finding | Classification | Current state |
|---|---|---|
| F01 money/centavo arithmetic | Broken | Fixed and verified by execution |
| F02 period/order/retry identity | Broken | Fixed and verified by execution |
| F03 lifecycle locking/immutability | Broken | Fixed and verified by execution |
| F04 disposal date/reason contract | Incomplete | Fixed and verified; F13 closed the zero-line edge case |
| F05 transfer custody race | Broken | Fixed and verified in service tests; surface remains hidden |
| F06 transfer live surface | Missing | Deferred product decision |
| F07 permission gates | Incomplete | Source aligned; role/browser acceptance is still missing |
| F08 API/UI field contract | Incomplete | Backend/source aligned; browser/contract acceptance is still missing |
| F09 QR rendering | Broken | Source fixed; browser acceptance is still missing |
| F10 January/recovery UX | Polish | Source fixed; browser acceptance is still missing |
| F11 acquisition/maintenance linkage | Missing | Deferred product decision |
| F13 zero-proceeds disposal | Broken | Fixed and verified |
| F14 HashID depreciation filter | Broken | Fixed and verified |
| F15 disposal-month GL reconciliation | Broken | Open P0; measured below |
| F16 automated journal maker attribution | Incomplete | Open cross-module decision; not fixed here |
| F17 soft-deleted asset restore binding | Broken | Open P1; reproduced below |
| F18 salvage value exceeds asset cost | Broken | Open P1 validation gap |
| F19 documented QR label-sheet workflow | Missing | Open P2 product/documentation gap |

### M031-F15 — Broken: disposal-month depreciation leaves the GL and register unreconciled

Priority: **P0**
Classification: **Broken**
Scope: **large**
Session recommendation: **separate-recommended**

The monthly runner deliberately includes a disposed asset when
`disposed_date >= periodStart` (`api/app/Modules/Assets/Services/DepreciationService.php:184-199`),
while disposal reverses only the accumulated depreciation present at the time
of disposal (`api/app/Modules/Assets/Services/AssetService.php:163-166,187-189`).
On PostgreSQL, a ₱12,000 asset acquired 2026-01-01 and disposed for zero on
2026-06-15 produced ₱1,200 accumulated in the register, but the disposal JE
reversed ₱1,000 and the later June depreciation JE credited ₱200. The
accumulated-depreciation account therefore retained a ₱200 credit for an asset
already removed from PPE. Disposing after the June run instead reports a
₱10,800 loss, so the reported result depends on operator/cron order.

The supported disposal/acquisition-month policy must be chosen before code is
changed: depreciate through the disposal month, exclude it, or make disposal
reverse the full scheduled month. Then assert the disposed asset's contra
account nets to zero and the register/GL loss agrees for both operation orders.

### M031-F16 — Incomplete: automated journal maker attribution is discarded

Priority: **P1**
Classification: **Incomplete**
Scope: **medium**
Session recommendation: **separate-recommended**

`DepreciationService` resolves an actor and passes it to the journal boundary
(`api/app/Modules/Assets/Services/DepreciationService.php:103-110`), and disposal
does the same (`api/app/Modules/Assets/Services/AssetService.php:209-216`).
`JournalEntryService::create()` deliberately sets `created_by` to null for any
source-linked entry (`api/app/Modules/Accounting/Services/JournalEntryService.php:126-140`).
The current probe measured `created_by = NULL` and `posted_by = 1` for an actor
1 depreciation/disposal path, so unattended financial postings cannot identify
their maker. This is the shared Accounting decision #12 recorded in
`audit/OVERNIGHT-2026-08-27.md:152-174`; it is a dependency blocker and is not
modified in this module audit.

### M031-F17 — Broken: the live restore endpoint cannot bind a soft-deleted asset

Priority: **P1**
Classification: **Broken**
Scope: **small**
Session recommendation: **same-session-ok**

The route exposes `PATCH /assets/{asset}/restore` without `withTrashed()`
(`api/app/Modules/Assets/routes.php:14-24`). `Asset` uses `SoftDeletes`
(`api/app/Modules/Assets/Models/Asset.php:19-24`), and its default hash route
binding queries without trashed rows (`api/app/Common/Traits/HasHashId.php:23-42`);
only the separate `resolveSoftDeletableRouteBinding()` path includes them
(`HasHashId.php:46-68`). A private-database probe soft-deleted an asset, then
the default binding raised `ModelNotFoundException`, while the soft-deletable
binding found it. The advertised restore operation is therefore unreachable
for every deleted asset. Add route opt-in and a focused HTTP test in a future
small implementation session.

### M031-F18 — Broken: salvage value is not bounded by acquisition cost

Priority: **P1**
Classification: **Broken**
Scope: **medium**
Session recommendation: **separate-recommended**

Both create and update validate salvage as a non-negative decimal but never
compare it with acquisition cost (`api/app/Modules/Assets/Requests/StoreAssetRequest.php:36-40`,
`api/app/Modules/Assets/Requests/UpdateAssetRequest.php:27-35`). The service
also accepts the values without a business-rule guard
(`api/app/Modules/Assets/Services/AssetService.php:57-76,82-120`). The model
then clamps depreciable value to zero (`api/app/Modules/Assets/Models/Asset.php:79-82`),
leaving a register whose salvage value exceeds its cost and whose schedule
silently posts no depreciation. Define the invariant, enforce it on create and
pre-history updates, and cover zero-cost, equal, greater-than-cost, and
post-history cases.

### M031-F19 — Missing: documented multi-row QR label workflow is absent

Priority: **P2**
Classification: **Missing**
Scope: **medium**
Session recommendation: **separate-recommended**

The user manual promises **Print QR Labels** from a multi-row selection on the
assets list (`docs/USER-MANUAL.md:251-254`). The live list has no selection or
print action (`spa/src/pages/assets/index.tsx:44-54,71-123`); only the detail
page's per-asset generated image exists (`spa/src/pages/assets/detail.tsx:217-245`).
Either remove/update the documented promise or implement a permission-gated
batch label workflow and browser coverage. This is separate from F09, which
concerns the correctness of the existing detail QR.

### Gate decision and next action

The current plan has one `same-session-ok` item (F17) and the rest are
`separate-recommended`; its total scope is not small. Per the audit gate, no
production fix is applied. Release M031 as `📋 Plan Ready` and schedule a
financial-hardening session for F15/F18, a shared Accounting decision for F16,
and product decisions for F06/F11 before any transfer or maintenance surface is
re-enabled. Browser acceptance for F07–F10 and the F19 QR-label decision remain
required.

---

## Re-audit — 2026-08-30

Scope: this module only. Dependency modules (`journal-ledger`,
`chart-of-accounts-periods`, `employee-master`, `maintenance-machine-health`)
were read for context and not modified.

### Carried-forward findings, re-verified

| Finding | State on 2026-08-30 | Evidence |
|---|---|---|
| F17 restore binding | **Closed** | `->withTrashed()` present at `api/app/Modules/Assets/routes.php:21-23`; `AssetRestoreRouteTest` passes. Committed in `2ce9d03b`/`0d38d4c5`. |
| F15 disposal-month GL reconciliation | Open, unchanged | `DepreciationService::assetsInServiceFor()` still keeps a disposed asset in service for `disposed_date >= periodStart` (`api/app/Modules/Assets/Services/DepreciationService.php:184-200`) while `AssetService::dispose()` reverses accumulated depreciation as of the disposal instant (`AssetService.php:196-199`). **Needs a policy decision, not a code change** — all three options move a reported money figure. |
| F16 journal maker attribution | Open, unchanged | Shared Accounting decision #12; not this module's to take. |
| F18 salvage bound | **Closed this session** | Reproduced first, then fixed — see below. |
| F06 transfer surface | Open, unchanged | Route group still commented out (`routes.php:33-47`); service + model retained. Product decision. |
| F11 acquisition/maintenance linkage | Open, unchanged | Product decision. |
| F19 QR label-sheet workflow | Open, unchanged | Product/documentation decision. |
| F07–F10 browser acceptance | Open, unchanged | No Playwright spec exists for `/assets*`. Vitest and typecheck pass, but neither measures layout or permission-gated rendering. |

Pre-fix measurement for F18, on a private database (`ogami_test_m031_0830`):
an asset with `acquisition_cost = 12000.00` and `salvage_value = 20000.00` saved
without complaint, and `monthly_depreciation` returned `0.00` for **both**
`straight_line` and `declining_balance`. The register therefore accepts an asset
that will never depreciate, and nothing raises.

### M031-F20 — Polish: asset detail resolved one journal entry per history row

Priority: **P2**
Classification: **Polish** (efficiency)
Scope: **small**
Session recommendation: **same-session-ok**

`AssetResource` published each depreciation row's journal hash with
`JournalEntry::find($d->journal_entry_id)` inside the `map`. Because that is a
fresh query rather than a lazy relation access, `Model::preventLazyLoading()`
(`api/app/Providers/AppServiceProvider.php:237`) never saw it, so the N+1 was
invisible to the guard that exists to catch exactly this.

Measured: `AssetService::show()` on an asset with 6 depreciation rows issued 7
queries — 1 for the rows and **6 separate `select * from journal_entries`**. A
five-year asset carries 60 rows, so the detail endpoint paid 60 extra round
trips per load. `AssetDepreciationController::index` already eager-loads the
same relation (`api/app/Modules/Assets/Controllers/AssetDepreciationController.php:20`),
so this was an inconsistency inside one module rather than a missing convention.

### Questions for the coordinator (not guessed at)

1. **F15 disposal-month policy.** Three defensible conventions, each changing a
   reported figure (loss ₱11,000 vs ₱10,800 on the measured fixture). This is a
   finance policy call, so it is left open rather than decided in an audit.
2. **`AssetController::restore()` reports success for an asset that was never
   trashed.** With `->withTrashed()` the binding now also matches a live asset;
   `$asset->restore()` is then a no-op but the response is still
   `200 {"message":"Asset restored."}` (`api/app/Modules/Assets/Controllers/AssetController.php:74-78`).
   Harmless to data, misleading to an operator. Intentional idempotence, or
   should a live asset be a 422? Not changed — it is a one-line behaviour change
   with a test asserting the current message.
