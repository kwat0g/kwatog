# Warehouse audit and interface improvements

Date: 2026-10-05

## Outcome

Warehouse movements now display the actual work-order number instead of `work_order #<hash>`. Production-output references resolve to their parent work order. The same reference handling is used by stock cards, and picking lists display the linked work-order number.

This pass fixes demonstrated search, navigation, recovery, and presentation problems. It preserves the existing inventory movement, reservation, valuation, approval, and Quality controls. No schema migration or live inventory mutation was performed.

## Feature coverage

| Feature | Findings and changes | Existing controls reviewed |
| --- | --- | --- |
| Inventory Items | Summary counts describe the current page accurately. Filtered empty states distinguish no matches from an empty catalog. | Item classification, base-unit restrictions, and historical unit integrity. |
| Units of Measure | Existing loading errors already offer Retry; no unnecessary redesign. | Referenced units cannot be renamed or deleted. Conversion factors used by purchasing documents, receipts, or material issues cannot be changed or removed. |
| Goods Receipts | Empty states reflect search, status, and date filters; failed reads remain visible and recoverable. | Purchase-order line matching, receiving location eligibility, incoming Quality inspection gates, and purchase-to-base-unit conversion. |
| Material Issues | Search initializes from URL filters. Failed reads identify material issues explicitly. Empty states describe the applied filters. | Work-order reservation linkage, protection of other reservations, replay protection, and cancellation accounting. |
| Material Returns | Return dialogs identify their source clearly. Opening a return is disabled while movement data is unavailable or refreshing. | Original issue cost and lot preservation, maximum return balances, recorded production floors, and idempotent replay. |
| Quarantine Holds / MRB | Labeled status filter and responsive filter layout. Location lookup failures have Retry. Submission is disabled after failed item/location lookups. | Quarantine/scrap stock cannot be consumed or transferred out through an ordinary stock movement; release requires the controlled MRB path. |
| Stock Levels | Item search now filters the backend results. Filters and the Stock levels / Movements switch stay synchronized with the URL. Quantities show units. “Available” is renamed “Unreserved,” with an explicit Quality/blocked-location caveat. | Reservation arithmetic remains unchanged. Invalid item/warehouse filters return validation errors instead of broadening the query. |
| Stock Movements | Readable direct-WO and output-parent references. Debounced search supports item code/name, WO number, lot, and notes. Date filters, units, and readable return context. Table scrolls inside its container on narrow screens. | Hash IDs remain the public identifiers. Pagination is bounded and ordering deterministic. Invalid ID/date filters are rejected. WO links appear only for users with the required Production permissions. |
| Stock Cards | Same readable WO references, including output parent links; existing non-WO trace links are retained. | Ledger cost calculations and internal-transfer balance behavior are unchanged and covered by focused tests. |
| Stock Adjustments | Clearer Direction and Quantity headings and filtered empty states. Existing requester/approver disclosure and role-gated actions remain. | Pending adjustments, reason requirements, Finance approval, and protected stock/reservation floors. |
| Warehouse Map / Structure | Existing map, bin-detail recovery, and canonical navigation reviewed. No arbitrary visual redesign. | Stock/reservations block destructive warehouse/location changes; inactive parents and pending adjustments restrict structure changes. |
| Stock Counts | A scanner deep link can open a selected count even when the session list is empty or unavailable. Count actions and confirmation dialogs are disabled while the selected session is stale or unavailable. Responsive list/detail columns fixed. | Count scope, movement freeze, variance approval, and separation of counter/checker duties. |
| Inventory Transfers | Clearer read errors, location retry, and disabled stale-detail actions and confirmations. Responsive list/detail columns fixed. | Existing creator/executor separation, transactional status checks, source stock restrictions, and duplicate execution protection remain. |
| Order Picking | Readable WO context, pagination beyond the first 50 issued slips, honest list counts, and responsive columns. “Record picks” is renamed “Open material issue” to describe the existing action accurately. Restricted users no longer request the material-issue list or receive an enabled link to that surface. | FEFO suggestions already cap stock by lot/location availability and account for reservations and Quality holds. No new picking lifecycle was invented. |
| Warehouse Scanner | A pending lookup retains its submitted barcode/context. Inputs cannot change during the request. Failed lookups preserve input and show the server's reason. | Existing role-sensitive suggested actions, context validation, and record binding. |

“Reviewed” means source and existing coverage were inspected. The focused execution below does not prove every branch in every Warehouse lifecycle.

## Recommendations from the initial audit

The follow-up below implements reservation breakdowns, physical handover recording, and searchable Scanner context records. The notes in this section describe the state at the initial audit; see the follow-up for current behavior.

1. **Reservation totals are not a WO-specific explanation.** Stock Levels combines reservations from multiple orders. A compact breakdown by WO, location, quantity, and reservation status would make it easier for Warehouse to verify a particular confirmed WO. A reservation is not a stock movement or proof of physical issuance. The new movement `work_order_id` filter covers direct WO and production-output references; it is not a replacement for a full reservation breakdown.
2. **Unreserved stock is not always usable stock.** Quality holds and blocked locations affect physical availability. This pass fixes the misleading column name and adds guidance. A future usable-stock column should reuse the central hold/location rules, including those used by replenishment, rather than introduce another availability calculation.
3. **Picking is currently advisory.** The page shows suggestions for issued material slips and opens the material-issue record. It does not record a separate physical pick confirmation or handover. If physical accountability is required, define one small confirmation step and its owner before adding statuses. Do not treat the existing page as proof that goods were physically picked.
4. **Scanner context entry still requires a record ID.** A searchable record selector or scanning the document barcode would be easier for normal users than copying an ID. Existing record binding stays in place; no unrestricted generic lookup was added.
5. **Item summary cards are page summaries.** Labels now make this accurate. If management needs catalog-wide figures, use a dedicated aggregate response instead of counting a paginated list.

These are recommendations, not newly reproduced backend corruption bugs. Earlier concerns about changing historical UOM factors, consuming quarantine stock, and exceeding a lot through picking were checked against the current implementation and were already guarded.

## Validation

Backend tests ran in the disposable database configured by `api/phpunit.pricing.20261004.xml`, not against live inventory.

```bash
docker exec ogami-api php -d memory_limit=-1 vendor/bin/phpunit \
  -c phpunit.pricing.20261004.xml \
  --filter 'WarehouseMovementReferenceTest|StockCardServiceTest|WarehouseRoleBoundaryTest|WarehouseStockControlHttpContractTest|ZoneGuardTest'
```

Result: **22 tests, 113 assertions passed**. Coverage includes the new reference/search behavior, Warehouse/Purchasing/Finance read boundaries, stock-control HTTP validation, stock-card valuation, and quarantine/scrap movement restrictions.

Frontend regression tests: **3 tests in 2 files passed** (`picking.test.tsx`, `scanner/index.test.tsx`).

Headless Chromium: **5 focused scenarios passed**, covering count deep links with an empty list, count read recovery, transfer read recovery, readable WO references/debounced search/mobile overflow, and stock search/Quality guidance. The transfer scenario was rerun successfully after the final responsive layout and confirmation changes.

Live read-only browser check: logged in as the Warehouse role and opened **13 Warehouse routes** (Items, UOM, GRN, Material Issues, MRB, Stock Levels, Movements, Adjustments, Map, Counts, Transfers, Picking, Scanner). All rendered without an error boundary or failed API read. Live WO and production-output references displayed readable parent numbers. No goods receipt, issue, return, transfer, adjustment, or count was submitted by this check.

Targeted ESLint, Laravel Pint, and `git diff --check` passed. The final `npm run build` passed both TypeScript and Vite after the transfer changes.

Both delegated reviews were inspected by the primary agent. Review caught and corrected selected-count rendering/type errors and responsive transfer/count/picking column spans; stale-detail confirmation protection was checked as well.

This is **not a complete live warehouse transaction E2E**. Physical warehouse operations and every concurrency branch still require their normal dedicated lifecycle tests and operational testing.

## Manual check for the current B2B test

1. Under the Warehouse account, open Stock Levels and search each BOM component for `WO-202610-0011`.
2. Treat Reserved as a combined total across orders, not the quantity exclusively allocated to this WO. Confirm the WO's reservation through its material/reservation context.
3. Open Movements and search `WO-202610-0011`. Issued material and production output appear only after the corresponding movement has actually been posted. A missing movement by itself does not mean the reservation failed.
4. Check source location, quantity, unit, and GL handoff state when a material issue is posted. The WO reference is now readable; its link remains subject to the account's Production access.
5. Keep physical material issuance and production output separate: reserving material does not prove Warehouse handed it over, and issuing material does not prove finished goods were produced.

## Approved operational follow-up

The three approved improvements are now implemented:

- **Material reservations:** Stock Levels has a Reservations view, searchable by WO or material, with location, quantity/unit, status, and reservation time. Reserved is the remaining allocation; Issued and Released are historical reservation rows. The WO Materials card links to its exact `work_order_id` scope. Pagination and validation prevent invalid IDs from silently widening that scope.
- **Physical handover:** Order Picking and Stock Movements can record the recipient of an existing material-issue movement. Warehouse chooses an active assigned Production worker for a linked WO and confirms the unreturned issue quantity. The system records Warehouse author, recipient name snapshot, quantity, and server time. Unlinked slips permit active Production employees with employee accounts. This is a Warehouse attestation of physical handover, not a second employee login or recipient acknowledgment.
- **Scanner records:** Context entry uses the shared searchable selector for WO, GRN, material-slip, or stock-count numbers, with 300 ms search debounce. The endpoint returns at most 25 eligible records and enforces context permissions. Paused WOs are eligible for material issuance. General item, bin, and PO barcode lookup remains available.

Handover does **not** post a second movement, change inventory cost, or add a production-start gate. It applies to automatic WO issue movements and manual material-slip issue movements. A unique record per source movement and the existing WO/slip/source lock order prevent duplicate receipt records. Identical retries return the existing record; conflicting recipient/quantity retries return HTTP 409. Returned quantities and terminal WO/slip state are rechecked before the first receipt is recorded. Subsequent returns preserve the original handover as historical evidence.

This deliberately supports one complete remaining-quantity handover per issue movement. Use separate issue movements for distinct handovers; partial receipt tracking and independent recipient acknowledgment are not part of this workflow.

### Schema and rollout

`2026_10_05_230000_create_material_handovers.php` adds one table with restrictive source/employee/user foreign keys. Existing inventory records are preserved; older application code ignores the new table. The targeted migration was applied successfully, and the live table initially contained zero handovers. Rollback removes only this new receipt table and therefore discards receipt history; keep it during an application rollback when that history exists. No existing stock table or lifecycle status was changed.

### Follow-up validation

```bash
docker exec ogami-api php -d memory_limit=-1 vendor/bin/phpunit \
  -c phpunit.pricing.20261004.xml \
  --filter 'WarehouseReservationHandoverTest|WarehouseScanTest|WarehouseMovementReferenceTest|WorkOrderMaterialIssueHandoffTest'
```

Result: **24 tests, 152 assertions passed** in the disposable database. They cover receipt replay/conflicts, no extra stock movement, active assigned recipients, returned quantities, cancelled manual slips, read scope, role boundaries, Scanner context eligibility, and the existing manual/automatic WO material accounting.

Frontend: **5 tests in 3 files passed**. Headless Chromium: **2 follow-up scenarios passed**, including reservation-to-movement navigation, recipient handover, searchable Scanner context, and desktop/mobile overflow checks.

Final TypeScript/Vite production build, focused ESLint, Laravel Pint (15 touched PHP files), and `git diff --check` passed. Both delegated contributions were reviewed; the primary review aligned paused-WO eligibility, validated manual-slip coverage, and checked handover retry and quantity behavior.

Live read-only Warehouse login opened Reservations, Movements, Picking, and Scanner with no failed API reads. The live reservation and Scanner record endpoints returned HTTP 200. No physical business transaction was submitted by the live check; receipt writes were exercised in disposable backend tests and mocked browser scenarios.

### Current manual test

1. Open the confirmed WO's Materials card and choose **View reservations**, or open Warehouse Stock Levels, choose **Reservations**, and search its WO number.
2. Review allocated material, location, quantity, and status. A Reserved row represents allocation, not physical receipt.
3. Once a manual material issue or the automatic WO-start issue is posted, open its **Order Picking** line or **Stock Movements** row and choose **Record handover**.
4. Choose the assigned Production worker who physically received the complete remaining issued quantity and select **Confirm handover**. Recipient, quantity, Warehouse author, and time remain visible in the receipt record.
5. In Scanner, choose the workflow, search/select its document number, and scan the material/bin barcode. No copying internal record IDs is required.
