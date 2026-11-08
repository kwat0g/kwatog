# Quantity-based quality inspection across units

User direction: use one simple PASS/FAIL capture for every source UoM, including bulk material such as kg. Remove daily sample readings and confirmations from this capture. Zero defective quantity produces PASS; positive defective quantity produces FAIL. PCS uses whole numbers with one explanation per defective piece; continuous units accept up to three decimal places with one explanation for the defective quantity.

Use the source quantity and original UoM as the bound and display context. Preserve bulk quantities separately from integer affected-lot counts. A reported 2.5 kg failure means 2.5 kg, not 2.5 pieces or five independent samples. A failed lot remains held for its existing disposition process; the form does not infer partial stock acceptance.

Extend the existing batch-result endpoint with a separate quantity_result snapshot: source quantity, UoM, defective quantity, and explanations. Preserve older capture contracts and recorded readings. Untouched counted version 1 forms use the new capture; terminal records retain their original evidence. Keep source locking, source reconciliation, result versions, independent review, startup physical evidence and historical production holds.

An additive nullable JSON column stores the new evidence. The form never fabricates numeric readings or per-check PASS answers. Review and certificate eligibility recognize the explicit quantity capture. A schema rollback refuses to delete recorded quantity evidence.

Verification: QuantityInspectionTest covers decimal kg, source bounds, zero PASS, whole piece counts, required explanations and independent review. QuantityInspectionPanel.test.tsx covers the rendered kg form, explicit zero, bounds, and five explanations for five PCS. Rendered component inspected at desktop and 390px mobile widths using isolated source data without submitting a business inspection.

## All-stage extension

The user explicitly requested the same simple form for every stage, including startup In-Process QC and final work-order Outgoing QC. Untouched measurement/checklist records also enter through quantity capture. Records with captured evidence retain their original evidence renderer.

Final work-order batches submit the same quantity_result through the existing inspection endpoint. They retain output/source/specification checks, per-defect descriptions, one result version increment, independent grouped review, and existing NCR/stock-release accounting. Both numeric version 2 and confirmed-check version 3 final inspections recognize the explicit quantity evidence without manufacturing measurement readings. Individual final results still cannot be reviewed through the ordinary inspection review endpoint.

Startup QC uses the actual pinned trial quantity. The existing physical trial gate remains mandatory, and PASS settles those same physical units once. The quantity form adds no coverage checkbox or per-check measurement fields.

Proof: 19 backend tests / 169 assertions passed across quantity capture, final work-order inspection, and startup quantity entry. Frontend targeted suites passed 64 tests across the shared quantity form, simplified final form, legacy evidence rendering, inspection details, and final inspection pages. Final quantity form inspected at desktop and mobile widths without submitting live production evidence.
