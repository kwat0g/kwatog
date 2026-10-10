# Warehouse deep audit — 2026-10-05

Method: three reviewed scopes (root: operational handover, returns, scanner, frontend and evidence; GPT-6.1-Sol: stock controls and connected modules; GPT-5.6-Luna: receiving, master data and replenishment). Root checked both agents’ findings against source. One agent incorrectly reported that safety-stock recomputation had no scheduler; the nightly schedule at `api/routes/console.php:338` disproves that claim, and it is excluded below.

## Baseline result and scope

Warehouse has substantial transaction safeguards, but several paths disagree about lots, usable stock and physical delivery. Correct those before adding more automatic stock changes. The recommended approach is to improve the existing ledger and forms, preserve independent approvals, and automate preparation and exception reminders.

Audit covers Inventory Items, categories, UOM and conversions, warehouse structure, Goods Receipts, material issues, stock levels, movements, reservations, stock cards, adjustments, Warehouse Map, physical counts, transfers, picking, scanner, quarantine/MRB, replenishment, safety stock, ABC classification, receipt cost reviews, dashboard and their connected modules. Connected boundaries include Procurement and supplier receipts; Quality and returns; Sales/MRP/Production; outbound deliveries; Accounting; HR crew assignment.

At the baseline audit stage, no product fixes, deployments, live stock writes, live QC decisions or live handovers were performed. Documents and disposable test probes were added. Browser authentication creates ordinary login sessions; all business endpoint samples were GET requests. Scanner resolve was inspected and tested in the disposable database, because the apparently read-only scan operation writes a scan-event record.

### Baseline evidence

- Headless Chromium: **40 visits** to 20 Warehouse routes at **1440 × 960** and **390 × 960**, with no failed API responses or browser exceptions during those visits. Four additional live detail visits also loaded without failed API responses.
- Read-only live endpoint matrix: Warehouse, PPC, Finance and CRM accounts. Warehouse execution reads returned 200; unrelated roles returned 403; Finance could read the adjustment approval queue. This verifies those read boundaries, not every write permission.
- **17 existing tests / 81 assertions passed** for role boundaries, reservation/handover, count maker/checker and scanner.
- **8 audit probes / 16 assertions passed**, reproducing undesirable current behavior. Passing these probes means the defects were reproduced, **not fixed**.
- Full Inventory run was interrupted with exit 143 before all 404 tests completed. A broader focused run also stopped before producing its final summary. Neither is counted as a passing suite. A completed stop-on-error diagnostic ran 14 tests / 25 assertions and stopped on one setup error: MaterialReturnServiceTest confirms a WO without an eligible crew/recorder. This is a stale test fixture against the new crew requirement; it does not establish a broken return transaction.
- Impeccable detector completed successfully and emitted no findings for the two page directories. This does not invalidate source/browser findings: the detector does not check these domain rules or all component accessibility.
- Evidence: `docs/testing/evidence/warehouse-deep-20261005/`. Reproducible probe file, logs, read-only browser summaries and representative screenshots are preserved there.

### Severity and confidence

**22 findings: 11 P1 and 11 P2; 0 P0.** Nine P1 backend/chain findings and two P1 frontend findings. P1: meaningful integrity, access, task-completion or accessibility risk; address before continuing the full B2B workflow. P2: operational gap or usability friction with a workaround. No verified P0 outage. Source-confirmed findings are distinguished from disposable reproduction and live visual observations. Existing test success is not evidence that new cases are covered.

## Verified backend and chain findings

### W01 — P1: expired lots remain eligible for production issue

**Evidence:** `api/app/Modules/Inventory/Services/StockMovementService.php:459` sorts lots by expiry but does not reject an expiry date before the business date; the resulting issue is accepted at `:182`. `PickingListService.php:130` also orders expiry without excluding expired lots. Audit probe `test_expired_lot_can_still_be_issued` reproduces a successful issue from a lot expired yesterday.

**Impact:** FEFO can prioritize expired resin/material. “First expiry” is not equivalent to “still usable.” Availability and replenishment can overstate executable stock as well.

**Recommendation:** prevent expired lots from material issue and delivery at the central movement boundary; exclude them from normal allocation/picking. Preserve returns, quarantine, approved disposal and transfer into quarantine, since expired material still physically exists. Show expiry status and send a deduplicated owner alert. Use the configured business timezone and date, not the browser clock. Transfers for ordinary production stock should not make expired stock appear usable.

### W02 — P1: public manual quarantine cannot identify the suspect lot

**Evidence:** `Requests/StoreMrbRequest.php:39` omits `lot_number` and `expiry_date`; `Controllers/MrbController.php:68` passes only validated fields. `Services/QuarantineService.php:477` explicitly warns that a missing lot moves the bin’s preferred lot and expects the missing fields. The request-contract probe confirms they cannot survive validation. Automatic GRN rework at `QuarantineService.php:529` already passes the receipt lot correctly.

**Trigger:** one bin contains good lot A and defective lot B; inspector/Warehouse links a failed inspection but cannot select B. The transfer can quarantine A while leaving B available.

**Recommendation:** show source lots and their usable quantities; derive expiry from the selected lot. Validate linked inspection/NCR lineage against the lot, not only item and quantity. Automatically select only when one source lot is unambiguous. Do not claim the automatic GRN rework path has this same omission.

### W03 — P1: count records a lot but reconciliation adjusts another lot

**Evidence:** `StockCountService.php:202` saves the entered lot. `StockAdjustmentService.php:67` and `:247` omit it from the movement. Probe `test_count_records_B_but_adjusts_A_and_reports_quantity_as_value`: counter enters B; shortage movement uses A.

**Impact:** physical aggregate balance can become correct while lot traceability becomes false. An overage with a supplied lot becomes an unlotted receipt. Later MRB, returns and delivery use misleading balances.

**Recommendation:** keep the normal count at item/bin level; explain that scope. For a variance in a bin with multiple lots, require a compact allocation of the actual variance to lot(s), with quantities summing to the variance. One lot can be preselected. Do not treat one arbitrary optional lot field as proof of a full bin count. Carry the confirmed allocation through approval and posting; preserve independent counter, approver and completer.

### W04 — P1: Picking Lists can suggest a different lot after stock was already issued

**Evidence:** `PickingListService.php:49` derives “needed” quantity from an issued slip but recalculates suggestions from current stock at `:79`. `MaterialIssueService.php:316` already posts the original lot and location before the list is shown. Probe `test_picking_suggests_B_after_issue_was_posted_from_A` reproduces issued lot A followed by a suggestion to pick B. Live `MI-202610-0001` displays “No available stock at any location” alongside an existing issue and Record handover button.

**Impact:** workers can physically collect B while ledger and traceability say A, or stop because already-issued material appears unavailable. The advisory explanation does not make conflicting operational instructions safe.

**Recommendation:** for issued slips, show immutable **Issued from / Lot / Quantity / Handover** evidence. Reserve FEFO suggestions for preparation before the issue is committed. Display “Issued; awaiting handover” rather than “No available stock” for this stage. Rename navigation to “Material picking & handover” unless outbound customer picking is added separately.

### W05 — P1: availability differs between items, dashboard, alerts and replenishment

**Evidence:** `ItemService.php:75` attaches `StockLocationSummaryService::availability`, which excludes quarantine/scrap and Quality holds. In contrast, `AutoReplenishmentService.php:42` loads stock levels and reads the fallback `Item::$available`; `Models/Item.php:150` only subtracts reservations. `InventoryDashboardService.php:37` similarly sums quantity minus reserved. `AlertEngineService.php:307` evaluates physical on-hand. Probe `test_item_fallback_availability_counts_quarantine_as_available` reproduces 10 available versus 0 usable for the same quarantined stock.

**Impact:** a shortage can appear on an item record or block Production while low-stock automation/dashboards see sufficient material. Procurement may not be prompted. Physical totals and future supply remain useful but must be named separately.

**Recommendation:** reuse one usable-stock calculation for actionable shortages. Display On hand, Reserved, QC held, Quarantine/scrap, Expired, Usable now and Incoming separately. Replenishment may net valid future supply and open commitments, but should not treat known unusable stock as executable stock.

### W06 — P1: cancelling an issued slip automatically restocks handed-over material

**Evidence:** `MaterialIssueService.php:430` blocks cancellation only when a linked WO has recorded production. `:459` automatically posts remaining unused returns, without checking existing `MaterialHandover` evidence or requiring a physical return confirmation. Probe `test_cancelling_handed_over_unlinked_issue_restock_without_return_receipt` creates a handover, cancels the unlinked slip, and reproduces full restocking.

**Impact:** a cancelled record can make material available in Warehouse while it remains in Production. An unlinked issue has no WO output evidence to protect it. This is the clearest reason not to make unused-material returns fully automatic.

**Recommendation:** once a handover exists, require Warehouse to record actual returned quantities before cancellation. Cancellation can automatically finish after every return is reconciled. Keep the current safe reversal of never-handed-over material and existing source-cost/lot/retry guards. Treat missing historical handover evidence as unknown, not proof that nothing left Warehouse.

### W07 — P1: replenishment setup failures become silent no-ops

**Evidence:** `AutoReplenishmentService.php:87` returns null for missing automation actor; `:92` returns null for invalid order quantity/zero standard cost. `Listeners/CheckReorderPoint.php:16` discards the outcome. No failure reason is recorded there; the parent stock event can finish normally. Only successful PR creation emits `LowStockPrCreated`.

**Impact:** “stock movement completed” says nothing about whether replenishment was evaluated successfully. Operators cannot distinguish already-covered demand from missing configuration. Fixing a cost or actor does not itself invoke `checkAndReplenish`, whose only caller is the stock-movement listener.

**Recommendation:** record explicit healthy/no-action and blocked outcomes in existing operational alert/recovery infrastructure. Block reasons should name the item, missing cost/actor and owner. Retry after relevant setup is corrected or through a bounded reconciliation job. Preserve item locking and open-supply netting; no duplicate PRs.

### W08 — P1: scanner bypasses workflow-specific lookup restrictions and emits forbidden links

**Evidence:** `WarehouseScanController.php:48` restricts `/scan/records` by workflow permissions, but `:62` resolve is only guarded by `inventory.view`. `BarcodeScanResolverService.php:135` returns supplier PO metadata and receiving/navigation actions; `:89` returns WO/output actions; `:208` accepts caller-supplied numeric/hash count context. No actor is passed into the resolver. Existing `WarehouseScanTest.php:72` deliberately demonstrates receiving/picking suggestions to an inventory-only role. `scanner/index.tsx:202` navigates any returned href without checking its target permission.

**Impact:** a generic inventory reader can retrieve cross-module PO/WO metadata through barcode resolution and be offered actions that end in denied pages. No stock write bypass was established: destination APIs still enforce their permissions.

**Recommendation:** permission-filter both entity data and suggested actions. Validate supplied context ownership, eligible status and permission before resolving it. Preserve general item lookup for authorized inventory readers. Build no action that the role cannot actually perform.

### W09 — P2: WO movement filters omit manual issues and unused returns

**Evidence:** `StockMovementController.php:74` matches only direct WO and output references. Manual issues refer to `material_issue_slip`; unused returns refer to their original `stock_movement`. Search at `:79` has the same limited WO relationship support. `Models/StockMovement.php:64` resolves labels only for direct WO/output.

**Impact:** filtering by WO can look like it used no material when Warehouse issued a manual slip, and can hide the returned balance. Manual references still display internal-looking hashes.

**Recommendation:** resolve lineage for direct issue, MIS, output and original-source return with actual document numbers and authorized destinations. Include each exactly once in a WO query. Warehouse should see a Warehouse evidence page when it lacks permission for the production record.

### W10 — P2: count “variance value” sums quantities instead of money

**Evidence:** `StockCountService.php:323` adds absolute quantity to `variance_value`. The count probe reproduces quantity variance 2 and posted cost 200, but a session variance value of 2.00.

**Impact:** the API exposes misleading currency-like values and mixes kg, pieces and other units. The actual adjustment’s WAC value is already correct in the reproduced case.

**Recommendation:** sum authoritative posted adjustment values, with a clear absolute-variance-value label. Do not change actual movement valuation to match the wrong report.

### W11 — P1: usable aggregate stock cannot always be issued or transferred across lots

**Evidence:** `StockMovementService.php:495` selects a single lot; when no one lot can fulfill demand, it falls back to the first and then rejects. `StockTransferService.php:27` splits known lots but `:45` handles untracked stock only when no known lots exist. Two probes reproduce issue 8 failing with A=5/B=5 and transfer 10 failing with A=5/untracked=5.

**Impact:** aggregate availability promises stock that cannot complete the next process. Operators manually add multiple lines or move stock to work around the failure.

**Recommendation:** allocate one request across exact eligible lots using existing split-transfer patterns. Include a positively verified untracked remainder; never infer extra stock from stale lot projections. Keep lot-specific ledger rows, total quantity/cost conservation, QC/reservation guards and one transaction. Unknown lot stock may require traceability reconciliation before production use; the UI must explain that policy rather than reporting a generic shortage.

### W12 — P2: inline receiving can attribute an inspection to an arbitrary user

**Evidence:** `GoodsReceiptNoteController.php:245` accepts an inspector string; `GrnService.php:1827` decodes it and `:1845` selects any existing User for inspection creation. No active-Quality eligibility check occurs at this assignment. Terminal verdict permission is separately enforced at controller and service boundaries.

**Impact:** audit attribution can name an inactive/non-Quality inspector. This does not grant that user Quality permissions or bypass the actual terminal actor gate.

**Recommendation:** use the actual acting Quality user, or restrict delegation to active inspection-authorized users and record assigning actor separately. No unrestricted numeric-ID fallback for new contracts.

### W13 — P2: handover is Warehouse attestation, not recipient acknowledgement; exceptions disappear

**Evidence:** `MaterialHandoverController.php:71` records Warehouse as author and selected employee as recipient. It supports one full remaining-quantity handover per movement. `:103` prevents first-time recording after WO completion. `WorkOrderService.php:353` checks materials/crew but does not require handover evidence. No pending-material-handover detector was located in the inspected alert/chain checks.

**Impact:** an issued material can remain physically unconfirmed, Production can proceed, and completion can remove the opportunity to enter the missing evidence. New crew members can be selected for material originally issued for a different crew. Current receipt text should not imply the employee signed.

**Recommendation:** default recipient from the current WO recorder where eligible, show author explicitly, and prompt for all pending material handovers before Production starts. Use an exception workflow for historical/missing evidence instead of fabricating a retrospective timestamp. For current operations, use a readiness check with an explicit authorized exception, or an acknowledged pending state; decide which operational policy is desired before enforcing a hard gate. Partial handovers can be added only if the real warehouse delivers material in parts.

### W14 — P2: malformed stock-card location filter silently broadens the report

**Evidence:** `StockCardController.php:39` turns invalid HashID into null; `StockCardService.php:53` applies no location filter when null.

**Impact:** a bad or stale location link returns all locations without warning, misleading reconciliation. Endpoint does not leak outside the actor’s inventory permission, but the filter promise is broken.

**Recommendation:** validate/decode consistently with Stock Levels and return 422 for malformed selection; unknown location should not become all locations.

### W15 — P2: an existing return regression test cannot reach its intended scenario

**Evidence:** completed stop-on-error run: `MaterialReturnServiceTest.php:620` calls WO confirm without a crew; WorkOrderAssignmentService rejects it with “Assign a Production crew and one output recorder before confirming.” Fourteen tests ran before this setup error.

**Impact:** the response-loss/production-floor return scenario is not currently validated by that test. An interrupted full-suite run cannot establish release readiness.

**Recommendation:** repair fixtures with an eligible assigned crew and recorder through the existing assignment path; preserve the crew gate and original return assertions. Run serial targeted tests through a full summary.

## Frontend audit and polish recommendations

**Implementation integrity verdict: conditional fail.** Shared Atelier tokens, tables, panels, header and error states are coherent, but picking instructions and availability disagree with physical warehouse tasks. Fix truthful behavior before decorative polish.

### Technical UI health

| Dimension | Score / 4 | Evidence and limitation |
|---|---:|---|
| Accessibility | 2 | Shared focus/error/combobox semantics exist; material-issue line inputs lack accessible names and table selection labels are only 14 × 14 px. No screen-reader certification claimed. |
| Performance | 2 | Debounced shared filters exist, but MRB searches query on every keystroke; several forms fetch every item page; count/transfer lists are unbounded. No production load benchmark performed. |
| Responsive design | 2 | Most forms/list shells fit 390 px; Warehouse Map document measures 968 px at 390 px. Movement actions require long horizontal scrolling. Touch-device gestures not tested on physical hardware. |
| Theming | 3 | Warm light and dark tokens and semantic status styling exist. Dark scanner inspected; full dark-mode contrast and every state were not measured. |
| Implementation integrity | 2 | Design system is consistent, but domain instructions and role navigation have verified contradictions. Detector found no additional issues. |
| **Total** | **11 / 20** | **Acceptable foundation; significant fixes needed. Provisional audit score, not an accessibility certificate.** |

### U01 — P1: Warehouse Map expands the phone page horizontally

`spa/src/pages/warehouse/map.tsx:201` renders all warehouse names as one SegmentedControl. Live 12-warehouse page: document width 968 px at viewport 390 px. Screenshot `map-mobile.png` preserves the observation.

**Improve:** use the existing combined searchable selector for Warehouse; keep Map / Stock Count as a small segmented control. Stack zone selection and bin detail on mobile. Keep selected warehouse/zone visible and shareable in the URL.

### U02 — P2: movement actions and handover text overload the far-right column

`pages/inventory/movements/index.tsx:414` puts Record handover and Return unused into every issue row. At desktop width, labels wrap within the narrow right column; phones display only the first few columns until scrolled. Actions remain offered for legacy/terminal/ineligible issues until the modal rejects them.

**Improve:** keep default table columns to Date, Type, Item, Quantity, Reference and Warehouse status. Put costs/GL in optional columns or expandable details for roles needing them. Show a compact status plus one “Open” action; record handover/return from a contextual drawer with the original lot, location, issued/returned quantities and owner. Keep the full ledger accessible for Accounting. Do not hide failed handoffs.

### U03 — P1: material-issue line inputs have insufficient accessible names

`pages/inventory/material-issues/create.tsx:363` quantity input, `:372` UOM, `:382` remarks and the optional lot field have no label/ARIA name; a desktop grid heading is not an input association. Browser inspection confirmed unnamed quantity/lot/UOM/remarks inputs. Shared `Checkbox.tsx:15` creates a 14 × 14 px table selection target with a similarly small wrapping label.

**Improve:** associate every line input with a label including line/item context, even when the visible desktop label is shared. Use at least a 24 × 24 px target including padding; 44 px is a sensible shop-floor target. Do not report the intentional 1 × 1 px hidden native select inside the accessible custom combobox as a visible accessibility failure. The whole combobox input is clickable; its 16 px suffix alone is not proof of a failure.

### U04 — P2: MRB repeats separate search-plus-dropdown patterns without debounce

`pages/inventory/mrb/index.tsx:237` uses raw `itemSearch` in query keys; `:326` updates it on every keystroke. `:276` does the same for quality search. Separate Find item / Item and Find records / record dropdowns increase work.

**Improve:** use existing SearchableSelect with 300 ms debounce, preserve selected labels across result changes, retry failed lookups and reset invalid linked records when item/lot changes. Show quantity/UOM and eligible locations beside the selection.

### U05 — P2: large catalogs and transaction histories are loaded all at once

`pages/warehouse/transfers.tsx:46` and `pages/inventory/material-issues/create.tsx:54` fetch every item page concurrently before useful selection. `StockCountService.php:32`, `TransferOrderService.php:22` return full histories. `StockCardService.php:61` reads all selected movement rows and reconstructs the entire opening history.

**Improve:** searchable server lookups with bounded results, paginated count/transfer histories and scoped row filters. Keep running stock-card balances correct when paging; do not page raw rows before computing the opening balance. Measure queries before introducing cached balance projections.

### U06 — P2: map bin quantities combine unlike units without clear interpretation

`StockLocationSummaryService.php:58` sums quantities across items. `map.tsx:293` displays the combined number without UOM; mixed bins show no primary item.

**Improve:** mixed bins show “3 items” and a detail breakdown by item/UOM. Single-item bins show quantity with UOM. Never sum kg and pieces as a usable inventory total.

### U07 — P2: count-only users can encounter map requests and denied toggles

`routes/inventoryRoutes.tsx:174` allows the stock-count alias with `inventory.stock_count.view`; `map.tsx:83` fetches map unconditionally, whose backend requires warehouse management. `map.tsx:60` normalizes count navigation onto the more restricted warehouse-map route.

**Improve:** fetch map only in map view and when authorized; keep count-only route/toggle within its permission contract. This is source-confirmed for a count-only role, not observed as a failure for the live Warehouse role which has both permissions.

### Professional layout direction

Preserve the system’s warm Atelier palette, typography, PageHeader, Panel, DataTable, filters and modal components. Do not invent a separate Warehouse visual system.

- List pages: one primary action, useful search, compact status filters, clear refresh age, actionable errors and contextual empty states.
- Record pages: process/status and next owner at the top; operational details and line table in the main column; linked records and activity on the right; valuation below activity for roles needing it.
- Forms: compact line tables on desktop, readable item cards on mobile, locked source UOM by default, valid alternate UOM selectable only where conversion exists. Avoid free-text unit codes as the normal path.
- Receiving: choose PO once; load expected lines and remaining quantity; enter only actual receipt, lot and bin exceptions. Show Incoming QC / stock / bill / GL handoff statuses and authorized next action.
- Material issues: choose WO; use required remaining materials and reservation sources; show exact lots. Save stock issue once; handover records physical receipt without a second deduction.
- Counts: “Pending / Counted / Needs approval / Posted” filters, scanner deep links and clear independent approver/completer guidance. Do not force users to scan a full history list to find unfinished lines.
- Picking and handover: group by WO/slip, show exact committed material, default eligible recorder as receiver, confirm selected lines together.
- Quarantine: show affected lot, held quantity, linked inspection/NCR, allowed disposition and evidence still missing. Keep Warehouse custody distinct from Quality approval.
- Transfers: Source, Destination and quantity visible together; show permitted quantity before submission; an independent executor confirms actual movement.

## Buttons: what they mean and how far automation should go

The current label is **Record handover**, not “Return handover.”

| Action | Current effect | Safe automation | Human evidence still needed |
|---|---|---|---|
| Record handover | Saves who Warehouse says received the remaining issued material, quantity, author and time. No new stock movement. | Prefill eligible recorder, group same WO/slip lines, create pending task/reminder, one confirmation for selected lines. | Confirm material actually reached that recipient. An assigned employee or started WO is not proof of receipt. |
| Return unused | Posts a receipt against the original issue, increasing stock at its original source. Preserves lot, expiry and original cost; production floor and replay guards apply. | Prepare candidate balance, prefill original lot/bin/cost, selectable reason, prompt at WO completion/cancellation, post all confirmed return lines once. | Actual quantity physically returned and condition. Expected BOM usage is a limit/reference, not measurement of material left. |

Example: Warehouse issues 10 kg; Production records output whose saved BOM implies 8 kg. The remaining 2 kg may be unused, spilled, rejected or still beside the machine. The system can suggest review of 2 kg; it must not silently add 2 kg back to Warehouse.

Recommended normal path: reserved materials become a pending warehouse task; Warehouse confirms issue/pick from exact source; handover confirms receipt; Production records output; completion prompts reconciliation; Warehouse confirms actual unused returns; system performs cost/ledger and notification work. Existing automatic issue-on-start can remain during transition, but must be displayed truthfully as “issued, physical handover pending,” with exception handling.

Do not create a new WO for ordinary unused returns. Defective finished goods continue through Quality/NCR/MRB/rework, separate from raw-material returns.

## Automation opportunities

| Priority | Automation | Trigger | Owner / safeguard |
|---|---|---|---|
| P1 | Usable-stock and expired-lot exception checks | Receipt, hold/release, reservation, issue, transfer; bounded daily expiry sweep | Warehouse/Quality; no automatic scrap or physical transfer |
| P1 | Replenishment blocked-state tracking and retry | Low-stock check cannot proceed; relevant actor/cost setup corrected | Purchasing plus Inventory master-data owner; existing commitment netting and dedupe |
| P1 | Quarantine preparation from failed inspection | Failed batch with exact source lot/bin known | Quality selects disposition; Warehouse confirms physical custody |
| P2 | WO picking and handover task grouping | WO confirmed, manual issue posted or automatic issue generated | Warehouse; one visible task per WO/slip, source-specific lines |
| P2 | Unused-material reconciliation prompt | WO completion/cancellation with net issued quantity | Production/Warehouse; suggested balance only, physical return confirmed |
| P2 | Count task scheduling from ABC/risk | Configured due date, approved count scope | Warehouse; schedule draft, never fabricate count quantities or auto-approve variances |
| P2 | Bounded retry of transient QC/GL handoffs | Durable handoff failed transiently | Existing recovery/outbox; closed period, account mapping and Quality decisions remain manual-required |
| P2 | Receipt cost review and BOM refresh visibility | Finance approves changed standard cost | Existing implementation already uses ItemService to update cost; show result and responsible owner, preserve frozen WO cost |
| P2 | Warehouse task ageing | Pending handover, held stock, uncompleted count, pending transfer | Permission-aware in-app reminders; deduplicated until condition changes |
| P2 | Suggested putaway | Actual receiving item/quantity known | Use compatible active bins; Warehouse confirms destination and actual placement |

Already present: expected draft GRN after PO sent; Incoming QC generation and recovery; stock receipt after authorized QC; source-preserving return postings; reservation creation/release; draft low-stock PR and critical-item PO routing; receipt-cost approval and connected costing; nightly safety-stock recomputation; GL recovery state; count reconciliation after independent completion. Improve these contracts rather than duplicating them.

## Coverage matrix

| Feature | Existing controls confirmed | Remaining risk / recommended improvement |
|---|---|---|
| Items and categories | Type/category canonicalization, stock/history-aware edit/delete guards, BOM connections | Consistent usable stock; bounded lookups; category manager remains inside Items |
| UOM and conversions | Canonical inventory unit, historic conversion protections, base-unit receiving | Lock base unit; select only valid alternative conversions; no free-text normal flow |
| Warehouse / zones / bins | Stock/reservation-aware deletion and reclassification; blocked receiving | Mixed-unit map labels; phone warehouse selector; useful bin detail |
| GRN | PO-line matching, remaining receipt quantity, active receiving bins, per-line QC and maker/checker gates | Inspector attribution; receiving shortcut accessibility and compact line flow |
| Supplier evidence / cost reviews | COA reference protected from receiving self-verification; incoming pass listener; stale cost approval guards | Clarify whether QC checklist explicitly includes COA review; generic pass inference is policy, not document authenticity validation |
| Material issues | Source movement/lot link, reservation consumption, replay key, production usage reconciliation | Split lots; physical handover; cancellation cannot assume returned material |
| Reservations | WO/item/location/status queries, start checks ledger consistency | Historical quantity is not a full allocation event ledger; show remaining versus issued explicitly |
| Stock levels | Physical/reserved totals, immutable movement source and version | Show usable/held/expired quantities and align shortages everywhere |
| Movements | Real direct WO/output labels, pagination, GL status, unused-return replay guards | Manual-slip/return lineage; cramped actions; only offer eligible operations |
| Stock card | Running balance, WAC, movement lineage | Invalid location silently broadens; large-history performance |
| Adjustments | Independent approval, current locked WAC, no double approval | Explicit lot allocation for count reconciliation; clear reason and costs |
| Physical counts | Scope validation, draft snapshot refresh, freeze, independent actors, atomic completion | Lot attribution, variance currency, bounded histories and pending-line filters |
| Transfers | Locked execution/cancellation, creator cannot execute own order, known-lot split | Mixed known/untracked balance, compact searchable options, actual physical movement confirmation |
| Quarantine/MRB | Dedicated zone, linked evidence and disposition restrictions, source-linked automatic GRN rework | Manual suspect-lot selection; permission-aware Quality links |
| Picking | FEFO/FIFO preparation, active bins, exclusion of special zones | Stop recalculating issued source; group exact material handovers |
| Scanner | Recognition events, searchable eligible context records, pending-payload protection | Resolve data/actions/context permissions; expired and unsupported next steps |
| Dashboard and alerts | Batched main summaries, low-stock alerts and follow-up infrastructure | Usable-stock consistency; explicit owners and blocked automations |
| Replenishment / safety stock / ABC | Item locking, open supply/commitment netting, scheduled safety stock, explicit ABC recompute | Visible skip reasons and setup-change retry; ABC-driven count drafts optional |
| Outbound delivery connection | Exact approved output/lot reservation, physical dispatch authorization | Shared expiry/usable-stock policy; Warehouse read evidence without unrelated Production access |
| Customer/supplier returns | Source receipt lineage, cumulative quantity and quarantine controls | Keep defective returns separate from unused raw material; no automatic restock by cancellation |
| Accounting connection | Original return cost, WAC, frozen production value, durable GL review | Count variance report value; avoid duplicate journals on retries |
| HR / Production connection | Department/employee crew eligibility, recorder, shifts/presence checked at WO start | Handover author versus recipient signature; pending physical-material exceptions |

## Safeguards to preserve

Independent stock adjustment approval; count counter/approver/completer separation; transfer creator/executor separation; QC maker/checker; positive/base-unit quantity validation; item and stock-row locks; count freezes; source lot and cost lineage; idempotency and expected-returned guards; no issue of Quality-held stock; exact delivery reservation; source-cost return conservation; frozen historic WO valuation; retry only failed handoffs.

## Recommended sequence

1. Fix expiry, usable-stock consistency, suspect-lot quarantine and misleading count lot attribution.
2. Correct issued picking evidence, manual issue/return WO lineage and handed-over slip cancellation.
3. Make scanner permissions and replenishment exceptions explicit and recoverable.
4. Reduce manual work with grouped handover and suggested return reconciliation; preserve actual quantity confirmation.
5. Apply shared UI improvements: phone map, compact movement table, accessible line fields, combined debounced lookups and bounded histories.
6. Run Warehouse role-based transaction E2E in disposable data, then resume the user’s manual SO/MRP/WO/QC/delivery test.

Impeccable recommendations: `$impeccable harden` for permission/error and truthful state; `$impeccable adapt` for map/tables; `$impeccable clarify` for handover/return/usable-stock language; `$impeccable distill` for grouped actions and forms; `$impeccable optimize` for bounded lookups; finish with `$impeccable polish`. Re-run the audit after fixes. Execution plan: `docs/superpowers/plans/2026-10-05-warehouse-integrity-and-workflow-polish.md`.


## Approved implementation and verification — 2026-10-05

The approved Warehouse changes are implemented in the shared workspace. The nullable `stock_count_items.variance_allocations` migration was applied successfully to the running application. It preserves existing count rows and does not rewrite stock. Application sources are mounted into the running API and development frontend. All business mutations during verification used disposable databases; no live warehouse receipts, handovers, returns, counts or QC verdicts were fabricated.

### Finding closure

| Findings | Delivered behavior | Proof |
|---|---|---|
| W01, W05 | Material issue and delivery reject expired source lots. Expiry day remains valid in the business timezone. Dashboard, low-stock alerts and replenishment use the shared usable-stock calculation. Stock Levels shows usable, expired and QC-held quantities. | Expiry/location tests, dashboard batching, real stock-level UI assertions |
| W02 | Manual MRB selects the suspect lot, derives its expiry and verifies available source/linked Quality lineage. Ambiguous bins require selection. Retry keys cannot switch lots; pre-change retries without a lot remain compatible. | Public A/B lot regression and full MRB tests; independent real-browser hold/release |
| W03, W10 | Count variances preserve actual lot allocations, require their quantities to equal the discrepancy, and report posted currency value. Independent counter, checker and completer remain required. | Lot/count regressions, WAC/freeze tests, four-role real-browser count journey |
| W04 | Issued picking instructions use the original movement's bin, lot and quantity, rather than suggesting another current-stock lot. | Picking regression; grouped handover tests |
| W06 | Handed-over material cannot be restocked by cancelling a slip. Actual returns reconcile custody first; never-handed and fully returned cancellation paths remain supported. | Material return and custody regression tests |
| W07 | Missing automation actor, cost or quantity setup creates one durable blocked replenishment condition. Capable owners receive deduplicated in-app notices. A bounded nightly retry processes older blocked items without starving later records, resolves corrected conditions and preserves purchase deduplication. | Attribution, notification and reconciliation tests |
| W08 | Scanner resolution validates actor, context permission, target existence and workflow eligibility. Unrelated module metadata/actions are filtered. | Scanner and Warehouse role tests |
| W09 | Movement search and WO filters include direct WO issues, outputs, manual slips and source-linked unused returns. Warehouse evidence retains the readable WO/MIS number and authorized document link. | Movement reference/filter tests and browser reference checks |
| W11 | Multi-lot issues and transfers preserve one exact movement per source lot under a single transaction. Mixed known/untracked quantities produce an explicit reconciliation requirement. | Material issue/reservation, lot traceability and transfer race tests |
| W12 | Receiving inspector delegation requires an active authorized Quality account; signed-in author remains identifiable. | Receiving authorization tests; Warehouse, QC and independent Production browser journey |
| W13 | Grouped full handover confirms exact lines once, defaults an eligible recorder and identifies Warehouse as the recording actor. WO records show pending handover/unused-return review. Fully returned issues no longer produce false handover prompts. | Grouped handover, WO prompt and source-return regressions |
| W14 | Malformed stock-card location filters return validation errors. Public history is paginated while opening/closing balance and cost cover the whole requested period. | Stock-card service and invalid-filter regression tests |
| W15 | Stale return/output fixtures now provide actual equipment, crew, schedule and source material without weakening production guards. | Repaired return tests and role-based chain tests |
| U01, U06, U07 | Warehouse Map fits a 390px viewport, uses a combined warehouse picker, preserves warehouse/zone selection, distinguishes mixed-item bins and includes base units. Kilogram capacity is interpreted only for a single kilogram item. Count-only users make no restricted map requests. | Headless Chromium map/access tests; light/dark desktop and mobile screenshots |
| U02, U03, U04 | Compact movement columns keep costing/GL details available. Handover/return dialogs use source evidence. Issue fields have accessible line names and locked base units. MRB uses combined debounced item/Quality selectors with preserved selected labels. | Frontend tests and real mobile issue/transfer forms |
| U05 | Item selection uses bounded server search. Transfer/count histories and stock-card responses are paginated. Selected units/labels survive search changes. | API regressions, TypeScript and browser form assertions |

### Verification and evidence

- Actual-cookie headless Chromium completed five disposable phases: transfer maker/checker; Warehouse receiving, QC inspection and independent Production release; stock-count counter/checker/completer plus Finance approval; MRB maker/checker; 390px material-issue and transfer forms. Reports include expected rejected operations, unchanged stock/audit assertions, duplicate protection and no uncaught browser errors.
- A role-backed HTTP journey starts with a customer portal order, confirms it as Sales, verifies automatic MRP and WO creation, confirms staffing/equipment as PPC, issues and hands over material as Warehouse, starts/records production, submits QC evidence and receives actual unused material against its source. It passed **1 test / 29 assertions**.
- Full MRB and movement-reference files passed **24 tests / 118 assertions**, including regressions first observed failing for legacy retry compatibility and fully returned handover prompts.
- Lot/reservation/map files passed **17 tests / 77 assertions**. Count/card/reference checks passed **13 tests / 64 assertions**. Expiry/dashboard checks passed **9 tests / 24 assertions**. These are separate focused executions; counts are not presented as one complete suite.
- Frontend unit coverage passed **5 tests**. Six focused headless UI tests passed before the final Stock Levels addition; the final map/count-access/Stock Levels file passed **3 tests**. TypeScript, focused ESLint, PHP formatting and production build passed. The final build and downstream order-to-cash results are recorded in the completion addendum below.
- The original full Inventory run and interrupted later attempts are not counted as passing suites. Browser layout tests use API mocks; the five operational cookie phases use actual APIs and disposable PostgreSQL records. This is not a claim of a single uninterrupted customer-to-payment browser journey.
- Final reports, logs and representative screenshots: `docs/testing/evidence/warehouse-deep-20261005/final/`. The final runner is `scripts/inventory-warehouse-headless.cjs`; UI checks are `spa/e2e/warehouse-chain-integrity.spec.ts` plus the existing Warehouse operational/reference tests.

### Deliberate operational limits

Physical handover and actual unused return still require Warehouse confirmation. Grouping, recipient/source defaults, reason selection, review prompts, ledger/cost posting and replenishment recovery reduce input without inventing receipt of material. Partial handovers, mandatory production-start handover enforcement, recipient signatures and ABC-based count scheduling remain separate policy decisions; no automatic physical count or stock return was introduced.

Unknown or newly discovered lot balances require reconciliation before normal traceable fulfillment. Aggregate reservations without lot attribution can make usable stock conservative when expiry overlaps those reservations. Stock cards bound response size and memory but still scan the requested period to preserve the correct financial closing balance; very long periods may take longer. Desktop/mobile rendering was checked in Chromium; physical scanners, offline operation and recipient signatures were not hardware-tested.

Root reviewed delegated changes and corrected lock ordering, blocked-item retry starvation, null lot allocation, selected-label/unit persistence and handover prompt compatibility before completion.


### Final completion addendum

The final downstream `OrderToCashChainTest.php` file completed successfully: **5 tests / 369 assertions**. It covers role-based sales confirmation, production-backed delivery, dispatch, proof of delivery, invoice/collection reconciliation and negative authorization/replay/balance cases. The separately completed customer-to-Warehouse/Production/QC journey passed **1 test / 29 assertions**. Final Stock Levels/map/access browser verification passed **3 tests**. The final `npm run build` completed successfully after the Usable now UI addition; it includes TypeScript compilation and Vite production bundling. Focused PHP formatting and `git diff --check` passed. Successful logs are preserved in the final evidence folder.
