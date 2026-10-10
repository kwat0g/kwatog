# Maintenance gap closure release record

This release adds required passing work results, work-record links for new part issues, part reconciliation before handoff, consistent assignment and production-ownership guards, and accurately labeled downtime and list measures. Historical work orders keep their existing verification mode, work history, stock movements, and cost.

## Pre-release proof

The live database backup was captured at `/tmp/maintenance-gap-20261009/pre-release.dump` as a PostgreSQL custom archive (4,395,888 bytes, mode 0600; SHA-256 `5b0a3f494102be0ae02990a703e440649d75df2c38722613841a7e89cc0020e3`). Keep it through the normal backup retention process; this temporary path alone is not long-term storage.

The backup restored with `pg_restore --exit-on-error` into `ogami_test_maintenancestage`. Only the two new Maintenance migrations were pending. `migrate --pretend` showed two nullable work-order columns, one nullable part-usage column, foreign keys, and the part-usage index. The migrations ran on the restored live snapshot in 15.06 ms and 7.52 ms. All 8 existing work orders and 1 part-usage row had the same ID/status/cost and ID/quantity/total-cost checksums as live. All new fields remained null in historical rows. The restored copy is isolated from the live `ogami` database.

The final isolated API suite passed 158 tests and 786 assertions, including the connected non-administrator role sequence and the reassignment regression. The Maintenance SPA suite passed 26 tests. TypeScript typecheck and the production build passed.

## Rollout state

At 2026-10-09 23:31 UTC, both additive migrations were applied to live `ogami` (18.44 ms and 8.51 ms). Compatible Maintenance API files were installed and the API container restarted. SPA source files were then installed in the live Vite-served checkout. The public work-order page returned HTTP 200; the new `PartReconciliationPanel.tsx` module returned HTTP 200 with `text/javascript`; unauthenticated work-order and reconciliation requests returned HTTP 401. The new route was present with `permission_any:maintenance.wo.complete,maintenance.wo.assign`. The live database still had 8 work orders and 1 spare-part usage row, with the same status/cost and quantity/total-cost checksums as the pre-release backup. New columns were null for historical rows. `MWO-202610-0008` remained in progress, legacy mode, with cost 24.00.

`maintenance.closed_loop.enabled` remains `false`. No live business work order, assignment, attendance, part movement, or repair evidence was created or changed for this release. The live role scenario is pending because the last observed technicians' covered night shift ended at 06:00 Asia/Manila; the audit occurred at 06:40. Recheck the real employee, shift, attendance, target ownership, and stock immediately before any live work order. Do not add synthetic attendance or a fabricated repair to satisfy this test. Keep the new-order switch off until a fresh role scenario completes; enabling it afterward affects only newly created orders.

A second read-only eligibility check at 08:01 Asia/Manila found Josefa and Rafael still on 22:00–06:00 shifts and Juan without an active shift assignment. Current day-shift presence remained unverified, so no live Start action was attempted.

Rollback: turn off the new-order switch, restore the backed-up source files, and restart the affected services. Leave additive nullable columns and genuine audit/stock history in place; dropping them would discard evidence. Restore the database archive only for a separately justified database recovery, because restoring it would overwrite legitimate intervening transactions.
