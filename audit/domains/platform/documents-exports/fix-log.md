# M011 — Documents & Exports fix log

Audit date: 2026-08-24
Hardening session: 2026-08-26
Module status after this session: 🔁 Needs Re-audit (see “Pending items” — nothing
in this log is a bounce-back to Plan Ready; the plan was worked, three items are
blocked on a human decision or on the environment)

---

## Session 1 (2026-08-24) — audit only

- Refreshed the registry and atomically claimed platform / documents-exports.
- Reviewed routes, auth/session and permission gates, export registry/runner, scheduled-export leases and queue handoff, private storage, document/PDF lifecycle, migrations/rollback, seed permissions, SPA routes/components, and E2E evidence.
- Confirmed the sensitive-field exposure path statically: request-supplied columns bypass the registry, the base mapper reads arbitrary model properties, and Employee exposes encrypted government IDs and bank-account data through casts.
- Confirmed the production registration gap: boot registers only hr.employees, while the runner maps only hr.employees even though the controller/UI advertise other modules.
- Decided against same-session implementation because the majority of material findings require coordinated security, module-contract, PDF-lifecycle, UI, and operational changes.
- **No production-code fixes were applied in session 1.**

---

## Session 2 (2026-08-26) — hardening

### Findings already resolved before this session started

The export/document surface was worked between the audit and this session (commits
`1dbb4187`, `891764d8`, plus uncommitted working-tree changes). Each finding was
re-checked against current code rather than trusted from the report. Already fixed:

| Finding | Evidence in current code |
|---|---|
| **M011-F01** export column allowlist | `ExportColumnRegistry::validateColumns()` (`api/app/Common/Services/Export/ExportColumnRegistry.php:159-207`) rejects unknown keys and enforces a per-column `permission`; `BaseModuleExport::map()` (`api/app/Common/Exports/BaseModuleExport.php:51-65`) now throws `LogicException` instead of falling back to direct model property access; the HR registry exposes no government ID or bank field and gates salary behind `hr.employees.view_sensitive` (`api/app/Modules/HR/Exports/EmployeeMasterExport.php:90-161`). |
| **M011-F02** scheduled-export capability boundary | `ScheduledExportController::validatePayload()` (`api/app/Common/Controllers/ScheduledExportController.php:156-195`) now checks registration, implementation, module permission, columns, and filters; `RunDueScheduledExports::runOne()` (`api/app/Console/Commands/RunDueScheduledExports.php:149-159`) rebuilds through `ExportRunner` before execution. **Two residual gaps found and fixed in this session — see below.** |
| **M011-F03** registry/runner/UI alignment | The controller permission map and `ExportRunner::MAP` are gone; both now read one contract via `ExportColumnRegistry::registerModule()`. `ExportController::guardModule()` 404s an unimplemented module (`api/app/Common/Controllers/ExportController.php:128-138`). No SPA reference to `inventory.valuation` or any other unregistered module remains. |
| **M011-F04** scheduled-export create UX | `spa/src/components/exports/ScheduledExportFormModal.tsx` exists and is mounted from both `spa/src/pages/admin/scheduled-exports/index.tsx:294` (“New schedule” header action) and `spa/src/pages/hr/employees/index.tsx:460`. **Browser test added this session — not executed, see Pending.** |
| **M011-F05** official PDF vault integration | Accounting bills/invoices/journal entries/POs (`api/app/Modules/Accounting/Services/PdfService.php:124-131`), Purchasing PO/PR, Quality CoC, Supply Chain Impex, and CRM 8D all route through `PdfRenderService` + `DocumentVaultService`. **Bulk PDF was still bypassing — fixed this session. Three Accounting statements still bypass — see Pending.** |
| **M011-F06** entity-scoped document surface | `GET /documents/entity/{entityType}/{entityId}` added with `authorizeEntityList()` + `DepartmentScope` (`api/app/Common/Controllers/DocumentController.php:64-91,170-191`), declared before the `{document}` binding; `DocumentList` mounted at `spa/src/pages/hr/employees/detail.tsx:677`; the stale “absolute URL” comment in `spa/src/api/documents.ts:1-6` is corrected. |
| **M011-F07** resource bounds / durable attachments | `SpreadsheetExportService::MAX_ROWS = 50_000` with an explicit over-limit failure (`api/app/Common/Services/Export/SpreadsheetExportService.php:20,68-74`); vault delivery streams via `readStream` under a 50 MiB cap (`api/app/Common/Services/DocumentVaultService.php:33,213-248`); `ScheduledExportArtifactService` writes a private durable artifact and `ScheduledExportMail` attaches it by path instead of embedding base64. |
| **M011-F08** retention / orphan reconciliation | `documents:reconcile` (`api/app/Console/Commands/ReconcileDocumentVault.php`, read-only by default, `--delete-orphans` + `--grace-days`) and `exports:prune-artifacts --days=7`, both scheduled in `api/routes/console.php:207-228`. |
| **M011-F10** renderer hardening | `enable_php`, `enable_javascript`, `enable_remote` all `false` in `api/config/dompdf.php:62-72`. **Assertion test added this session.** |

### Fixes applied this session

**1. M011-F05 — bulk print bypassed the renderer and the vault**
`api/app/Common/Services/BulkPdfService.php:1-95`
Before: `Pdf::loadView('pdf._bulk', …)->output()` returned a raw `Response`. No
`documents` row, no checksum, no private blob, no re-download, and — because the
wrapped per-document Blades extend `pdf._layout` — no `$company` / `$generated`
context, so every bulk print rendered with an empty letterhead (`letterhead.blade.php`
guards with `!empty($company[…])`, so the omission was silent).
After: renders through `PdfRenderService`, stores through `DocumentVaultService`
with `DocumentType::BulkPdf`, and streams from the vault row. The artifact spans
many records so it has no single owning entity and `documents.entity_type/entity_id`
are `NOT NULL`; the row is therefore filed against the requesting user, which grants
no extra reach because `DocumentController::permissionFor('bulk_pdf')` gates access
on `admin.print.bulk`, never on entity ownership.
`api/app/Modules/Admin/Controllers/BulkPrintController.php:20-32` — now resolves and
passes the acting `User`, returns `StreamedResponse`. The SPA sets its own download
filename (`spa/src/api/print.ts:27`), so the server-side filename change is invisible.

**2. M011-F02 (residual) — the background run did not re-check the module permission**
`api/app/Common/Services/Export/ExportRunner.php:32-59`
Before: `build()` revalidated columns and filters against the registry but never the
module permission. `ExportController` checked it at the HTTP edge; the scheduler did
not. A schedule created while its owner held `hr.employees.export` therefore kept
running after that grant was revoked.
After: `build()` re-checks `ExportColumnRegistry::permissionFor($module)` against the
actor and throws a `ValidationException` naming the required slug. A null actor stays
permissive (console/system path, scoped by the exporter itself); every caller acting
for a person passes that person.

**3. M011-F02 (residual) — the scheduler loaded a partial owner, so no permission or scope resolved**
`api/app/Console/Commands/RunDueScheduledExports.php:52-59`
Before: `->with('owner:id,name,email')`. That projection omits `role_id` and
`employee_id`, so `$owner->can(…)` always returned false and
`DepartmentScope::apply()` fell to its deny-everything branch. Two consequences:
a permission-gated column (`monthly_salary`) could never be scheduled, and every
scheduled `hr.employees` export silently produced a header-only file. Fail-closed,
so not an exposure — but the feature did not work.
After: `->with('owner')`. Verified by
`ScheduledExportExecutionTest::test_a_due_export_queues_a_durable_artifact_and_advances_its_next_run`,
which is the first test to exercise a *successful* run end to end.

**4. M011-F03 — added a public accessor so the contract is enumerable, and a drift guard**
`api/app/Common/Services/Export/ExportColumnRegistry.php:83-103` — added
`modules()` and `filtersFor()`; `validateFilters()` now reads through `filtersFor()`
instead of poking the private array.
`api/tests/Feature/Exports/ExportModuleContractTest.php` (new, 3 tests) — iterates
the registry rather than a hardcoded list and asserts, for every advertised module:
implementation is a `BaseModuleExport`, permission is a real seeded slug, columns are
non-empty with a label and a callable resolver, defaults are non-empty, and every
column-level permission is seeded. Plus: a column-only registration (no module
contract) is not downloadable, and the registry is not silently empty.

**5. M011-F09 — negative and lifecycle coverage**
- `api/tests/Feature/Documents/DocumentControllerTest.php:26-127` (6 new tests):
  admin index requires `admin.audit_logs.view`; destroy requires it too (holding the
  document type's own read permission does not imply purge); view/download 404 when
  the blob is gone but the audit row survives; entity list is refused to an
  `admin.audit_logs.view`-only caller (an entity list must not become a second,
  less-guarded enumeration surface); entity list is department/self scoped; an
  unsupported entity type 404s.
- `api/tests/Feature/Infrastructure/ScheduledExportExecutionTest.php:52-113` (2 new
  tests): a successful due run queues the mail, writes exactly one durable private
  artifact, clears the lease, and moves `next_run_at` forward; a schedule whose owner
  lost the export grant fails, records the required slug in `last_error`, and queues
  nothing.
- `api/tests/Feature/Documents/BulkPrintVaultTest.php` (new, 3 tests): bulk print
  writes a vault row with checksum + private blob and streams as an attachment;
  requires `admin.print.bulk`; and a stored bulk artifact is refused to a caller who
  can read the underlying invoices but not bulk-print.
- `api/tests/Unit/DompdfHardeningTest.php` (new, 3 tests) — M011-F10 acceptance:
  asserts `enable_php`, `enable_javascript`, `enable_remote` are all off so a future
  config edit fails here rather than in production.
- `api/tests/Feature/Exports/ExportAuthorizationTest.php:81-89` — **fixed a genuinely
  failing test.** It asserted `last_error` contained the literal `invalid`; the
  message is now the validation message naming the rejected column. The *behaviour*
  was correct (tampered column rejected, lease released); the assertion was wrong.
  Now asserts the message names `tin` and `not available`.

**6. M011-F04 — browser coverage for the create flow**
`spa/e2e/scheduled-exports.spec.ts` (new, 2 tests) — follows the repo's mocked-route
Playwright convention: empty state still offers “New schedule”; the modal loads the
module's authoritative column list; submit posts the module's own column keys and
shows next-run feedback; the list refreshes. Second test proves a 422 capability
rejection surfaces in the UI `role="alert"` instead of closing silently.
**Written and typechecked, NOT executed — see Pending item 3.**

---

## Verification performed (2026-08-26)

| Check | Result | Notes |
|---|---|---|
| Focused backend M011 suite | **PASS — 59 tests, 199 assertions, 0 failures** | `--filter='Documents\|Exports\|BulkPrintVault\|DompdfHardening\|ScheduledExport\|SpreadsheetExport\|ExportColumnRegistry\|ExportFrequency\|DocumentTypeEnum\|ExportModuleContract\|ExportAuthorization\|EmployeeMasterExport\|DocumentController\|DocumentVaultService'` on a dedicated database `ogami_test_r3` |
| PHP syntax (`php -l`) | PASS | All 9 changed/added PHP files |
| SPA typecheck (`tsc --noEmit`) | PASS for this module | 3 pre-existing errors remain in other modules: `src/pages/assets/detail.tsx` (missing `qrcode` types ×2) and `src/pages/return-management/detail.tsx:902` (duplicate JSX attribute). Not this module, not touched. |
| E2E spec typecheck | PASS | `spa/e2e/scheduled-exports.spec.ts` compiled with the SPA compiler options (the repo `tsconfig.json` `include` covers only `src`, so `npm run typecheck` does not reach `e2e/`) |
| Playwright execution | **BLOCKED (environment)** | See Pending item 3 |
| Financial-statement PDF vaulting | NOT DONE | See Pending item 1 |
| Recipient domain policy | NOT DONE | See Pending item 2 |

### Environment notes (not this module, do not fix here)

- `api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:42`
  runs `DROP INDEX IF EXISTS holidays_date_name_unique` where that name is a UNIQUE
  **constraint**, so Postgres raises `SQLSTATE[2BP01]` and **every** `RefreshDatabase`
  test on any database dies with zero assertions reached. Per coordinator instruction
  the file was moved aside for the duration of each run and restored in the same
  command via a shell `trap`; restoration was verified byte-identical
  (`diff -q` clean, sha256 `f0085c6189fbf9e202c01edb86ed80837a380a5dfb596390860f92ffb567e690`).
  The file is untracked and was observed appearing/disappearing repeatedly under
  another session throughout; it is absent from the tree as of this session's end,
  which is that session's state, not a deletion by this one. A byte-identical copy is
  preserved at `/tmp/holiday_mig_r3.bak` should it need restoring.
- `ComprehensiveDemoSeeder.php:634` aborts on the accounting trigger
  `prevent_posted_journal_line_mutation`. Not used by any test here (tests seed
  `RolePermissionSeeder` or factories only). Reported, not fixed.

---

## Pending items (why this module is 🔁 Needs Re-audit, not ✅ Verified)

**1. M011-F05 — three Accounting financial statements still bypass the vault.**
`api/app/Modules/Accounting/Services/PdfService.php:60-116` — `trialBalance()`,
`incomeStatement()`, `balanceSheet()` return raw `response($bytes)`. `DocumentType`
already has `BalanceSheet` / `IncomeStatement` / `TrialBalance` cases and
`DocumentController::permissionFor()` maps all three to
`accounting.statements.view`, so vaulting was clearly the intent. Two reasons it is
not done here:
 (a) **Needs a business/ops decision.** A period or as-of report has no owning
     business record, so it needs the same “file against the requester” convention
     bulk print now uses — but unlike a deliberate bulk print, statements are
     re-rendered from a dashboard on every refresh. Vaulting each render creates
     unbounded near-duplicate blobs, and `documents:reconcile` only reaps
     *unreferenced* files, not superseded rows. Retention for report-type documents
     must be chosen before this is switched on.
 (b) The file belongs to the Accounting module's audit scope and was already dirty
     in the shared working tree from another concurrent session.
**Recommended next step:** decide report-document retention, then apply the existing
`PdfService::storeAndStream()` pattern to the three statement methods and add one
access/audit test per statement.

**2. M011-F02 — scheduled-export recipient policy is still an open business decision.**
`api/app/Common/Controllers/ScheduledExportController.php:186-194` validates that each
recipient is a syntactically valid email and de-duplicates, but applies no
approved-domain or organization-membership restriction. As the code comment records,
this is a deployment/business policy and must not be guessed from the sender address.
Today any authenticated user who can export a module can mail that module's data to an
arbitrary external address. **This needs a human ruling** (owner-only? seeded allowed
domains? an admin-managed allowlist?) before it can be enforced.

**3. M011-F04 / M011-F09 — the browser test is written but was never executed.**
`spa/node_modules` and `spa/test-results` are owned by `root` from a prior
root/container install, so the Vite dev server cannot write
`spa/node_modules/.vite-temp/…` (`EACCES`) and Playwright cannot write
`test-results/.last-run.json` (`EACCES`). `--configLoader runner` fails separately on
`__dirname` in `vite.config.ts`. This blocks the entire existing Playwright suite for
any user on this box, not just the new spec. Unblock with
`sudo chown -R "$USER":"$USER" spa/node_modules spa/test-results`, then
`npx playwright test e2e/scheduled-exports.spec.ts --project=desktop-chromium`.
The spec was typechecked clean against the SPA compiler options as the strongest
available substitute.

**4. Observation, not a finding — flagging rather than guessing.**
 - `spa/src/pages/admin/scheduled-exports/index.tsx:300` hardcodes
   `module={editTarget?.module ?? 'hr.employees'}` for a *new* schedule. Correct while
   `hr.employees` is the only registered module, but an admin without
   `hr.employees.export` gets a 422 from the only create path. Once a second module is
   registered this needs a module picker sourced from the registry.
 - `api/app/Modules/Landing/Controllers/QualityPolicyController.php:39` streams a
   public quality-policy PDF directly. This looks deliberate (public landing asset, no
   owning entity, no per-user audit requirement) and is not in the F05 list of company
   records. **Question for the owner:** confirm it is an intended exception, alongside
   the documented self-service certificate exception in
   `api/app/Modules/HR/Services/SelfServiceDocumentService.php:15-24`.

---

## Files changed (2026-08-26)

Production code
- `api/app/Common/Services/BulkPdfService.php`
- `api/app/Modules/Admin/Controllers/BulkPrintController.php`
- `api/app/Common/Services/Export/ExportRunner.php`
- `api/app/Common/Services/Export/ExportColumnRegistry.php`
- `api/app/Console/Commands/RunDueScheduledExports.php`

Tests
- `api/tests/Feature/Documents/BulkPrintVaultTest.php` (new)
- `api/tests/Feature/Documents/DocumentControllerTest.php`
- `api/tests/Feature/Exports/ExportModuleContractTest.php` (new)
- `api/tests/Feature/Exports/ExportAuthorizationTest.php`
- `api/tests/Feature/Infrastructure/ScheduledExportExecutionTest.php`
- `api/tests/Unit/DompdfHardeningTest.php` (new)
- `spa/e2e/scheduled-exports.spec.ts` (new)

Audit artifacts
- `audit/domains/platform/documents-exports/fix-log.md` (this file)
