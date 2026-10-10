# Inspection specifications and PASS/FAIL inspection audit

Date: 2026-10-06. Scope: inspection specifications, incoming item/supplier plans, all five inspection stages, review, certificates, stock release, production closure and return disposition. This is an audit and simplification proposal. No application behavior or business records were changed.

## Main conclusion

The current workflow is only partly simplified. Checklist decisions exist, but specification authoring still requires numeric limits for dimensional checks. Critical toleranced checks still require numeric readings at submission and review. Certificates independently require measurement evidence. Returning goods uses a different creation path that still generates a piece-by-check matrix.

Recommended direction: one PASS/FAIL decision for each check across the inspected batch/sample, with a clear written acceptance criterion. FAIL reveals the problem description. Keep batch quantities, sample coverage and confirmed defective quantities because stock release and rework depend on them. These quantities are operational evidence, not nominal/minimum/maximum measurements.

PASS/FAIL is an attribute decision: the inspector confirms a defined check, such as “fits the approved gauge” or “matches the approved color sample.” It cannot by itself establish a recorded diameter or calculate Cp/Cpk. A customer drawing or approved test method may still be the governing reference without numeric entry in the application.

## Evidence

| Check | Result | Limits |
|---|---|---|
| Existing QC backend suites | [47 tests, 296 assertions passed](evidence/quality-pass-fail-audit-20261006/phpunit.txt) | InspectionQuickResultTest, InspectionSpecBoundaryTest, InspectionMeasurementContractTest and InspectionMakerCheckerPolicyTest. These verify the current mixed contract, including numeric requirements; they do not prove a PASS/FAIL-only redesign is implemented. |
| Existing warehouse QC gates | [13 tests, 25 assertions passed](evidence/quality-pass-fail-audit-20261006/grn-gates.txt) | Includes a test that accepts a receipt after its incoming inspection is canceled. Passing establishes the current release behavior, not its safety. |
| Existing frontend suites | [24 tests passed](evidence/quality-pass-fail-audit-20261006/vitest.txt) | Specification editor, inspection detail and checklist panel. |
| Headless Chromium, QC and Admin accounts | [20 visits, no page exceptions or failing page-data responses](evidence/quality-pass-fail-audit-20261006/browser-results.json) | Desktop 1440px and mobile 390px. Authentication sessions only; business requests were GET. Existing incoming, in-process and return drafts were inspected; the selected outgoing record was already failed, so its numeric-entry behavior was established by source/tests rather than editable browser capture. |
| Mechanical UI detector | [No deterministic findings](evidence/quality-pass-fail-audit-20261006/detector.json) | Manual and browser checks still found overflow and short touch targets. |
| Palette contrast | [93 tests passed](evidence/quality-pass-fail-audit-20261006/palette.txt) | Defined token combinations; no complete WCAG certification or full dark-mode interaction audit. |

Backend verification used the dedicated `phpunit.pricing.20261004.xml` configuration and `ogami_test_pricing_20261004`. Browser verification used the isolated audit application at `http://localhost:18080`, not the public deployment. The [browser script](evidence/quality-pass-fail-audit-20261006/browser-audit.cjs) is repeatable and submits no business mutations.

## Findings and risks

| ID / severity | Finding and evidence | Correction for the simple workflow |
|---|---|---|
| Q1 / P1, current functional mismatch | The [specification editor](../../spa/src/pages/quality/inspection-specs/editor.tsx:42) and [request](../../api/app/Modules/Quality/Requests/UpsertInspectionSpecRequest.php:81) require nominal/minimum/maximum for dimensional checks. The [checklist](../../spa/src/pages/quality/inspections/components/InspectionChecklistPanel.tsx:72) still creates numeric entry for critical toleranced rows. [Submission](../../api/app/Modules/Quality/Services/InspectionService.php:1144) and [review](../../api/app/Modules/Quality/Services/InspectionService.php:1175) enforce those readings. | Change the specification, capture and server contracts together. Merely hiding controls leaves blocked completion. Keep the classification of dimensional/visual/functional checks separate from how the result is captured; labeling every check Visual would misdescribe real dimensional or functional tests. |
| Q2 / P1, downstream contract mismatch | [Certificate evidence](../../api/app/Modules/Quality/Services/CoCService.php:286) treats unresolved toleranced rows and missing critical readings as incomplete. The [PDF](../../api/resources/views/pdf/coc.blade.php:92) prints nominal/tolerance/actual range. [SPC](../../api/app/Modules/Quality/Services/SpcService.php:135) requires numerical readings and bilateral limits. | New checklist-based certificates must show the actual check, acceptance reference, decision, governing revision and inspector/checker. Preserve numeric tables only for historical measurement-based inspections. Remove SPC from the simple QC record/form; keep historical numerical capability separately and do not calculate Cp/Cpk from PASS/FAIL. |
| Q3 / P2, stage inconsistency | [Creation](../../api/app/Modules/Quality/Services/InspectionService.php:475) selects lot-checklist mode only for incoming, in-process and outgoing. New product-linked customer/supplier returns still generate `sample_size × parameters` rows. Existing browser return drafts happen to be lot-checklist fixtures, which does not change this creation path. | Use one checklist capture shape for all five stages. Sampling/full-coverage policy remains stage-specific. A 100-piece return with five checks needs five decisions plus coverage/defect evidence, not 500 rows. |
| Q4 / P1, canceled incoming QC can release stock | [Warehouse gate](../../api/app/Modules/Inventory/Services/GrnService.php:1452) accepts canceled inspections as a completed logistics decision. [Existing regression](../../api/tests/Feature/Inventory/GrnQcGateTest.php:200) cancels the only inspection and then accepts the GRN. Generic QC cancellation therefore shares the same release treatment as logistics handling. | Canceling a QC inspection must not prove conformance. QC-eligible accepted quantities need replacement completed/checked QC or explicit reviewed disposition. A logistics rejection can end inspection work for rejected quantities, but must not authorize accepting those quantities. Preserve legitimate canceled historical siblings when a valid replacement covers the line. |
| Q5 / P2, inconsistent meaning of FAIL | [Batch submission](../../api/app/Modules/Quality/Services/InspectionService.php:889) rejects a submitted PASS with observed defects, yet later derives PASS if defects are within Ac and no requirement failed. [Existing test](../../api/tests/Feature/Quality/InspectionQuickResultTest.php:166) submits FAIL with one defect and receives proposed PASS after segregation. A failed checklist requirement, however, always holds the batch. | Show requirement decision, inspection verdict and disposition as separate concepts. Recommended simple behavior: any failed check keeps inspection FAIL; good stock becomes releasable only through reviewed sorting/disposition. Preserve the pinned sample policy until its acceptance semantics are deliberately aligned; do not silently delete or reinterpret Ac/Re. |
| Q6 / P1, acceptance instructions are lost or invisible | Product parameter notes are replaced with null during [inspection creation](../../api/app/Modules/Quality/Services/InspectionService.php:533), including the return matrix. Incoming quality-plan notes survive as row notes, but the checklist presents type/limits rather than a clear instruction. | Capture an immutable written acceptance criterion/reference with each inspection check and show it beside PASS/FAIL. A label like “Diameter” alone does not tell the inspector how to decide. Do not reuse the defect/problem field as the governing instruction. |
| Q7 / P2, supplier plan is a second numeric path | [Item quality-plan authoring](../../spa/src/pages/inventory/items/quality-plans.tsx:112) retains Min/Max and has no nominal input. [Controller validation](../../api/app/Modules/Quality/Controllers/ItemQualityPlanController.php:45) validates numeric fields individually but lacks the product specification's unique-name/type/window checks. It accepts definitions that can be invalid for downstream numeric scaffolding. | Apply the same written checklist model and normalized unique check-name rules to product specs and item/supplier plans. Require meaningful criteria, enforce valid shape at the server boundary and surface field errors. Keep supplier scoping and immutable revision identity. |
| Q8 / P2, quantity units can be confused | [Incoming plan creation](../../api/app/Modules/Quality/Services/InspectionService.php:250) rounds received decimal quantities into integer QC batch quantities. The panel calls all sample/defect quantities “pieces.” [GRN acceptance](../../api/app/Modules/Inventory/Services/GrnService.php:1089) subtracts a defective-piece count directly from base received quantity. For resin measured in kg, a defective bag and one kg are different quantities. | Retain original received quantity and base UoM. Define the inspected sampling unit and confirmed rejected quantity explicitly. Piece counts can drive piece stock; kg/liter stock must use a quantity in its own UoM for disposition. Do not auto-subtract “one defective bag” as one kg. This is a source-confirmed unit hazard; no new fractional-stock transaction was executed in this audit. |
| Q9 / P2, submission and editing friction | [Panel state](../../spa/src/pages/quality/inspections/components/InspectionChecklistPanel.tsx:81) is initialized from the first response and persists local drafts while refreshed records change. Lot submission performs separate measurement, item-decision and batch requests. A later failure leaves earlier draft writes saved, while readiness is represented mainly by a disabled button. | Keep one clear submit path for checklist-only results, preserving server ownership/review checks. Explain blockers such as “2 checks unanswered.” Reconcile refreshed or reviewed state without silently submitting stale drafts; retain failed-submission input and distinguish saved draft from final result. An all-in-one generic workflow framework is unnecessary. |
| Q10 / P2, measured UI problems | The [editor table](../../spa/src/pages/quality/inspection-specs/editor.tsx:521) has a 960px minimum width plus a similarly wide revision table. Browser document width was **1619px at a 1440px viewport** and **1379px at 390px**. Revision notes and the revision selector lack accessible names. PASS/FAIL buttons measured **28px high** because [Button touch size](../../spa/src/components/ui/Button.tsx:27) uses the office `--hit-min`, contradicting its 44px comment. | Replace numeric columns with Check, Acceptance criterion and Critical. Contain historical tables inside their own scroll region. Label notes and revision choice. Guarantee at least 44px for QC decision controls independent of office palette; preserve text labels, selected state and keyboard focus. |
| Q11 / P2, migration and history risk | [Specifications](../../api/app/Modules/Quality/Services/InspectionSpecService.php:129) already create immutable revisions and soft-delete old item rows; measurements retain numerical snapshots. Existing open inspections can remain governed by old numeric definitions even after a new spec is published. | Publish new checklist revisions instead of clearing old limits or changing old recorded results. Keep a small explicit distinction between legacy measurement evidence and new decision evidence. Explain old open records and provide a controlled replacement/revision path; never auto-PASS unanswered rows or fabricate readings. |

Severity summary: four P1 and seven P2 findings. P1 denotes a major workflow/release/evidence problem here; it does not claim a production incident occurred. Most findings are source-confirmed and existing-test-confirmed; measured UI findings and canceled-GRN acceptance have runtime evidence above.

## Recommended specification form

Keep the existing product specification and item/supplier plan concepts; a new module is unnecessary.

| Field | Example | Rule |
|---|---|---|
| Check | Surface condition | Required, unique normalized name within the revision. |
| Acceptance criterion / reference | No cracks, flash or contamination; compare with approved sample. | Required clear instruction. Can reference an approved drawing, gauge, sample or test method. |
| Critical | Yes / No | Retain significance independently from numeric entry. A critical FAIL blocks release. |
| Revision notes | Updated inspection method. | Optional. Governing revision and actor/time remain automatic. |

Remove nominal/minimum/maximum/measurement-UoM controls from new checklist authoring and daily QC capture. No type chooser is needed in the daily inspection workflow. Existing check classifications can remain internal where needed for historical interpretation.

Do not automatically invent acceptance criteria from old numeric values. Existing dimensional checks need a meaningful method/reference before conversion, such as an approved GO/NO-GO gauge; a button is a recorded judgment, not a substitute for performing the check.

## Recommended inspection experience

1. Auto-load the source WO output, receipt line or return product, batch/lot, governing checklist revision and stage-specific planned coverage.
2. Show one compact row per check: its acceptance instruction and PASS/FAIL buttons. All start unanswered.
3. PASS requires no problem text. FAIL reveals the problem description; criticality comes from the check, not a second severity form.
4. Show the planned sample/coverage as read-only context. The user confirms the checks were actually performed. Use “Inspect the planned sample” instead of exposing statistical formulas.
5. When every check passes and coverage is confirmed, derive zero observed defects without forcing a separate zero input. This inference must follow the user's inspection attestation, not page load or a default PASS.
6. When a physical defect is found, record its description and the unique defective quantity once for the batch/sample. Count the same piece once even if it fails several checks. A missing certificate/document can fail a batch check with zero defective pieces; do not manufacture a piece count for document problems.
7. Submit for the existing independent review where required. Failed inspection retains FAIL and opens/links the existing NCR; stock remains held until the applicable sorting, rework, scrap, concession or supplier-return disposition is approved.

For long checklists, retain local search and Needs attention. “PASS all remaining” can reduce clerical clicks only after the inspector confirms that those checks were performed; it must not overwrite saved FAIL or automatically answer a newly added requirement. Local search requires no network request per keystroke.

Example: WO produces **100 pieces**, planned sample is fetched by the system, and the checklist contains Surface condition, Color and Fit. The inspector records three check decisions. If one inspected piece is scratched and does not fit, record those two failed checks but a unique defective-piece count of **1**, then hold the affected batch for reviewed disposition. Sampling alone does not prove the other 99 pieces are all good; that conclusion needs the applicable sorting/full-coverage evidence.

## Stage and downstream behavior to preserve

| Stage | Source | Capture | Downstream gate |
|---|---|---|---|
| Incoming | GRN line/item/supplier plan | Checklist PASS/FAIL, receipt UoM and actual coverage | Independent QC/review before accepting stock; failures use NCR/MRB and supplier-return disposition. |
| In-process | WO/operation | Checklist PASS/FAIL over the configured planned sample | Required process-quality gate; failures remain visible to production and block closure until resolved. |
| Outgoing | Exact WO output batch | Checklist PASS/FAIL, output-derived batch quantity | Independent review, QC stock release, delivery allocation and truthful checklist-based CoC. |
| Customer return | RMA product and received quantity | Same checklist interface; return coverage policy retained | Reviewed return disposition; QC FAIL is not automatic restock or credit approval. |
| Supplier return | RMA/source receipt/product where applicable | Same checklist interface; return coverage policy retained | Reviewed supplier-return handling and source-owned stock/finance adjustment. Item-only return exemptions remain explicit policy. |

Keep result authors, maker/checker separation, terminal immutability, source/party scoping, pinned checklist history, unique inspection/output identity, held quantities and durable downstream events. [WO closure](../../api/app/Modules/Production/Services/WorkOrderService.php:1060) already checks open NCRs, required process quality, output receipts/GL and independently released QC; changing capture must not weaken those checks.

## UI/UX assessment

The current Atelier components and semantic colors remain the visual authority. The problem is excessive columns and mixed evidence language, not a need for another theme.

| Dimension | Assessment | Evidence boundary |
|---|---|---|
| Accessibility | 2/4, provisional | Named checklist decisions and selected state exist; revision controls and touch sizes need correction. Keyboard/screen-reader/zoom audit not completed. |
| Performance | Not scored | No load/query profile. Return matrix amplification is source-confirmed; no large-production benchmark was run. |
| Theming | 3/4, provisional | Shared semantic tokens, 93 passing palette checks and no detector drift. Full rendered dark/floor behavior not tested. |
| Responsive | 1/4 for specification editor | Measured body overflow at desktop/mobile. Existing inspection detail visits fit their viewport. Physical-device gestures untested. |
| Implementation integrity | 2/4, provisional | Shared design is coherent, but measurement language and server contracts conflict with the requested simple workflow. |

No total health score is asserted because performance and full accessibility were not measured. Focused polish should preserve record flow on top, details above the checklist, linked records, inspector/reviewer and stock disposition context. Move historical measurement evidence away from the daily checklist; show no empty numeric/SPC cards for new decision-based inspections.

## Implementation sequence and acceptance conditions

This proposal is reviewable but implementation has not started.

1. Align written product and supplier-plan checks, immutable snapshots and all five stage creation paths. Preserve historical numeric evidence and classifications.
2. Align decision capture, submission/review, certificate eligibility and certificate copy. Remove new numeric entry requirements through the explicit new evidence contract, not by globally disabling old safeguards.
3. Correct canceled-inspection release handling and keep UoM-safe rejected quantities. Standardize the meaning of inspection FAIL versus approved partial stock release.
4. Simplify the forms/record layout, conditional failure details, count entry and submit guidance. Correct touch sizing and table overflow using shared components.
5. Verify the resulting flow with role-based, isolated transaction tests: QC maker, independent checker, Warehouse, Production/PPC and denied employee. Test all five stages, 100-piece batches, one piece with multiple faults, document-only failure, canceled QC, retries, late spec revision and legacy certificate history.

Acceptance: no nominal/minimum/maximum or numerical measurement input in any new daily QC stage; one result per check rather than per physical piece; unanswered checks cannot pass; critical failures remain blocking; failed stock cannot be released by cancellation; counts/UoM and sampled/full coverage remain truthful; certificates print the evidence actually recorded; old records stay reconstructable. Full role-based transaction E2E remains a required implementation check, not a claim made by this read-only audit.
