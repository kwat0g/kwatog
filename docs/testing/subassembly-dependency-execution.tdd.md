# Subassembly dependency execution — TDD evidence

## Source and journey

The journey was derived from the MRP/Production requirement: a manufactured
subassembly must be scheduled before its parent and the parent must not start
until the child has produced the required good quantity.

## Evidence

| Guarantee | Test / command | Result |
|---|---|---|
| Scheduler places child before parent | `tests/Feature/MRP/CapacityPlanningServiceTest.php::test_run_schedules_subassembly_children_before_their_parent` | PASS |
| Parent start is blocked while a child is short | `tests/Feature/Production/WorkOrderMachineConflictTest.php::test_parent_work_order_cannot_start_before_subassembly_child_is_ready` | PASS |
| Parent starts after required good quantity exists | `tests/Feature/Production/WorkOrderMachineConflictTest.php::test_parent_work_order_can_start_after_child_produces_required_good_quantity` | PASS |
| Focused dependency and machine lifecycle suite | `docker compose exec -T api vendor/bin/phpunit tests/Feature/MRP/CapacityPlanningServiceTest.php tests/Feature/Production/WorkOrderMachineConflictTest.php` | 14 tests, 26 assertions PASS |
| MRP regression suite | `docker compose exec -T api vendor/bin/phpunit tests/Feature/MRP` | 54 tests, 145 assertions PASS |
| CRM chain and Production regression suites | `docker compose exec -T api vendor/bin/phpunit tests/Feature/CRM/SalesOrderChainBridgeTest.php tests/Feature/Production` | 39 tests, 158 assertions PASS |
| API static analysis | `vendor/bin/phpstan analyse app/Modules/MRP app/Modules/Production --no-progress` | PASS |
| SPA typecheck and tests | `npm run typecheck && npm run test:run` | Typecheck PASS; 217 tests PASS |

## RED/GREEN checkpoints

- RED: `c265bf5f test: cover subassembly execution dependencies` — the
  scheduler put the high-priority parent first and the parent could start
  while its child was still short.
- GREEN: `0e7ad868 fix: enforce subassembly execution readiness` — dependency
  ordering, readiness validation, API payload, and work-order detail warning
  are implemented.

## Coverage and known gaps

`--coverage-text` was attempted. The focused suite passed, but PHPUnit returned
the warning `No code coverage driver available` in the API container. This is
an environment limitation; line coverage was not measurable.

The scheduler dependency graph covers the selected work-order set. A parent
whose child is already confirmed or running is still protected at execution by
the readiness gate; its exact schedule is represented by the child’s existing
schedule and should be revisited by the next planning run if dates change.
