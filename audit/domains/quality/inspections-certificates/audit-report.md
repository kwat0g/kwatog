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

---

# Re-audit — 2026-08-30 (measured against real PostgreSQL rows)

**Module:** quality/inspections-certificates (M056, Tier 3)
**Claim:** CLAIMED (coordinator had released the lock cleanly)
**Decision:** 🔁 Needs Re-audit → **✅ Verified (P0 closed) with gated remainder** — see release note.

## What the prior sessions had actually left

Four prior sessions, **zero production changes ever applied**. The 2026-08-26
report and action plan are thorough and their static citations still resolve,
but the report's own Evidence-gaps section records the decisive fact: *"all 50
tests stopped before assertions because PostgreSQL host `db` could not be
resolved for `ogami_test` (`SQLSTATE[08006]`)"*. **No finding in IC-01…IC-11 had
ever been executed.** The 2026-08-30 session then died on an API quota error
mid-discovery, leaving only an untracked scratch probe.

Root cause of the 08006, found and fixed this session: **every container in the
compose project was stopped** (`ogami-db` had exited 39 minutes before this
session started). Started `db` + `redis` only — no `down`, no volume change.

The aborted session's one second-hand lead — *"no scheduled command runs
inspections directly"* — is **confirmed correct**. See G-series below.

**Prior-finding status: 11 open findings, 9 still reproduce, 2 partially
superseded by measurement.** Details inline. The scratch probe was promoted:
its CoC and evidence cases became
`api/tests/Feature/Quality/CoCEvidenceIntegrityTest.php`; the rest was deleted.

## Broken

### IC-12 — A Certificate of Conformance could be issued without, beyond, or against its evidence  ✅ FIXED

Classification: **Broken** · Priority **P0** · IATF 16949 external artefact

`CoCService::assertEligible()` checked exactly two things — stage is Outgoing and
`status === Passed` (`api/app/Modules/Quality/Services/CoCService.php:158-175`
at HEAD). `status` is a snapshot the state machine writes at completion; the
measurement rows the certificate *prints* are reachable outside that machine,
and `Inspection::$fillable` still contains `'status'`
(`api/app/Modules/Quality/Models/Inspection.php:49`), so a row can assert a
verdict it never earned. Measured — each of these **issued a certificate**:

| probe | measured result at HEAD |
|---|---|
| passed outgoing inspection, **0** measurement rows | `ISSUED` |
| 500-unit lot, 45 of 50 sampled units `is_pass = NULL` | `ISSUED COC-P-0e30df1` |
| all 10 readings rewritten to failing values after issue | `ISSUED`, same number, different bytes |
| **all evidence rows deleted** after issue | `ISSUED`, same number `COC-202608-0001`, critical-dimension table now empty |

The last row is the worst: the certificate silently degrades to a bare
conformance claim, under a number a customer already holds.

Fixed by `assertEvidenceSupportsCertificate()`
(`api/app/Modules/Quality/Services/CoCService.php:191-241`), which re-reads the
rows — deliberately not `defect_count`, which is also a completion snapshot —
and refuses with a typed code: `COC_NO_MEASUREMENT_EVIDENCE`,
`COC_EVIDENCE_INCOMPLETE`, `COC_EVIDENCE_CONTRADICTS_VERDICT`,
`COC_EVIDENCE_SHORT_OF_SAMPLE`.

### IC-13 — One certificate number, several vault documents with different checksums  ✅ FIXED

Classification: **Broken** · Priority **P1**

`cocNumber()` is derived from the inspection number
(`CoCService.php:178-181`), so re-issuing is the **same** certificate. But
`generateForInspection()` re-rendered and called `vault->store()` every time.
Measured: two calls → *"documents rows after 2 vault issues: 2
distinct_checksums=2"*. The bytes differ because the payload embeds
`issued_at` (`CoCService.php:127`) and the requesting user
(`CoCService.php:125`), so nothing distinguishes the copy that shipped. Fixed by
streaming the certificate already on file. That also removes a **persistent
write from a GET endpoint** — measured at HEAD: a view-only role fetching
`/coc` wrote a `documents` row.

### IC-14 — `measured_value` silently rounded the operator's reading, and overflowed into 500s  ✅ FIXED

Classification: **Broken** · Priority **P2**

`['nullable', 'numeric']` against a `decimal(12,4)` column
(`api/app/Modules/Quality/Requests/RecordMeasurementsRequest.php:56` at HEAD;
column at `api/database/migrations/0090_create_inspection_measurements_table.php:41`).
Measured via HTTP:

| input | at HEAD | after fix |
|---|---|---|
| `10.00005` | **HTTP 200, stored `10.0001`, evaluated PASS** | HTTP 422 |
| `1e3` | HTTP 200, stored `1000.0000` | HTTP 422 |
| `1e20` | **HTTP 500** | HTTP 422 |
| `-1e20` | **HTTP 500** | HTTP 422 |
| `99999999999999` | **HTTP 500** | HTTP 422 |

The first row is the IATF problem: the stored evidence was not the reading the
inspector entered. Now `decimal:0,4` + `between:-99999999.9999,99999999.9999`.

### IC-07 (prior) — Detail page could complete against stale server evidence  ✅ FIXED — **reproduces**

Confirmed exactly as the prior report described.
`disabled={unresolvedCount > 0}` omitted `dirtyCount`, and `save.onSuccess`
never cleared the dirty flags while the seeding `useEffect` deliberately
*preserves* dirty rows — so a saved row stayed dirty forever, showing stale
local values. Both fixed in
`spa/src/pages/quality/inspections/detail.tsx` (Complete now also gated on
`dirtyCount > 0` with an explanatory `title`; drafts cleared on save success).

## Missing

### IC-15 — A completed quality record is fully mutable, with no observer and no database trigger

Classification: **Missing** · Priority **P1** · Scope large · **separate-recommended**

Measured:

- `Inspection` does **not** use `SoftDeletes`.
- Hard-deleting a **passed** inspection via the model **SUCCEEDED**; rows gone, and all 10 measurement rows cascaded away.
- `select … from information_schema.triggers where event_object_table in ('inspections','inspection_measurements')` → **NONE**.
- A direct `UPDATE` of a passed inspection's measurements changed **10 rows** with nothing objecting.
- After that rewrite the root row still read `status=passed defect_count=0 accepted_quantity=10` while **10 rows were failing**. Nothing reconciles.

Compare `journal-ledger`, which enforces this with an observer **plus** a
PostgreSQL `P0001` trigger across update / re-date / soft-delete /
force-delete / line-mutation / status-flip. Quality evidence is IATF-retained
records and has none of it. Containment: there is no `DELETE
/quality/inspections/{id}` route and no measurement-delete route, so the
reachable surface is console/import/SQL — which is exactly the surface the
`journal-ledger` trigger exists to cover.

IC-12's guard now stops that state producing a *certificate*, but it does not
stop the record itself being falsified.

### IC-16 — No gate stops a work order advancing past a failed in-process inspection

Classification: **Missing** · Priority **P1** · **cross-module (Production) — reported, not touched**

Of the three touchpoints that gate other chains, two hold and one does not:

| gate | measured |
|---|---|
| Incoming QC → GRN | **HOLDS.** `GrnService::accept()` on a `pending_qc` GRN with a draft inspection → `BusinessRuleException` *"GRN GRN-202608-9225 cannot be accepted until every incoming inspection passes (current: draft)"*. After a **failed** inspection → *"Only pending_qc GRNs can be accepted."* |
| Outgoing QC → delivery | Gate present in code (`api/app/Modules/SupplyChain/Services/DeliveryService.php:396,431`) and covered by `CreateDeliveryDraftOnQcPassTest` (5/5 pass). My direct probe did **not** reach it — it was refused earlier by *"Each delivery item must reference a sales-order line"* — so I record this as **verified by existing test, not by my own probe.** |
| In-process QC → work order | **NO GATE EXISTS.** `grep -rn "InProcess" api/app/Modules/Production` returns **nothing**, and Production's only reference to the inspection domain at all is a read-only relation, `api/app/Modules/Production/Models/WorkOrder.php:120`. Nothing in Production consults inspection status, so a failed in-process check cannot block anything. |

CLAUDE.md states in-process QC samples "between operations". There is no
mechanism by which that sampling constrains the run. Fix belongs to Production.

### IC-17 — `createIncomingForItem()` claims an AQL sample it never took

Classification: **Missing** · Priority **P2** · in-module · **question for a human**

Measured: `createIncomingForItem(batch=100)` produced
`sample_size=32  aql_code=G  measurement_rows=1`
(`api/app/Modules/Quality/Services/InspectionService.php:160-189`). The row
records that a 32-unit AQL code-G sample was drawn; the evidence is a single
`'Overall incoming material verdict'` visual row. Resolve that one row and the
inspection completes as passed with `defect_count <= accept_count`.

This is a record-integrity mismatch, but whether the fix is "stop writing an
AQL sample_size on the lightweight path" or "scaffold the sample" is a quality-
plan decision, so it is **not** mine to make. Note this path is Incoming, so
IC-12 does not expose it to a certificate.

## Incomplete

### IC-02 (prior) — Ac/Re counts failed parameter rows, not failed sampled units — **reproduces, and now characterised**

The prior report flagged this but could not execute it. Measured, and the
picture is better than the report implies: **the AQL implementation itself is
correct.**

The plan is **derived from settings, not hardcoded**
(`api/app/Modules/Quality/Services/AqlSampleSizeService.php:29-52`), and it
throws rather than falling back if the setting is missing. The derived table
matches ISO 2859-1 / ANSI-ASQ Z1.4 AQL 0.65 General Level II exactly, including
the arrow rule for codes A–F and the `min(n, lot)` 100%-inspection clamp:

```
lot=150 → code=G n=32  Ac=0 Re=1      lot=1201  → code=K n=125 Ac=2  Re=3
lot=280 → code=G n=32  Ac=0 Re=1      lot=10000 → code=L n=200 Ac=3  Re=4
lot=281 → code=H n=50  Ac=1 Re=2      lot=35000 → code=M n=315 Ac=5  Re=6
lot=500 → code=H n=50  Ac=1 Re=2      lot=150000→ code=N n=500 Ac=7  Re=8
lot=501 → code=J n=80  Ac=1 Re=2      lot=500001→ code=Q n=1250 Ac=14 Re=15
lot=8   → code=G n=8   Ac=0 Re=1      (clamped: 100% inspection)
lot=10  → code=G n=10  Ac=0 Re=1      (lot smaller than sample → clamped)
```

**Acceptance boundary is correct.** The naive probe looked wrong — lot 500,
Ac=1, exactly 1 defect → `failed` — but isolating the variables showed why:
the spec parameter was `is_critical`, and any critical failure rejects before
Ac is consulted (`InspectionService.php:562-566`). Re-run with a non-critical
parameter:

```
lot=500 n=50 Ac=1  0 defects → passed  accepted_quantity=500
lot=500 n=50 Ac=1  1 defect  → passed  accepted_quantity=500   ← Ac boundary accepts
lot=500 n=50 Ac=1  2 defects → failed  accepted_quantity=0     ← Re boundary rejects
lot=500 n=50 Ac=1  1 CRITICAL defect → failed                  ← critical rule overrides
```

**No off-by-one.** What remains genuinely open is only the prior report's
question: `defects` counts rows where `is_pass = false`
(`InspectionService.php:563`), and creation makes one row per
`(sample_index × spec_item)` (`:375-398`), so one unit failing two parameters
counts as two defects against an Ac defined per *sampled unit*. With a
single-parameter spec the two definitions coincide, which is why the boundary
above looks clean. **This is an IATF-auditable definition, so it is not mine to
change — it is a question for a human.**

### IC-18 — Tolerance evaluation is float, not decimal — but I could not make it produce a wrong verdict

Classification: **Incomplete** · Priority **P2** · **honest negative result**

`InspectionMeasurement::evaluate()` casts to `(float)` on all three sides
(`api/app/Modules/Quality/Models/InspectionMeasurement.php:67-70`), against
CLAUDE.md's explicit rule. And `InspectionMeasurementResource` emits decimals
as JSON **floats** (`:25,28`), which is why `RecordMeasurementsData.measured_value`
is typed `number` (`spa/src/types/quality.ts:152`).

I tried to produce a wrong verdict and **failed**. All boundary cases evaluate
correctly:

```
exactly upper limit 10.1000  → is_pass=true    one increment above 10.1001 → is_pass=false
exactly lower limit  9.9000  → is_pass=true    one increment below  9.8999 → is_pass=false
tol_max=99999999.0001 measured=99999999.0002   → is_pass=false  (correct)
```

That is expected: at `decimal(12,4)` the largest value is `99999999.9999`, and
double spacing near 1e8 is ~1.5e-8, far below the 1e-4 column granularity. So
this is **latent, not live** — a correctness landmine that fires only if the
column precision ever widens. Recorded as such rather than as a live defect.

I deliberately did **not** half-migrate it: I started changing the SPA to send
the reading as a string, then reverted, because the resource returns a float —
switching one direction only would leave the contract inconsistent. An
`AUDIT NOTE` marks the spot in `detail.tsx`.

### IC-19 — Non-numeric on a Dimensional parameter throws `MathException` in the service

Classification: **Incomplete** · Priority **P3**

Via HTTP the request validator catches it (`422` measured). Via the service —
the path an importer, listener or console task uses — measured:
`Illuminate\Support\Exceptions\MathException — Unable to cast value to a
decimal`, i.e. a 500-class error, not a `BusinessRuleException`. Note the
`decimal:4` cast is what saves this: `evaluate()` itself would have returned
`true` for a non-numeric value on any window spanning zero, since `(float)'abc'`
is `0.0`. Contained today; brittle.

### IC-03 (prior) — Outgoing no-spec fallback — **reproduces exactly as described**

Measured end to end:

```
fallback inspection QC-202608-0001 status=draft spec_revision=NULL measurement_rows=0
complete()  → REFUSED "Cannot complete: inspection has no measurement rows."
seed a row  → REFUSED "The measurement payload contains rows that do not belong to this inspection."
```

Confirmed uncompletable and unrepairable, exactly as
`api/app/Modules/Quality/Listeners/TriggerOutgoingQC.php:122-168` predicted.
Prior finding stands unchanged; still `separate-recommended` (needs a
Production-chain recovery contract).

### IC-01 (prior) — Decimal received quantity truncated — **reproduces**

`(int) (float) $line->quantity_received` at
`api/app/Modules/Quality/Services/InspectionService.php:207` and the matching
skip in `TriggerIncomingQC.php:75-84`. Unchanged; needs the Inventory
unit-of-measure decision the prior report identified. Not touched.

### IC-04, IC-06, IC-09 (prior) — **static citations still resolve; not independently re-measured**

Stage/entity provenance, the 1,000,000 batch ceiling, and the first-page
selector bounds. All three are `separate-recommended` and decision-dependent; I
spent the budget on the certificate and evidence invariants instead. Recorded
honestly as **not re-measured this session**.

## Polish

- **IC-08 (prior) reproduces.** The queue still defaults to `status: 'in_progress'` (`spa/src/pages/quality/inspections/index.tsx:32-34`) while new work is created `draft`. Product decision.
- **IC-10 (prior) ✅ FIXED.** Every numeric input shared `aria-label="Measured value"`. Now `Measured value — {parameter}, sample {n} ({unit})`.
- **Dead permissions.** `quality.inspections.create` and `quality.inspections.edit` are seeded (`api/database/seeders/RolePermissionSeeder.php:328-329`) but no route uses them; routes use the `view`/`manage` pair throughout.
- **Empty 404 body.** With `app.debug=false` a bad hash returns `404 {"message": ""}` — no raw-id leak (good) but no message either.
- **No dead surfaces otherwise.** Every inspection route has an SPA caller in `spa/src/api/quality/inspections.ts:27-56`, and every SPA page has a route. Checked both directions.

## Cross-module / shared-service, reported not fixed

- `HashIdFilter::decode` accepts **raw integers in every environment**: `GET /quality/inspections?product_id=999999` → `200`. Documented correction #4; `Common` scope.
- `DocumentSequenceService::generate()` create-race. `inspection` is configured as a *setting* (`api/database/migrations/0360_seed_document_sequence_config.php:17`), not a pre-created `document_sequences` row, so the first caller of each month takes the known racy path (`DocumentSequenceService.php:66-84`). `Common` scope. **My two-connection probe deadlocked** (connection B blocks on A's uncommitted unique insert) and was abandoned rather than reported as a result — see the invariant table.
- `CocAutoAttachOnConfirmTest` (SupplyChain) fixture mass-assigns a `passed` outgoing inspection with zero measurement rows. Needs a realistic fixture from its owner. Not touched.

## Scheduled commands — "nothing to do" vs "everything threw"

The aborted session's lead is confirmed: **no scheduled command runs
inspections directly.** The three that touch inspection data, executed with a
failed inspection, a pending inspection and a live outbox row present:

| command | exit | output | distinguishes? |
|---|---|---|---|
| `operations:rollout-health` | **1** | "No active system administrator is available for permission-aware health metrics." | **Yes** — fails loudly rather than reporting zeros |
| `chain:check-bottlenecks` | 0 | "Chain bottleneck scan completed in 72ms — raised 0 new alerts." | Yes — reports elapsed time, so it demonstrably ran |
| `outbox:dispatch` | 0 | "Enqueued 0 outbox messages." | **Yes, but only after investigation** |

`outbox:dispatch` reporting 0 while an `InspectionFailed` outbox row existed
looked exactly like the dead 8D escalation ledger. It is **not**:
`OutboxService::record()` already enqueues via `DB::afterCommit`
(`api/app/Common/Services/OutboxService.php:66-76`), so under the test queue
driver the message is consumed before the command looks. The command also warns
separately on failed messages and failed/retrying listeners
(`DispatchOutboxCommand.php:56-75`), so a mass failure is not silent. No dead
subsystem here.

## NCR feedback loop — verified live

```
inspection status=failed defect_count=1
NCR created: YES NCR-202608-0001 source=inspection_fail severity=critical
outbox InspectionFailed rows: 1
Pareto total_defects=1 rows=["Shaft OD"]
Pareto drill-down row-mapping branch returned 1 row
```

All links fire, inside the completion transaction
(`InspectionService.php:592-609`) — deliberately not an `afterCommit` callback,
per the comment there. The **replacement/rework work order is NOT created on
inspection failure**; it is created when the NCR is *closed* with a `scrap` or
`rework` disposition (`api/app/Modules/Quality/Services/NcrService.php:319-357`),
and `createRequiredWorkOrder()` logs *and rethrows* rather than swallowing
(`:404-420`). That is the correct shape, and it is NCR/CAPA-module territory.
Note the M059 handoff applies in reverse here: the Pareto drill-down
row-mapping branch **did** return a row, so it executed.

## Permissions

`none` → **403 on all 11 endpoints**, including `options`, `index`,
`aql-preview` and `work-order-outputs`. View-only → `200` on the six reads,
`403` on all four mutations. No missing gate, and no gate so tight that a
registry role cannot work: `qc_inspector` receives `module('quality')` in full
(`RolePermissionSeeder.php:682-698`), `production_manager` receives
`quality.view, quality.inspections.view, quality.ncr.view` (`:577`), and
`system_admin` everything.

**One question, not a defect.** The CoC route is gated on
`quality.inspections.view` (`api/app/Modules/Quality/routes.php:86-87`), so
`production_manager` — view-only, and the role producing the goods — can issue
the certificate for its own output. Measured: HTTP **200**. Whether issuing an
external conformance certificate is a read is a separation-of-duties decision
for a human, so I did not change the gate. IC-13's fix does remove the write
side effect that made it worse.

## Evidence and limits of this session

- Own database `ogami_test_qc2`, created and dropped. Never `ogami_test`.
- Pre-existing lifecycle baseline **before** any change: **56 passed / 0 failed / 130 assertions** — a real run with real assertions, not the zero-assertion signature.
- **Not measured:** IC-04, IC-06, IC-09 (static citations only); the outgoing-delivery gate by my own probe (existing test relied on); sequence uniqueness under true concurrency (probe deadlocked); browser-level coverage (IC-11 stands).
- **N/A:** file attachments. `grep -rn "UploadedFile|->file(|Storage::" api/app/Modules/Quality` returns nothing — inspections carry no uploads, so there is no MIME/filename/web-root surface to audit.
