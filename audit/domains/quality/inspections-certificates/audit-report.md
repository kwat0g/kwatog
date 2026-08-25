# M056 — Inspections & Certificates audit report

**Audit date:** 2026-08-25  
**Module:** quality/inspections-certificates  
**Tier:** 3  
**Surface:** L — inspection lifecycle, measurements, AQL, NCR hand-off, CoC generation  
**Roles:** system_admin, qc_inspector, production_manager  
**Decision:** 📋 Plan Ready

## Re-audit context and scope

M056 was claimed as the first unlocked Tier-3 `📋 Plan Ready` module after the registry refresh. The prior `audit-report.md`, `action-plan.md`, and `fix-log.md` were read in full. Quality backend, migration, and SPA files had changed after the prior report, so this session repeated discovery, hardening, and polish rather than executing stale findings.

The audit is limited to the inspection/certificate lifecycle and its Quality frontend. Dependency modules were read only to verify quantity, production, return, permission, and hand-off contracts. No production code or dependency module was modified by this session.

## Discovery

### Backend surface

- Quality inspection routes are behind `auth:sanctum` and the Quality feature flag. Read routes use `quality.inspections.view`; create, measurement, complete, and cancel routes use `quality.inspections.manage` (`api/app/Modules/Quality/routes.php:23-23,59-81`).
- `InspectionController` exposes options, list, detail, chain, creation, measurement recording, completion, cancellation, CoC, AQL preview, and outgoing-output lookup (`api/app/Modules/Quality/Controllers/InspectionController.php:53-109,123-243`).
- `InspectionService` owns inspection creation, AQL sampling, measurement scaffolding, measurement evaluation, completion, NCR/outbox hand-off, cancellation, and source-context loading (`api/app/Modules/Quality/Services/InspectionService.php:57-144,146-417,426-700`).
- The current enums advertise five stages and four entity types, including supplier/customer returns and delivery/return-request sources (`api/app/Modules/Quality/Enums/InspectionStage.php:16-32`; `api/app/Modules/Quality/Enums/InspectionEntityType.php:15-25`).
- Automatic incoming, in-process, and outgoing listeners use authoritative source-row locks and idempotency guards; Return Management calls the Quality service for return inspections (`api/app/Modules/Quality/Listeners/TriggerIncomingQC.php:49-117`; `api/app/Modules/Quality/Listeners/TriggerInProcessQC.php:53-118`; `api/app/Modules/Quality/Listeners/TriggerOutgoingQC.php:57-181`).
- CoC generation loads the product, inspector, measurements, and traceability context and emits a data-driven PDF only for eligible inspections (`api/app/Modules/Quality/Services/CoCService.php:37-72,78-143,146-163`).

### Persistence and conventions

- Inspection and measurement writes use repository HashID decoding at the request boundary, `DB::transaction()`, row locks, enum casts, and the explicit inspection state machine (`api/app/Modules/Quality/Requests/RecordMeasurementsRequest.php:28-75`; `api/app/Modules/Quality/Models/Inspection.php:30-56`; `api/app/Modules/Quality/Support/InspectionStateMachine.php:11-48`).
- The new integrity migration now protects stage/entity/parameter allow-lists, positive batch and sample quantities, sample-not-larger-than-batch, positive sample indices, and tolerance ordering (`api/database/migrations/2026_08_25_200000_add_inspection_integrity_checks.php:20-103`). Cross-row and source-record rules remain outside those checks.
- Inspection-spec revisions are persisted and existing rows are backfilled where the historical root spec can be reconstructed (`api/database/migrations/2026_08_25_170000_create_inspection_spec_revisions.php:40-92`). The resource explicitly reports an absent revision as `legacy_unknown` rather than silently displaying the current root version (`api/app/Modules/Quality/Resources/InspectionResource.php:95-110`).
- No money or centavo calculation is performed in this module. IDs are stored internally as integers and exposed through the repository HashID convention; no ULID-specific path applies here.

### Frontend surface

- Quality routes are guarded by the module and view/manage permissions (`spa/src/routes/qualityRoutes.tsx:31-54`).
- The list has filters, loading/error/empty states, status chips, and opaque semantic stat-card surfaces (`spa/src/pages/quality/inspections/index.tsx:32-58,178-218`).
- The create page now supports outgoing output-batch selection, quantity synchronization, AQL preview, notes, and all stages returned by the API (`spa/src/pages/quality/inspections/create.tsx:31-41,48-100,118-174`).
- The detail page supports tolerance-driven numeric input, manual pass/fail for non-tolerance parameters, row notes, save/complete/cancel, linked source records, quality-plan/spec revision display, and CoC download (`spa/src/pages/quality/inspections/detail.tsx:102-169,333-452,533-588`).
- SPA types now include all five stages/four entity types, evaluation mode, output/plan/revision fields, and measurement notes (`spa/src/types/quality.ts:42-142`).

## Controls confirmed

- Terminal operations and measurement writes lock the authoritative inspection and measurement rows inside transactions; completion records the NCR and durable outbox event in the same transaction (`api/app/Modules/Quality/Services/InspectionService.php:432-518,535-609`).
- The state machine has an explicit `TRANSITIONS` map and is invoked by record, complete, and cancel paths (`api/app/Modules/Quality/Support/InspectionStateMachine.php:15-47`; `api/app/Modules/Quality/Services/InspectionService.php:511-516,567-583,630-637`).
- Measurement requests reject malformed/foreign IDs and tolerance rows cannot accept an explicit verdict without a measured value or against the calculated result (`api/app/Modules/Quality/Requests/RecordMeasurementsRequest.php:33-58`; `api/app/Modules/Quality/Services/InspectionService.php:453-500`).
- `BusinessRuleException` is rendered as a structured 422, and CoC uses the typed `InspectionCertificateException` with stable identifiers (`api/bootstrap/app.php:85-105`; `api/app/Modules/Quality/Exceptions/InspectionCertificateException.php:9-21`).
- `production_manager` receives inspection view but not manage; `qc_inspector` receives the Quality module permissions, including inspection management (`api/database/seeders/RolePermissionSeeder.php:316-330,548-559,655-670`). The six statically unused seeded permissions include legacy inspection slugs, but current inspection routes use the view/manage pair consistently.
- Scaffold inserts are bounded to 500 rows per insert, avoiding retention of the whole sample matrix in PHP memory (`api/app/Modules/Quality/Services/InspectionService.php:643-671`). The remaining total-workload risk is recorded below.

## Findings

### IC-01 — Decimal received quantities are truncated or skipped by incoming QC

Classification: **Broken**  
Priority: P0 — inventory/QC hand-off integrity  
Scope: Quality trigger/service, quantity contract, and cross-module tests  
Session recommendation: separate-recommended

Inventory accepts and stores received quantities to three decimal places (`api/app/Modules/Inventory/Models/GrnItem.php:20-38`; `api/app/Modules/Inventory/Requests/StoreGrnRequest.php:45-55`). The Quality plan path casts that value through `float` to `int` (`api/app/Modules/Quality/Services/InspectionService.php:198-209`), and the automatic GRN listener repeats the cast and skips any result below one (`api/app/Modules/Quality/Listeners/TriggerIncomingQC.php:75-84`). A valid `0.500` line receives no inspection; a valid `1.750` line is recorded as batch quantity `1`. The inspection model and table also store batch/sample quantities as integers (`api/app/Modules/Quality/Models/Inspection.php:44-53`; `api/database/migrations/0089_create_inspections_table.php:35-40`), so the loss cannot be recovered downstream.

The unit-of-measure policy for fractional lots, full inspection, AQL sampling, and accepted quantities is not defined in this module. This must be decided with Inventory before changing the representation; silently truncating or skipping a valid received line is not safe.

### IC-02 — AQL Ac/Re defects are counted per failed parameter row, not per sampled unit

Classification: **Incomplete**  
Priority: P0 — acceptance-decision integrity  
Scope: Quality evaluation algorithm, product rule, and AQL tests  
Session recommendation: separate-recommended

Creation produces one measurement row for every `(sample_index × spec_item)` pair (`api/app/Modules/Quality/Services/InspectionService.php:371-395`). Completion then counts every row with `is_pass=false` and compares that row count with the AQL acceptance count (`api/app/Modules/Quality/Services/InspectionService.php:560-565`). The AQL service defines Ac/Re for a sample plan (`api/app/Modules/Quality/Services/AqlSampleSizeService.php:20-52`). If one sampled unit fails two parameters, the current result records two defects. The implementation is deterministic, but the product contract does not state whether a defect means a failed unit or a failed parameter; either interpretation changes pass/fail and NCR behavior.

Confirm the definition, then persist or calculate the aggregate at the same level as Ac/Re. The current focused test suite could not execute against the unavailable test database, so the boundary cases are not independently verified in this session.

### IC-03 — The outgoing no-spec fallback creates an uncompletable inspection and masks typed failures

Classification: **Broken**  
Priority: P1 — production-to-QC hand-off can be permanently stuck  
Scope: outgoing listener, recovery workflow, and cross-module tests  
Session recommendation: separate-recommended

The listener catches every `BusinessRuleException` from `InspectionService::create()` and labels it “no active spec” (`api/app/Modules/Quality/Listeners/TriggerOutgoingQC.php:122-148`). It then inserts only a root `Inspection` row with no spec revision and no measurement rows (`api/app/Modules/Quality/Listeners/TriggerOutgoingQC.php:150-168`). Completion rejects an inspection with no measurement rows (`api/app/Modules/Quality/Services/InspectionService.php:546-553`), and the measurement endpoint can update only IDs that already belong to the inspection (`api/app/Modules/Quality/Services/InspectionService.php:443-468`); there is no route to seed the missing rows. The same output guard treats that root row as already handled on replay (`api/app/Modules/Quality/Listeners/TriggerOutgoingQC.php:108-120`), while the listener records `outgoing_inspection_created` (`api/app/Modules/Quality/Listeners/TriggerOutgoingQC.php:212-215`).

This masks causes such as a spec with no parameters or no immutable revision, and it can leave a draft that cannot be completed or repaired by replay. If the fallback is intentional, it needs an explicit manual-required state and a reseed/repair path; otherwise only a narrowly identified “no active spec” condition should enter it.

### IC-04 — Stage/entity provenance is advertised broadly but not closed by validation or the manual form

Classification: **Incomplete**  
Priority: P1 — traceability and downstream gating  
Scope: create request/service, entity policy, SPA form, and contract tests  
Session recommendation: separate-recommended

The options endpoint advertises all five stages and four entity types (`api/app/Modules/Quality/Controllers/InspectionController.php:58-70`). `CreateInspectionRequest` decodes `grn` and `work_order` hashes only; delivery and return-request hashes are left untouched (`api/app/Modules/Quality/Requests/CreateInspectionRequest.php:38-65`). Its rules validate enum/integer shape and conditional presence, but not source existence, product/line ownership, or a valid stage/entity combination (`api/app/Modules/Quality/Requests/CreateInspectionRequest.php:89-99`). The service has a strong output/work-order check for outgoing inspections (`api/app/Modules/Quality/Services/InspectionService.php:288-312`), but non-outgoing values are persisted without equivalent source validation (`api/app/Modules/Quality/Services/InspectionService.php:349-358`).

The SPA displays all returned stages but submits no entity type or entity ID controls; its manual payload contains only stage, product, batch quantity, outgoing output, and notes (`spa/src/pages/quality/inspections/create.tsx:118-136,155-174`). A caller can therefore create a standalone or mismatched incoming/in-process/return inspection, while a caller using a delivery/return hash does not receive the advertised decoding contract. Define the valid stage/entity matrix and whether standalone inspections are intentional, then enforce it consistently in the API and UI.

### IC-05 — Persistence and terminal validation still leave cross-row evidence invariants open

Classification: **Missing**  
Priority: P1 — defense-in-depth for quality evidence  
Scope: migration checks, terminal service validation, and integrity tests  
Session recommendation: separate-recommended

The current integrity migration correctly checks allowed values, positive quantities, `sample_size <= batch_quantity`, positive sample indices, and tolerance ordering (`api/database/migrations/2026_08_25_200000_add_inspection_integrity_checks.php:46-103`). It does not constrain a measurement `sample_index` to its parent inspection's `sample_size`, `accepted_quantity` to `batch_quantity`, or the stored `defect_count` to the measurement rows. It also cannot protect source existence/product ownership or the numeric-evidence rule with a portable row-local check. Completion checks only that every row has a non-null `is_pass` before counting defects (`api/app/Modules/Quality/Services/InspectionService.php:546-565`); the final path does not re-evaluate each tolerance row against its stored reading.

The normal measurement endpoint enforces the important rules, but a direct model/import/migration write can leave a row that the terminal path trusts. Add service-level final recomputation and the database checks that are safe for the supported drivers; keep source and cross-row policy in a single domain validator.

### IC-06 — Full-sample inspection accepts a workload large enough to stall one transaction

Classification: **Hardening**  
Priority: P1 — availability and operational limits  
Scope: request limits, sampling policy, row generation, and load tests  
Session recommendation: separate-recommended

The create request accepts a batch quantity up to `1,000,000` (`api/app/Modules/Quality/Requests/CreateInspectionRequest.php:89-99`). For incoming and in-process product inspections, the default sample is the full batch (`api/app/Modules/Quality/Services/InspectionService.php:330-344`), and the service creates one row per sample and parameter (`api/app/Modules/Quality/Services/InspectionService.php:371-395`). Inserts are chunked to 500 rows, which bounds PHP memory but does not bound total rows, transaction duration, lock time, or database volume (`api/app/Modules/Quality/Services/InspectionService.php:643-671`). A million-unit lot with several parameters can still create millions of rows synchronously.

Set a domain-approved operational ceiling, or move large/full inspections to a bounded asynchronous workflow with explicit progress and retry semantics. The current code has no measured capacity gate or load-test evidence.

### IC-07 — The detail page can complete against stale server measurements while local edits are unsaved

Classification: **Broken**  
Priority: P1 — operator edits can be lost at finalization  
Scope: SPA detail workflow and browser tests  
Session recommendation: same-session-ok

The page keeps edits in local `drafts` and sends them only from the separate Save mutation (`spa/src/pages/quality/inspections/detail.tsx:102-145`). The Complete button is disabled only when the server payload has unresolved rows; it does not check `dirtyCount` (`spa/src/pages/quality/inspections/detail.tsx:193-241`). If all server rows are already resolved, an operator can change a measurement, verdict, or note and click Complete before Save. The API then computes the final result from persisted rows (`api/app/Modules/Quality/Services/InspectionService.php:546-583`), so the local edit is discarded and the inspection is finalized on older evidence. After Save, the success handler invalidates the query but does not clear the dirty drafts (`spa/src/pages/quality/inspections/detail.tsx:122-141`), which also leaves the UI state misleading.

Disable completion while dirty or make completion await a successful save, then reset draft state from the saved response. Add browser coverage for numeric, manual, and notes-only edits.

### IC-08 — The default QC queue hides newly created draft inspections

Classification: **Incomplete**  
Priority: P2 — role workflow visibility  
Scope: SPA queue defaults and product decision  
Session recommendation: same-session-ok

New product inspections are created in `draft` status (`api/app/Modules/Quality/Services/InspectionService.php:349-368`), and the outgoing fallback is also stored as `draft` (`api/app/Modules/Quality/Listeners/TriggerOutgoingQC.php:151-166`). The list page defaults its query to `status: 'in_progress'` (`spa/src/pages/quality/inspections/index.tsx:32-34`). A QC inspector entering the default queue therefore does not see newly staged draft work unless they know to change the filter. Confirm whether the intended inbox is draft plus in-progress, or whether another notification/queue owns draft work, and make that path explicit.

### IC-09 — Manual selectors stop at the first page of products and output batches

Classification: **Incomplete**  
Priority: P2 — manual inspection reachability  
Scope: Quality create page and lookup endpoints  
Session recommendation: separate-recommended

The create page loads active products once with `per_page: 200` and has no search or pagination (`spa/src/pages/quality/inspections/create.tsx:97-100`). CRM caps that endpoint at 100 records per page (`api/app/Modules/CRM/Services/ProductService.php:59-60`). Outgoing output lookup likewise returns only the latest 100 positive batches (`api/app/Modules/Quality/Controllers/InspectionController.php:90-97`), and the form renders that one response without paging (`spa/src/pages/quality/inspections/create.tsx:155-171`). Once a catalog or product's output history exceeds those bounds, an authorized operator cannot select an older record from the primary manual workflow.

Use searchable/paginated selectors or document a deliberate operational maximum. The fix crosses the Quality form and lookup contract, so it should be verified with a larger fixture set.

### IC-10 — Numeric measurement inputs are not uniquely labelled per row

Classification: **Polish**  
Priority: P3 — accessibility and operator clarity  
Scope: SPA detail table  
Session recommendation: same-session-ok

Every numeric measurement input uses the same `aria-label="Measured value"` (`spa/src/pages/quality/inspections/detail.tsx:386-400`), even though the surrounding row contains the parameter name and sample number (`spa/src/pages/quality/inspections/detail.tsx:333-379`). A screen-reader user navigating multiple rows cannot identify which parameter the focused input edits. Include the parameter and sample in the accessible label while preserving the existing design-system layout.

### IC-11 — Inspection lifecycle has no browser-level regression suite

Classification: **Missing**  
Priority: P2 — verification evidence gap  
Scope: SPA inspection pages and backend contract coverage  
Session recommendation: separate-recommended

The SPA inspection directory contains the three production pages but no page test; the only adjacent Quality page test is `spa/src/pages/quality/inspection-specs/editor.test.tsx`. Static token/RBAC checks cannot exercise the create, measurement, completion, role, and unsaved-draft paths. The focused backend command in this session also could not reach the test database, so the current run provides no executable regression evidence for this module.

Add browser/API coverage for the critical findings and restore a runnable test database in the verification environment before marking the module verified.

## Questions requiring a product or cross-module decision

- Are fractional received quantities valid inspection units, and how should their AQL/full-sample and accepted quantities be represented?
- Does Ac/Re count failed sampled units or failed parameter rows?
- Is the outgoing no-spec fallback a manual-required placeholder, or should the hand-off fail until a scaffoldable spec exists?
- What stage/entity combinations are valid, and are standalone inspections allowed?
- Should the default QC queue include `draft` inspections?
- Are the first-100 product/output selector bounds an intentional operational limit?

## Evidence gaps

- The focused backend command was attempted with the current Quality/return test set, but all 50 tests stopped before assertions because PostgreSQL host `db` could not be resolved for `ogami_test` (`SQLSTATE[08006]`).
- No runnable test evidence exists yet for fractional GRN quantities, AQL aggregation boundaries, fallback recovery, source/stage validation, cross-row integrity, million-unit/full-sample load, or finalization with dirty SPA edits.
- No browser test covers the inspection list, create, detail, role guards, output selector, manual functional parameters, or notes.
- `php -l` passed for the Quality PHP sources and relevant migrations; `npm run audit:tokens` passed with 776 files checked; `npm run audit:rbac` passed with 0 referenced-but-unseeded permissions (catalog 248, references 242). `npm run typecheck` remains blocked by unrelated errors in `src/pages/assets/detail.tsx` and `src/pages/return-management/detail.tsx`; no M056 path appeared in that output.

## Release recommendation

Keep M056 at **📋 Plan Ready**. The fresh audit has two P0 contract/integrity findings, several P1 hand-off and hardening findings, and a P1 SPA finalization flaw. Most work requires product decisions, cross-module quantity/provenance policy, persistence changes, or larger verification. No production fixes were applied in this session; the ordered remediation plan is in `action-plan.md`.
