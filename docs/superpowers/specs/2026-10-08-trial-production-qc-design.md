# Startup trial production and in-process QC

Status: Approved and implemented on 2026-10-08. Trial/QC and material handover are deployed together. Historical production counts remain unchanged; only untouched active startup drafts gain the trial requirement.

## Problem and evidence

Starting a WO creates its draft in-process inspection synchronously in `WorkOrderService::start()`. `WorkOrder::assertProcessQualityReleased()` then blocks ordinary output and routing setup while that draft is unfinished. Production therefore cannot record the pieces needed for the first inspection. Conversely, `InspectionService` currently accepts the inspector's coverage confirmation without recorded physical sample evidence.

The initial QC record is legitimate preparation after Start. The missing step is producing and recording a limited startup sample before normal production is released. Delaying all QC until normal output would remove the startup quality gate; allowing ordinary output while QC is pending would permit unrestricted production. Neither addresses both problems.

## Recommended flow

1. **Start WO:** Keep existing schedule, attendance, machine, mold, material coverage and specification checks. Issue reserved material once and prepare draft startup QC.
2. **Confirm material handover:** Warehouse records the physical receipt in Material Handover. The existing explicit manager exception remains available only where authorized; it does not approve QC.
3. **Prepare equipment and record trial samples:** Permit setup under the existing resource, attendance and handover checks. The assigned recorder or authorized manager records the actual startup sample batch. The count is the inspection's pinned sample size, capped by the WO target; no new arbitrary five-piece default.
4. **Inspect samples:** QC follows the pinned inspection specification. Size checks require numeric readings; other checks use PASS/FAIL. QC cannot enter physical evidence before samples exist or finalize a result without the full recorded sample.
5. **PASS:** Classify those same sample pieces as good once, then permit normal production. Samples count toward the WO good target and receive the usual outgoing QC before shipping.
6. **FAIL:** Hold the actual samples and keep normal production blocked. Existing NCR recovery records independently approved reinspection and explicit good-kept/scrapped quantities. Do not infer scrap from a failed check or discard the full WO target.

## Data and accounting

Reuse `work_order_outputs` as the physical production ledger. Add nullable `trial_inspection_id`, immutable positive integer `trial_quantity`, and nullable `trial_settled_at`. Add `startup_trial_required` to inspections with default false for compatibility. A trial batch is a whole pinned sample captured once per startup inspection; ordinary output endpoints cannot accept a trial flag.

Before disposition, the output row has `good_count = 0`, `reject_count = 0`, and the actual `trial_quantity`. Record the actor, recorder/crew snapshot, sample inspection, timestamp, idempotency key and any material-handover exception. Increase physical `quantity_produced`, material-use coverage and mold shots once. Display the pieces as **Awaiting QC**, not zero output or good stock.

Unsettled trials cannot create a finished-goods receipt, outgoing inspection, inventory release or shipment. Both automatic and manual receipt paths enforce this rule. PASS or approved recovery settles the existing row: good plus scrap must equal `trial_quantity`. Increase `quantity_good` and `quantity_rejected` by those classifications without changing `quantity_produced` or mold shots again. Use the existing output receipt handoff only for classified good pieces; its durable replay protection remains authoritative.

If discarded trials leave the WO below its good target, replacement production uses existing per-item material coverage and shot limits. Additional material must be issued and handed over when required. No synthetic stock adjustment, automatic extra material or hidden overproduction allowance.

## Permissions and gates

Dedicated trial capture uses existing assigned-recorder authorization and manager recording-on-behalf requirements. QC inspectors, unrelated employees, Warehouse, HR and Sales cannot manufacture production evidence. Inactive users cannot capture or approve.

Capture requires an in-progress root WO, assigned live equipment, valid current crew, required attendance, material coverage, and an active unfinished startup inspection that requires a trial. Reject repair contexts, operation inspections, mismatched WO/inspection, zero/fractional/oversized counts, and duplicate batches. Do not bypass failed or awaiting-review QC.

Ordinary output, normal routing production, routing completion/skip, WO completion and delivery release retain their quality gates. Setup start/end may proceed before startup QC under the production-readiness checks. This sample batch represents setup pieces on the WO's acquired equipment; it never claims routing operations completed.

Final routing reconciliation compares routed quantities with ordinary canonical output, excluding startup sample rows. WO totals still include classified samples. If samples need to pass through every routing operation, that is a different process requiring explicit per-operation trial progression; this proposal does not silently loosen operation gates.

## QC submission and failure recovery

Use one shared physical-evidence check at every inspection write boundary: measurement saves, checklist item results, lot and batch result submission, completion and review. Startup inspections without sample capture display **Waiting for Production to record N trial pieces** and disable result entry. Final inspected quantity must equal the recorded pinned sample. Ordinary operation QC derives available pieces from actual operation output and must never fall back to `max(1, target)` when no pieces exist.

On FAIL, retain samples as held physical output. A failed visual requirement can indicate reworkable pieces or a documentation problem, so `sample_defect_count` must not automatically become scrap. Trial-linked NCR recovery uses the actual sample quantity for affected/recovery coverage across both checklist and measurement modes. An independent authorized reviewer approves verified process correction plus the actual sample disposition. Good-kept plus scrapped equals the sample quantity; defect types and counts must reconcile with any confirmed scrap.

Existing non-trial NCR recovery behavior remains unchanged. Trial recovery can release the corrected process with fewer good pieces than the original sample after explicit discard; requiring all sampled pieces to become good would trap a legitimate failed trial indefinitely. Unresolved disposition keeps production blocked.

## Transactions and retries

Align new capture and QC settlement on source-first locking: WO, inspection, trial output, then required equipment/material/inventory resources. Review existing inspection-first paths before introducing any WO writes, so concurrent capture, review, cancellation and receipt cannot deadlock or classify pieces twice.

A durable exact retry returns the original capture even after QC or WO becomes terminal. Reusing its key with another actor, count, crew or reason fails. Settlement is synchronous and atomic with the approved QC/recovery result; queued events handle replay-safe notifications and downstream work. Receipt failures use the existing recoverable handoff status rather than silently deleting the QC evidence.

Once physical samples exist, refuse inspection cancellation or replacement that would orphan them or reset the sample cap. Do not reuse a cancelled inspection as a new unlimited trial allowance.

## UI and copy

WO detail shows one current step: **Confirm material handover**, **Record N trial pieces**, **Waiting for QC**, **Trial failed — QC recovery required**, or **Ready for production**. Keep this separate from general scheduling warnings.

The recorder form prefills the required trial count and identifies the current crew and inspection. Count confirmation is explicit; no automatic capture. Preserve submitted data and key on an unknown save outcome, with the existing receipt-check/retry pattern.

QC detail shows recorded sample count and capture time. Measurements and PASS/FAIL remain driven by the approved specification. A blocked page names the owner and action once. Output history differentiates awaiting-QC trial pieces, accepted samples and normal production. NCR recovery explains that its counts cover only the sample batch.

## Compatibility and rollout

Retain legacy terminal QC and output evidence without inventing sample history. New startup inspections require trial evidence. Existing active root-WO draft inspections with an actual started WO and zero recorded output can be marked trial-required only when no submitted result or physical evidence exists; ambiguous records require review. No backfill changes stock, produced/good/rejected counts, mold shots or terminal results.

Deploy the additive schema, trial recorder UI/API, QC evidence checks and settlement together. Keep additive columns during application rollback once evidence exists. Do not roll back the database by deleting trial evidence.

## Implementation scope

- Migration, `WorkOrderOutput`, `Inspection`, `WorkOrder`, resources and receipt eligibility.
- `WorkOrderOutputService`, `WorkOrderService`, `WoOperationService`, `InspectionService`, `NcrService` and startup inspection creation.
- Dedicated production trial request/routes/controller plus assigned-recorder access; ordinary output request remains unchanged.
- Production/Quality types and API methods; WO detail, recorder/self-service forms, inspection result panels and NCR recovery form.
- Existing production/QC fixtures updated only where new real sample evidence is required.

## Acceptance proof

- Start creates draft QC after actual Start; setup is possible, ordinary production remains blocked.
- No QC evidence or PASS before physical samples; correct count follows the pinned specification sample.
- Wrong recorder/role, missing handover, inactive actor, stale crew, unsuitable equipment, paused WO and crafted ordinary-output trial flags fail without writes.
- Concurrent or repeated captures produce one batch; count, stock coverage and mold shots change once. Changed retry payload fails.
- PASS accepts the same trial once; receipt retry cannot receive it twice; subsequent good output reaches the exact WO target.
- FAIL holds only actual samples. Approved recovery classifies actual kept-good and scrap, never the WO target; unresolved samples remain blocked.
- Pending trials cannot enter manual/automatic inventory receipt, outgoing QC or shipment.
- Operation QC requires actual operation pieces; setup allowance cannot bypass ordinary operation gates. Routing reconciliation excludes startup samples correctly.
- Size measurements remain mandatory where the spec contains approved dimensional limits.
- Legacy terminal records remain unchanged; cancellation cannot orphan samples or reset the cap.
- Isolated Playwright roles exercise Warehouse handover, recorder trial capture, QC PASS/FAIL, manager recovery and ordinary production, including mobile recorder and QC views.

No new dependency, scheduler, generalized trial engine or per-operation trial workflow is included.
