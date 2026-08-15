# Subassembly stock netting — TDD evidence

## Source and journey

No plan file was supplied. The journey was derived from the requested MRP
behavior:

> As a planner, I want available manufactured subassemblies to satisfy BOM
> demand before MRP creates child work orders, so that production and raw
> material requirements are not overstated.

## Evidence

| Guarantee | Test / command | Result |
|---|---|---|
| Full subassembly stock suppresses child production | `tests/Feature/MRP/SubassemblyWorkOrderTest.php::test_available_subassembly_stock_suppresses_child_production` | PASS |
| Partial stock reduces child quantity and downstream raw demand | `tests/Feature/MRP/SubassemblyWorkOrderTest.php::test_partial_subassembly_stock_reduces_child_and_raw_material_demand` | PASS |
| Existing hierarchy, rerun, and stale-child behavior remain valid | `docker compose exec -T api vendor/bin/phpunit tests/Feature/MRP/SubassemblyWorkOrderTest.php` | 5 tests, 22 assertions PASS |
| MRP, CRM chain, and production regressions remain valid | `docker compose exec -T api vendor/bin/phpunit tests/Feature/MRP tests/Feature/CRM/SalesOrderChainBridgeTest.php tests/Feature/Production` | 90 tests, 298 assertions PASS |
| Static analysis remains clean | `vendor/bin/phpstan analyse app/Modules/MRP app/Modules/Production --no-progress` | PASS |

## RED/GREEN checkpoints

- RED: `d57fc84a test: cover subassembly stock netting` — full stock still
  created a child WO and partial stock planned 20 instead of 15.
- GREEN: `48b26f3a fix: net subassembly stock in MRP` — focused hierarchy suite
  passed with 5 tests and 22 assertions.

## Coverage and known gaps

`--coverage-text` was attempted, but PHPUnit reported `No code coverage driver
available` in the API container. This is an environment limitation, not a test
failure. The implementation uses available stock plus in-transit supply from
non-quarantine/non-scrap locations and shares allocations across an all-SO MRP
run.
