# Batch inspection and late production reporting

Status: Approved by the user and implemented. Additive schema and application changes are authorized; test fixtures remain isolated from live business records.

## Goal and confirmed choices

Make inspection entry independent of the number of pieces while keeping physical coverage, quality holds, quantity accounting, and late production reports truthful.

The user selected:

- Enter a unique defective quantity and problem description; the system calculates PASS/FAIL. Do not require a PASS/FAIL answer for every piece or every attribute check.
- Keep required Size / Measurement readings in a separate section. Calculate their result from the pinned specification limits.
- Allow reporting work that was physically finished but entered after its production schedule ended.

The user approved zero-defect batch acceptance and management approval of historical production evidence. This design supersedes the entry experience of the deployed startup-trial design, not its physical evidence or stock safeguards.

## Current behavior and identified gaps

`InspectionService::recordChecklistSubmission()` currently requires decisions for every checklist row, physical-defect confirmation, coverage confirmation, and a unique defective-piece count. Numeric size readings remain mandatory. Multiple switches and confirmations repeat the same intent.

`InspectionService::finalizeTerminal()` calculates an outgoing accepted quantity using the batch quantity minus observed sample defects. Observing two defective samples does not prove that only two pieces in the entire batch are defective. The new workflow must never interpret a sampled count as a full-batch sorting result.

`WorkOrderAssignmentService::assertCanRecord()` checks the recorder's presence and approved schedule at submission time. Workers cannot report after the slot ends. Manager reporting with a reason exists, but current crew eligibility, equipment ownership, WO state, and QC guards can still prevent reporting legitimate earlier work. Outputs record only the entry timestamp, not an actual production interval.

`assignedQuery()` hides ended crews and ordinary completed WOs. The worker detail page also suppresses its blocker whenever a startup trial exists. This explains the empty startup card in the screenshots; the list shows a blocker while the detail hides it.

The startup trial ledger already records physical samples once, holds them before classification, and prevents invented good stock. Existing outgoing QC, NCR sorting, repair, scrap, replacement planning, and independent approval should be reused.

## Approach

Use a versioned **counted batch** capture contract for new inspections. The pinned requirements remain visible references, but attribute checks have no individual result buttons. Numeric size evidence stays separate. One aggregate submission records actual coverage, unique defective quantity, problem evidence, and the calculated result.

Alternatives considered:

1. Keep per-check PASS/FAIL and add a batch count. This preserves the present model but retains the complexity the user rejected.
2. Count-only inspection with no dimensional readings. This reduces entry further but discards evidence required by the existing size specification; the user rejected this option.
3. Counted batch with separate dimensional readings. Recommended and selected. It retains required evidence with substantially fewer interactions.

Do not silently populate historical per-piece or per-check rows as PASS. Aggregate coverage is a different evidence contract and must be identified as such. Terminal and submitted legacy inspections keep their original interpretation.

## Inspection page

Show one compact form:

1. Source and batch quantity, automatically populated from the received, returned, or physically recorded production source.
2. A short coverage statement: either “Check all 200 pieces” or “Check the planned sample of 20 from 200.” The approved plan determines this; the inspector cannot downgrade it.
3. Required checks as references, grouped and expandable. No PASS/FAIL controls alongside every piece or attribute.
4. Defective quantity, initially blank. Zero is an explicit entered answer. Label it “Defective pieces in the checked quantity,” and explain “Count each piece once.”
5. “What is wrong?” appears when the defective quantity is positive. Require a description; optional check/problem tags provide context without making the inspector create a second count table.
6. A separate Size / Measurement section appears only when required by the pinned specification. Preserve the approved number of readings, units, target, and limits. Calculate out-of-limit results automatically.
7. One confirmation that the prescribed checks were performed on the stated quantity, followed by “Submit inspection.” Display a calculated result preview; the backend is authoritative.

For a non-piece material, retain its unit and lot quantity. Do not present kilograms as a count of pieces. Use an affected-lot flag and description for lot-based checks; derive zero or one affected lot. Full sorting and disposition use the actual source units.

Unfinished inspections keep draft recovery, source/version conflict detection, and unsaved-change protection. Show one actionable blocker with its owner. Keep forms usable on mobile and independent of batch size.

## Coverage and automatic result

Separate **entry effort** from **physical inspection effort**. Entering one count does not inspect the pieces automatically. The system must never label all 200 pieces checked when only five samples were examined.

Proposed policy for new counted inspections:

- Zero observed defects, required valid dimensional readings, completed coverage, and valid source/specification/equipment evidence produce PASS.
- Any observed defective piece, any out-of-limit reading, or a reported requirement/process problem produces FAIL and a hold. There is no automatic release of a defective counted batch based on an AQL allowance.
- Invalid or missing evidence blocks submission rather than creating a result. Missing size readings are not equivalent to zero defects.
- A failed dimensional reading must be covered by the declared unique defective count. If one piece fails several checks, count it once. The inspector supplies the unique count; the system does not sum characteristic failures as pieces.
- A requirement or documentation failure with no known defective pieces may have defective quantity zero but still FAIL. Place this uncommon case under “Report another problem.” It requires an explanation and keeps the source held.
- Unanswered references are handled by aggregate coverage confirmation, not manufactured row-level PASS evidence. Every required dimension still has its own recorded reading.
- Keep existing maker/checker rules. A calculated result requiring review stays awaiting review and cannot release production or delivery.

Pin the result policy and coverage mode at inspection creation. Existing accepted sample plans and terminal results must not be reinterpreted under the new zero-defect rule.

For outgoing production, recommend full visual/appearance screening recorded through one aggregate count, plus the existing approved dimensional sample. Save attribute coverage and dimensional coverage separately. This does not require measuring every piece unless the approved specification calls for that. Incoming and return coverage continue to follow their approved plan; startup and operation samples remain limited to actual produced pieces.

## Startup samples and the 200-piece example

Replace the large “Startup production” form with a compact **“Samples ready for QC”** action. Show the pinned count as text, not a disabled input. Hide internal hash IDs and technical wording. The assigned recorder confirms once that the sample pieces physically exist. A manager recording on behalf still provides the existing required reason.

Startup sample size remains pinned, currently five in the displayed WO. Do not hard-code five. Their passing result permits normal production but does not certify the remaining 195 pieces. The accepted sample pieces count toward the 200-good-piece target once; do not ask the worker to record them again.

If the startup sample fails, hold the sample, keep normal production blocked, correct the setup, and require independently approved reinspection and explicit kept-good/scrap accounting. Do not expand the startup hold to 200 unproduced pieces.

Outgoing QC applies to recorded output batches. Multiple production reports may create multiple outgoing inspections; show their batch references and coverage clearly. Do not silently merge distinct output, material, or inspection lineages into a single 200-piece verdict.

## Defective-item fallback

FAIL holds the affected source and creates or reuses its linked NCR. A sample failure holds the remaining batch pending full sorting; the sample defective count is only an observation.

The fallback page gives the responsible user one next action:

1. Sort the affected batch or lot and enter actual good-kept and defective quantities. Full sorting is physically required; data entry is still aggregate.
2. Choose the disposition using existing authorized recovery: repair and reinspect, scrap, or supplier/customer return where supported.
3. An independent authorized quality reviewer approves sorting and release. The original failed inspection remains failed; recovery evidence is linked separately.
4. Release only independently approved conforming quantities. Keep unresolved and repair pieces held. A manager's late-report approval never approves quality release.
5. Repair follows existing material coverage, crew, equipment, and reinspection rules. Scrap is explicit, never inferred merely from FAIL. Replan any sales-order shortage through the existing replacement flow.

For 200 pieces with three defective pieces after full sorting, 197 may be released after approval; three remain held for repair or are explicitly scrapped. If those 200 include production rejects already segregated and counted, outgoing QC covers only the reported good batch. Do not subtract the same three twice.

Preserve conservation at every step: physical produced equals classified good plus classified scrap plus held pieces. Account for quantities already shipped, returned, or transferred. Previously released stock cannot be silently deleted or reclassified as available; use existing late-NCR, quarantine, return, and stock movement processes.

## Late production reporting

Offer **“Report earlier production”** to an active internal recorder who belonged to the crew when the work was performed, even after that schedule or crew assignment ended. Show historical assignments separately with the actual recording status. Normal live-output recording retains its existing safety gates.

The historical form asks for the actual start and finish time of the reported batch, quantities, and a short reason for late entry. Default source/crew context from the recorded WO history, not the current crew. Render all displayed times in Asia/Manila using 12-hour format. Future times, reversed intervals, production before actual WO start, impossible source quantities, and a duplicate report are rejected.

Record `recorded_at` as the true submission time. Store the actual production interval separately. Do not backdate audit entries, invent attendance, rewrite the approved schedule, or extend the run solely to permit entry.

Use a distinct persisted late-report record with the proposed quantities, actor, historical crew, interval, explanation, status, and immutable retry fingerprint. Proposed records do not create stock or count as accepted output. All late reports initially require production-management approval; historical attendance and recorded run/resource evidence are shown as verified, missing, or conflicting so the approver can assess them. This conservative first version avoids treating an attendance punch as proof that a particular batch was produced.

On approval, atomically post one linked production ledger entry using validated historical evidence. Current machine occupancy and current worker presence must not reject an already-produced batch, nor may approval acquire/release today's equipment or start new production. Keep quantity, source lineage, issued-material reconciliation, mold-life accounting, QC, and idempotency safeguards. Unreconciled material or impossible historical evidence remains pending with an actionable explanation; approval is not a blanket bypass.

Record physical units and mold usage once at posting. Post inventory/accounting effects through existing handoffs at the actual entry/posting time and respect closed-period controls. Prevent a subsequent regular output entry from duplicating the same reported production; check exact retry keys, report linkage, source batch reference, accumulated quantities, and overlapping reports, with conflicts requiring review.

Allow reports for in-progress, paused, and completed WOs. Closed WOs require an authorized correction review because stock or accounting may already be final. Cancelled WOs do not auto-post reports. Inactive accounts cannot submit or approve. Historical access must be scoped to the actual former crew and must not expose unrelated employees' WOs or broad HR records.

## Late reports while startup QC is still unfinished

Distinguish two cases:

- Only the actual startup samples were produced earlier: approve and capture that sample through the existing trial ledger using historical evidence, then let QC inspect it. Do not require today's crew presence just to enter that history.
- More than the sample was produced before required startup release: accept a report for review, flag a production-process deviation, and hold the actual batch. Do not claim the entire batch was the five-piece startup sample, fabricate a past QC PASS, or discard the remaining pieces from the ledger.

The second case needs explicit pending-quality production quantity on an output ledger row, independent of the special startup-trial quantity. Reuse the existing hold/NCR and outgoing source lineage. QC must inspect/sort the affected recorded batch; the startup gate remains unresolved until quality approves the process correction and any required reinspection. Approving the report acknowledges physical history; it does not authorize further production or shipping.

The physical batch includes any startup samples selected from it. Samples are not an additional production count. Require linkage and transaction checks so a late whole-batch report cannot count already-captured samples again. Keep ambiguous overlaps pending for reconciliation.

## Data and integration boundaries

Use additive, versioned changes:

- Inspection: counted capture version, pinned result policy, separate attribute and dimensional coverage, aggregate problems/counts. Reuse existing coverage and defect fields only where their sample-versus-batch meaning remains exact. Extend contract rather than repurpose historical row evidence.
- Late production report: proposed quantities and interval, historical crew/source reference, pending/approved/rejected status, independent approver/time, exact retry fingerprint, and unique linked output.
- WorkOrderOutput: true production interval and held physical quantity where required. Preserve `recorded_at`, trial ledger semantics, existing good/reject fields, and exact-retry behavior.
- Stock eligibility: held physical output cannot become available stock or a shipment through automatic, manual, replay, or alternate receipt paths. If physical stock is registered, use an explicit held/quarantine location/status.
- Existing NCR, repair, inventory, sales-order replacement, and notification services remain owners of their respective effects. Add no parallel recovery engine.

Use source-first locking for report approval and QC disposition. Review existing lock order before adding cross-service writes. Unique report-output linkage and durable fingerprints prevent repeated approvals from adding units or stock twice. A result/version conflict reloads current data without overwriting another user's evidence.

## Compatibility, rollout, and stop conditions

Do not rewrite terminal QC, submitted legacy evidence, output counts, attendance, or stock. New inspections use the new contract after deployment. An untouched active draft can switch only with an explicit safe conversion preserving its pinned source/specification/sample and without existing captured results; otherwise finish through the legacy renderer.

Retain additive columns and pending reports if application rollback occurs after new evidence exists. Refuse schema rollback that would delete production or quality evidence. Deploy backend safeguards before exposing new forms.

Finish when counted inspection, exact defect holds/recovery, compact startup confirmation, historical worker reporting, manager approval, and stock conservation pass role-based tests. Exclude per-piece barcode tracking, automatic vision, destructive sample accounting, a general approval framework, scheduler redesign, new dependencies, and unrelated cleanup.

## Required proof

- No defect input or confirmation is auto-filled as a completed inspection. Zero defects plus valid complete evidence produces PASS; positive count or another problem produces FAIL.
- A 200-piece inspection has a bounded form regardless of quantity. Sampled versus full coverage is explicit. Required size readings cannot be omitted, forged, or contradicted by PASS.
- Counts are whole, bounded by actual checked pieces, and unique across problems. Non-piece material uses its actual units/lot semantics.
- Sample failure holds the source batch; release requires full sorting. Three defects produce explicit 197/3 disposition only after that full evidence exists.
- Startup sample counted once; outgoing rejects do not double-subtract production rejects; repair/scrap/replacement and receipt retries conserve all quantities.
- Former recorder can propose an earlier batch after time-out or schedule expiry without changing today's schedule. Wrong worker, future/pre-start intervals, stale source, inactive account, duplicate payload, and forbidden role fail safely.
- Late approval validates historical evidence and does not occupy today's resources. Pending and rejected reports have no stock effects. Approval, stock receipt, and QC replay are durable and exact.
- A whole batch produced before startup QC remains held with a deviation, without fabricated sample history or unrestricted normal output.
- Completed and closed corrections preserve audit/posting times and do not duplicate existing shipped or counted stock.
- Worker list and detail display the same actionable blocker; mobile forms, draft recovery, and review controls remain usable.
- Execute backend, frontend, and isolated Playwright tests with Production recorder, Production manager, QC maker/reviewer, Warehouse, and unrelated employee accounts. Never create live physical production evidence for tests.
