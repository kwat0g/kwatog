# Quality source lifecycle implementation — 2026-10-06

The approved source-lifecycle audit has been implemented using the existing work-order, quarantine, return, outbox and delivery workflows. Historical inspection verdicts and measured evidence were preserved.

## Implemented behavior

1. Work orders with produced output cannot be cancelled. Cancelled historical manufacture no longer contributes MRP fulfillment credit.
2. New countable incoming/outgoing checklists require full piece coverage. Inspectors record one PASS/FAIL per requirement, rather than one record per physical piece. Bulk quantities retain their real unit and lot basis.
3. Cancelled incoming inspections release their uniqueness slot and retries create an active replacement, updating the GRN anchor.
4. New outgoing QC cannot be created for a closed WO. Late defects use a new incident against its existing inspection; closed production history remains intact.
5. Short production completion requires an explicit reason. A rejected-only run can finish with that decision; it does not imply order fulfillment. Equipment is released at production completion and final closure remains quality-gated.
6. Process recovery checks the original inspected sample for new checklists rather than claiming unproduced target pieces were inspected. Historical measurement policy remains pinned.
7. Late incoming incidents preserve the reported defect count separately from suspect remaining quantity. Remaining traceable stock receives quarantine holds; logical holds survive missing setup, reservations and partial physical quarantine. Cancellation cannot bypass the incident. Closure requires every original held quantity accounted and all physical holds resolved.
8. Unsupported local Scrap on unaccepted receipts is blocked with supplier-return guidance. PCS sorted-good quantities must be whole numbers.
9. Physical return lines receive their own checklist, using product specifications or inventory quality plans. New captures require independent review. Exact checked incoming evidence may be reused for already rejected supplier goods; accepted-stock MRB supplier returns reuse their completed material review without shipping twice. An explicit zero receipt skips QC and may only use No goods returned; it cannot create a stock disposition or credit for missing pieces.
10. A fully received line may split into good/defective quantities before disposition. Money, source allocations and physical receipt totals are conserved; receipt movement provenance remains attached to the original line. Replayed splits, partial receipts, serial/truck returns, issued credits, existing dispositions and legacy shared inspection evidence are blocked. This intentionally supports one controlled split, rather than arbitrary allocation editing.
11. Approved sorted output emits a dedicated outbox event. Delivery drafting reuses current release checks and remains idempotent without changing the failed inspection verdict.
12. Internal failure notifications include source-specific owners and assigned crew. Crew links point to their self-service surface; no external notification transport was added.
13. WO details distinguish approved, rejected and recovery/replacement obligations, including closed historical sources with missing process evidence. GRNs show original, returned and net quantities plus active late quality holds. NCRs show material reviews and clear next actions.
14. Stock-bin errors direct users to movement history and controlled consolidation when defects span bins.

## Verification and limits

Backend regression passed 130 tests / 563 assertions. Final production-completion, process-sample recovery and return-chain regression passed 97 tests / 543 assertions. The final-state checklist and quantity-split suites passed 20 tests / 160 assertions, including explicit zero receipts. These batches overlap and are not additive. Frontend TypeScript and production build passed; targeted frontend tests passed. Three real headless Playwright tests passed using Quality maker/checker, Warehouse, PPC and employee accounts on the disposable application, covering all five stages, independent review, certificates and desktop/mobile/dark/floor layouts. UI detector and token checks passed.

Backend tests use only `ogami_test_pricing_20261004`; browser transactions use only `ogami_test_audit_20261002`. No live business sample data was created. The additive live migration was applied successfully, production assets were published, and queue reload was requested.

Physical inspection and defect discovery still require people. Full checklist coverage is an attestation, not fabricated measurements or Cp/Cpk. Material already consumed requires downstream traceability review; the system does not retrospectively reverse production or automatically recall delivered goods. Existing delivery drafts remain subject to current release checks rather than automatic enlargement after later sorting.

Evidence: `docs/testing/evidence/quality-source-lifecycle-20261006/`.
