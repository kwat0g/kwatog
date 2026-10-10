# Finish production before full-piece WO inspection

Status: planning only. The user authorized this plan; implementation and defective-item testing are separate steps.

## Requested experience

For eligible new work orders, Production starts the run, records all physically produced pieces, and finishes production. Only then does the system create final QC. Remove the startup sample action and automatic startup inspection for this workflow. The inspector checks every physical piece; the software reduces data entry, not physical coverage.

The QC page offers **PASS ALL** after explicit confirmation that every piece was checked. When defects exist, the inspector enters the defective quantity and describes each defective piece. A piece with multiple problems counts once. The same description can be applied to several pieces. Required numeric size readings remain separate and determine PASS/FAIL automatically.

Finished production reported late remains reportable through historical crew access and management review. Recording history does not extend the run, change attendance, or occupy equipment now.

## Workflow and labels

| Step | Responsible role | Action and result |
| --- | --- | --- |
| Confirm | Production management | Approve schedule, equipment and crew; reserve materials. |
| Start | Production management | Check actual readiness, presence and material handover; begin the run. No startup QC is created for this workflow. |
| Produce | Assigned recorder | Record actual quantity made. It is **Awaiting QC**, not QC-approved good stock. |
| Finish production | Production management | Verify recorded totals and completed routing. Release resources, save the actual finish time, and request final QC. |
| Inspect | QC inspector | Check every piece, enter defects and required readings, then submit the calculated result. |
| Review | Independent QC reviewer | Approve the evidence and calculated PASS/FAIL. This is the release decision. |
| Recover defects | QC and Production | Segregate defects; approve conforming quantities; repair/reinspect or scrap and replace the shortage. |
| Close | Existing automatic close service | Close only after QC, inventory/accounting handoffs and required NCR actions are resolved. |

Keep the existing `completed` status for physically finished production. Show **Production finished · Awaiting QC**, **QC awaiting review**, **Defects held**, or **Quality released** as derived detail. Do not add a second lifecycle enum. Production completion must not require final QC, otherwise completion and QC depend on each other.

Record all actual pieces before normal finishing. Below-target finishing retains the existing explicit reason and shortage handling. Zero physical output cannot create a fake inspection. Pending late reports block a normal finish until reviewed. Management can mark an already physically finished run using its real finish timestamp after approved late reports are posted; this does not restart the run.

## Scope and existing evidence

Pin `work_orders.qc_workflow = finish_first_v1` on eligible newly created WOs. Existing null values retain the current startup/operation/final QC behavior. This is an internal compatibility marker, not a user-selectable inspection shortcut. A deployment switch enables creation only after all readers and workers understand the marker.

Eligibility: standard root production and replacement production with no approved routing requirement for intermediate QC. Existing repair and parent-component flows retain their owners and gates. A routing with `qc_required` remains on the established intermediate-QC process and displays **This routing requires QC during production.** Do not silently remove a mandatory control or edit approved product/routing specifications.

Pin eligibility against the approved routing at creation. Before confirmation/start, recheck that routing changes have not introduced a mandatory intermediate check; block incompatible changes instead of silently changing a started WO's policy. The marker cannot be changed through normal update requests.

Incoming, supplier-return, customer-return and mandatory operation QC do not change. Existing draft, submitted and terminal inspections, startup pieces, output totals, stock movements and audit records are not converted or deleted. In particular, the currently demonstrated WO is not silently moved to this new workflow.

## One WO page, separate output evidence

Reuse one outgoing inspection per physical output batch, created only after the WO is completed. Present these on one **Final inspection for WO…** page, with one combined summary, one evidence submission and one review action. The normal QC queue displays one WO task; expanding it reveals output batches. This avoids introducing a new parent QC entity and preserves existing output-bound release, NCR, delivery and certificate contracts.

The group is derived from the completed WO and its active output inspections. Its manifest contains `output_id`, `inspection_id`, `physical_quantity`, `result_version` and pinned specification revision. Responses and writes carry that manifest. Every submit/review transaction locks the WO, outputs in ID order, inspections in ID order, evidence, and then the existing inventory resources. Reject a stale manifest before changing any evidence.

Group operations are atomic: if one batch is invalid, no other batch is submitted or reviewed. Mixed results are allowed and remain distinct: the combined result is FAIL if any child batch fails. One review confirms each child's calculated result, not a fabricated identical result for every batch. Failed batches are held; independently approved passing batches may release.

The manifest includes already finalized batches for stale-source detection, but submit touches only editable children and review touches only children awaiting review. Previously approved evidence is read-only. A later added output cannot force the system to resubmit or rereview unrelated finalized batches.

## Quantities and stock

For new finish-first recording, the worker enters `produced_count` only. Persist that physical quantity through the existing output ledger with `good_count = produced_count`, `reject_count = 0`, and no historical exception `held_quantity`. In this versioned workflow, `good_count` remains a production-reported quantity; it does not grant quality approval. Legacy rows keep their meaning. UI must say **Produced**, **Awaiting QC**, **QC accepted** and **Defective**, rather than showing pending quantities as approved good output.

The shared stock release predicate already holds production receipts without an approved outgoing inspection. Reuse it: zero approved quantity before QC creation, during inspection, while awaiting review, and for a failed batch before approved sorting. Keep existing traceable physical receipts and GL posting; do not use historical-held settlement to represent ordinary finish-first production.

The final inspection batch quantity equals actual output physical quantity, including an all-defective result. QC accepted quantity is separate from the immutable originally produced quantity. Do not subtract defects both from output source counts and from QC accepted counts. Reclassification, scrap, repair-source consumption and return movements use existing audited owners.

Conservation after disposition: original produced pieces are either conforming source pieces, defective pieces still held, consumed repair source pieces, or scrapped source pieces. Repair consumes a source piece and returns that same piece in a repaired lot; it does not manufacture an extra unit. Replacement production manufactures new units. Mold usage records actual physical operations; it is not inferred from QC acceptance.

Pending QC counts as a production commitment for MRP, not available stock. A FAIL pending disposition must not immediately generate duplicate replacements. Reserve authorized repair quantities; after scrap or a repair shortfall, the existing single replacement owner produces only the remaining shortage. All receipt/release/recovery retries are idempotent.

## Full inspection and efficient entry

- Required physical coverage is the entire output quantity. PASS ALL sets zero defects but never supplies coverage confirmation or numeric readings automatically.
- Required dimensions are measured for every applicable piece. No hidden five-piece dimensional sample certifies this workflow. Missing readings block submission; out-of-tolerance readings force FAIL.
- Reuse pinned inspection measurement rows as requirement references. Add separate reading records keyed by inspection, requirement reference and piece number, so the existing detail resource does not return a massive matrix.
- Save readings and defect descriptions in pages of 50 pieces. Render at most 50 piece rows per page. Keep server draft progress, optimistic version checking and account-scoped local draft recovery. Final submission validates all pages, not only the currently displayed page.
- Each defective piece has a unique batch-local ordinal and a 1–500 character problem description. The ordinal is a QC handling reference, not a manufactured serial number. Physically keep pieces and containers matched to their batch and labels.
- For a single batch, entering 5 defects opens five problem rows. For multiple batches, distribute the five by batch first; the batch quantities must add up to the combined defective quantity. Individual descriptions may be applied in bulk without counting the same piece twice.
- A numeric failure identifies its piece and requirement. Require that piece in the defect list; multiple failed requirements on one piece count as one defective piece. Do not invent its actual measured value or silently add scrap.
- Coverage, readings and descriptions cannot be edited after submission without the existing controlled return-for-correction/version process. Review cannot turn failed evidence into PASS.

Copy should name one action once: **Check all 200 pieces.**, **5 defective · 195 conforming**, **Describe all 5 defective pieces.**, **QC review required before release.** Missing specification/calibration evidence names QC as the owner. No raw hash IDs in user instructions.

## Defective-item fallback

1. The inspector submits actual defective pieces and their problems. The result is calculated; positive defects or invalid required readings produce FAIL.
2. Independent QC review preserves the failed result and opens/reuses the linked NCR. The affected output batch remains fully held until physical segregation and approval are recorded.
3. Reuse full inspection evidence for sorting; do not ask the inspector to check all pieces a second time if the original coverage and segregation are still valid. Record segregation evidence and use an independent sorting approver. The original inspector may perform sorting, but cannot approve it.
4. Release only verified conforming quantities through the existing NCR sorting approval. Defective pieces stay held. If there are several output batches, each release retains its exact source lineage.
5. Choose existing **Repair and reinspect** or **Scrap and replace** recovery. FAIL alone does not mean scrap. Repair scheduling/materials/crew remain enforced; repaired output is reinspected after repair finishes. Repeat failure remains held with linked recovery evidence.
6. Replacement demand is owned by the existing MRP/NCR integration, never both. Replacements must pass final QC before delivery. Retain required root cause, corrective action and effectiveness evidence for NCR closure.

Example: 200 pieces produced; 5 defective. After independently approved segregation, 195 conforming pieces can release and 5 stay held. If those 5 are successfully repaired and reinspected, accepted fulfillment becomes 200 with no new original production. If all 5 are scrapped, produce 5 replacement pieces; lifetime physical production becomes 205 and scrap is 5. If repair recovers 3 and scraps 2, replacement demand is 2.

## Late finished production

Preserve actual start/finish timestamps, historical crew identity, true submission timestamp, independent management review, issued-material reconciliation and retry identity. Current attendance expiry or occupied machinery does not prevent reporting completed history. No fabricated attendance, backdated audit timestamps or automatic run extension.

For this workflow, legitimate production before final QC is expected and must not create a historical process-deviation NCR just because startup QC is absent. Actual known historical conflicts remain blockers. Before physical completion, approved late output joins the eventual final-QC group.

After completion, an approved additional report creates a new held output and a new outgoing child inspection. Do not enlarge or rewrite existing inspected batches. Pending reports prevent final close; a changed group manifest blocks a stale submit/review. Already approved unrelated batch evidence remains valid. Duplicate or overlapping reported batches remain review conflicts. A closed WO follows the existing controlled correction flow; the correction can create traceable inspection work for its new output without reopening old final evidence or acquiring resources.

## Rollout and proof

Expand schema and deploy all writers/readers with the deployment switch disabled. Restart queue workers onto the compatible build. Enable new-WO policy only after backend, SPA and isolated role tests pass. Turning the switch off stops new adoption; it must not reinterpret WOs already pinned to the new workflow. Retain a compatible build and additive schema; a pre-feature rollback cannot safely process new evidence. Refuse destructive schema rollback once new-policy WOs, readings or defects exist.

Prove normal zero-defect full production, multiple output batches, all-defective batches, reading violations, stale groups, unauthorized actors, partial release, repair/scrap/replacement conservation, late reporting, queue/receipt retries and legacy preservation. User-requested defective-item trials run later in a disposable database with role-based accounts. Do not manufacture physical/QC proof in the live system.

Physical inspection accuracy cannot be guaranteed by a checkbox. Finishing the entire run before QC also discovers setup problems later and may leave more defective pieces to recover; approved intermediate routing controls therefore remain enforced.

Full-piece checks must be compatible with the published specification and physical test method. Destructive requirements cannot be applied to every piece while promising all pieces remain deliverable; QC must resolve that specification conflict before the product enters this workflow. PASS ALL does not authorize dropping such requirements.
