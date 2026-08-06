# ## INVENTORY MODULE AUDIT PROMPT

Paste into Claude Code. Scope: Chain 1 (stock for production) + Chain 2 (receiving, GRN, warehouse).

> Audit the **Inventory module** of Ogami ERP at `/home/kwat0g/Desktop/kwatog`. Read `CLAUDE.md` first.
>
> **Bucket 1 — Weighted-average cost integrity (P0).** Tear through the `Item`, `StockLevel`, `GoodsReceiptNote`, `StockMovement`, and `MaterialIssueSlip` models and services. Find every path that changes inventory value. Build the real cost-recalculation table. Is there any route an item's value can change without a WAC recompute? Any path where a negative quantity or zero-cost receipt can corrupt the running average? Any batch/concurrency issue where two receipts near-simultaneously read the same old cost?
>
> **Bucket 2 — Stuck process states.** GRN lifecycle: `pending_qc → accepted | partial_accepted | rejected | pending_qc`. Is there any QC hold that has no resolution handler? Any GRN that can enter `pending_qc` but never get checked because the QC user lacks a route, a permission, or a UI action?
>
> **Bucket 3 — Reservation / allocation correctness.** `material_reservations` vs `stock_levels.reserved_quantity`. Find every place reserved_quantity is incremented and decremented. Is there any path where a WO cancellation, PR deletion, or PO line change fails to release the reservation? Any negative reserved_quantity possible?
>
> **Bucket 4 — Physical inventory / stock count.** `stock_count_sessions` and `stock_count_items`. Can an adjustment from a count session create a stock movement without a corresponding journal entry? Can a count be applied twice?
>
> **Bucket 5 — Transfers and inter-warehouse moves.** `transfer_orders`. Can goods arrive without the outbound side releasing? Can they be lost between "shipped" and "received"?
>
> **Bucket 6 — Missing features (blocking only).** Only report absence of something that *stops a production line*: e.g. no way to issue material to a WO, no way to receive a partial shipment, no way to scrap material. NOT BUILDING list in CLAUDE.md is out of scope.
>
> **Evidence rules:** Every finding cites file:line with verbatim quote. "Missing" claimed only after 3+ searches under different names. Adversarial verify: re-open the file, hunt for the guard the first pass missed.
>
> **Deliverable:** `docs/AUDIT-INVENTORY-<date>.md` with findings ranked P0→P3, stuck-process table, and a test-count section.

---

# ## PURCHASING MODULE AUDIT PROMPT

Paste into Claude Code. Scope: Chain 2 — PR → Approval → PO → Supplier → Shipment → GRN → QC → Bill → Payment.

> Audit the **Purchasing module** of Ogami ERP at `/home/kwat0g/Desktop/kwatog`. Read `CLAUDE.md` first.
>
> **Bucket 1 — Approval workflow integrity (P0).** The chain has multiple approval steps (PR approval, PO approval). Build the real transition table for each. Can a PR skip its approval? Can a PO be sent to a supplier while in `draft`? Can a user approve their own PR/PO? Is there any path where an approver delegates but the delegation isn't checked?
>
> **Bucket 2 — 3-way match correctness.** `PurchaseOrder` ↔ `GoodsReceiptNote` ↔ `Bill`. Find every place quantities and prices are compared. What happens when received quantity > ordered? Billed > received? Billed > ordered? Is there any path where overbilling is paid without triggering a flag? Any path where a mismatch is created by rounding?
>
> **Bucket 3 — Stuck process states.** Can a PO be stuck in `pending_approval` because the approver role has no user? Can a GRN sit in `pending_qc` indefinitely? Can a bill be received but never scheduled for payment?
>
> **Bucket 4 — Supplier performance recalculation.** `supplier_performance_snapshots`. Find the cron or event that triggers recalculation. Can performance data go stale? Can a single supplier's snapshot fail without alerting anyone?
>
> **Bucket 5 — Cancellation / correction paths.** Can a PO be cancelled after partial receipt? What happens to linked GRNs and bills? Can a PR be deleted after a PO is created from it?
>
> **Bucket 6 — Security / authorization.** Every purchasing route, check middleware: can a supplier (B2B portal) see other suppliers' POs? Can an employee create a PO outside their department scope?
>
> **Evidence rules:** File:line with verbatim quote. 3+ searches before claiming missing. Adversarial verify.
>
> **Deliverable:** `docs/AUDIT-PURCHASING-<date>.md` with findings ranked P0→P3 and a stuck-process table.

---

# ## PRODUCTION MODULE AUDIT PROMPT

Paste into Claude Code. Scope: Chain 1 — Work Orders → Output Recording → OEE → Mold Shots → Downtime → In-Process QC.

> Audit the **Production module** of Ogami ERP at `/home/kwat0g/Desktop/kwatog`. Read `CLAUDE.md` first.
>
> **Bucket 1 — Work Order lifecycle (P0).** Build the complete WO state machine from code. Can a WO be closed without all output recorded? Can material be issued to a WO that hasn't started? Can a WO be created without a valid BOM/routing? What happens when actual output exceeds planned quantity?
>
> **Bucket 2 — OEE calculation correctness.** `production_logs`, `machine_downtimes`. Find every field that feeds into OEE. Is availability calculated from planned vs actual runtime? Are changeovers counted as downtime or setup? Any division by zero when planned_time = 0? Any race condition where a running WO and a downtime event overlap and double-count?
>
> **Bucket 3 — Mold shot tracking.** `molds.shot_count`, auto-increment on output recording. Can shots be recorded without a WO? Can the counter be reset or rolled back? What happens at 80% / 100% of max — is there an alert, and does it reach anyone?
>
> **Bucket 4 — In-process QC integration.** WOs should trigger periodic inspection. Is there any enforcement that QC checks happen at defined intervals or quantities? Can output be recorded without the required QC step?
>
> **Bucket 5 — Scrap / rework handling.** `work_order_outputs`, `work_order_defects`. Can scrap be recorded without a corresponding material issue? Can rework be created without a new WO? What's the GL impact of scrap — is there a journal entry?
>
> **Bucket 6 — Scheduling / MRP II integration.** `production_schedules`, `mrp_plans`. Does rescheduling a WO cascade to dependent WOs? Does MRP re-plan trigger a notification?
>
> **Evidence rules:** File:line with verbatim quote. 3+ searches before claiming missing. Adversarial verify.
>
> **Deliverable:** `docs/AUDIT-PRODUCTION-<date>.md` with findings ranked P0→P3 and a stuck-process table.

---

# ## QUALITY MODULE AUDIT PROMPT

Paste into Claude Code. Scope: All 4 IATF 16949 touchpoints + NCR feedback loop + CoC + SPC + COPQ.

> Audit the **Quality module** of Ogami ERP at `/home/kwat0g/Desktop/kwatog`. Read `CLAUDE.md` first — quality is the thesis differentiator and is woven through all 3 chains.
>
> **Bucket 1 — Inspection spec integrity (P0).** `inspection_specs` and `inspection_spec_items` define tolerances. Can a spec be changed after inspections have been recorded against it? Can an inspection be recorded against a spec that's been deleted (soft or hard)? What happens when spec tolerances are unitless — does the pass/fail engine default to a wrong comparator?
>
> **Bucket 2 — Measurement recording and pass/fail logic (P0).** `inspection_measurements` and `InspectionsService::recordMeasurements()`. Find the exact code that compares measured_value against spec min/max. Can floating-point precision cause a borderline value to pass when it should fail, or vice versa? Can measurements be edited or deleted after the NCR is created?
>
> **Bucket 3 — NCR lifecycle and the feedback loop.** `NonConformanceReport` → `ncr_actions`. What statuses exist? Can an NCR be closed without corrective action? Can a replacement WO be auto-generated for a scrap NCR? Do NCRs ever enter a state with no valid transition out?
>
> **Bucket 4 — CoC (Certificate of Conformance) generation.** Is the CoC auto-generated from inspection data on shipment? Are all measured values included, or only critical dimensions? Can a shipment proceed without a CoC?
>
> **Bucket 5 — SPC (Statistical Process Control).** `spc_control_charts`, `spc_data_points`, `spc_alerts`. Do SPC rules fire on every new data point or only batch? Are control limits recalculated or static? Any alert that has no UI surface?
>
> **Bucket 6 — COPQ (Cost of Poor Quality).** `copq_snapshots`, monthly cron. What costs are included (scrap, rework, inspection, returns, warranty)? Are costs double-counted across scrap/rework/NCR paths? Can a P0 finding in NCR be missed by the COPQ snapshot?
>
> **Bucket 7 — Missing features (blocking only).** Only report if it stops a QC workflow: e.g. no way to record a visual/functional pass/fail, no way to print a CoC, no way to link an NCR to the original WO. NOT BUILDING list in CLAUDE.md is out of scope.
>
> **Evidence rules:** File:line with verbatim quote. 3+ searches before claiming missing. Adversarial verify. Test hypotheses with `docker compose exec -T api php artisan test --filter=X`.
>
> **Deliverable:** `docs/AUDIT-QUALITY-<date>.md` with findings ranked P0→P3, a stuck-process table, and a coverage section stating what was not audited.