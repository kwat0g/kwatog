# Stock Levels database repair — October 6, 2026

## Reported failure

The warehouse account received HTTP 500 from `GET /inventory/stock-levels` at `2026-10-06T17:19:50.431Z`. The application log identified PostgreSQL error `42703`: `mrb.return_request_item_id` did not exist. The shared stock availability service uses this link to calculate quality holds for customer returns.

## Repair

A full database backup was created before the repair, outside the repository with private file permissions. The existing migration `2026_10_05_000002_link_mrb_to_customer_return_items` was applied to the live database. No quality hold checks were bypassed and no business quantities were changed.

The migration ledger also listed `2026_10_03_000005_add_qc_recovery_quantity_snapshots` as pending, although both columns already existed. Their integer types, nullable flags, and null defaults were verified against the migration before reconciling its ledger entry. The migration was not rerun. Both migrations now show as applied.

## Verification

- A headless Playwright session authenticated as the existing warehouse account against the live application. The Stock Levels API returned HTTP 200, and the Reservations view displayed the work order without browser errors.
- `WO-202610-0014` retained reservations for `RM-001` (1.890 kg), `RM-011` (0.204 kg), and `PKG-001` (100 pieces).
- Four focused backend test files passed: `WarehouseMovementReferenceTest`, `StockLevelOptimisticLockTest`, `WarehouseReservationHandoverTest`, and `CustomerReturnRestockOnDisposeTest`. Total: 25 tests and 226 assertions.
- The added Stock Levels regression verifies that production stock without passing QC evidence remains held: 10 physical units, 10 QC-held units, and zero usable units.

The manual B2B test can resume at the warehouse reservation verification step. Production has not been started by this repair.
