# M031 — Fixed Assets & Depreciation fix log

Audit date: 2026-08-25  
Claim: `finance / fixed-assets-depreciation`  
Session result: `🔁 Needs Re-audit`

## Fixes applied

### M031-F01 — Money/centavo arithmetic

- Before: `Asset` and disposal/depreciation services converted financial
  values to binary floats and rounded only at persistence/JE boundaries.
- After: monthly depreciation, book value, disposal proceeds, book value,
  gain/loss, per-asset rows, accumulated balances, and consolidated totals use
  `Money` string arithmetic (`api/app/Modules/Assets/Models/Asset.php:76-110`,
  `api/app/Modules/Assets/Services/AssetService.php:154-177`,
  `api/app/Modules/Assets/Services/DepreciationService.php:73-137,242-265`).
  Each depreciation row is rounded before the same row amount is aggregated
  into the journal.
- Verification: boundary unit coverage for cost/salvage equality and declining
  balance salvage capping was added at
  `api/tests/Unit/Assets/AssetMoneyCalculationTest.php:13-38`.

### M031-F02 — Period eligibility, ordering, and retry identity

- Before: any shaped year/month could be posted out of order using the current
  accumulated balance, with no durable period-level journal identity.
- After: completed-period validation, acquisition/disposal-month eligibility,
  chronological prior-period checks, explicit chronological backfill, legacy
  row reconciliation, and run summary reconciliation are enforced in
  `api/app/Modules/Assets/Services/DepreciationService.php:39-70,141-240,280-359`.
  `asset_depreciation_runs` persists the unique period/journal summary via
  `api/app/Modules/Assets/Models/AssetDepreciationRun.php:1-35` and
  `api/database/migrations/2026_08_25_111000_create_asset_depreciation_runs_table.php:9-27`.
  The command and HTTP entry points reject current/future periods and expose
  explicit backfill only at
  `api/app/Console/Commands/RunMonthlyDepreciation.php:20-81` and
  `api/app/Modules/Assets/Controllers/AssetDepreciationController.php:58-69`.
- Verification: the idempotent backfill and durable-handoff fixtures were
  updated at `api/tests/Feature/Assets/AssetDepreciationCommandTest.php:39-72`
  and `api/tests/Feature/Assets/AssetDepreciationDurableHandoffTest.php:113-126`.

### M031-F03 — Lifecycle locking and financial immutability

- Before: update/delete checked stale in-memory state, schedule fields could
  change after depreciation, and custody history did not prevent deletion.
- After: update and delete re-read the asset under row lock;
  useful-life/salvage changes are rejected after depreciation history, and any
  depreciation or transfer history blocks deletion
  (`api/app/Modules/Assets/Services/AssetService.php:82-115,199-214`).

### M031-F04 — Disposal date, proceeds, reason, and audit contract

- Before: disposal accepted unbounded dates, treated the reason as optional,
  and did not persist it.
- After: the request bounds disposal to today, the service rejects dates before
  acquisition, checks the canonical posting-period guard, requires a trimmed
  reason, stores it, and includes it in the disposal journal description
  (`api/app/Modules/Assets/Requests/DisposeAssetRequest.php:16-22`,
  `api/app/Modules/Assets/Services/AssetService.php:125-196`).
  The new persisted field is exposed by the resource and migration at
  `api/app/Modules/Assets/Resources/AssetResource.php:41-44` and
  `api/database/migrations/2026_08_25_110000_add_disposal_reason_to_assets.php:9-19`.
  The detail modal now collects date/reason and surfaces server errors at
  `spa/src/pages/assets/detail.tsx:88-102,268-305`.

### M031-F05 — Transfer custody race and status guards

- Before: transfer creation/approval did not lock the asset, require active
  status, reject overlapping pending requests, or re-check the source
  department before moving custody.
- After: create and approve lock the asset, validate lifecycle/source state,
  reject another pending request, and use an explicit transition map in
  `api/app/Modules/Assets/Services/AssetTransferService.php:38-155`.
  Regression coverage for disposed assets and duplicate pending requests is at
  `api/tests/Feature/Assets/AssetTransferTest.php:148-196`.

### M031-F06 — Transfer HTTP/SPA surface

- Deferred: the route/UI reactivation was deliberately not retained. The
  repository roadmap still marks asset transfers `DEPRECATE / HIDE` until live
  rows, custody policy, and approval ownership exist
  (`docs/SYSTEM-IMPROVEMENT-ROADMAP-2026-08-13.md:484-486`), which conflicts
  with treating the retained code as a live workflow. The route group and SPA
  routes remain hidden at `api/app/Modules/Assets/routes.php:31-45` and
  `spa/src/routes/assetsRoutes.tsx:22-27`. This needs a product decision and
  re-audit; no UI/API surface was re-enabled on assumption.

### M031-F07 — Permission gate alignment

- Before: edit controls used `assets.create`, and the depreciation action used
  `assets.depreciation.view` while the API requires update/run permissions.
- After: the edit route/detail action use `assets.update`, and the list/runner
  use `assets.depreciation.run` (`spa/src/routes/assetsRoutes.tsx:23-27`,
  `spa/src/pages/assets/detail.tsx:139-147`,
  `spa/src/pages/assets/index.tsx:77-82`).

### M031-F08 — API/UI field contract

- Before: create omitted depreciation method/insurance fields; edit posted
  immutable acquisition fields and could clear the department; depreciation
  list rows omitted journal identity.
- After: the TypeScript contract includes the backend asset fields and a
  restricted `UpdateAssetData` shape (`spa/src/types/assets.ts:1-75`), create
  renders the supported method/insurance fields
  (`spa/src/pages/assets/create.tsx:20-51,97-143`), edit makes immutable fields
  read-only and preserves the department (`spa/src/pages/assets/edit.tsx:19-65`),
  and the depreciation list includes `journal_entry_id`
  (`api/app/Modules/Assets/Controllers/AssetDepreciationController.php:15-45`).
  Missing salvage defaults are normalized to `Money::zero()` in
  `api/app/Modules/Assets/Services/AssetService.php:60-76,106-115`.

### M031-F09 — QR rendering

- Before: the asset deep-link URL was used directly as an image source and
  downloaded as a PNG.
- After: the pinned `qrcode` runtime package and types are declared in
  `spa/package.json:42,61` (with the matching lockfile); the detail page turns
  the URL payload into a real PNG data URL, renders it, provides a valid
  download, and offers a link fallback on generation failure
  (`spa/src/pages/assets/detail.tsx:3-75,217-250`).

### M031-F10 — January/recovery UI polish

- Before: January defaulted to month `0`, and both depreciation surfaces hid
  server validation/closed-period messages behind a generic toast.
- After: both pages calculate the prior calendar month with rollover and show
  the API error message (`spa/src/pages/assets/DepreciationRunner.tsx:20-34`,
  `spa/src/pages/admin/depreciation.tsx:13-26`).

### M031-F11 — Acquisition/maintenance linkage

- Deferred: this remains a human/product-scope question. Implementing machine,
  mold, vehicle, custodian, and maintenance ownership would create
  cross-module writers and a new auditable timeline; no safe module-local fix
  was inferred from the existing register contract.

## Verification and limits

- PHP lint passed for all changed Assets PHP files, commands, migrations, and
  the new unit test.
- PHPStan reported `No errors` for the Assets module and depreciation commands.
- `AssetMoneyCalculationTest` passed: 2 tests, 4 assertions.
- Scoped ESLint passed for the changed asset SPA files.
- A disposable-copy targeted TypeScript check covering the asset API/types,
  pages, depreciation page, and asset routes passed with `npx tsc -p
  tsconfig.m031.json`. The repository-wide check remains non-green because of
  pre-existing unrelated CRM/budgeting/type errors, including a syntax-broken
  `spa/src/pages/crm/sales-orders/create.tsx`.
- `php artisan route:list --path=asset` passed and showed no transfer routes;
  the live Assets/depreciation surface lists 11 routes.
- Module-scoped `git diff --check` passed.
- Fifteen targeted Assets feature tests were attempted but reached 0
  assertions because PostgreSQL host `db` is unavailable in this checkout
  (`SQLSTATE[08006]`). No database-backed result is treated as passing.
- No browser/E2E run, live PostgreSQL migration rehearsal, or two-connection
  race test was available.

## Remaining work

- F06 requires an explicit product decision on whether the hidden transfer
  workflow is retired or reactivated after its ownership policy is defined.
- F11 requires the same kind of human scope decision for acquisition,
  maintenance, and custody linkage.

These pending items are why the module is released as `🔁 Needs Re-audit` rather
than `✅ Verified`.

## Resumed-session verification — 2026-08-25

- No additional production-code fix was applied in this resumed verification
  session. The existing F01–F05 and F07–F10 implementation remains in the
  working tree at the file/line locations recorded above.
- Scoped PHP lint passed for the Assets module, depreciation commands, related
  migrations, and Assets tests. PHPStan passed with `No errors` for
  `app/Modules/Assets` and both depreciation commands. The pure Money coverage
  passed: 2 tests, 4 assertions (`api/tests/Unit/Assets/AssetMoneyCalculationTest.php:13-38`).
- Scoped SPA ESLint and module diff-check passed for the changed asset files.
  An asset-only TypeScript check could not complete because this checkout has
  no installed `qrcode` runtime/types despite declarations in
  `spa/package.json:42,61` and `spa/package-lock.json:27,2133,5185`; it also
  surfaced the pre-existing `import.meta.env` typing error at
  `spa/src/api/client.ts:250`.
- The host feature run could not resolve Compose hostname `db`. Running the
  same suite inside the API container reached 16 tests: 6 progressed without
  setup errors and 10 errored before assertions because the shared
  `ogami_test` schema is stale/incomplete (`accounts`/`permissions` missing and
  `roles.deleted_at` absent). No database-backed result is treated as passing.
- F06 remains deferred pending a product decision to retire the hidden transfer
  implementation or restore its guarded API/SPA workflow. F11 remains deferred
  pending a product decision on cross-module asset, custodian, and maintenance
  ownership. Both are human-input blockers, so the module remains
  `🔁 Needs Re-audit`.
