# M040 — Warehouse & Stock Control Fix Log

Audit session: 2026-08-25  
Claimed with: `audit/scripts/claim-module.sh inventory warehouse-stock-control`

## Re-audit work

- Re-ran scoped discovery → hardening → polish because Inventory files changed after the prior report and directly affected its findings.
- Rebuilt [audit-report.md](audit-report.md) and [action-plan.md](action-plan.md) against the current working tree.

## Production fixes

None in this session. The application changes referenced by the re-audit pre-date this claim; this session changed only the M040 audit artifacts.

## Deferred plan items and blockers

- Item 1 is pending a human decision on authoritative lot balances/projection and mixed-lot allocation. Implementing without that decision could make the map, transfers, and picking disagree.
- Items 2 and 6 are policy-dependent: empty/unexpected bins, monetary versus quantity variance, approval segregation, and picking reservation versus material issue.
- Items 3–9 remain pending because the plan must be worked in order and item 1 is blocked. See [action-plan.md](action-plan.md) for the complete ordered list; no out-of-order production patch was made.

## Verification record

Command:

```text
cd /home/kwat0g/Desktop/kwatog/api && php artisan test --compact --filter='(WarehouseMapHashBindingTest|WarehouseScanTest|TransferOrderRaceRegressionTest|StockCountCancelRegressionTest|StockCountMovementFreezeTest|CycleCountWacTest|StockAdjustmentReasonTest|StockAdjustmentDoubleApproveRaceTest|StockLevelOptimisticLockTest|ZoneGuardTest)'
```

Outcome: exit code 2; 42 tests failed before assertions because PostgreSQL host `db` could not resolve (`SQLSTATE[08006]`). PHPUnit also emitted doc-comment metadata deprecation warnings. With no production fixes made in this session, there were no fixes to re-check against the unavailable database.

Intended release status: `🔁 Needs Re-audit`.
