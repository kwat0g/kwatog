# Automatic MRP replanning — TDD evidence

## Scope

- Queue one automatic MRP + finite-capacity scheduling job per affected sales-order scope.
- Trigger it after sales-order confirmation, inventory movement completion, and BOM changes.
- Replan parent demand when a subassembly or consumed item changes.
- Preserve manual MRP execution as a recovery path.
- Surface material shortages, schedule conflicts, MRP run failures, and per-order BOM/data errors as alerts.

## RED checkpoint

- `1ada8445 test: cover automatic MRP replanning`
- The new five-test suite initially failed because the event, job, scope resolver, automation service, and automatic trigger did not exist.

## GREEN evidence

- `tests/Feature/MRP/MrpAutomationTest.php`: 6 tests, 13 assertions.
- MRP + Production + SO-chain regressions: 100 tests, 320 assertions.
- SPA test suite: 30 files, 217 tests passed.
- SPA TypeScript: `npm run typecheck` passed.
- Targeted PHPStan analysis of all changed API files: no errors.

## Run-status visibility evidence

- `spa/src/components/mrp/MrpRunStatusPanel.test.tsx`: 2 tests cover automatic trigger context, trigger reason, shortages, scheduling conflicts, and failed-run errors.
- MRP plans now polls the latest run while it is running, refreshes recent run history, and shows the existing manual trigger as the recovery action.
- SPA regression after the status-panel change: 31 files, 219 tests passed; `npm run typecheck` and `npm run lint` passed.
- Production bundle passed with `npx vite build --outDir /tmp/ogami-spa-dist-codex`; the normal `npm run build` reached bundle generation but could not replace the root-owned `spa/dist/assets` directory left by an earlier container build.

## Coverage

`phpunit --coverage-text tests/Feature/MRP/MrpAutomationTest.php` passed the tests but PHPUnit reported that no code-coverage driver is installed in the container.

## Known baseline verification issue

Full `phpstan analyse app` still reports one unrelated pre-existing error in `Console/Commands/CheckRecruitmentBottlenecks.php:188` (`DB` is unresolved at the configured analysis level). The changed API files pass targeted analysis.
