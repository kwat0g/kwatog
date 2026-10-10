# Outgoing QC with confirmed checks and defective counts

Status: proposed; planning only. Supersedes the requirement for a numeric reading on every piece in the finish-first WO workflow, after approval and controlled rollout. The physical inspection still covers every piece.

## Purpose and scope

QC inspectors should inspect finished WO output, report defects, and submit for independent review without typing thousands of measurements. Keep the existing QC detail page appearance. Reconstruct the outgoing record view around its source, requirements, quantities, defects, and review evidence.

Plan the confirmed-check method for outgoing inspections belonging to eligible finish-first WOs. Incoming, returns, startup and mandatory operation inspections retain their existing evidence contracts. Legacy outgoing records receive compatible presentation cleanup but do not acquire weaker evidence rules. No automatic conversion of historical inspections or product specifications.

Assumption based on the latest request: the desired method is confirmed physical checks and defect counts. This does not include recording automatically generated target values as measurements. Approval of this design settles the previously presented evidence-method choice.

## Inspection process

1. Production records physical output and finishes the WO. Final QC is created exactly once per output batch.
2. Inspector sees the product, output quantity, batch label, and the pinned requirements. Targets, tolerances, units and surface criteria populate automatically as references.
3. Inspector physically checks all pieces against every requirement. Defective quantity counts each piece once, regardless of how many faults it has.
4. PASS ALL sets the defective quantity to zero; it does not assert that the physical inspection occurred. With defects, each defective piece has a description and an affected-check reference. A shared description/check can fill selected defect rows.
5. One confirmation states: "I checked every piece against all listed requirements, including size, weight, and surface checks." The implementation lists only the requirement categories that actually exist. Coverage uses the actual produced quantity.
6. Server calculates PASS when the count is zero and complete coverage is confirmed. A positive count produces FAIL. Inspection evidence locks on submission.
7. A different authorized QC user reviews the saved evidence. Review cannot change the calculated FAIL to PASS or substitute expected targets for measurements.
8. Released quantities are available only after their existing QC and warehouse gates succeed.

Example: 500 produced, 5 defective, 495 without reported defects. Before independent review or segregation approval, approved-for-release may remain zero. Do not call the 495 available, released, or accepted while approval remains pending.

## Evidence and critical checks

Targets remain specification values. The new method stores the inspector's confirmation, actor, time, revision, physical coverage, defective count and defect descriptions. It stores no invented size or weight readings. PASS ALL records a zero-defect assessment, not an assertion that every piece exactly equals its target.

Each defective row identifies its primary failed requirement, or "Other / not classified". Preserve additional faults in its description. A critical requirement failure produces critical-defect handling. An unclassified defect is handled conservatively as requiring critical review so missing classification cannot bypass existing critical restrictions. The primary-check selector is conditional on defects; the zero-defect path has no extra selection.

Numeric evidence already recorded remains visible and immutable under its original method. Existing customer requirements for numeric evidence are not automatically satisfied by confirmations. Where actual measurements must be supplied, retain the established numeric method; this plan adds no new customer-policy editor or measurement import integration.

## Page reconstruction

Use the existing QC number header, product subtitle, status chip, record flow and warm application panels. The same outgoing inspection record is shown whether reached from the QC list or its WO; no duplicate QC entity or alternative workflow is introduced.

Desktop main column:

- **Inspection summary:** stage Outgoing, produced quantity, defective quantity, quantity without reported defects, approved for release. Clearly distinguish current draft inputs from saved/reviewed totals.
- **Requirements:** a compact reference table with check, target/range, unit, surface/acceptance criterion and critical marker. It shows real pinned requirements and identifies the evidence method as confirmed checks. No editable target fields.
- **Inspection result:** defective quantity and PASS ALL; conditional problem rows; shared-problem application; one coverage checkbox; Save draft and Submit inspection. Keep draft recovery, save feedback and confirmation invalidation after edits.
- **Review / disposition:** submitted count, recorded confirmation and independent reviewer action. Failed results show the NCR and the next segregation/rework/scrap action. Read-only views show the saved defects and confirmed coverage rather than an empty form.

Right column:

- **Linked records:** product, WO, output batch, equipment and pinned specification. Sales order and delivery dates remain under optional order details.
- **History:** created, performed, submitted and reviewed timestamps with actor names. Production timing sits in collapsed source details rather than another large panel.

Mobile stacks these sections. At most 50 defect rows render at once, with local scrolling and pagination for large counts. Long identifiers wrap; tables scroll internally. Reuse Panel, ChainHeader, Input, Button, Checkbox and existing focus/error patterns.

Remove from the new method: the per-piece measurement matrix; remaining-readings progress; Save readings and readings pagination; AQL/sample terminology and limits; size-sample counts; measurement defects; duplicated acceptance rules and next-action messages; startup sample controls; SPC charts based on measurements that were never collected. Keep relevant historical sample/measurement evidence accessible under "Original inspection method" for legacy records.

Never hide a missing handoff or critical restriction merely to make the page simpler. Render one actionable blocker instead of repeated warnings. Use 12-hour plant time throughout.

## Defect fallback

A failed batch remains held after independent QC review and opens or reuses the linked NCR. Physically segregate defective pieces and record the segregation evidence. An authorized independent disposition approver may release only the conforming quantity allowed by the saved inspection, preserving critical restrictions.

Defective pieces stay held for repair or scrap. Repair uses existing linked rework, then reinspects repaired pieces. Scrap uses the audited inventory movement; existing shortage ownership creates replacement production only for the remaining shortage. Never add both repaired pieces and replacement pieces for the same deficit. All-defective output releases zero.

Example test later: 500 produced, 5 defective. Hold all 500 through initial FAIL. Approve segregation to release at most 495; keep 5 held. Repair/reinspect those 5, or scrap them and produce the authorized shortage. Retries must not duplicate NCRs, receipts or released stock.

## Versioning and existing WO-202610-0004

Add an immutable counted-capture version 3 with workflow `finish_first_v1` and evidence method `confirmed_checks`. Version 2 remains actual readings for every piece. Both preserve full physical coverage and per-output lineage. Reuse source and result-version locks; reject unknown methods and stale manifests.

New version-3 inspections are created only after all readers, queue grouping, review, NCR, stock release and certificates understand them. Default the rollout switch off until verification succeeds.

For WO-202610-0004, re-read the current state at rollout. An explicit controlled replacement may cancel and replace a truly untouched draft while preserving its original record and pinned specification revision. Require draft status, no recorded readings, no defects, no result authors, no coverage confirmation, no review/release, and a still-eligible completed WO. Any evidence present blocks replacement and leaves the old method intact. Replacement needs a reason and authorized QC actor, is atomic, idempotent, and never occurs from a GET. Do not clear evidence or bypass the pinned-capture immutability guard.

## Certificates and analytics

Version-3 certificates show full confirmed coverage, targets/limits as references, inspector and reviewer, and: "Numeric measurements were not recorded for this inspection." Omit actual ranges, numeric reading counts, AQL sampling claims and invented measurement statistics. Version-2 certificates retain their actual-reading validation and ranges. A held or failed batch cannot generate a certificate pretending the whole original lot passed. Confirmed-check data must not enter numeric SPC calculations.

## Acceptance and limits

Zero-defect path for 500 pieces has no numeric measurement fields: physically inspect, PASS ALL or zero count, confirm coverage, submit, independent review. Positive counts require one valid problem per uniquely identified defective piece. Changing counts/descriptions removes confirmation. Targets never appear in measurement storage.

Production totals, late output reporting, material handover, equipment release and existing lifecycle gates remain intact. The existing WO finish timestamp preceding its start is a separate production timing issue; this plan preserves its recorded history and does not invent a correction.
