# M011 — Documents & Exports audit report

Audit date: 2026-08-27
Claim: platform / documents-exports (M011)
Registry tier: 4
Status: 📋 Plan Ready
Session recommendation: separate-recommended

## Verdict

The previous session’s high-risk export-boundary, registry-drift, bulk-PDF, and
renderer-hardening issues are fixed in the current tree. M011 is still not ready
for an unqualified release: the vault contract is incomplete across official
reports, its integrity/retention policy is unfinished, export generation is
still materialized in memory, and delivery/entity-surface coverage is incomplete.

The gate is **Plan Ready**. The action plan is dominated by medium/large,
separate-session work and unresolved policy decisions, so no production-code
change was made in this re-audit.

## Evidence checked

- Re-read the coordinator registry snapshot, M011 `status.md`, prior
  `audit-report.md`, `action-plan.md`, and `fix-log.md` before trusting the
  previous status. M011 was claimed atomically with
  `audit/scripts/claim-module.sh platform documents-exports`.
- Re-read inherited design/pattern guidance, module routes, middleware,
  permissions, models, migrations, registry/runner, vault/PDF paths, scheduler,
  SPA surfaces, and focused tests. Dependencies were inspected for context only.
- Compared the working-tree diff and mtimes. There was no pre-existing M011
  application/test/UI diff; unrelated dirty worktree entries were preserved.
- Ran the focused backend suite on `ogami_test_m011_roll_b`: **49 tests,
  185 assertions, 0 failures**. A fresh migration also completed on that
  database.
- PHP syntax checks passed for the matched M011 application/command files;
  `docker compose exec -T spa npm run typecheck` passed.
- `php artisan route:list` confirmed the documents, exports, scheduled-export,
  and related producer routes. The targeted Playwright spec was attempted but is
  blocked by the missing browser executable:
  `spawn /root/.cache/ms-playwright/chromium_headless_shell-1223/.../chrome-headless-shell ENOENT`.

## Pass summary

### Discovery

The current runtime has one complete export contract: `hr.employees` is
registered by `EmployeeMasterExport::registerColumns()`
(`api/app/Modules/HR/Exports/EmployeeMasterExport.php:88-161`), and the old
inventory export affordance is no longer present. The HTTP and background paths
now use the same registry/runner boundary. The vault stores private blobs,
metadata, and SHA-256 checksums (`api/app/Common/Services/DocumentVaultService.php:43-88`),
and scheduled artifacts have explicit byte limits and a private disk path.

### Hardening

The negative authorization and lease tests pass. Unknown/sensitive columns are
rejected by `ExportColumnRegistry::validateColumns()`
(`api/app/Common/Services/Export/ExportColumnRegistry.php:162-207`), the runner
rechecks the owner’s module permission before background execution
(`api/app/Common/Services/Export/ExportRunner.php:32-59`), and Dompdf PHP,
JavaScript, and remote loading are disabled (`api/config/dompdf.php:62-72`).

The remaining hardening gaps are integrity verification, typed filter values,
delivery outcome tracking, and the incomplete per-document permission contract
listed below.

### Polish

The scheduled-export create/edit modal and browser spec now exist, and the
employee detail page mounts `DocumentList` (`spa/src/pages/hr/employees/detail.tsx:677`).
The SPA typecheck is clean. The admin scheduled-export page still exposes a
new-schedule action to a role that may not have the only registered module’s
export permission, and the document type union is behind the backend enum.

## Findings

### M011-F05 — Incomplete/high: official PDF and vault permission contracts are not complete

Priority: **P1**
Scope: **large**
Recommendation: **separate-recommended**

The three financial-statement PDF methods still render and return raw response
bytes instead of creating a vault row: `trialBalance()` and `incomeStatement()`
(`api/app/Modules/Accounting/Services/PdfService.php:65-84`) and
`balanceSheet()` (`api/app/Modules/Accounting/Services/PdfService.php:107-115`).
The enum already defines these document types
(`api/app/Common/Enums/DocumentType.php:23-25`) and the central controller maps
them to `accounting.statements.view` (`api/app/Common/Controllers/DocumentController.php:230-243`),
so the intended vault contract is present but not applied. The dependent audit
log PDF similarly returns renderer bytes directly (`api/app/Modules/Admin/Controllers/AuditLogController.php:179-205`).

ImpEx packing lists and commercial invoices now do write vault rows
(`api/app/Modules/SupplyChain/Services/ImpexDocumentService.php:32-81`), but
`DocumentController::permissionFor()` has no `packing_list` or
`commercial_invoice` case and falls back to `admin.audit_logs.view`
(`api/app/Common/Controllers/DocumentController.php:230-243`). A user with the
producer route’s `supply_chain.view` grant (`api/app/Modules/SupplyChain/routes.php:42-46`)
can generate and receive the file, then cannot reopen that stored row through
the central view/download routes. This is a broken lifecycle/authorization
contract, not an exposure: the fallback fails closed.

Action: decide ownership and retention for statement/report artifacts; complete
the per-type permission matrix; route the remaining company PDFs through the
vault; and add generation → central view/download tests for each family. Changes
belong to the owning modules plus the shared M011 contract and should be done in
a separate session.

### M011-F06 — Incomplete: entity-scoped document discovery covers employees only

Priority: **P2**
Scope: **medium**
Recommendation: **separate-recommended**

The entity endpoint accepts only `employee`/`employees`
(`api/app/Common/Controllers/DocumentController.php:64-90`), and the SPA
component’s prop is correspondingly hard-coded to `'employees'`
(`spa/src/components/documents/DocumentList.tsx:20-25`). The component is mounted
only on the HR employee detail page (`spa/src/pages/hr/employees/detail.tsx:677`);
the general `/documents` index remains an audit-permission list
(`api/app/Common/Controllers/DocumentController.php:36-61`). Vaulted invoices,
purchase records, inspections, shipments, complaints, and report artifacts do
not have a common entity-scoped history surface in this module.

Action: publish a guarded entity resolver for the supported business records,
mount the list on the intended detail pages, or explicitly narrow/document the
vault contract. Add access-negative tests for each supported entity family.

### M011-F07 — Incomplete: export resource limits are applied after materialization

Priority: **P2**
Scope: **medium**
Recommendation: **separate-recommended**

The current protections reduce risk but do not make generation streaming:
`EmployeeMasterExport::collection()` calls `get()` after applying scope and
filters (`api/app/Modules/HR/Exports/EmployeeMasterExport.php:50-81`), while
`SpreadsheetExportService` checks the row cap only after receiving the complete
collection and builds a complete workbook (`api/app/Common/Services/Export/SpreadsheetExportService.php:53-92`).
`render()` then reads the complete temporary file into another string
(`api/app/Common/Services/Export/SpreadsheetExportService.php:31-50`), and the
scheduled runner retains those bytes while writing the durable artifact
(`api/app/Console/Commands/RunDueScheduledExports.php:160-180`). The 50,000-row
and 25 MiB artifact caps are useful fail-closed limits, but they do not protect
the request/worker from the memory and CPU cost of a large capped workbook, and
there is no export timeout policy.

Action: use cursor/chunk or disk-backed generation, avoid duplicate full-byte
copies, define request/worker timeouts, and retain explicit over-limit metrics.

### M011-F08 — Incomplete: retention and reconciliation do not define document history lifecycle

Priority: **P2**
Scope: **medium**
Recommendation: **separate-recommended**

Reconciliation now reports missing blobs and unreferenced files and can delete
old orphans only with explicit flags (`api/app/Console/Commands/ReconcileDocumentVault.php:35-77`).
However, it only counts soft-deleted document rows; it does not apply a
document-type retention policy or prune superseded historical rows/blobs. The
scheduled jobs are registered (`api/routes/console.php:215-228`), but queued
export artifacts are retained by a hard-coded seven-day route setting and have
no database metadata linking them to a schedule
(`api/app/Common/Services/Export/ScheduledExportArtifactService.php:22-41`).

Action: approve retention by document type and legal/audit requirement, then
add observable row/blob reaping and a durable scheduled-artifact policy that
cannot delete an in-flight retry.

### M011-F09 — Missing: production-like cross-family and browser verification is incomplete

Priority: **P2**
Scope: **medium**
Recommendation: **separate-recommended**

The focused M011 backend suite is green, including negative export authorization,
vault access, lease behavior, durable artifact creation, spreadsheet safety, and
Dompdf settings. It does not cover the remaining contract edges: statement
PDF vaulting, ImpEx central re-download authorization, checksum mismatch,
delivery-failure state, typed filter rejection, or the schedule-only admin role.
The scheduled-export Playwright spec exists (`spa/e2e/scheduled-exports.spec.ts:63-152`)
but could not launch because Chromium is absent from the container; it was not
counted as a pass. The suite cannot be called production-like until the browser
dependency is installed and the API/UI flow is executed.

Action: add the missing negative and lifecycle cases, install/pin the browser in
the E2E environment, execute the new spec, and retain the result as a release
artifact.

### M011-F16 — Missing: vault checksums are recorded but never verified

Priority: **P2**
Scope: **medium**
Recommendation: **separate-recommended**

`DocumentVaultService::store()` records `checksum_sha256`
(`api/app/Common/Services/DocumentVaultService.php:72-84`), but `readBytes()`
only checks existence and size (`api/app/Common/Services/DocumentVaultService.php:194-210`),
and the streaming path likewise checks only existence/size before `fpassthru`
(`api/app/Common/Services/DocumentVaultService.php:213-247`). The reconciliation
command also does not compare content to the recorded digest
(`api/app/Console/Commands/ReconcileDocumentVault.php:35-49`). A changed or
corrupted private blob is therefore served as if it matched its audit metadata.

Action: verify the digest in a bounded/disk-streaming path, quarantine or refuse
mismatches, emit an actionable alert, and test both direct reads and HTTP
downloads after deliberate blob mutation.

### M011-F11 — Incomplete/high: scheduled-export recipient policy is unresolved

Priority: **P1**
Scope: **medium**
Recommendation: **separate-recommended**

The controller validates email syntax and de-duplicates addresses only
(`api/app/Common/Controllers/ScheduledExportController.php:186-194`); the worker
repeats syntax filtering and then queues to every remaining address
(`api/app/Console/Commands/RunDueScheduledExports.php:137-143,172-180`). An
export-capable user can consequently send module data to an arbitrary external
address. The code correctly records that approved-domain/recipient ownership is
a business/deployment decision rather than guessing one from the sender, but no
policy has been selected.

Action: obtain an owner decision (owner-only, organization membership, approved
domains, or explicit admin approval), enforce it at create/update and execution,
and add rejection/audit tests. Do not implement an invented allowlist.

### M011-F12 — Incomplete: queued-mail failure is not correlated to schedule state

Priority: **P2**
Scope: **medium**
Recommendation: **separate-recommended**

After `Mail::queue()` accepts a message, the runner immediately records
`last_run_at`, advances `next_run_at`, and clears `last_error`
(`api/app/Console/Commands/RunDueScheduledExports.php:172-203`). The mailable
does not carry a schedule ID (`api/app/Common/Mail/ScheduledExportMail.php:21-41`),
and its `failed()` hook sends an inbox notification without updating the
schedule (`api/app/Common/Mail/ScheduledExportMail.php:87-99`). The resource and
SPA expose `last_error`/“Retry pending” fields
(`api/app/Common/Resources/ScheduledExportResource.php:31-35`,
`spa/src/pages/admin/scheduled-exports/index.tsx:136-145`), but a provider
rejection is not reflected there. Queue acceptance is therefore presented as a
successful delivery with no schedule-level retry/diagnostic state.

Action: define whether `last_run_at` means generation or delivery, then carry a
stable schedule/attempt identifier into the mailable and persist delivery
outcomes or a deliberate separate delivery ledger.

### M011-F13 — Incomplete: registered export filters have no value schema

Priority: **P2**
Scope: **medium**
Recommendation: **separate-recommended**

`ExportColumnRegistry::validateFilters()` checks only filter names
(`api/app/Common/Services/Export/ExportColumnRegistry.php:214-224`). The
scheduled-export validator accepts any nested array under `filters`
(`api/app/Common/Controllers/ScheduledExportController.php:140-150`), while the
HR exporter passes `status` and `pay_type` directly into query predicates and
decodes `department_id` (`api/app/Modules/HR/Exports/EmployeeMasterExport.php:66-79`).
Invalid enum values, arrays, and oversized values can be persisted into a
schedule and later produce an empty export or a worker/query failure. The
column contract is now typed and fail-closed; the filter contract is not.

Action: register scalar/enum/hash schemas with bounds, validate them at every
boundary, and add malformed persisted-payload tests before adding more modules.

### M011-F14 — Polish: scheduled-export affordances are not capability-aware

Priority: **P2**
Scope: **small**
Recommendation: **separate-recommended**

The SPA route admits either `admin.scheduled_exports.view` or
`hr.employees.export` (`spa/src/routes/adminRoutes.tsx:144-150`). The page always
renders “New schedule” and defaults the modal to `hr.employees`
(`spa/src/pages/admin/scheduled-exports/index.tsx:208-223,294-303`), but the
backend requires the module’s export permission on create
(`api/app/Common/Controllers/ScheduledExportController.php:156-173`). A role
with only the scheduled-list permission reaches a form whose column request is
rejected for the only registered module. Once more modules are registered, the
hard-coded module also becomes stale.

Action: hide/disable creation until a permitted module is selected, or load a
module catalog and provide a capability-aware picker.

### M011-F15 — Polish: SPA document types lag the backend enum

Priority: **P2**
Scope: **small**
Recommendation: **same-session-ok**

The backend enum/resource can return `packing_list` and `commercial_invoice`
(`api/app/Common/Enums/DocumentType.php:34-35`,
`api/app/Common/Resources/DocumentResource.php:22-30`), but the SPA
`DocumentType` union stops at `bulk_pdf`
(`spa/src/types/documents.ts:5-25`). The current employee-only component does
not exercise those values, so this is contract drift rather than an observed
runtime exposure.

Action: update the frontend union and add a resource-fixture type contract test
when the broader entity surface is implemented.

## Previously reported items rechecked

| Prior finding | Current result |
|---|---|
| F01 — arbitrary export columns (Broken) | **Resolved.** Registry validation and resolver-only mapping reject unknown/sensitive keys. |
| F02 — scheduled capability boundary (Incomplete) | **Resolved at create/run boundaries.** Permission, implementation, columns, and filters are revalidated; recipient policy remains M011-F11. |
| F03 — advertised-but-unimplemented modules (Broken) | **Resolved.** The runnable registry is authoritative and the unsupported inventory affordance is gone. |
| F04 — missing schedule creation flow (Missing) | **Implemented; execution unverified.** Modal/page/spec exist; browser launch is covered by M011-F09. |
| F05 — official PDF vaulting (Incomplete) | **Still open; narrowed.** Bulk and several module families now use the vault; statements and the permission mapping above remain. |
| F06 — entity document surface (Incomplete) | **Partially resolved.** Employee list endpoint and mount exist; broader entity coverage remains M011-F06. |
| F07 — resource bounds (Operational risk) | **Mitigated but incomplete.** Row/byte caps and durable artifacts exist; post-materialization generation remains M011-F07. |
| F08 — retention/reconciliation (Missing) | **Partially resolved.** Orphan/reaper commands exist; policy and historical-row lifecycle remain M011-F08. |
| F09 — risk-surface coverage (Missing) | **Expanded but still open.** Focused backend tests pass; cross-family/browser coverage remains M011-F09. |
| F10 — Dompdf hardening (Hardening) | **Resolved.** PHP, JavaScript, and remote loading are disabled and asserted. |

## Gate result

**📋 Plan Ready — do not fix in this session.** The ordered plan contains one
`same-session-ok` small polish action and the rest are `separate-recommended`
medium/large actions, including unresolved recipient and retention decisions.
The majority/small-scope gate therefore does not permit a code fix.
